<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc-задача: обработка ОДНОГО элемента очереди ИИ-генерации
 * ([[ai-queue-parallel-design]], 3.4 аудита). Ставится ai_queue::enqueue();
 * ядро Moodle исполняет adhoc-задачи параллельными воркерами - очередь
 * дренируется конкурентно без своего пула процессов.
 *
 * Клейм атомарный: если элемент уже забрал другой воркер/подметальщик или
 * заявка отменена на umk_status (строка удалена) - задача тихо завершается.
 * umk_processor::process() не бросает наружу (ошибка -> status=4 в строке),
 * поэтому ядро не ретраит задачу и двойной обработки нет.
 *
 * @package local_unics
 */
class process_umk_item extends \core\task\adhoc_task {

    public function execute(): void {
        $data = (object)$this->get_custom_data();
        $queueid = (int)($data->queueid ?? 0);
        if ($queueid <= 0) {
            mtrace('process_umk_item: пустой queueid - пропуск');
            return;
        }

        $row = \local_unics\ai\ai_queue::claim($queueid);
        if ($row === null) {
            mtrace("process_umk_item: элемент #{$queueid} занят/обработан/отменен - пропуск");
            return;
        }

        (new \local_unics\ai\umk_processor())->process($row);
    }
}
