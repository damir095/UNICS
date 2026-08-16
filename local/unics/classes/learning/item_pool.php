<?php
namespace local_unics\learning;

use local_unics\codifier_link_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Общий пул заданий по элементу кодификатора. [[umk-item-pool-design]]
 *
 * Зачем: генерация УМК создавала каждому ученику свои пять вопросов, и на стенде 17 заданий из 22
 * имели РОВНО ОДИН ответ. IRT оценивает параметры задания по ответам многих учеников на одно и то
 * же задание, поэтому калибровка была невозможна в принципе. Пул делает измеритель общим, оставляя
 * персональным обучающий материал.
 *
 * Класс намеренно не знает ни про ИИ, ни про курсы: он отвечает на один вопрос - какие записи
 * банка взять и скольких не хватило.
 *
 * ИЗВЕСТНОЕ ОГРАНИЧЕНИЕ. Отбор и последующая привязка не атомарны: если три воркера (предел
 * параллельности на стенде) стартуют по одному элементу с пустым пулом, каждый создаст свои пять
 * заданий - будет 15 вместо 5. Состояние временное и самоисправляющееся: со следующей генерации
 * пул уже не пуст. Блокировка на элемент тут не помогает - дорогая часть это генерация, и
 * остальные воркеры уйдут в обход по таймауту. Лечится только перепланировкой (сначала застолбить
 * места, потом наполнять) - отдельная задача, см. [[umk-item-pool-design]], раздел 6a.
 */
class item_pool {

    /** Целевая трудность IRT для заявленного уровня. Стартовые числа, шкала примерно [-3, 3]. */
    const TARGET_B = [1 => -1.0, 2 => 0.0, 3 => 1.0];

    /**
     * С какого числа наблюдений измеренной трудности можно верить.
     *
     * Порог общий для пула, адаптивной проверки и индикатора готовности - держится в
     * {@see \local_unics\item_irt_manager::MIN_CALIBRATED_N}, чтобы три места не разъезжались:
     * именно расхождение мерок привело к тому, что пул задание отфильтровывал, а CAT его выдавал
     * ребенку. Пока наблюдений меньше, задание судится по заявленному уровню, как до калибровки.
     */
    const MIN_CALIBRATED_N = \local_unics\item_irt_manager::MIN_CALIBRATED_N;

    /**
     * Отобрать задания элемента под уровень.
     *
     * @return array{ids: int[], missing: int}
     */
    public static function take(int $element_id, int $level, int $count): array {
        $candidates = self::candidates($element_id, $level);
        $ids = array_slice($candidates, 0, $count);
        return ['ids' => $ids, 'missing' => max(0, $count - count($ids))];
    }

