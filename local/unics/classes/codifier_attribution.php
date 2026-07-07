<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Движок атрибуции «оценённая попытка теста -> баллы по элементам кодификатора».
 * Per-question приоритет (дробь вопроса -> bankentry -> элементы), cmid фолбэк по
 * элементу (whole-test % для элементов, привязанных к cmid и не покрытых вопросами).
 * Единый источник правды для mastery (write) и аналитики (read). [[codifier-phase2-design]].
 */
class codifier_attribution {

    /**
     * Баллы по элементам для одной оценённой попытки теста.
     * @return array<int,float> [element_id => pct 0..100]
     */
    public static function element_scores_for_attempt(int $attemptid): array {
        global $DB;
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, userid, uniqueid');
        if (!$attempt) {
            return [];
        }

        // 1. Per-question: вопрос попытки -> версия -> bankentry -> элементы; дробь
        //    последнего грейд-шага (fraction != null, max sequencenumber). Элемент =
        //    средняя дробь его привязанных вопросов в этой попытке.
        $rows = $DB->get_recordset_sql(
            "SELECT l.id AS linkid, l.element_id, qas.fraction
               FROM {question_attempts} qa
               JOIN {question_versions} qv ON qv.questionid = qa.questionid
               JOIN {unics_codifier_link} l
                    ON l.target_type = :tq AND l.target_id = qv.questionbankentryid
               JOIN {question_attempt_steps} qas ON qas.id = (
                       SELECT s.id
                         FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qa.id AND s.fraction IS NOT NULL
                     ORDER BY s.sequencenumber DESC
                        LIMIT 1)
              WHERE qa.questionusageid = :uniqueid",
            ['tq' => codifier_link_manager::TYPE_QUESTION, 'uniqueid' => (int)$attempt->uniqueid]);
        $acc = []; // element_id => [sum, cnt]
        foreach ($rows as $r) {
            $eid = (int)$r->element_id;
            if (!isset($acc[$eid])) {
                $acc[$eid] = [0.0, 0];
            }
            $acc[$eid][0] += (float)$r->fraction * 100;
            $acc[$eid][1]++;
        }
        $rows->close();

        $out = [];
        foreach ($acc as $eid => $sc) {
            $out[$eid] = $sc[1] > 0 ? $sc[0] / $sc[1] : 0.0;
        }

        // 2. cmid фолбэк: элементы, привязанные к cmid теста (type=1) и НЕ покрытые
        //    вопросами выше, получают whole-test %.
        $cmid = self::cmid_for_quiz((int)$attempt->quiz);
        if ($cmid) {
            $whole = self::whole_test_pct($cmid, (int)$attempt->userid);
            if ($whole !== null) {
                $cmidels = $DB->get_fieldset_select('unics_codifier_link', 'element_id',
                    'target_type = :t AND target_id = :cmid',
                    ['t' => codifier_link_manager::TYPE_ACTIVITY, 'cmid' => $cmid]);
                foreach ($cmidels as $eid) {
                    $eid = (int)$eid;
                    if (!array_key_exists($eid, $out)) {
                        $out[$eid] = $whole;
                    }
                }
            }
        }
        return $out;
    }

    /** cmid теста по quizid (или null). */
    private static function cmid_for_quiz(int $quizid): ?int {
        $cm = get_coursemodule_from_instance('quiz', $quizid, 0, false, IGNORE_MISSING);
        return $cm ? (int)$cm->id : null;
    }

    /** Whole-test % (finalgrade/grademax), зеркалит \local_unics\learning\mastery_manager::attempt_pct. */
    private static function whole_test_pct(int $cmid, int $userid): ?float {
        global $DB;
        $rec = $DB->get_record_sql(
            "SELECT g.finalgrade, gi.grademax
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {grade_items} gi
                 ON gi.itemtype = 'mod' AND gi.itemmodule = m.name AND gi.iteminstance = cm.instance
               JOIN {grade_grades} g ON g.itemid = gi.id AND g.userid = :uid
              WHERE cm.id = :cmid AND g.finalgrade IS NOT NULL AND gi.grademax > 0",
            ['uid' => $userid, 'cmid' => $cmid]);
        if (!$rec) {
            return null;
        }
        return (float)$rec->finalgrade / (float)$rec->grademax * 100;
    }
}
