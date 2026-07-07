<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * A5: пересчёт rollup учебной статистики (unics_stats_student_course).
 * Кумулятивно по всем активным учащимся; идемпотентно (upsert по учащийся x курс).
 */
class aggregate_stats extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'УНИКС: Пересчёт учебной статистики';
    }

    public function execute(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/analytics/stats_manager.php');

        $res = \local_unics\analytics\stats_manager::rebuild_all();
        mtrace("Статистика пересчитана: учащихся {$res['students']}, строк (учащийся x курс) {$res['rows']}.");
    }
}
