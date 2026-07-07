<?php
namespace local_unics\social;

defined('MOODLE_INTERNAL') || die();

/**
 * Групповые беседы класса (M1).
 *
 * Класс = (organization_id, class_number, class_letter) из unics_students -
 * это «сквозная» сущность класса (не зависит от конкретного курса). Для каждого
 * класса создаётся одна групповая беседа core_message (component=null, тип GROUP);
 * её id хранится в unics_class_conversation.
 *
 * Решение (2026-06-02): беседа «с педагогом» - в неё входят все активные
 * учащиеся класса + назначенные им педагоги (unics_teacher_student). Взрослый в
 * чате обеспечивает надзор, что важно для детей с ОВЗ.
 *
 * Членство синхронизируется лениво - при открытии страницы сообщений любым
 * участником класса (см. get_user_class_conversations). Это самовосстанавливающийся
 * подход: новый учащийся попадает в беседу при следующем открытии мессенджера
 * кем-либо из класса, выбывший (выпуск/архив) - удаляется.
 */
class class_chat_manager {

    /**
     * Активный учащийся: не выпущен и не архивирован, аккаунт Moodle не удалён.
     * Условие для алиаса s (unics_students) и u (user).
     */
    private static function active_where(string $salias = 's', string $ualias = 'u'): string {
        return "{$salias}.graduated_at IS NULL AND {$salias}.archived_at IS NULL AND {$ualias}.deleted = 0";
    }

    /**
     * mdl_user_id всех активных учащихся класса.
     *
     * @return int[]
     */
    public static function get_class_student_userids(int $org, int $class_number, string $class_letter): array {
        global $DB;
        if ($org <= 0 || $class_number <= 0) {
            return [];
        }
        $where = self::active_where();
        $sql = "SELECT u.id
                  FROM {unics_students} s
                  JOIN {user} u ON u.id = s.mdl_user_id
                 WHERE s.organization_id = :org
                   AND s.class_number    = :cn
                   AND COALESCE(s.class_letter, '') = :cl
                   AND {$where}";
        $ids = $DB->get_fieldset_sql($sql, [
            'org' => $org, 'cn' => $class_number, 'cl' => $class_letter,
        ]);
        return array_map('intval', $ids);
    }

    /**
     * mdl_user_id всех педагогов, назначенных учащимся этого класса
     * (через unics_teacher_student).
     *
     * @return int[]
     */
    public static function get_class_teacher_userids(int $org, int $class_number, string $class_letter): array {
        global $DB;
        if ($org <= 0 || $class_number <= 0) {
            return [];
        }
        $where = self::active_where();
        $sql = "SELECT DISTINCT tu.id
                  FROM {unics_students} s
                  JOIN {user} u  ON u.id = s.mdl_user_id
                  JOIN {unics_teacher_student} ts ON ts.student_id = s.id
                  JOIN {unics_teachers} t  ON t.id = ts.teacher_id
                  JOIN {user} tu ON tu.id = t.mdl_user_id AND tu.deleted = 0
                 WHERE s.organization_id = :org
                   AND s.class_number    = :cn
                   AND COALESCE(s.class_letter, '') = :cl
                   AND {$where}";
        $ids = $DB->get_fieldset_sql($sql, [
            'org' => $org, 'cn' => $class_number, 'cl' => $class_letter,
        ]);
        return array_map('intval', $ids);
    }

    /**
     * Класс активного учащегося по его mdl_user_id.
     *
     * @return \stdClass|null {organization_id, class_number, class_letter} или null
     */
    public static function get_student_class(int $mdl_user_id): ?\stdClass {
        global $DB;
        $rec = $DB->get_record('unics_students', ['mdl_user_id' => $mdl_user_id],
            'id, organization_id, class_number, class_letter, graduated_at, archived_at');
        if (!$rec || $rec->graduated_at !== null || $rec->archived_at !== null) {
            return null;
        }
        if (empty($rec->organization_id) || empty($rec->class_number)) {
            return null;
        }
        $out = new \stdClass();
        $out->organization_id = (int)$rec->organization_id;
        $out->class_number    = (int)$rec->class_number;
        $out->class_letter    = (string)($rec->class_letter ?? '');
        return $out;
    }

