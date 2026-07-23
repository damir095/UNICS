<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Отложенная отправка уведомления о новом (вручную добавленном) задании (этап
 * 6.1 роадмапа, [[new-assignment-notification-design]]). Отправка отложена
 * намеренно: синхронный message_send() внутри обработчика course_module_created
 * попадает в ту же транзакцию, что и создание модуля - Moodle это не допускает
 * (создание активности должно быть чистой back-end операцией).
 */
class send_new_assign_notification extends \core\task\adhoc_task {
    public function execute(): void {
        $data = $this->get_custom_data();
        \local_unics\social\notification_manager::notify_new_assign_for_module((int)$data->cmid);
    }
}
