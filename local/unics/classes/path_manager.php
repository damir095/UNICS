<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * A2: ИОМ — индивидуальный образовательный маршрут (план/трекер). [[iom-design]]
 *
 * Своя сущность: маршрут (unics_learning_path, один активный на ученика) + шаги
 * (unics_path_step, шаг = тема: курс + тема + опц. УМК). Доступ к курсам НЕ
 * блокирует — это надстройка-план над Moodle-completion: цель, текущий шаг,
 * прогресс N из M, план уровня против факта.
 *
 * v1: наполнение и статусы ставятся вручную методистом/педагогом. Подсказка из
 * оценок (course_grade_hint) — advisory, без автосмены статуса.
 */
class path_manager {

    /** Статусы маршрута. */
    const STATUS_ACTIVE   = 1;
    const STATUS_DONE     = 2;
    const STATUS_ARCHIVED = 3;

    /** Статусы шага. */
    const STEP_PLANNED    = 1;
    const STEP_INPROGRESS = 2;
    const STEP_DONE       = 3;

    /** Источник шага. */
    const SOURCE_MANUAL   = 1;
    const SOURCE_AI       = 2;
    const SOURCE_ADAPTIVE = 3;

    /** Доля от максимума как порог «по оценкам похоже на пройденный» (как B4/B7). */
    const HINT_THRESHOLD = 0.5;

    // -----------------------------------------------------------------
    // Метки
    // -----------------------------------------------------------------

    public static function status_label(int $status): string {
        return [
            self::STATUS_ACTIVE   => 'Активен',
            self::STATUS_DONE     => 'Завершён',
            self::STATUS_ARCHIVED => 'Архив',
        ][$status] ?? '-';
    }

    public static function step_status_label(int $status): string {
        return [
            self::STEP_PLANNED    => 'Запланирован',
            self::STEP_INPROGRESS => 'В работе',
            self::STEP_DONE       => 'Пройден',
        ][$status] ?? '-';
    }

    /** Bootstrap badge-класс для статуса шага. */
    public static function step_status_badge(int $status): string {
        return [
            self::STEP_PLANNED    => 'secondary',
            self::STEP_INPROGRESS => 'info',
            self::STEP_DONE       => 'success',
        ][$status] ?? 'secondary';
    }

    // -----------------------------------------------------------------
    // Маршрут
    // -----------------------------------------------------------------

    /**
     * Активный маршрут учащегося (status=active) или null.
     */
    public static function get_active_path(int $student_id): ?object {
        global $DB;
        $rec = $DB->get_record('unics_learning_path',
            ['student_id' => $student_id, 'status' => self::STATUS_ACTIVE]);
        return $rec ?: null;
    }

