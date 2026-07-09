<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: с учащегося списаны баллы (покупка в ярмарке). Этап 2.4 аудита.
 * В other['points'] - положительный размер списания.
 *
 * @package local_unics
 */
final class points_spent extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'unics_points_log';
    }

    public static function get_name() {
        return get_string('eventpointsspent', 'local_unics');
    }

    public function get_description() {
        return "С учащегося #{$this->other['student_id']} (userid {$this->relateduserid}) "
            . "списано баллов: {$this->other['points']}.";
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['student_id', 'points'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("Ключ '{$key}' обязателен в other события points_spent.");
            }
        }
    }
}
