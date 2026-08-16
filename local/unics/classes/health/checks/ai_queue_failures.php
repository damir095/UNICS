<?php
namespace local_unics\health\checks;

use local_unics\ai\ai_queue;
use local_unics\health\check;
use local_unics\health\check_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Сколько генераций завершилось ошибкой. Уровень намеренно «внимание», а не «авария»: обращения
 * к внешнему сервису иногда падают, это нормальное состояние живой системы. Если бы этот счетчик
 * поднимал полосу тревоги, она горела бы постоянно (на стенде 24 старые ошибки) и перестала бы
 * что-либо значить. Разбор по конкретным УМК - у методиста в umk_status.php.
 */
class ai_queue_failures implements check {

    public function name(): string {
        return 'ai_queue_failures';
    }

    public function title(): string {
        return 'Ошибки генерации в истории';
    }

    public function is_cheap(): bool {
        return true;
    }

    public function run(): check_result {
        global $DB;
        $n = $DB->count_records('unics_ai_queue', ['status' => ai_queue::STATUS_FAILED]);
        if ($n > 0) {
            return check_result::attention(
                'Записей с ошибкой: ' . $n,
                'Откройте «История генерации УМК», чтобы увидеть причину по каждой и повторить.'
            );
        }
        return check_result::ok('Нет');
    }
}
