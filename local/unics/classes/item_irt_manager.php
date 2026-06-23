<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Доступ к параметрам заданий unics_item_irt (Rasch b) + офлайн-калибровка через сервис.
 * item_ref = questionbankentryid (та же сущность, что в unics_codifier_link).
 */
class item_irt_manager {

    /** Параметр b по списку bankentry-id: [item_ref => b]. */
    public static function get_b_for_entries(array $entryids): array {
        global $DB;
        if (!$entryids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select('unics_item_irt', "item_ref $insql", $params, '', 'id, item_ref, b');
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->item_ref] = (float)$r->b;
        }
        return $out;
    }

    /** Upsert параметра задания (по item_ref). */
    public static function upsert(int $item_ref, ?int $element_id, float $b, int $n): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record('unics_item_irt', ['item_ref' => $item_ref]);
        if ($existing) {
            $DB->update_record('unics_item_irt', (object)[
                'id' => $existing->id, 'element_id' => $element_id,
                'b' => round($b, 4), 'calibrated_n' => $n, 'updated_at' => $now,
            ]);
        } else {
            $DB->insert_record('unics_item_irt', (object)[
                'item_ref' => $item_ref, 'element_id' => $element_id, 'model' => 'rasch',
                'a' => 1, 'b' => round($b, 4), 'c' => 0, 'calibrated_n' => $n, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Собрать обезличенную матрицу ответов по привязанным к кодификатору вопросам, отправить
     * сервису, записать b. По сети - только числовые суррогаты. Возвращает число заданий.
     */
    public static function calibrate_all(): int {
        global $DB;
        $rs = $DB->get_recordset_sql(
            "SELECT qa.id AS qaid, s.id AS student_ref, qv.questionbankentryid AS item_ref,
                    qas.fraction, l.element_id
               FROM {quiz_attempts} att
               JOIN {unics_students} s ON s.mdl_user_id = att.userid
               JOIN {question_attempts} qa ON qa.questionusageid = att.uniqueid
               JOIN {question_versions} qv ON qv.questionid = qa.questionid
               JOIN {unics_codifier_link} l
                    ON l.target_type = :tq AND l.target_id = qv.questionbankentryid
               JOIN {question_attempt_steps} qas ON qas.id = (
                       SELECT x.id FROM {question_attempt_steps} x
                        WHERE x.questionattemptid = qa.id AND x.fraction IS NOT NULL
                     ORDER BY x.sequencenumber DESC LIMIT 1)
              WHERE att.state = 'finished'",
            ['tq' => codifier_link_manager::TYPE_QUESTION]);
        $matrix = [];
        $elementof = [];
        foreach ($rs as $r) {
            $ref = (int)$r->item_ref;
            $matrix[] = ['student_ref' => (int)$r->student_ref, 'item_ref' => $ref,
                'correct' => ((float)$r->fraction) >= 0.5 ? 1 : 0];
            $elementof[$ref] = $r->element_id !== null ? (int)$r->element_id : null;
        }
        $rs->close();
        if (!$matrix) {
            return 0;
        }
        $items = \local_unics\adaptive\irt_client::calibrate($matrix);
        if ($items === null) {
            return 0;
        }
        $count = 0;
        foreach ($items as $it) {
            $ref = (int)$it['item_ref'];
            self::upsert($ref, $elementof[$ref] ?? null, (float)$it['difficulty'], (int)$it['n']);
            $count++;
        }
        return $count;
    }
}
