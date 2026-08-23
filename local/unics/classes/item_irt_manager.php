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

    /**
     * Насколько дискриминация должна отличаться от единицы, чтобы считаться оцененной.
     *
     * Сервис при нехватке ответов включает гард Раша и подставляет ровно 1.0, но поле
     * discrimination отдает ВСЕГДА. Точное сравнение с единицей ненадежно: число проходит через
     * сеть, JSON и round(), - поэтому допуск. Тот же допуск стоит в индикаторе готовности
     * ([[codifier_analytics::element_bank_readiness]]).
     */
    const A_TOLERANCE = 0.01;

    /**
     * Со скольких ответов сервис вообще оценивает дискриминацию (2PL).
     *
     * Значение дублирует MIN_RESPONSES_FOR_2PL из ai-service/app/irt.py. Дублирование
     * сознательное: сервис своих порогов наружу не отдает, а методисту надо показывать, сколько
     * ответов еще нужно, - иначе колонка «2PL» стоит нулем без объяснения. При изменении порога
     * в сервисе править и здесь.
     */
    const MIN_N_FOR_2PL = 20;

    /**
     * Upsert параметра задания (по item_ref).
     *
     * Модель пишется по ФАКТУ, а не по наличию поля: зонд 2026-08-22 нашел на стенде девять
     * заданий с надписью «2pl» при дискриминации ровно 1.000, то есть при калибровке Раша.
     * Наружу это не выходило только потому, что индикатор готовности страховался своей
     * проверкой, - но запись в базе врала.
     */
    public static function upsert(int $item_ref, ?int $element_id, float $b, int $n, ?float $a = null): void {
        global $DB;
        $now = time();
        // Сравниваем в десятитысячных долях целыми числами - ровно так, как посчитает база на
        // колонке NUMBER(6,4). На double граница обманывает: abs(round(1.01004, 4) - 1.0) дает
        // 0.010000000000000009, то есть «больше допуска», а SQL на тех же числах скажет «не
        // больше», и надпись разошлась бы с колонкой индикатора.
        $units = $a === null ? 0 : (int)round(abs(round($a, 4) - 1.0) * 10000);
        $model = ($a !== null && $units > (int)round(self::A_TOLERANCE * 10000)) ? '2pl' : 'rasch';
        $existing = $DB->get_record('unics_item_irt', ['item_ref' => $item_ref]);
        if ($existing) {
            $rec = (object)[
                'id' => $existing->id, 'element_id' => $element_id, 'model' => $model,
                'b' => round($b, 4), 'calibrated_n' => $n, 'updated_at' => $now,
            ];
            // Дискриминацию пишем ВСЕГДА, в том числе возвращаем к единице: иначе строка с
            // надписью «rasch» тащила бы прежнюю оценку 1.63, и отбор заданий в CAT считал бы
            // по ней информацию Фишера, хотя сама строка объявляет дискриминацию неоцененной.
            $rec->a = $a !== null ? round($a, 4) : 1;
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
    /**
     * Наблюдения для калибровки: ПО ОДНОМУ на пару «ученик - задание».
     *
     * Зонд 2026-08-23: у задания было 42 ответа при 6 учениках - 36 из них повторные попытки тех
     * же детей. Модель IRT считает, что у испытуемого одна способность, а тут один и тот же
     * ребенок отвечал на одно задание то верно, то неверно; оценка 2PL от таких данных
     * вырождалась - дискриминация упиралась в нижнюю границу. Число ответов росло, а число
     * испытуемых нет ([[calibration-one-attempt]]).
     *
     * Берем ПОСЛЕДНЮЮ попытку: она отражает нынешнее состояние ребенка, а не первое знакомство
     * с темой.
     *
     * @return array<int,array{student_ref:int,item_ref:int,correct:int}>
     */
    public static function response_matrix(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            // Первым столбцом - уникальный id: get_records() требует этого, а пара
            // «ученик-задание» повторяется по построению.
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
              WHERE att.state = 'finished'
           ORDER BY att.attempt ASC, att.id ASC",
            ['tq' => codifier_link_manager::TYPE_QUESTION]);

        // Порядок по номеру попытки: последняя запись пары затирает предыдущие.
        $byPair = [];
        $elementof = [];
        foreach ($rows as $r) {
            $ref = (int)$r->item_ref;
            $byPair[(int)$r->student_ref . '-' . $ref] = [
                'student_ref' => (int)$r->student_ref,
                'item_ref' => $ref,
                'correct' => ((float)$r->fraction) >= 0.5 ? 1 : 0,
            ];
            $elementof[$ref] = $r->element_id !== null ? (int)$r->element_id : null;
        }
        self::$last_element_map = $elementof;
        return array_values($byPair);
    }

    /** Элемент каждого задания из последнего сбора матрицы. */
    private static array $last_element_map = [];

    public static function calibrate_all(): int {
        $matrix = self::response_matrix();
        $elementof = self::$last_element_map;
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
