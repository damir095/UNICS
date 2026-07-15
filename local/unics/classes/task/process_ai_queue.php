<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

use local_unics\ai\ai_queue;
use local_unics\ai\umk_processor;

/**
 * Минутный шедулер очереди ИИ-генерации - теперь ПОДМЕТАЛЬЩИК
 * ([[ai-queue-parallel-design]], 3.4 аудита). Основной дренаж идет adhoc-задачами
 * process_umk_item (ставятся при постановке, исполняются параллельными воркерами);
 * здесь - страховка:
 *  - ждущие СТАРШЕ 5 минут (потерянный adhoc / legacy-строки без него) -
 *    заклеймить и обработать по-старому, до 15 за прогон;
 *  - «в работе» старше 6 часов - пометить ошибкой (воркер умер, генерация
 *    одного УМК столько не живет).
 *
 * Тело обработки элемента живет в ai\umk_processor (перенос из монолита).
 *
 * @package local_unics
 */
class process_ai_queue extends \core\task\scheduled_task {

    /** Ждущим даем 5 минут уйти через adhoc, прежде чем подметать. */
    private const SWEEP_MIN_AGE = 300;

    /** «В работе» дольше 6 часов = мертвый воркер. */
    private const STALE_PROCESSING_AGE = 6 * 3600;

    /** Не больше стольких элементов за прогон (исторический лимит). */
    private const SWEEP_LIMIT = 15;

    public function get_name(): string {
        return 'УНИКС: Обработка очереди генерации УМК';
    }

    public function execute(): void {
        $failed = ai_queue::fail_stale_processing(self::STALE_PROCESSING_AGE);
        if ($failed > 0) {
            mtrace("Протухших «в работе» помечено ошибкой: {$failed}");
        }

        $ids = ai_queue::sweep_pending(self::SWEEP_MIN_AGE, self::SWEEP_LIMIT);
        if (empty($ids)) {
            return;
        }

        $processor = new umk_processor();
        foreach ($ids as $queueid) {
            $row = ai_queue::claim($queueid);
            if ($row === null) {
                continue; // Успел adhoc-воркер - тем лучше.
            }
            mtrace("Подметальщик: обработка элемента очереди #{$queueid}");
            $processor->process($row);
        }
    }
}