    /**
     * Годные задания в порядке выдачи.
     *
     * Две разные обязанности разведены намеренно.
     *
     * ЗАЯВЛЕННЫЙ УРОВЕНЬ - жесткий фильтр: задание чужого уровня ребенку не показываем ни при
     * какой измеренной трудности. Для детей с ОВЗ это важнее статистической выгоды.
     *
     * ИЗМЕРЕННАЯ ТРУДНОСТЬ - мягкое упорядочивание внутри уровня. Раньше она работала жестким
     * допуском и ВЫБРАСЫВАЛА задания: живой зонд показал, что после первой же калибровки пул
     * уровня 2 просел с пяти заданий до трех, то есть система принялась догенерировать новые
     * вместо накопленных - против собственной цели.
     *
     * Порядок: сначала свой уровень, потом задания без уровня вообще. Внутри группы реже
     * отвеченные идут первыми (отбор по первым пяти закрепил бы неравенство навсегда), при
     * равенстве ответов ближе к целевой трудности.
     *
     * @return int[] questionbankentryid
     */
    private static function candidates(int $element_id, int $level): array {
        global $DB;

        $target = self::TARGET_B[$level] ?? 0.0;

        // Записи банка, привязанные к элементу и ГОДНЫЕ к показу.
        //
        // Двух проверок мало одной. Существование записи ловит удаление начисто (урок 249 сирот
        // на стенде). Но `question_delete_question()` для вопроса, который где-то использован,
        // не удаляет его, а помечает версию скрытой: запись банка остается, и без проверки
        // статуса удаленный педагогом вопрос всплывал бы в тесте каждого следующего ученика.
        //
        // Число ответов считается тем же запросом: раньше на каждое задание уходил свой COUNT.
        $rows = $DB->get_records_sql("
            SELECT l.target_id AS item_ref, il.level AS declared, i.b AS measured,
                   i.calibrated_n AS calibrated_n, COUNT(qa.id) AS answers
              FROM {unics_codifier_link} l
              JOIN {question_bank_entries} qbe ON qbe.id = l.target_id
              JOIN {question_versions} qv ON qv.questionbankentryid = l.target_id
                   AND qv.status = :ready
         LEFT JOIN {unics_item_level} il ON il.item_ref = l.target_id
         LEFT JOIN {unics_item_irt} i ON i.item_ref = l.target_id
         LEFT JOIN {question_attempts} qa ON qa.questionid = qv.questionid
             WHERE l.target_type = :tq AND l.element_id = :eid
          GROUP BY l.target_id, il.level, i.b, i.calibrated_n",
            [
                'ready' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
                'tq'    => codifier_link_manager::TYPE_QUESTION,
                'eid'   => $element_id,
            ]);

        $exact = [];
        $unleveled = [];
        foreach ($rows as $r) {
            $ref     = (int)$r->item_ref;
            $answers = (int)$r->answers;
            // Трудности верим только при достаточном числе наблюдений; иначе расстояние до цели
            // считаем нулевым, и задание упорядочивается по одному счетчику ответов.
            $trusted = $r->measured !== null && (int)$r->calibrated_n >= self::MIN_CALIBRATED_N;
            $distance = $trusted ? abs((float)$r->measured - $target) : 0.0;

            if ($r->declared === null) {
                $unleveled[$ref] = ['answers' => $answers, 'distance' => $distance];
                continue;
            }
            if ((int)$r->declared === $level) {
                $exact[$ref] = ['answers' => $answers, 'distance' => $distance];
            }
        }

        return array_merge(self::ordered($exact), self::ordered($unleveled));
    }

    /**
     * Упорядочить группу: реже отвеченные первыми, при равенстве - ближе к целевой трудности.
     *
     * @param array $group ref => ['answers' => int, 'distance' => float]
     * @return int[] ref в порядке выдачи
     */
    private static function ordered(array $group): array {
        uasort($group, function (array $a, array $b) {
            if ($a['answers'] !== $b['answers']) {
                return $a['answers'] <=> $b['answers'];
            }
            return $a['distance'] <=> $b['distance'];
        });
        return array_keys($group);
    }

    /**
     * Сколько ответов собрало задание.
     *
     * Считаются ответы ВСЕХ версий записи банка: `question_attempts.questionid` указывает на
     * версию, и без этого правка формулировки обнуляла бы счетчик, а задание уезжало бы в начало
     * очереди отбора, хотя ответы по нему есть.
     */
    public static function answers_count(int $item_ref): int {
        global $DB;
        return (int)$DB->count_records_sql("
            SELECT COUNT(qa.id)
              FROM {question_versions} qv
              JOIN {question_attempts} qa ON qa.questionid = qv.questionid
             WHERE qv.questionbankentryid = ?", [$item_ref]);
    }

    /** Запомнить заявленный уровень задания. Повторный вызов обновляет. */
    public static function remember_level(int $item_ref, int $level, int $userid): void {
        global $DB;
        $existing = $DB->get_record('unics_item_level', ['item_ref' => $item_ref]);
        if ($existing) {
            $DB->set_field('unics_item_level', 'level', $level, ['id' => $existing->id]);
            return;
        }
        $DB->insert_record('unics_item_level', (object)[
            'item_ref'               => $item_ref,
            'level'                  => $level,
            'created_by_mdl_user_id' => $userid,
            'timecreated'            => time(),
        ]);
    }
}
