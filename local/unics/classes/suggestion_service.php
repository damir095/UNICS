<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Очередь предложений гибридного гейта педагога [[adaptive-ai-design]]. S1 - только
 * хранилище-CRUD + дедуп; наполнение (рекомендатель), применение (смена уровня / создание
 * path_step) и авто-применение по сроку - S2.
 */
class suggestion_service {

    /** Виды предложений. */
    const KIND_LEVEL_CHANGE = 1;
    const KIND_REMEDIATION  = 2;
    const KIND_ADVANCEMENT  = 3;

    /** Статусы. */
    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_AUTO     = 3;

    /**
     * Создать предложение. Дедуп: один открытый (ожидает) на (student, element, kind) -
     * при дубле возвращает null (как дедуп шагов в path_manager).
     *
     * @return int|null id нового предложения, либо null если открытый дубль уже есть
     */
    public static function create(int $student_id, int $kind, ?int $element_id, string $payload,
                                  ?int $auto_apply_after = null, ?string $rationale = null): ?int {
        global $DB;
        if (self::has_open($student_id, $element_id, $kind)) {
            return null;
        }
        $id = (int)$DB->insert_record('unics_adaptive_suggestion', (object)[
            'student_id'       => $student_id,
            'element_id'       => $element_id,
            'kind'             => $kind,
            'payload'          => $payload,
            'status'           => self::STATUS_PENDING,
            'auto_apply_after' => $auto_apply_after,
            'created_at'       => time(),
            'decided_by'       => null,
            'decided_at'       => null,
            'rationale'        => $rationale,
        ]);
        self::notify_teachers($student_id, $kind);
        return $id;
    }

