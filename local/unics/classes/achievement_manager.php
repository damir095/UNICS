<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class achievement_manager {

    const BADGE_DILIGENT  = 1; // ⭐ Старательный: avg ≥ 85% за последние 5 тестов
    const BADGE_ACTIVE    = 2; // 📚 Активный: записан на ≥ 3 курса
    const BADGE_EXCELLENT = 3; // 🚀 Отличник: avg ≥ 90% (минимум 3 теста)
    const BADGE_COMPLETER = 4; // 🎓 Завершитель: сдал хотя бы 1 тест с результатом ≥ 60%

    /**
     * Проверить и выдать все применимые значки.
     * При выдаче нового значка - начисляет баллы и отправляет уведомления.
     * Возвращает список новых badge_type.
     */
    public static function evaluate_student(int $student_id, int $mdl_user_id): array {
        global $DB;

        $awarded = [];
        if (self::check_diligent($student_id, $mdl_user_id))  $awarded[] = self::BADGE_DILIGENT;
        if (self::check_active($student_id, $mdl_user_id))    $awarded[] = self::BADGE_ACTIVE;
        if (self::check_excellent($student_id, $mdl_user_id)) $awarded[] = self::BADGE_EXCELLENT;
        if (self::check_completer($student_id, $mdl_user_id)) $awarded[] = self::BADGE_COMPLETER;

        if (empty($awarded)) {
            return [];
        }

        $badge_info = self::get_badge_info();

        // Получить родителей учащегося для уведомлений
        $parent_rows = $DB->get_records('unics_parent_student', ['student_id' => $student_id], '', 'parent_mdl_user_id');
        $parent_uids = array_column((array)$parent_rows, 'parent_mdl_user_id');

        $mdl_user = $DB->get_record('user', ['id' => $mdl_user_id, 'deleted' => 0]);
        $student_name = $mdl_user ? fullname($mdl_user) : 'Учащийся';

        foreach ($awarded as $badge_type) {
            $info = $badge_info[$badge_type];

            // Начислить баллы
            try {
                points_manager::award(
                    $student_id,
                    points_manager::POINTS_BADGE,
                    points_manager::REASON_BADGE,
                    'Значок «' . $info['name'] . '»'
                );
            } catch (\Throwable $e) {
                // Нефатально
            }

            // Уведомить учащегося
            try {
                notification_manager::notify_badge_earned_student(
                    $mdl_user_id,
                    $info['icon'],
                    $info['name'],
                    points_manager::POINTS_BADGE
                );
            } catch (\Throwable $e) {
                // Нефатально
            }

            // Уведомить родителей
            try {
                if (!empty($parent_uids)) {
                    notification_manager::notify_badge_earned_parents(
                        $parent_uids,
                        $student_name,
                        $info['icon'],
                        $info['name']
                    );
                }
            } catch (\Throwable $e) {
                // Нефатально
            }
        }

        return $awarded;
    }

    public static function get_badge_info(): array {
        return [
            self::BADGE_DILIGENT  => [
                'icon' => '⭐',
                'name' => 'Старательный',
                'desc' => 'Средний балл ≥ 85% за последние 5 тестов',
            ],
            self::BADGE_ACTIVE    => [
                'icon' => '📚',
                'name' => 'Активный',
                'desc' => 'Записан на 3 и более курса',
            ],
            self::BADGE_EXCELLENT => [
                'icon' => '🚀',
                'name' => 'Отличник',
                'desc' => 'Средний балл ≥ 90% по всем тестам (не менее 3)',
            ],
            self::BADGE_COMPLETER => [
                'icon' => '🎓',
                'name' => 'Завершитель',
                'desc' => 'Сдан хотя бы один тест с результатом не менее 60%',
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Внутренние проверки
    // ----------------------------------------------------------------

    private static function award(int $student_id, int $badge_type, string $note = ''): bool {
        global $DB;
        if ($DB->record_exists('unics_achievements', ['student_id' => $student_id, 'badge_type' => $badge_type])) {
            return false;
        }
        $DB->insert_record('unics_achievements', (object)[
            'student_id' => $student_id,
            'badge_type' => $badge_type,
            'awarded_at' => time(),
            'awarded_by' => 0,
            'note'       => $note,
        ]);
        return true;
    }

    private static function check_diligent(int $student_id, int $mdl_user_id): bool {
        global $DB;
        $grades = $DB->get_records_sql(
            "SELECT g.finalgrade, gi.grademax
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid = :uid
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0
              ORDER BY g.timemodified DESC
              LIMIT 5",
            ['uid' => $mdl_user_id]
        );
        if (count($grades) < 5) return false;
        $pcts = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades);
        if ((array_sum($pcts) / count($pcts)) >= 85) {
            return self::award($student_id, self::BADGE_DILIGENT, 'avg ≥ 85% за 5 тестов');
        }
        return false;
    }

    private static function check_active(int $student_id, int $mdl_user_id): bool {
        global $DB;
        $count = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT e.courseid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND ue.status = 0 AND e.courseid != 1",
            ['uid' => $mdl_user_id]
        );
        if ($count >= 3) {
            return self::award($student_id, self::BADGE_ACTIVE, "записан на {$count} курсов");
        }
        return false;
    }

    private static function check_excellent(int $student_id, int $mdl_user_id): bool {
        global $DB;
        $grades = $DB->get_records_sql(
            "SELECT g.finalgrade, gi.grademax
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid = :uid
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0",
            ['uid' => $mdl_user_id]
        );
        if (count($grades) < 3) return false;
        $pcts = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades);
        if ((array_sum($pcts) / count($pcts)) >= 90) {
            return self::award($student_id, self::BADGE_EXCELLENT, 'avg ≥ 90% по всем тестам');
        }
        return false;
    }

    private static function check_completer(int $student_id, int $mdl_user_id): bool {
        global $DB;
        $passed = (int)$DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid = :uid
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0
                AND (g.finalgrade / gi.grademax) >= 0.6",
            ['uid' => $mdl_user_id]
        );
        if ($passed >= 1) {
            return self::award($student_id, self::BADGE_COMPLETER, 'сдан тест ≥ 60%');
        }
        return false;
    }
}
