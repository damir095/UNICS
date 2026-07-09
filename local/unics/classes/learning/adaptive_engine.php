<?php
namespace local_unics\learning;

defined('MOODLE_INTERNAL') || die();

class adaptive_engine {

    /** Порог среднего балла для повышения уровня (%). */
    const THRESHOLD_UP = 85;
    /** Порог среднего балла для понижения уровня (%). */
    const THRESHOLD_DOWN = 50;
    /** Минимум тестов для оценки уровня. */
    const MIN_GRADES = 3;

    /** Стартовая диагностика: >= PLACE_HIGH % -> уровень 3 (продвинутый). */
    const PLACE_HIGH = 80;
    /** Стартовая диагностика: < PLACE_LOW % -> уровень 1 (базовый); между - уровень 2. */
    const PLACE_LOW = 50;

    /**
     * Стартовая диагностика (стадия «Диагностика и старт» адаптивного цикла):
     * по результату входного теста РАЗОВО ставит стартовый уровень сложности.
     *
     * Срабатывает один раз на учащегося (флаг unics_students.diagnosed). Маппинг:
     *   pct >= PLACE_HIGH -> 3; pct < PLACE_LOW -> 1; иначе -> 2.
     * При смене уровня пишет историю (как evaluate_student) и обновляет поле
     * профиля Moodle. Вызывается из observer при оценке теста, помеченного
     * как входной диагностический (unics_activity_meta.is_diagnostic).
     *
     * @return int|null установленный стартовый уровень, либо null если уже
     *   диагностирован / нет оценки по диагностическому тесту.
     */
    public static function diagnose_student(int $student_id, int $cmid): ?int {
        global $DB;

        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) {
            return null;
        }
        // Разовость: стартовый уровень ставится один раз.
        if ((int)$student->diagnosed === 1) {
            return null;
        }

        // Оценка учащегося именно по диагностическому тесту (по его cmid).
        $rec = $DB->get_record_sql(
            "SELECT g.finalgrade, gi.grademax
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
               JOIN {course_modules} cm ON cm.instance = gi.iteminstance
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
              WHERE cm.id = :cmid
                AND gi.itemtype = 'mod' AND gi.itemmodule = 'quiz'
                AND g.userid = :uid
                AND g.finalgrade IS NOT NULL AND gi.grademax > 0",
            ['cmid' => $cmid, 'uid' => (int)$student->mdl_user_id]
        );
        if (!$rec) {
            return null;
        }

        $pct   = (float)$rec->finalgrade / (float)$rec->grademax * 100;
        $level = $pct >= self::PLACE_HIGH ? 3 : ($pct < self::PLACE_LOW ? 1 : 2);
        $cur   = (int)$student->difficulty_level;

        // Отмечаем «диагностирован» в любом случае - чтобы повтор не переставлял.
        $DB->set_field('unics_students', 'diagnosed', 1, ['id' => $student_id]);

        if ($level === $cur) {
            // Стартовый уровень совпал с текущим - менять и логировать нечего.
            return $level;
        }

        $DB->set_field('unics_students', 'difficulty_level', $level, ['id' => $student_id]);

