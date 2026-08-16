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

        // Записи банка, привязанные к элементу и ЖИВЫЕ. Проверка существования обязательна:
        // задание могли удалить руками, привязка при этом осталась бы, а битый слот в тесте
        // ребенку не покажешь (урок 249 сирот на стенде).
        $rows = $DB->get_records_sql("
            SELECT l.target_id AS item_ref, il.level AS declared, i.b AS measured
              FROM {unics_codifier_link} l
              JOIN {question_bank_entries} qbe ON qbe.id = l.target_id
         LEFT JOIN {unics_item_level} il ON il.item_ref = l.target_id
         LEFT JOIN {unics_item_irt} i ON i.item_ref = l.target_id
             WHERE l.target_type = :tq AND l.element_id = :eid",
            ['tq' => codifier_link_manager::TYPE_QUESTION, 'eid' => $element_id]);

        $exact = [];
        $unleveled = [];
        foreach ($rows as $r) {
            $ref = (int)$r->item_ref;
            if ($r->measured !== null) {
                if (abs((float)$r->measured - $target) <= self::B_TOLERANCE) {
                    $exact[$ref] = self::answers_count($ref);
                }
                continue;
            }
            if ($r->declared === null) {
                $unleveled[$ref] = self::answers_count($ref);
                continue;
            }
            if ((int)$r->declared === $level) {
                $exact[$ref] = self::answers_count($ref);
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
