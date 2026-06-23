<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/** Ночная офлайн-калибровка параметров заданий IRT (только при включенном флаге). */
class calibrate_irt extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_calibrate_irt', 'local_unics');
    }

    public function execute(): void {
        if ((int)get_config('local_unics', 'adaptive_irt_enabled') !== 1) {
            return;
        }
        $n = \local_unics\item_irt_manager::calibrate_all();
        mtrace('local_unics: откалибровано заданий IRT: ' . $n);
    }
}