        // История уровней (как evaluate_student) - честный переход cur -> level.
        try {
            $DB->insert_record('unics_level_history', (object)[
                'student_id'  => $student_id,
                'mdl_user_id' => (int)$student->mdl_user_id,
                'old_level'   => $cur,
                'new_level'   => $level,
                'avg_score'   => round($pct, 2),
                'changed_at'  => time(),
            ]);
        } catch (\Throwable $e) {
            debugging('local_unics: запись стартовой диагностики не удалась: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Поле профиля Moodle (как в evaluate_student).
        \unics_user_manager::set_student_level((int)$student->mdl_user_id, $level);

        // Событие в штатный журнал (этап 2.4 аудита).
        \local_unics\event\level_changed::create([
            'context'       => \context_system::instance(),
            'objectid'      => $student_id,
            'relateduserid' => (int)$student->mdl_user_id,
            'other'         => ['old_level' => $cur, 'new_level' => $level, 'source' => 'placement'],
        ])->trigger();

        return $level;
    }

    /**
     * Read-only расчёт: какой уровень предложил бы движок учащемуся, БЕЗ записи в БД.
     * Та же логика и пороги, что у evaluate_student — для предпросмотра на странице
     * «Адаптивные уровни» (текущий → предложенный) перед пересчётом.
     *
     * @param int $student_id id записи unics_students
     * @return array{cur:int, proposed:int|null, avg:float|null, n:int}
     *   proposed === null — данных мало (нет смысла менять); иначе предложенный уровень
     *   (может совпадать с текущим — значит изменения не будет).
     */
    public static function preview_student(int $student_id): array {
        global $DB;

        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) {
            return ['cur' => 0, 'proposed' => null, 'avg' => null, 'n' => 0];
        }

        $cur_lvl = (int)$student->difficulty_level;

        $grades = $DB->get_records_sql(
            "SELECT g.id, g.finalgrade, gi.grademax
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
        $n = count($grades);

        if ($n < self::MIN_GRADES) {
            return ['cur' => $cur_lvl, 'proposed' => null, 'avg' => null, 'n' => $n];
        }

        $pcts = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades);
        $avg  = array_sum($pcts) / count($pcts);

        $proposed = $cur_lvl;
        if ($avg >= self::THRESHOLD_UP && $cur_lvl < 3) {
            $proposed = $cur_lvl + 1;
        } else if ($avg < self::THRESHOLD_DOWN && $cur_lvl > 1) {
            $proposed = $cur_lvl - 1;
        }

        return ['cur' => $cur_lvl, 'proposed' => $proposed, 'avg' => round($avg, 1), 'n' => $n];
    }

    /**
     * Проверить и при необходимости скорректировать уровень сложности учащегося.
     *
     * Логика:
     *   - avg ≥ 85% по последним 5 тестам → уровень + 1 (max 3) → +100 баллов
     *   - avg < 50% по последним 5 тестам → уровень - 1 (min 1)
     *   - Минимум 3 теста - иначе данных недостаточно
     *
     * Это МГНОВЕННОЕ применение (вычисление + apply_level). Используется явным ручным
     * пересчётом педагога (pages/course_levels.php). Автоматические пути (observer ->
     * mastery_manager, ночная задача) идут через gate_level_change (гибридный гейт S2).
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

        return self::apply_level($student_id, $new_lvl, $avg);
    }

    /**
     * Применить новый уровень учащемуся: difficulty_level + история + поле профиля +
     * уведомления (ученик/родители/педагоги) + баллы за повышение. Чистое применение,
     * БЕЗ вычисления и БЕЗ гейта - используется и evaluate_student (мгновенно), и
     * suggestion_service::apply (по approve / авто через N).
     *
     * @return int|null новый уровень, либо null если ученика нет или new == cur
     */
    public static function apply_level(int $student_id, int $new_lvl, ?float $avg): ?int {
        global $DB;

        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) {
            return null;
        }
        $cur_lvl = (int)$student->difficulty_level;
        if ($new_lvl === $cur_lvl) {
            return null;
        }

        $DB->set_field('unics_students', 'difficulty_level', $new_lvl, ['id' => $student_id]);

