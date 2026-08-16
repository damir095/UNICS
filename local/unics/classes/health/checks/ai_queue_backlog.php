<?php
namespace local_unics\health\checks;

use local_unics\ai\ai_queue;
use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Идет ли дренаж очереди УМК. Свежая ждущая заявка - норма (воркер стартует не мгновенно,
 * задача Windows ходит раз в 10 минут). Ждущая старше порога означает, что дренаж встал.
 */
class ai_queue_backlog implements check {

    /** Штатный старт - до 10 минут (интервал задачи Windows). Даем запас. */
    const STALE_AFTER = 900;

    public function name(): string {
        return 'ai_queue_backlog';
    }

    public function title(): string {
        return 'Очередь генерации УМК';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        global $DB;
        $cutoff = time() - self::STALE_AFTER;
        $old = $DB->count_records_select('unics_ai_queue',
            'status = :st AND created_at < :cutoff',
            ['st' => ai_queue::STATUS_PENDING, 'cutoff' => $cutoff]);
        $total = $DB->count_records('unics_ai_queue', ['status' => ai_queue::STATUS_PENDING]);

        if ($old > 0) {
            return check_result::alarm(
                'Заявок ждет дольше ' . format_time(self::STALE_AFTER) . ': ' . $old,
                'Дренаж очереди не идет. Проверьте, работают ли плановые задачи (пункт выше).',
                ['Всего ждет' => $total]
            );
        }
        return check_result::ok($total > 0 ? 'В работе, ждет заявок: ' . $total : 'Пуста');
    }
}
