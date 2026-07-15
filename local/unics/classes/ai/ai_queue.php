<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Жизненный цикл очереди ИИ-генерации unics_ai_queue (3.4 аудита,
 * [[ai-queue-parallel-design]]): постановка с adhoc-задачей, атомарный клейм
 * для параллельных воркеров, подметание и протухшие элементы.
 *
 * Источник истины статусов - строка unics_ai_queue (UI umk_status/dashboard
 * читают ее); adhoc-задача process_umk_item - только транспорт исполнения.
 *
 * @package local_unics
 */
class ai_queue {

    /** Статусы строки очереди (историческая нумерация сохранена). */
    public const STATUS_PENDING    = 1;
    public const STATUS_PROCESSING = 2;
    public const STATUS_DONE       = 3;
    public const STATUS_FAILED     = 4;

    /**
     * Поставить запрос генерации в очередь + adhoc-задача на исполнение.
     *
     * @param int $umkid id строки unics_umk
     * @param int[] $studentids id учащихся (unics_students)
     * @param array $flags generate_audio|generate_quiz|generate_assignment|generate_video (0/1)
     * @return int id строки очереди
     */
    public static function enqueue(int $umkid, array $studentids, array $flags): int {
        global $DB;

        $queueid = (int)$DB->insert_record('unics_ai_queue', (object)[
            'umk_id'              => $umkid,
            'student_ids'         => json_encode(array_values($studentids)),
            'generate_text'       => 1,
            'generate_audio'      => (int)($flags['generate_audio'] ?? 1),
            'generate_quiz'       => (int)($flags['generate_quiz'] ?? 1),
            'generate_assignment' => (int)($flags['generate_assignment'] ?? 0),
            'generate_video'      => (int)($flags['generate_video'] ?? 0),
            'status'              => self::STATUS_PENDING,
            'created_at'          => time(),
        ]);

        $task = new \local_unics\task\process_umk_item();
        $task->set_custom_data(['queueid' => $queueid]);
        \core\task\manager::queue_adhoc_task($task);

        return $queueid;
    }

    /**
     * Атомарный клейм элемента: ждет -> в работе. Лок только вокруг
     * «перечитал-записал» (секунды), обработка идет вне лока.
     *
     * @return \stdClass|null строка очереди (status уже PROCESSING) или null,
     *         если элемент занят другим воркером / уже обработан / удален (отмена).
     */
    public static function claim(int $queueid): ?\stdClass {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_unics_ai_queue');
        $lock = $factory->get_lock('item' . $queueid, 2);
        if (!$lock) {
            return null; // Кто-то клеймит прямо сейчас.
        }
        try {
            $row = $DB->get_record('unics_ai_queue', ['id' => $queueid]);
            if (!$row || (int)$row->status !== self::STATUS_PENDING) {
                return null;
            }
            $DB->set_field('unics_ai_queue', 'status', self::STATUS_PROCESSING, ['id' => $queueid]);
            $row->status = self::STATUS_PROCESSING;
            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * Кандидаты для подметальщика: ждущие СТАРШЕ $minage секунд (свежим положено
     * уйти через adhoc; подметальщик - страховка для потерянных/legacy строк).
     *
     * @return int[] id строк, старые первыми
     */
    public static function sweep_pending(int $minage, int $limit): array {
        global $DB;
        $rows = $DB->get_records_select('unics_ai_queue',
            'status = :st AND created_at < :cutoff',
            ['st' => self::STATUS_PENDING, 'cutoff' => time() - $minage],
            'created_at ASC', 'id', 0, $limit);
        return array_map('intval', array_keys($rows));
    }

    /**
     * Протухшие «в работе» -> «ошибка»: строка создана дольше $maxage назад и все
     * еще PROCESSING - воркер умер, генерация одного УМК столько не живет.
     * (Отдельной метки времени клейма в схеме нет - возраст меряем по created_at,
     * обработка стартует через минуты после постановки.)
     *
     * @return int сколько строк помечено
     */
    public static function fail_stale_processing(int $maxage): int {
        global $DB;
        $select = 'status = :st AND created_at < :cutoff';
        $params = ['st' => self::STATUS_PROCESSING, 'cutoff' => time() - $maxage];
        $stale = $DB->get_records_select('unics_ai_queue', $select, $params, '', 'id, umk_id');
        foreach ($stale as $row) {
            $DB->update_record('unics_ai_queue', (object)[
                'id'            => $row->id,
                'status'        => self::STATUS_FAILED,
                'error_message' => 'Обработка прервана: воркер не завершился (таймаут очереди)',
                'processed_at'  => time(),
            ]);
            $DB->set_field('unics_umk', 'status', self::STATUS_FAILED, ['id' => $row->umk_id]);
        }
        return count($stale);
    }
}