        try {
            $DB->insert_record('unics_level_history', (object)[
                'student_id'  => $student_id,
                'mdl_user_id' => (int)$student->mdl_user_id,
                'old_level'   => $cur_lvl,
                'new_level'   => $new_lvl,
                'avg_score'   => $avg !== null ? round($avg, 2) : null,
                'changed_at'  => time(),
            ]);
        } catch (\Throwable $e) {
            debugging('local_unics: запись unics_level_history не удалась: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        \unics_user_manager::set_student_level((int)$student->mdl_user_id, $new_lvl);

        // Событие в штатный журнал (этап 2.4 аудита).
        \local_unics\event\level_changed::create([
            'context'       => \context_system::instance(),
            'objectid'      => $student_id,
            'relateduserid' => (int)$student->mdl_user_id,
            'other'         => ['old_level' => $cur_lvl, 'new_level' => $new_lvl, 'source' => 'apply'],
        ])->trigger();

        $level_names = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $direction   = $new_lvl > $cur_lvl ? 'повышен' : 'понижен';
        $mdl_user    = $DB->get_record('user', ['id' => $student->mdl_user_id]);
        $sname       = $mdl_user ? fullname($mdl_user) : 'Учащийся #' . $student_id;

        $teachers = $DB->get_records_sql(
            "SELECT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student_id]
        );
        $parents     = $DB->get_records('unics_parent_student', ['student_id' => $student_id], '', 'parent_mdl_user_id');
        $parent_uids = array_column((array)$parents, 'parent_mdl_user_id');

        try {
            $points_awarded = $new_lvl > $cur_lvl ? \local_unics\social\points_manager::POINTS_LEVEL_UP : 0;
            \local_unics\social\notification_manager::notify_level_changed_student(
                (int)$student->mdl_user_id, $cur_lvl, $new_lvl, $avg !== null ? $avg : 0, $points_awarded);
            if (!empty($parent_uids)) {
                \local_unics\social\notification_manager::notify_level_changed_parents($parent_uids, $sname, $cur_lvl, $new_lvl);
            }
            $subject = "Уровень сложности изменён: {$sname}";
            $body    = '<p>Уровень учащегося <strong>' . htmlspecialchars($sname) . '</strong> '
                     . '<strong>' . $direction . '</strong>: '
                     . ($level_names[$cur_lvl] ?? $cur_lvl) . ' → '
                     . '<strong>' . ($level_names[$new_lvl] ?? $new_lvl) . '</strong></p>';
            foreach ($teachers as $tl) {
                \local_unics\social\notification_manager::send((int)$tl->mdl_user_id, $subject, $body,
                    $new_lvl > $cur_lvl ? \local_unics\social\notification_manager::TYPE_LEVEL_UP : \local_unics\social\notification_manager::TYPE_LEVEL_DOWN);
            }
        } catch (\Throwable $e) {
            // Уведомления нефатальны.
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }

        if ($new_lvl > $cur_lvl) {
            try {
                \local_unics\social\points_manager::award($student_id, \local_unics\social\points_manager::POINTS_LEVEL_UP,
                    \local_unics\social\points_manager::REASON_LEVEL_UP,
                    'Повышение уровня до «' . ($level_names[$new_lvl] ?? $new_lvl) . '»');
            } catch (\Throwable $e) {
                // Нефатально.
                debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
            }
        }

        return $new_lvl;
    }

    /**
     * Чистое решение гейта по предпросчитанным данным (БД не трогает - тестируемо).
     *
     * @return array{action:string, target:?int} action: 'none'|'apply'|'suggest'
     */
    public static function gate_decision(int $cur, ?int $proposed, ?float $avg, int $autoapply_days): array {
        if ($proposed === null || $proposed === $cur) {
            return ['action' => 'none', 'target' => null];
        }
        return ['action' => $autoapply_days <= 0 ? 'apply' : 'suggest', 'target' => $proposed];
    }

    /**
     * Гейт глобальной смены уровня (автоматический путь: observer/ночная задача).
     * preview -> gate_decision -> применить (N=0) ИЛИ создать предложение педагогу.
     * Дедуп смены уровня - один открытый suggestion(kind=level_change) на ученика
     * (element_id NULL) обеспечивает suggestion_service::create.
     *
     * @return int|null применённый уровень, либо null (создано предложение / нет изменения)
     */
    public static function gate_level_change(int $student_id): ?int {
        $p = self::preview_student($student_id);
        $days = (int)get_config('local_unics', 'adaptive_autoapply_days');
        $decision = self::gate_decision((int)$p['cur'], $p['proposed'], $p['avg'], $days);

        if ($decision['action'] === 'apply') {
            return self::apply_level($student_id, (int)$decision['target'], $p['avg']);
        }
        if ($decision['action'] === 'suggest') {
            $auto_after = $days > 0 ? time() + $days * 86400 : null;
            suggestion_service::create(
                $student_id,
                suggestion_service::KIND_LEVEL_CHANGE,
                null,
                json_encode(['target_level' => (int)$decision['target'], 'avg' => $p['avg']]),
                $auto_after,
                null
            );
        }
        return null;
    }
}