    /**
     * Список классов, в которых у педагога есть назначенные учащиеся.
     *
     * @return \stdClass[] массив {organization_id, class_number, class_letter}
     */
    public static function get_teacher_classes(int $mdl_user_id): array {
        global $DB;
        $where = self::active_where();
        $sql = "SELECT DISTINCT s.organization_id, s.class_number, COALESCE(s.class_letter, '') AS class_letter
                  FROM {unics_teachers} t
                  JOIN {unics_teacher_student} ts ON ts.teacher_id = t.id
                  JOIN {unics_students} s ON s.id = ts.student_id
                  JOIN {user} u ON u.id = s.mdl_user_id
                 WHERE t.mdl_user_id = :uid
                   AND s.organization_id IS NOT NULL
                   AND s.class_number   IS NOT NULL
                   AND {$where}
              ORDER BY s.organization_id, s.class_number, class_letter";
        $rows = $DB->get_records_sql($sql, ['uid' => $mdl_user_id]);
        $out = [];
        foreach ($rows as $r) {
            $c = new \stdClass();
            $c->organization_id = (int)$r->organization_id;
            $c->class_number    = (int)$r->class_number;
            $c->class_letter    = (string)$r->class_letter;
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Человекочитаемое имя беседы: «Класс 5А - Школа №1».
     */
    private static function conversation_name(int $org, int $class_number, string $class_letter): string {
        global $DB;
        $name = 'Класс ' . $class_number . $class_letter;
        // short_name может быть пустой строкой (не NULL), поэтому выбираем в PHP.
        $orgrec = $DB->get_record('unics_organizations', ['id' => $org], 'short_name, name');
        if ($orgrec) {
            $orgname = (!empty(trim((string)$orgrec->short_name)))
                ? trim((string)$orgrec->short_name)
                : trim((string)$orgrec->name);
            if ($orgname !== '') {
                $name .= ' - ' . $orgname;
            }
        }
        // message_conversations.name = char(255).
        return \core_text::substr($name, 0, 255);
    }

    /**
     * Синхронизировать членство беседы: привести к набору $desired (mdl_user_id).
     * Добавляет новых, удаляет выбывших. Нефатально.
     */
    private static function sync_members(int $convid, array $desired): void {
        global $DB;
        $desired = array_values(array_unique(array_map('intval', $desired)));
        if (empty($desired)) {
            // Защита: не опустошаем беседу полностью.
            return;
        }
        $current = $DB->get_fieldset_select('message_conversation_members', 'userid',
            'conversationid = ?', [$convid]);
        $current = array_map('intval', $current);

        $toadd = array_values(array_diff($desired, $current));
        $toremove = array_values(array_diff($current, $desired));

        try {
            if (!empty($toadd)) {
                \core_message\api::add_members_to_conversation($toadd, $convid);
            }
            if (!empty($toremove)) {
                \core_message\api::remove_members_from_conversation($toremove, $convid);
            }
        } catch (\Throwable $e) {
            debugging('local_unics class_chat: sync_members failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Найти-или-создать беседу класса и синхронизировать её членство.
     *
     * @return int conversationid (>0) или 0, если беседа не нужна/не создана
     *             (messaging выключен, менее 2 участников, нет учащихся).
     */
    public static function ensure_conversation(int $org, int $class_number, string $class_letter): int {
        global $CFG, $DB;

        if (empty($CFG->messaging)) {
            return 0; // Без штатного messaging беседы бессмысленны.
        }
        if ($org <= 0 || $class_number <= 0) {
            return 0;
        }
        $class_letter = (string)$class_letter;

        $students = self::get_class_student_userids($org, $class_number, $class_letter);
        $teachers = self::get_class_teacher_userids($org, $class_number, $class_letter);
        $desired  = array_values(array_unique(array_merge($students, $teachers)));

        $row = $DB->get_record('unics_class_conversation', [
            'organization_id' => $org,
            'class_number'    => $class_number,
            'class_letter'    => $class_letter,
        ]);

        // Если запись есть, но сама беседа удалена в ядре - сбрасываем привязку.
        if ($row && !$DB->record_exists('message_conversations', ['id' => $row->conversationid])) {
            $DB->delete_records('unics_class_conversation', ['id' => $row->id]);
            $row = false;
        }

        if ($row) {
            self::sync_members((int)$row->conversationid, $desired);
            // Поддерживаем актуальное имя (организация могла быть переименована).
            try {
                \core_message\api::update_conversation_name((int)$row->conversationid,
                    self::conversation_name($org, $class_number, $class_letter));
            } catch (\Throwable $e) {
                debugging('local_unics class_chat: rename failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $DB->set_field('unics_class_conversation', 'timemodified', time(), ['id' => $row->id]);
            return (int)$row->conversationid;
        }

        // Беседы ещё нет. Создаём только если есть смысл: хотя бы один учащийся
        // и не меньше двух участников всего (иначе групповой чат пуст).
        if (empty($students) || count($desired) < 2) {
            return 0;
        }

        try {
            $conv = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP,
                $desired,
                self::conversation_name($org, $class_number, $class_letter)
            );
        } catch (\Throwable $e) {
            debugging('local_unics class_chat: create_conversation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }

        $rec = new \stdClass();
        $rec->organization_id = $org;
        $rec->class_number    = $class_number;
        $rec->class_letter    = $class_letter;
        $rec->conversationid  = (int)$conv->id;
        $rec->timecreated     = time();
        $rec->timemodified    = $rec->timecreated;
        $DB->insert_record('unics_class_conversation', $rec);

        return (int)$conv->id;
    }

    /**
     * Беседы класса, доступные пользователю под его ролью УНИКС.
     * Учащийся - своя одна беседа; педагог - все классы, где у него есть ученики.
     * Попутно создаёт/синхронизирует беседы (ленивое провижининг).
     *
     * @return \stdClass[] массив {convid, name, count}
     */
    public static function get_user_class_conversations(int $mdl_user_id, string $role): array {
        global $DB;

        $classes = [];
        if ($role === 'student') {
            $c = self::get_student_class($mdl_user_id);
            if ($c) {
                $classes[] = $c;
            }
        } else if ($role === 'teacher') {
            $classes = self::get_teacher_classes($mdl_user_id);
        }
        // Методисты/родители/админ - не участники классных бесед в v1.

        $out = [];
        foreach ($classes as $c) {
            $convid = self::ensure_conversation($c->organization_id, $c->class_number, $c->class_letter);
            if ($convid <= 0) {
                continue;
            }
            // Пользователь должен быть участником (педагог без активных учеников
            // в классе, но с записью - не должен попасть; sync уже это учёл).
            if (!\core_message\api::is_user_in_conversation($mdl_user_id, $convid)) {
                continue;
            }
            $o = new \stdClass();
            $o->convid = $convid;
            $o->name   = self::conversation_name($c->organization_id, $c->class_number, $c->class_letter);
            $o->count  = $DB->count_records('message_conversation_members', ['conversationid' => $convid]);
            $out[] = $o;
        }
        return $out;
    }
}
