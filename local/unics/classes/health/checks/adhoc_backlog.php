<?php
namespace local_unics\health\checks;

use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Отложенные задачи Moodle, которые никто не выполняет.
 *
 * Инцидент 2026-08-16: четыре уведомления «новое задание» пролежали в очереди две недели и так и
 * не дошли до учеников. Функция была исправна - не работал транспорт.
 */
class adhoc_backlog implements check {

    /** Сутки - заведомо больше любого штатного ожидания. */
    const STALE_AFTER = DAYSECS;

    public function name(): string {
        return 'adhoc_backlog';
    }

    public function title(): string {
        return 'Отложенные задачи';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        global $DB;
        $n = $DB->count_records_select('task_adhoc', 'timecreated < :cutoff',
            ['cutoff' => time() - self::STALE_AFTER]);
        $total = $DB->count_records('task_adhoc');
        if ($n > 0) {
            return check_result::alarm(
                'Задач ждет дольше суток: ' . $n,
                'Их некому выполнить. Проверьте плановые задачи (пункт выше).',
                ['Всего в очереди' => $total]
            );
        }
        return check_result::ok($total > 0 ? 'В очереди: ' . $total : 'Очередь пуста');
    }
}
