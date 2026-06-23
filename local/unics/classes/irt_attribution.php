<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * IRT-атрибуция: для оцененной попытки дает по элементу кодификатора вектор ответов {b, correct}
 * ТОЛЬКО по вопросам, у которых есть параметр сложности в unics_item_irt. Зеркалит per-question
 * путь codifier_attribution, но возвращает дихотомические ответы + параметр задания (для Rasch).
 */
class irt_attribution {

    /** @return array<int, array<int, array{b: float, correct: int}>> [element_id => [{b,correct}]] */
    public static function element_responses_for_attempt(int $attemptid): array {
        global $DB;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, uniqueid');
        if (!$attempt) {
            return [];
        }
        $rows = $DB->get_recordset_sql(
            "SELECT qa.id AS qaid, l.element_id, p.b, qas.fraction
               FROM {question_attempts} qa
               JOIN {question_versions} qv ON qv.questionid = qa.questionid
               JOIN {unics_codifier_link} l
                    ON l.target_type = :tq AND l.target_id = qv.questionbankentryid
               JOIN {unics_item_irt} p ON p.item_ref = qv.questionbankentryid
               JOIN {question_attempt_steps} qas ON qas.id = (
                       SELECT s.id FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qa.id AND s.fraction IS NOT NULL
                     ORDER BY s.sequencenumber DESC LIMIT 1)
              WHERE qa.questionusageid = :uniqueid",
            ['tq' => codifier_link_manager::TYPE_QUESTION, 'uniqueid' => (int)$attempt->uniqueid]);
        $out = [];
        foreach ($rows as $r) {
            $eid = (int)$r->element_id;
            $out[$eid][] = ['b' => (float)$r->b, 'correct' => ((float)$r->fraction) >= 0.5 ? 1 : 0];
        }
        $rows->close();
        return $out;
    }
}
