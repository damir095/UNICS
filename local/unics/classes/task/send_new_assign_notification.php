<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Отложенная отправка уведомления о новом (вручную добавленном) задании (этап
 * 6.1 роадмапа, [[new-assignment-notification-design]]). Отправка отложена
 * намеренно: синхронный message_send() внутри обработчика course_module_created
 * попадает в ту же транзакцию, что и создание модуля - Moodle это не допускает
 * (создание активности должно быть чистой back-end операцией).
 *
 * @package local_unics
 */
class send_new_assign_notification extends \core\task\adhoc_task {

    /**
     * Уведомление о новом задании - best-effort, не критично. Без ретрая:
     * notify_new_assign_for_module не идемпотентен (нет дедупликации по учащемуся),
     * повторный прогон после частичного сбоя разослал бы дубли уже уведомленным.
     * Пропустить уведомление безопаснее, чем дублировать.
     */
    public function retry_until_success(): bool {
        return false;
    }

    public function execute(): void {
        $data = $this->get_custom_data();
        \local_unics\social\notification_manager::notify_new_assign_for_module((int)$data->cmid);
    }
}
