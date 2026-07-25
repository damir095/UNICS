<?php
namespace local_unics\social;

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
                debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
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
                debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
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
                debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
            }
        }

        return $awarded;
    }

    public static function get_badge_info(): array {
        // icon_key - SVG из pix/shop/{key}.svg (основной способ);
        // icon (эмодзи) остается legacy-фолбэком.
        return [
            self::BADGE_DILIGENT  => [
                'icon'     => '⭐',
                'icon_key' => 'star',
                'name'     => 'Старательный',
                'desc'     => 'Средний балл не ниже 85% за последние 5 тестов',
            ],
            self::BADGE_ACTIVE    => [
                'icon'     => '📚',
                'icon_key' => 'book',
                'name'     => 'Активный',
                'desc'     => 'Записан на 3 и более курса',
            ],
            self::BADGE_EXCELLENT => [
                'icon'     => '🚀',
                'icon_key' => 'rocket',
                'name'     => 'Отличник',
                'desc'     => 'Средний балл не ниже 90% по всем тестам (не менее 3)',
            ],
            self::BADGE_COMPLETER => [
                'icon'     => '🎓',
                'icon_key' => 'medal',
                'name'     => 'Завершитель',
                'desc'     => 'Сдан хотя бы один тест с результатом не менее 60%',
            ],
        ];
    }

    /**
     * Прогресс ученика к каждому значку ([[badge-progress-design]], срез 4).
     * earned читается из unics_achievements; прогресс - по связывающему воротцу.
     *
     * @return array<int, array{earned:bool, awarded_at:?int, unit:?string,
     *                          current:int, target:int, pct:int}>
     */
    public static function get_badge_progress(int $student_id, int $mdl_user_id): array {
        global $DB;
        $awarded = $DB->get_records('unics_achievements', ['student_id' => $student_id],
            '', 'badge_type, awarded_at');

        $last5   = self::quiz_grade_pcts($mdl_user_id, 5);
        $all     = self::quiz_grade_pcts($mdl_user_id, null);
        $courses = self::count_courses($mdl_user_id);
        $passed  = self::count_passed($mdl_user_id);
        $avg     = fn(array $p) => $p ? array_sum($p) / count($p) : 0;
        $clamp   = fn($cur, $target) => max(0, min(100, (int)round($cur / $target * 100)));

        // Связывающее воротце по каждому значку.
        $gate = [
            self::BADGE_ACTIVE    => ['unit' => 'count', 'current' => $courses, 'target' => 3],
            self::BADGE_COMPLETER => ['unit' => 'count', 'current' => $passed,  'target' => 1],
        ];
        $gate[self::BADGE_DILIGENT] = (count($last5) < 5)
            ? ['unit' => 'count', 'current' => count($last5), 'target' => 5]
            : ['unit' => 'pct',   'current' => (int)round($avg($last5)), 'target' => 85];
        $gate[self::BADGE_EXCELLENT] = (count($all) < 3)
            ? ['unit' => 'count', 'current' => count($all), 'target' => 3]
            : ['unit' => 'pct',   'current' => (int)round($avg($all)), 'target' => 90];

        $out = [];
        foreach (array_keys(self::get_badge_info()) as $type) {
            if (isset($awarded[$type])) {
                $out[$type] = ['earned' => true, 'awarded_at' => (int)$awarded[$type]->awarded_at,
                    'unit' => null, 'current' => 0, 'target' => 0, 'pct' => 100];
            } else {
                $g = $gate[$type];
                $out[$type] = ['earned' => false, 'awarded_at' => null,
                    'unit' => $g['unit'], 'current' => (int)$g['current'],
                    'target' => (int)$g['target'], 'pct' => $clamp($g['current'], $g['target'])];
            }
        }
        return $out;
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

    /** Проценты (finalgrade/grademax*100) по тестам ученика; $limit последних по времени, null - все. */
    private static function quiz_grade_pcts(int $mdl_user_id, ?int $limit): array {
        global $DB;
        $sql = "SELECT g.id, g.finalgrade, gi.grademax
                  FROM {grade_grades} g
                  JOIN {grade_items} gi ON gi.id = g.itemid
                 WHERE g.userid = :uid
                   AND gi.itemtype   = 'mod'
                   AND gi.itemmodule = 'quiz'
                   AND g.finalgrade IS NOT NULL
                   AND gi.grademax   > 0
                 ORDER BY g.timemodified DESC";
        $records = $limit !== null
            ? $DB->get_records_sql($sql, ['uid' => $mdl_user_id], 0, $limit)
            : $DB->get_records_sql($sql, ['uid' => $mdl_user_id]);
        return array_map(fn($g) => (float)$g->finalgrade / (float)$g->grademax * 100,
            array_values($records));
    }

    /** Число курсов, где ученик записан (кроме сайтового id=1). */
    private static function count_courses(int $mdl_user_id): int {
        global $DB;
        return (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT e.courseid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND ue.status = 0 AND e.courseid != 1",
            ['uid' => $mdl_user_id]);
    }

    /** Число тестов, сданных на >= 60%. */
    private static function count_passed(int $mdl_user_id): int {
        global $DB;
        return (int)$DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid = :uid
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0
                AND (g.finalgrade / gi.grademax) >= 0.6",
            ['uid' => $mdl_user_id]);
    }

    private static function check_diligent(int $student_id, int $mdl_user_id): bool {
        $pcts = self::quiz_grade_pcts($mdl_user_id, 5);
        if (count($pcts) < 5) {
            return false;
        }
        if ((array_sum($pcts) / count($pcts)) >= 85) {
            return self::award($student_id, self::BADGE_DILIGENT, 'avg ≥ 85% за 5 тестов');
        }
        return false;
    }

    private static function check_active(int $student_id, int $mdl_user_id): bool {
        $count = self::count_courses($mdl_user_id);
        if ($count >= 3) {
            return self::award($student_id, self::BADGE_ACTIVE, "записан на {$count} курсов");
        }
        return false;
    }

    private static function check_excellent(int $student_id, int $mdl_user_id): bool {
        $pcts = self::quiz_grade_pcts($mdl_user_id, null);
        if (count($pcts) < 3) {
            return false;
        }
        if ((array_sum($pcts) / count($pcts)) >= 90) {
            return self::award($student_id, self::BADGE_EXCELLENT, 'avg ≥ 90% по всем тестам');
        }
        return false;
    }

    private static function check_completer(int $student_id, int $mdl_user_id): bool {
        if (self::count_passed($mdl_user_id) >= 1) {
            return self::award($student_id, self::BADGE_COMPLETER, 'сдан тест ≥ 60%');
        }
        return false;
    }
}
