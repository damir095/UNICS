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

    /** Насколько измеренная трудность может отстоять от целевой, чтобы задание считалось годным. */
    const B_TOLERANCE = 0.75;

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
     * Порядок: сначала подходящие точно (по измеренной b, если она есть, иначе по заявленному
     * уровню), потом задания без уровня вообще. Внутри группы - реже отвеченные первыми: отбор по
     * первым пяти закрепил бы неравенство навсегда, задания сверх пятерки никогда не набрали бы
     * ответов и не откалибровались бы.
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
                   COUNT(qa.id) AS answers
              FROM {unics_codifier_link} l
              JOIN {question_bank_entries} qbe ON qbe.id = l.target_id
              JOIN {question_versions} qv ON qv.questionbankentryid = l.target_id
                   AND qv.status = :ready
         LEFT JOIN {unics_item_level} il ON il.item_ref = l.target_id
         LEFT JOIN {unics_item_irt} i ON i.item_ref = l.target_id
         LEFT JOIN {question_attempts} qa ON qa.questionid = qv.questionid
             WHERE l.target_type = :tq AND l.element_id = :eid
          GROUP BY l.target_id, il.level, i.b",
            [
                'ready' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
                'tq'    => codifier_link_manager::TYPE_QUESTION,
                'eid'   => $element_id,
            ]);

        $exact = [];
        $unleveled = [];
        foreach ($rows as $r) {
            $ref = (int)$r->item_ref;
            $answers = (int)$r->answers;
            if ($r->measured !== null) {
                if (abs((float)$r->measured - $target) <= self::B_TOLERANCE) {
                    $exact[$ref] = $answers;
                }
                continue;
            }
            if ($r->declared === null) {
                $unleveled[$ref] = $answers;
                continue;
            }
            if ((int)$r->declared === $level) {
                $exact[$ref] = $answers;
            }
        }

        asort($exact);
        asort($unleveled);
        return array_merge(array_keys($exact), array_keys($unleveled));
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