    /** Уведомить педагогов ученика о новом предложении (нефатально). */
    private static function notify_teachers(int $student_id, int $kind): void {
        global $DB;
        $labels = [
            self::KIND_LEVEL_CHANGE => 'смена уровня сложности',
            self::KIND_REMEDIATION  => 'повторение навыка (пробел)',
            self::KIND_ADVANCEMENT  => 'продвижение по освоенному навыку',
        ];
        $label = $labels[$kind] ?? 'адаптивное предложение';

        $student = $DB->get_record('unics_students', ['id' => $student_id], 'id, mdl_user_id');
        if (!$student) {
            return;
        }
        $mdl = $DB->get_record('user', ['id' => $student->mdl_user_id]);
        $sname = $mdl ? fullname($mdl) : 'Учащийся #' . $student_id;

        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student_id]);
        try {
            require_once(dirname(__DIR__) . '/classes/notification_manager.php');
            foreach ($teachers as $t) {
                notification_manager::notify_adaptive_suggestion((int)$t->mdl_user_id, $sname, $label);
            }
        } catch (\Throwable $e) {
            // Нефатально.
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }
    }

    public static function get(int $id): ?object {
        global $DB;
        return $DB->get_record('unics_adaptive_suggestion', ['id' => $id]) ?: null;
    }

    /** Открытые (ожидающие) предложения ученика по времени создания. */
    public static function get_open_for_student(int $student_id): array {
        global $DB;
        return $DB->get_records('unics_adaptive_suggestion',
            ['student_id' => $student_id, 'status' => self::STATUS_PENDING], 'created_at ASC');
    }

    /** Есть ли открытое предложение на (student, element, kind). element_id NULL учитывается как NULL. */
    public static function has_open(int $student_id, ?int $element_id, int $kind): bool {
        global $DB;
        $params = ['sid' => $student_id, 'k' => $kind, 'st' => self::STATUS_PENDING];
        $sql = 'student_id = :sid AND kind = :k AND status = :st AND ';
        if ($element_id === null) {
            $sql .= 'element_id IS NULL';
        } else {
            $sql .= 'element_id = :eid';
            $params['eid'] = $element_id;
        }
        return $DB->record_exists_select('unics_adaptive_suggestion', $sql, $params);
    }

    /** Сменить статус (+ кто решил, когда). Применение действия - S2. */
    public static function set_status(int $id, int $status, ?int $decided_by = null): void {
        global $DB;
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED,
                self::STATUS_REJECTED, self::STATUS_AUTO], true)) {
            return;
        }
        $DB->update_record('unics_adaptive_suggestion', (object)[
            'id'         => $id,
            'status'     => $status,
            'decided_by' => $decided_by,
            'decided_at' => time(),
        ]);
    }

    /**
     * Применить предложение (выполнить действие по kind). Используется approve и
     * авто-применением. status -> APPROVED (ручное) или AUTO (по сроку).
     *
     * @return bool успех применения
     */
    public static function apply(int $id, ?int $decided_by, bool $auto = false): bool {
        global $DB;
        $s = self::get($id);
        if (!$s || (int)$s->status !== self::STATUS_PENDING) {
            return false;
        }
        require_once(dirname(__DIR__) . '/classes/adaptive_engine.php');
        require_once(dirname(__DIR__) . '/classes/path_manager.php');

        $payload = json_decode((string)$s->payload, true) ?: [];
        $target  = isset($payload['target_level']) ? (int)$payload['target_level'] : null;
        $by      = $decided_by ?: 2; // 2 = admin как создатель шага при авто-применении
        $ok = false;

        switch ((int)$s->kind) {
            case self::KIND_LEVEL_CHANGE:
                if ($target !== null) {
                    \local_unics\adaptive_engine::apply_level((int)$s->student_id, $target,
                        isset($payload['avg']) ? (float)$payload['avg'] : null);
                    $ok = true; // если уровень уже такой, apply_level вернёт null - предложение всё равно закрываем
                }
                break;
            case self::KIND_REMEDIATION:
            case self::KIND_ADVANCEMENT:
                if (!empty($s->element_id)) {
                    $note = ($payload['reason'] ?? '') !== '' ? $payload['reason'] : null;
                    \local_unics\path_manager::add_adaptive_skill_step((int)$s->student_id, (int)$by,
                        (int)$s->element_id, $target, $note);
                    $ok = true; // дедуп шага -> null не считаем ошибкой, предложение рассмотрено
                }
                break;
        }

        self::set_status($id, $auto ? self::STATUS_AUTO : self::STATUS_APPROVED, $decided_by);
        return $ok;
    }

    /** Педагог принял предложение -> применить. */
    public static function approve(int $id, int $decided_by): bool {
        return self::apply($id, $decided_by, false);
    }

    /** Педагог отклонил предложение. */
    public static function reject(int $id, int $decided_by): void {
        self::set_status($id, self::STATUS_REJECTED, $decided_by);
    }

    /**
     * Открытые (ожидающие) предложения, которые пользователь вправе рассмотреть.
     * Фильтр - по path_manager::can_edit (админ / методист в скоупе / педагог по привязке).
     * Новые сверху. Объём открытых предложений невелик - фильтруем в PHP с кэшем по ученику.
     *
     * @return array<int,object> строки unics_adaptive_suggestion
     */
    public static function list_open_for_user(int $userid): array {
        global $DB;
        require_once(dirname(__DIR__) . '/classes/path_manager.php');
        $rows = $DB->get_records('unics_adaptive_suggestion',
            ['status' => self::STATUS_PENDING], 'created_at DESC');
        $can = [];
        $out = [];
        foreach ($rows as $r) {
            $s = (int)$r->student_id;
            if (!array_key_exists($s, $can)) {
                $can[$s] = \local_unics\path_manager::can_edit($s, $userid);
            }
            if ($can[$s]) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public static function kind_label(int $kind): string {
        return [
            self::KIND_LEVEL_CHANGE => 'Смена уровня сложности',
            self::KIND_REMEDIATION  => 'Повторение навыка (пробел)',
            self::KIND_ADVANCEMENT  => 'Продвижение по навыку',
        ][$kind] ?? 'Адаптивное предложение';
    }

    /**
     * Человекочитаемая деталь предложения (навык + целевой уровень + причина), HTML-экранировано.
     */
    public static function describe(object $s): string {
        global $DB;
        $levels  = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $payload = json_decode((string)$s->payload, true) ?: [];
        $parts = [];

        if (!empty($s->element_id)) {
            $title = (string)$DB->get_field('unics_codifier_element', 'title', ['id' => (int)$s->element_id]);
            if ($title !== '') {
                $parts[] = 'Навык: ' . s(mb_substr(trim($title), 0, 160));
            }
        }
        if (isset($payload['target_level']) && $payload['target_level'] !== null) {
            $tl = (int)$payload['target_level'];
            $parts[] = 'Целевой уровень: ' . s($levels[$tl] ?? (string)$tl);
        }
        if (!empty($payload['reason'])) {
            $parts[] = s((string)$payload['reason']);
        }
        return implode('. ', $parts);
    }
}
