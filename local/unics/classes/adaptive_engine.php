<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class adaptive_engine {

    /**
     * Проверить и при необходимости скорректировать уровень сложности учащегося.
     *
     * Логика:
     *   - avg ≥ 85% по последним 5 тестам → уровень + 1 (max 3) → +100 баллов
     *   - avg < 50% по последним 5 тестам → уровень - 1 (min 1)
     *   - Минимум 3 теста — иначе данных недостаточно
     *
     * @return int|null Новый уровень если изменился, null если не изменился или данных мало.
     */
    public static function evaluate_student(int $student_id): ?int {
        global $DB;

        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) {
            return null;
        }

        $grades = $DB->get_records_sql(
            "SELECT g.finalgrade, gi.grademax
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid      = :uid
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0
              ORDER BY g.timemodified DESC
              LIMIT 5",
            ['uid' => $student->mdl_user_id]
        );

        if (count($grades) < 3) {
            return null;
        }

        $pcts    = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades);
        $avg     = array_sum($pcts) / count($pcts);
        $cur_lvl = (int)$student->difficulty_level;
        $new_lvl = $cur_lvl;

        if ($avg >= 85 && $cur_lvl < 3) {
            $new_lvl = $cur_lvl + 1;
        } elseif ($avg < 50 && $cur_lvl > 1) {
            $new_lvl = $cur_lvl - 1;
        }

        if ($new_lvl === $cur_lvl) {
            return null;
        }

        $DB->set_field('unics_students', 'difficulty_level', $new_lvl, ['id' => $student_id]);

        // Обновляем Moodle profile field
        require_once(dirname(__DIR__) . '/classes/user_manager.php');
        \unics_user_manager::set_student_level((int)$student->mdl_user_id, $new_lvl);

        $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $direction   = $new_lvl > $cur_lvl ? 'повышен' : 'понижен';
        $mdl_user    = $DB->get_record('user', ['id' => $student->mdl_user_id]);
        $sname       = $mdl_user ? fullname($mdl_user) : 'Учащийся #' . $student_id;

        // Педагоги учащегося
        $teachers = $DB->get_records_sql(
            "SELECT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student_id]
        );

        // Родители учащегося
        $parents     = $DB->get_records('unics_parent_student', ['student_id' => $student_id], '', 'parent_mdl_user_id');
        $parent_uids = array_column((array)$parents, 'parent_mdl_user_id');

        try {
            require_once(dirname(__DIR__) . '/classes/notification_manager.php');

            // Уведомить учащегося
            $points_awarded = $new_lvl > $cur_lvl ? points_manager::POINTS_LEVEL_UP : 0;
            notification_manager::notify_level_changed_student(
                (int)$student->mdl_user_id,
                $cur_lvl,
                $new_lvl,
                $avg,
                $points_awarded
            );

            // Уведомить родителей
            if (!empty($parent_uids)) {
                notification_manager::notify_level_changed_parents(
                    $parent_uids,
                    $sname,
                    $cur_lvl,
                    $new_lvl
                );
            }

            // Уведомить педагогов (старый тип TYPE_LOW_SCORE использован — используем напрямую send)
            $subject = "Уровень сложности изменён: {$sname}";
            $body    = '<p>Уровень учащегося <strong>' . htmlspecialchars($sname) . '</strong> '
                     . 'автоматически <strong>' . $direction . '</strong>: '
                     . ($level_names[$cur_lvl] ?? $cur_lvl) . ' → '
                     . '<strong>' . ($level_names[$new_lvl] ?? $new_lvl) . '</strong></p>'
                     . '<p>Средний балл по последним ' . count($grades) . ' тестам: '
                     . round($avg, 1) . '%</p>';

            foreach ($teachers as $tl) {
                notification_manager::send(
                    (int)$tl->mdl_user_id,
                    $subject,
                    $body,
                    $new_lvl > $cur_lvl ? notification_manager::TYPE_LEVEL_UP : notification_manager::TYPE_LEVEL_DOWN
                );
            }
        } catch (\Throwable $e) {
            // Уведомления нефатальны
        }

        // Начислить баллы за повышение уровня
        if ($new_lvl > $cur_lvl) {
            try {
                require_once(dirname(__DIR__) . '/classes/points_manager.php');
                points_manager::award(
                    $student_id,
                    points_manager::POINTS_LEVEL_UP,
                    points_manager::REASON_LEVEL_UP,
                    'Повышение уровня до «' . ($level_names[$new_lvl] ?? $new_lvl) . '»'
                );
            } catch (\Throwable $e) {
                // Нефатально
            }
        }

        return $new_lvl;
    }
}
