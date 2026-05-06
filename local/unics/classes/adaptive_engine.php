<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class adaptive_engine {

    /**
     * Проверить и при необходимости скорректировать уровень сложности учащегося.
     *
     * Логика:
     *   - avg ≥ 85% по последним 5 тестам → уровень + 1 (max 3)
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
              WHERE g.userid        = :uid
                AND gi.itemtype     = 'mod'
                AND gi.itemmodule   = 'quiz'
                AND g.finalgrade   IS NOT NULL
                AND gi.grademax     > 0
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

        // Уведомляем педагогов об изменении уровня
        $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $direction   = $new_lvl > $cur_lvl ? 'повышен' : 'понижен';
        $mdl_user    = $DB->get_record('user', ['id' => $student->mdl_user_id]);
        $sname       = $mdl_user ? fullname($mdl_user) : 'Учащийся #' . $student_id;
        $subject     = "Уровень сложности изменён: {$sname}";
        $body        = '<p>Уровень учащегося <strong>' . htmlspecialchars($sname) . '</strong> '
                     . 'автоматически <strong>' . $direction . '</strong>: '
                     . ($level_names[$cur_lvl] ?? $cur_lvl) . ' → '
                     . '<strong>' . ($level_names[$new_lvl] ?? $new_lvl) . '</strong></p>'
                     . '<p>Средний балл по последним ' . count($grades) . ' тестам: '
                     . round($avg, 1) . '%</p>';

        try {
            require_once(dirname(__DIR__) . '/classes/notification_manager.php');
            $teachers = $DB->get_records_sql(
                "SELECT t.mdl_user_id
                   FROM {unics_teacher_student} ts
                   JOIN {unics_teachers} t ON t.id = ts.teacher_id
                  WHERE ts.student_id = :sid",
                ['sid' => $student_id]
            );
            foreach ($teachers as $tl) {
                \local_unics\notification_manager::send(
                    (int)$tl->mdl_user_id,
                    $subject,
                    $body,
                    \local_unics\notification_manager::TYPE_LOW_SCORE
                );
            }
        } catch (\Throwable $e) {
            // Уведомление нефатально
        }

        return $new_lvl;
    }
}
