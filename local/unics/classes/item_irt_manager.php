<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Доступ к параметрам заданий unics_item_irt (2PL: дискриминация a и сложность b) + офлайн-калибровка через сервис.
 * item_ref = questionbankentryid (та же сущность, что в unics_codifier_link).
 */
class item_irt_manager {

    /**
     * С какого числа наблюдений калибровка считается достоверной. ЕДИНСТВЕННЫЙ источник порога:
     * им пользуются пул заданий, адаптивная проверка и индикатор готовности к CAT.
     *
     * Порог оплачен двумя живыми находками. Первая: при одном ответе калибровка отдает вырожденную
     * b = ±3.892, и пул выбрасывал такое задание совсем. Вторая: адаптивная проверка вела ребенка
     * по этой же вырожденной трудности, потому что брала все строки параметров подряд. Десять -
     * школьный масштаб: один класс прошел тест.
     */
    const MIN_CALIBRATED_N = 10;

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

    /**
     * Параметры a и b по списку bankentry-id: [item_ref => ['a'=>float,'b'=>float]].
     *
     * @param bool $trustedonly отдавать только достоверную калибровку (см. MIN_CALIBRATED_N).
     *        Для адаптивной проверки это обязательно: подбирать задание под оценку по параметрам,
     *        снятым с нескольких ответов, значит делать вид, что измерение было.
     */
    public static function get_ab_for_entries(array $entryids, bool $trustedonly = false): array {
        global $DB;
        if (!$entryids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED);
        $where = "item_ref $insql";
        if ($trustedonly) {
            $where .= ' AND calibrated_n >= :mincal';
            $params['mincal'] = self::MIN_CALIBRATED_N;
        }
        $rows = $DB->get_records_select('unics_item_irt', $where, $params, '',
            'id, item_ref, a, b');
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->item_ref] = ['a' => (float)$r->a, 'b' => (float)$r->b];
        }
        return $out;
    }

    /** Upsert параметра задания (по item_ref). $a !== null -> пишем дискриминацию и model='2pl'. */
    public static function upsert(int $item_ref, ?int $element_id, float $b, int $n, ?float $a = null): void {
        global $DB;
        $now = time();
        $model = $a !== null ? '2pl' : 'rasch';
        $existing = $DB->get_record('unics_item_irt', ['item_ref' => $item_ref]);
        if ($existing) {
            $rec = (object)[
                'id' => $existing->id, 'element_id' => $element_id, 'model' => $model,
                'b' => round($b, 4), 'calibrated_n' => $n, 'updated_at' => $now,
            ];
            if ($a !== null) {
                $rec->a = round($a, 4);
            }
            $DB->update_record('unics_item_irt', $rec);
        } else {
            $DB->insert_record('unics_item_irt', (object)[
                'item_ref' => $item_ref, 'element_id' => $element_id, 'model' => $model,
                'a' => $a !== null ? round($a, 4) : 1, 'b' => round($b, 4), 'c' => 0,
                'calibrated_n' => $n, 'updated_at' => $now,
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
            $a = isset($it['discrimination']) ? (float)$it['discrimination'] : null;
            self::upsert($ref, $elementof[$ref] ?? null, (float)$it['difficulty'], (int)$it['n'], $a);
            $count++;
        }
        return $count;
    }
}
