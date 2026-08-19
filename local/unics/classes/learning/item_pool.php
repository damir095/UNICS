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
 * ПАРАЛЛЕЛЬНОСТЬ. Отбор и привязка сами по себе не атомарны, и три воркера по одному элементу с
 * пустым пулом создавали 15 заданий вместо 5. Лечит бронь мест: take_or_reserve() под КОРОТКИМ
 * локом считает и столбит недостающее, а генерация идет вне лока ([[item-pool-reservation-design]]).
 * Прежняя запись «блокировка не помогает» касалась наивной схемы с локом на всю генерацию.
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
     * Сколько живет бронь места. Генерация комплекта на стенде занимает около трех минут
     * (замер 2026-08-10 - 169 секунд), поэтому пяти минут хватает живому воркеру, а мертвый
     * освобождает места сам - отдельный уборщик не нужен.
     */
    const RESERVATION_TTL = 300;

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

    /**
     * Отобрать задания и забронировать недостающие места.
     *
     * Бронируются только НЕДОСТАЮЩИЕ места, а не весь тест: существующие задания общие, их
     * берут все воркеры сразу, в этом и смысл пула. Лок держится ровно на подсчет и вставку
     * брони - генерация идет ВНЕ его. Именно это отличает замысел от «лока на всю генерацию»,
     * который был отвергнут как блокирующий воркеров на минуты.
     *
     * @return array{ids: int[], mine: int, waiting: int}
     */
    public static function take_or_reserve(int $element_id, int $level, int $count,
                                           int $queue_id): array {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_unics_item_pool');
        $lock = $factory->get_lock('element_' . $element_id . '_level_' . $level, 2);
        if (!$lock) {
            // Лок занят дольше двух секунд - работаем как до этой правки, без брони. Редкая
            // гонка лучше зависшего воркера: заторами мы занимались весь август.
            $plain = self::take($element_id, $level, $count);
            return ['ids' => $plain['ids'], 'mine' => $plain['missing'], 'waiting' => 0];
        }

        try {
            $now = time();
            // Уборка протухших - здесь и вся уборка, отдельного уборщика нет.
            $DB->delete_records_select('unics_item_reservation',
                'element_id = :eid AND level = :lvl AND expires_at <= :now',
                ['eid' => $element_id, 'lvl' => $level, 'now' => $now]);

            // Порядок ВАЖЕН: сначала чужие брони, потом задания. Сосед, который завершится
            // между этими двумя запросами, снимает бронь и добавляет задания - при таком
            // порядке мы увидим и старую бронь, и новые задания, то есть в худшем случае
            // недосчитаем себе мест. Обратный порядок дал бы ноль там и ноль там - и мы
            // создали бы дубли, ради устранения которых все и затевалось (найдено ревью).
            $others = (int)$DB->get_field_sql(
                'SELECT COALESCE(SUM(slots), 0) FROM {unics_item_reservation}
                  WHERE element_id = :eid AND level = :lvl
                    AND owner_queue_id <> :qid AND expires_at > :now',
                ['eid' => $element_id, 'lvl' => $level, 'qid' => $queue_id, 'now' => $now]);

            $plain   = self::take($element_id, $level, $count);
            $missing = $plain['missing'];

            $mine    = max(0, $missing - $others);
            $waiting = min($missing - $mine, $others);

            // Одна бронь на заявку и пару: перезапуск ЗАМЕНЯЕТ свою прежнюю.
            $DB->delete_records('unics_item_reservation',
                ['owner_queue_id' => $queue_id, 'element_id' => $element_id, 'level' => $level]);
            if ($mine > 0) {
                $DB->insert_record('unics_item_reservation', (object)[
                    'element_id'     => $element_id,
                    'level'          => $level,
                    'slots'          => $mine,
                    'owner_queue_id' => $queue_id,
                    'expires_at'     => $now + self::RESERVATION_TTL,
                ]);
            }

            return ['ids' => $plain['ids'], 'mine' => $mine, 'waiting' => $waiting];
        } finally {
            $lock->release();
        }
    }

    /** Снять свою бронь: генерация не состоялась либо уже завершена. */
    public static function release(int $queue_id, int $element_id, int $level): void {
        global $DB;
        $DB->delete_records('unics_item_reservation',
            ['owner_queue_id' => $queue_id, 'element_id' => $element_id, 'level' => $level]);
    }

    /**
     * Закрыть бронь созданными заданиями: привязать к элементу, записать уровень, снять бронь.
     *
     * Бронь снимается ЦЕЛИКОМ, даже если создано меньше, чем бронировали: висящая бронь
     * держала бы места до протухания зря, а сосед ждал бы того, чего уже не будет.
     */
    public static function fulfil(int $queue_id, int $element_id, int $level, array $refs,
                                  int $userid): void {
        foreach ($refs as $ref) {
            \local_unics\codifier_link_manager::link_question($element_id, (int)$ref, $userid);
            self::remember_level((int)$ref, $level, $userid);
        }
        self::release($queue_id, $element_id, $level);
    }

    /**
     * Дождаться, пока в пуле наберется $need годных заданий, но не дольше $seconds.
     *
     * Ждем чужую бронь: сосед генерирует те же задания, и взять их правильнее, чем плодить
     * свои. По истечении срока отдаем что есть - короткий тест лучше, чем никакого, а зависший
     * сосед не должен останавливать очередь (урок августовского затора).
     *
     * @return int[] item_ref, сколько набралось
     */
    public static function wait_for_slots(int $element_id, int $level, int $need,
                                           int $seconds): array {
        $deadline = time() + $seconds;
        while (true) {
            $ids = self::take($element_id, $level, $need)['ids'];
            if (count($ids) >= $need || time() >= $deadline) {
                return $ids;
            }
            // Ждать больше некого: сосед либо упал (release), либо его бронь протухла.
            // Без этой проверки воркер честно выстаивал все 60 секунд впустую - и уходил
            // с пустыми руками, хотя мог бы сразу сгенерировать сам (найдено ревью).
            if (!self::has_live_reservations($element_id, $level)) {
                return $ids;
            }
            // Пауза, а не плотный цикл: отбор кандидатов - тяжелый запрос с агрегатом,
            // а сосед генерирует десятки секунд. Пять секунд дают 12 опросов вместо 30.
            sleep(5);
        }
    }

    /** Есть ли живые чужие брони по паре: ждать имеет смысл только пока они есть. */
    public static function has_live_reservations(int $element_id, int $level): bool {
        global $DB;
        return $DB->record_exists_select('unics_item_reservation',
            'element_id = :eid AND level = :lvl AND expires_at > :now',
            ['eid' => $element_id, 'lvl' => $level, 'now' => time()]);
    }
}
