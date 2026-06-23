<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * S2: авто-применение просроченных адаптивных предложений (гибридный гейт «авто через N
 * дней»). Берёт pending-предложения с auto_apply_after <= now и применяет их через
 * suggestion_service (status -> AUTO). Предложения с auto_apply_after IS NULL (создано при
 * N=0) сюда не попадают - они применяются сразу в момент создания.
 */
class auto_apply_suggestions extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'УНИКС: Авто-применение адаптивных предложений';
    }

    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/suggestion_service.php');

        $now = time();
        $due = $DB->get_records_select('unics_adaptive_suggestion',
            'status = :st AND auto_apply_after IS NOT NULL AND auto_apply_after <= :now',
            ['st' => \local_unics\suggestion_service::STATUS_PENDING, 'now' => $now],
            'auto_apply_after ASC', 'id');

        $applied = 0;
        foreach ($due as $row) {
            if (\local_unics\suggestion_service::apply((int)$row->id, null, true)) {
                $applied++;
            }
        }
        mtrace("УНИКС авто-применение предложений: применено {$applied} из " . count($due) . '.');
    }
}
