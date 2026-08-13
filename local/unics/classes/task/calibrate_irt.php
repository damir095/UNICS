<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ночная офлайн-калибровка параметров заданий IRT.
 *
 * Калибровка нужна ДВУМ потребителям: оценщику-подплагину, который потребляет ответы по
 * заданиям, и адаптивной проверке CAT. Поэтому условие запуска - «есть хотя бы один
 * потребитель», а не прежний флаг `adaptive_irt_enabled` (он снят вместе с переездом IRT
 * в подплагин). Имен реализаций задача не знает: спрашивает маркер у активного оценщика.
 */
class calibrate_irt extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_calibrate_irt', 'local_unics');
    }

    public function execute(): void {
        $forestimator = \local_unics\adaptive\estimator_factory::make()
            instanceof \local_unics\adaptive\item_response_consumer;
        $forcat = (int)get_config('local_unics', 'adaptive_cat_enabled') === 1;
        if (!$forestimator && !$forcat) {
            return;
        }
        $n = \local_unics\item_irt_manager::calibrate_all();
        mtrace('local_unics: откалибровано заданий IRT: ' . $n);
    }
}
