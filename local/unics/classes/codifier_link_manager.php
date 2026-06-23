<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Тегирование: привязка элемента кодификатора к контенту. [[codifier-design]].
 * v1 - только активности (target_type=1, target_id=cmid). Вопросы (=2) - фаза 2.
 */
class codifier_link_manager {

    const TYPE_ACTIVITY = 1;
    const TYPE_QUESTION = 2;

    /** Привязать активность к элементу (идемпотентно). Возвращает id связи. */
    public static function link_activity(int $element_id, int $cmid, int $userid): int {
        global $DB;
        $existing = $DB->get_record('unics_codifier_link',
            ['element_id' => $element_id, 'target_type' => self::TYPE_ACTIVITY, 'target_id' => $cmid]);
        if ($existing) {
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('unics_codifier_link', (object)[
            'element_id'             => $element_id,
            'target_type'            => self::TYPE_ACTIVITY,
            'target_id'              => $cmid,
            'weight'                 => null,
            'created_by_mdl_user_id' => $userid,
            'timecreated'            => time(),
        ]);
    }

    public static function unlink(int $link_id): void {
        global $DB;
        $DB->delete_records('unics_codifier_link', ['id' => $link_id]);
    }

    /** Элементы (с кодом/заголовком + link_id), привязанные к активности. */
    public static function get_elements_for_activity(int $cmid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT e.*, l.id AS link_id
               FROM {unics_codifier_link} l
               JOIN {unics_codifier_element} e ON e.id = l.element_id
              WHERE l.target_type = :t AND l.target_id = :cmid
              ORDER BY e.path ASC",
            ['t' => self::TYPE_ACTIVITY, 'cmid' => $cmid]));
    }

    /**
     * cmid'ы, привязанные к элементу (опц. с поддеревом через path).
     * @return int[] список cmid
     */
    public static function get_activities_for_element(int $element_id, bool $include_descendants = true): array {
        global $DB;
        $el = $DB->get_record('unics_codifier_element', ['id' => $element_id], 'id, codifier_id, path', MUST_EXIST);
        if ($include_descendants && $el->path) {
            $elementids = $DB->get_fieldset_select('unics_codifier_element', 'id',
                'codifier_id = :cid AND ' . $DB->sql_like('path', ':p'),
                ['cid' => $el->codifier_id, 'p' => $el->path . '%']);
        } else {
            $elementids = [(int)$el->id];
        }
        if (!$elementids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($elementids, SQL_PARAMS_NAMED);
        $params['t'] = self::TYPE_ACTIVITY;
        return array_map('intval', $DB->get_fieldset_select('unics_codifier_link', 'DISTINCT target_id',
            "element_id $insql AND target_type = :t", $params));
    }

    /** Привязать вопрос (по стабильному question_bank_entries.id) к элементу. Идемпотентно. */
    public static function link_question(int $element_id, int $bankentryid, int $userid): int {
        global $DB;
        $existing = $DB->get_record('unics_codifier_link',
            ['element_id' => $element_id, 'target_type' => self::TYPE_QUESTION, 'target_id' => $bankentryid]);
        if ($existing) {
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('unics_codifier_link', (object)[
            'element_id'             => $element_id,
            'target_type'            => self::TYPE_QUESTION,
            'target_id'              => $bankentryid,
            'weight'                 => null,
            'created_by_mdl_user_id' => $userid,
            'timecreated'            => time(),
        ]);
    }

    /** Элементы (+ link_id), привязанные к вопросу (bankentryid). */
    public static function get_elements_for_question(int $bankentryid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT e.*, l.id AS link_id
               FROM {unics_codifier_link} l
               JOIN {unics_codifier_element} e ON e.id = l.element_id
              WHERE l.target_type = :t AND l.target_id = :beid
              ORDER BY e.path ASC",
            ['t' => self::TYPE_QUESTION, 'beid' => $bankentryid]));
    }

    /**
     * bankentryid'ы вопросов элемента (опц. с поддеревом через path).
     * @return int[] список question_bank_entries.id
     */
    public static function get_questions_for_element(int $element_id, bool $include_descendants = true): array {
        global $DB;
        $el = $DB->get_record('unics_codifier_element', ['id' => $element_id], 'id, codifier_id, path', MUST_EXIST);
        if ($include_descendants && $el->path) {
            $elementids = $DB->get_fieldset_select('unics_codifier_element', 'id',
                'codifier_id = :cid AND ' . $DB->sql_like('path', ':p'),
                ['cid' => $el->codifier_id, 'p' => $el->path . '%']);
        } else {
            $elementids = [(int)$el->id];
        }
        if (!$elementids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($elementids, SQL_PARAMS_NAMED);
        $params['t'] = self::TYPE_QUESTION;
        return array_map('intval', $DB->get_fieldset_select('unics_codifier_link', 'DISTINCT target_id',
            "element_id $insql AND target_type = :t", $params));
    }
}