    /**
     * Активный маршрут, создавая при отсутствии (для конструктора).
     */
    public static function get_or_create_path(int $student_id, int $created_by): object {
        global $DB;
        $path = self::get_active_path($student_id);
        if ($path) {
            return $path;
        }
        $now = time();
        $id = $DB->insert_record('unics_learning_path', (object)[
            'student_id'             => $student_id,
            'created_by_mdl_user_id' => $created_by,
            'goal'                   => null,
            'status'                 => self::STATUS_ACTIVE,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        return $DB->get_record('unics_learning_path', ['id' => $id], '*', MUST_EXIST);
    }

    /** Обновить цель маршрута. */
    public static function set_goal(int $path_id, ?string $goal): void {
        global $DB;
        $DB->update_record('unics_learning_path', (object)[
            'id'         => $path_id,
            'goal'       => ($goal !== null && trim($goal) !== '') ? $goal : null,
            'updated_at' => time(),
        ]);
    }

    /** Сменить статус маршрута (активен/завершён/архив). */
    public static function set_path_status(int $path_id, int $status): void {
        global $DB;
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DONE, self::STATUS_ARCHIVED], true)) {
            return;
        }
        $DB->update_record('unics_learning_path', (object)[
            'id'         => $path_id,
            'status'     => $status,
            'updated_at' => time(),
        ]);
    }

    // -----------------------------------------------------------------
    // Шаги
    // -----------------------------------------------------------------

    /** Шаги маршрута по порядку (ordinal, затем id). */
    public static function get_steps(int $path_id): array {
        global $DB;
        return $DB->get_records('unics_path_step', ['path_id' => $path_id], 'ordinal ASC, id ASC');
    }

    /**
     * Прогресс маршрута.
     *
     * @return array{done:int, total:int, current:?object}
     *   current = первый шаг со статусом ≠ пройден (или null, если все пройдены).
     */
    public static function progress(int $path_id): array {
        $steps = self::get_steps($path_id);
        $done = 0;
        $current = null;
        foreach ($steps as $s) {
            if ((int)$s->status === self::STEP_DONE) {
                $done++;
            } else if ($current === null) {
                $current = $s;
            }
        }
        return ['done' => $done, 'total' => count($steps), 'current' => $current];
    }

    /**
     * Добавить шаг в конец маршрута.
     *
     * @param int $path_id маршрут
     * @param array $data title (обяз.), topic, mdl_course_id, umk_id, target_level,
     *              status, source, planned_from, planned_to, note
     * @return int id нового шага
     */
    public static function add_step(int $path_id, array $data): int {
        global $DB;
        $maxord = (int)$DB->get_field_sql(
            "SELECT MAX(ordinal) FROM {unics_path_step} WHERE path_id = :pid", ['pid' => $path_id]);
        $rec = (object)[
            'path_id'       => $path_id,
            'ordinal'       => $maxord + 1,
            'title'         => (string)($data['title'] ?? ''),
            'topic'         => self::clean_str($data['topic'] ?? null),
            'mdl_course_id' => !empty($data['mdl_course_id']) ? (int)$data['mdl_course_id'] : null,
            'umk_id'        => !empty($data['umk_id']) ? (int)$data['umk_id'] : null,
            'target_level'  => !empty($data['target_level']) ? (int)$data['target_level'] : null,
            'status'        => (int)($data['status'] ?? self::STEP_PLANNED),
            'source'        => (int)($data['source'] ?? self::SOURCE_MANUAL),
            'planned_from'  => !empty($data['planned_from']) ? (int)$data['planned_from'] : null,
            'planned_to'    => !empty($data['planned_to']) ? (int)$data['planned_to'] : null,
            'note'          => self::clean_str($data['note'] ?? null),
        ];
        $id = $DB->insert_record('unics_path_step', $rec);
        self::touch_path($path_id);
        return $id;
    }

    /**
     * Адаптация маршрута: авто-добавление шага «вернуться к теме» (source=adaptive)
     * в активный маршрут учащегося. Маршрут создаётся, если его нет.
     *
     * Дедуп: если в активном маршруте уже есть НЕзавершённый шаг по этой паре
     * (курс + тема), повторно не добавляем - чтобы повторные провалы той же темы
     * (или уже запланированная вручную тема) не плодили дубликаты.
     *
     * @return int|null id нового шага, либо null если дубликат (шаг уже есть).
     */
    public static function add_adaptive_topic_step(int $student_id, int $created_by, int $course_id,
                                                   string $topic, ?int $target_level, ?string $note): ?int {
        global $DB;
        $topic = trim($topic);
        if ($topic === '' || $course_id <= 0) {
            return null;
        }
        $path = self::get_or_create_path($student_id, $created_by);

        // Дедуп: уже есть незавершённый шаг по этому курсу и теме?
        $steps = $DB->get_records('unics_path_step', ['path_id' => $path->id, 'mdl_course_id' => $course_id]);
        foreach ($steps as $s) {
            if ((int)$s->status !== self::STEP_DONE && (string)$s->topic === $topic) {
                return null;
            }
        }

        return self::add_step($path->id, [
            'title'         => 'Повторить: ' . $topic,
            'topic'         => $topic,
            'mdl_course_id' => $course_id,
            'target_level'  => $target_level,
            'status'        => self::STEP_PLANNED,
            'source'        => self::SOURCE_ADAPTIVE,
            'note'          => $note,
        ]);
    }

    /**
     * Обновить поля шага. Меняются только переданные ключи.
     */
    public static function update_step(int $step_id, array $data): void {
        global $DB;
        $step = $DB->get_record('unics_path_step', ['id' => $step_id], '*', MUST_EXIST);
        $upd = ['id' => $step_id];
        if (array_key_exists('title', $data))        { $upd['title']        = (string)$data['title']; }
        if (array_key_exists('topic', $data))        { $upd['topic']        = self::clean_str($data['topic']); }
        if (array_key_exists('mdl_course_id', $data)){ $upd['mdl_course_id'] = !empty($data['mdl_course_id']) ? (int)$data['mdl_course_id'] : null; }
        if (array_key_exists('umk_id', $data))       { $upd['umk_id']       = !empty($data['umk_id']) ? (int)$data['umk_id'] : null; }
        if (array_key_exists('target_level', $data)) { $upd['target_level'] = !empty($data['target_level']) ? (int)$data['target_level'] : null; }
        if (array_key_exists('status', $data))       { $upd['status']       = (int)$data['status']; }
        if (array_key_exists('planned_from', $data)) { $upd['planned_from'] = !empty($data['planned_from']) ? (int)$data['planned_from'] : null; }
        if (array_key_exists('planned_to', $data))   { $upd['planned_to']   = !empty($data['planned_to']) ? (int)$data['planned_to'] : null; }
        if (array_key_exists('note', $data))         { $upd['note']         = self::clean_str($data['note']); }
        $DB->update_record('unics_path_step', (object)$upd);
        self::touch_path((int)$step->path_id);
    }

    /**
     * Удалить шаг и перенумеровать оставшиеся (ordinal без дыр).
     */
    public static function delete_step(int $step_id): void {
        global $DB;
        $step = $DB->get_record('unics_path_step', ['id' => $step_id]);
        if (!$step) {
            return;
        }
        $DB->delete_records('unics_path_step', ['id' => $step_id]);
        self::reindex((int)$step->path_id);
        self::touch_path((int)$step->path_id);
    }

    /**
     * Переместить шаг вверх/вниз (обмен ordinal с соседом).
     *
     * @param string $dir 'up' | 'down'
     */
    public static function move_step(int $step_id, string $dir): void {
        global $DB;
        $step = $DB->get_record('unics_path_step', ['id' => $step_id]);
        if (!$step) {
            return;
        }
        $steps = array_values(self::get_steps((int)$step->path_id));
        $idx = null;
        foreach ($steps as $i => $s) {
            if ((int)$s->id === $step_id) { $idx = $i; break; }
        }
        if ($idx === null) {
            return;
        }
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= count($steps)) {
            return; // край списка
        }
        $a = $steps[$idx];
        $b = $steps[$swap];
        $DB->update_record('unics_path_step', (object)['id' => $a->id, 'ordinal' => $b->ordinal]);
        $DB->update_record('unics_path_step', (object)['id' => $b->id, 'ordinal' => $a->ordinal]);
        self::touch_path((int)$step->path_id);
    }

    /** Перенумеровать ordinal маршрута 1..N по текущему порядку. */
    private static function reindex(int $path_id): void {
        global $DB;
        $i = 1;
        foreach (self::get_steps($path_id) as $s) {
            if ((int)$s->ordinal !== $i) {
                $DB->set_field('unics_path_step', 'ordinal', $i, ['id' => $s->id]);
            }
            $i++;
        }
    }

    private static function touch_path(int $path_id): void {
        global $DB;
        $DB->set_field('unics_learning_path', 'updated_at', time(), ['id' => $path_id]);
    }

    private static function clean_str($val): ?string {
        if ($val === null) {
            return null;
        }
        $val = trim((string)$val);
        return $val === '' ? null : $val;
    }

    // -----------------------------------------------------------------
    // Подсказка из оценок (advisory, без автосмены статуса)
    // -----------------------------------------------------------------

    /**
     * Средний % учащегося по тестам курса шага (для подсказки «похоже на пройденный»).
     *
     * @return float|null средний процент 0..100, либо null если оценок нет
     */
    public static function course_grade_hint(int $mdl_user_id, int $course_id): ?float {
        global $DB;
        if (!$course_id) {
            return null;
        }
        $rows = $DB->get_records_sql(
            "SELECT g.id, g.finalgrade, gi.grademax
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid      = :uid
                AND gi.courseid   = :cid
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0",
            ['uid' => $mdl_user_id, 'cid' => $course_id]
        );
        if (!$rows) {
            return null;
        }
        $pcts = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $rows);
        return round(array_sum($pcts) / count($pcts), 1);
    }

    /** true, если по оценкам курса шаг «похож на пройденный» (avg ≥ порога). */
    public static function looks_done(int $mdl_user_id, int $course_id): bool {
        $avg = self::course_grade_hint($mdl_user_id, $course_id);
        return $avg !== null && $avg >= self::HINT_THRESHOLD * 100;
    }

    // -----------------------------------------------------------------
    // Доступ (зеркалит student_report.php)
    // -----------------------------------------------------------------

    /**
     * Уровень доступа пользователя к маршруту ученика: 'edit' | 'view' | 'none'.
     *
     * edit  — админ; методист (свой скоуп орг.); педагог (привязан teacher_student).
     * view  — дополнительно: родитель (ребёнка), сам ученик.
     */
    public static function access_level(int $student_id, int $userid): string {
        global $DB;
        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) {
            return 'none';
        }
        $ctx = \context_system::instance();
        $is_admin   = has_capability('local/unics:manage', $ctx, $userid);
        $is_teacher = has_capability('local/unics:viewstudents', $ctx, $userid);
        $is_methodist = $is_teacher && !$is_admin && \local_unics_is_methodist($userid);

        if ($is_admin) {
            return 'edit';
        }
        if ($is_methodist) {
            $rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $userid]);
            $org = ($rec && $rec->organization_id) ? (int)$rec->organization_id : 0;
            if ($org > 0 && (int)$student->organization_id === $org) {
                return 'edit';
            }
        } else if ($is_teacher) {
            $rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $userid]);
            if ($rec && $DB->record_exists('unics_teacher_student',
                    ['teacher_id' => $rec->id, 'student_id' => $student_id])) {
                return 'edit';
            }
        }
        // Просмотр: родитель ребёнка или сам ученик.
        if ($DB->record_exists('unics_parent_student',
                ['parent_mdl_user_id' => $userid, 'student_id' => $student_id])) {
            return 'view';
        }
        if ((int)$student->mdl_user_id === $userid) {
            return 'view';
        }
        return 'none';
    }

    public static function can_edit(int $student_id, int $userid): bool {
        return self::access_level($student_id, $userid) === 'edit';
    }

    public static function can_view(int $student_id, int $userid): bool {
        return self::access_level($student_id, $userid) !== 'none';
    }
}
