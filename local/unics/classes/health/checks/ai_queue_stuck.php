<?php
namespace local_unics\health\checks;

use local_unics\ai\ai_queue;
use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Заявки, застрявшие в статусе «в работе». Генерация одного комплекта занимает около трех минут
 * (замер 2026-08-10 - 169 секунд), поэтому строка, висящая часами, означает умершего воркера.
 * Подметальщик `process_ai_queue` помечает такие ошибкой, но только если сам запускается.
 */
class ai_queue_stuck implements check {

    /** Тот же порог, что у подметальщика в process_ai_queue. */
    const STUCK_AFTER = 6 * 3600;

    public function name(): string {
        return 'ai_queue_stuck';
    }

    public function title(): string {
        return 'Застрявшие генерации';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        global $DB;
        $n = $DB->count_records_select('unics_ai_queue',
            'status = :st AND created_at < :cutoff',
            ['st' => ai_queue::STATUS_PROCESSING, 'cutoff' => time() - self::STUCK_AFTER]);
        if ($n > 0) {
            return check_result::alarm(
                'Заявок висит «в работе» дольше 6 часов: ' . $n,
                'Воркер прервался. Строки будут помечены ошибкой автоматически, когда заработают '
                . 'плановые задачи; после этого генерацию можно повторить.'
            );
        }
        return check_result::ok('Нет');
    }
}
