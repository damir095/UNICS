<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: учащемуся начислены баллы (геймификация). Этап 2.4 аудита.
 *
 * @package local_unics
 */
final class points_awarded extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'unics_points_log';
    }

    public static function get_name() {
        return get_string('eventpointsawarded', 'local_unics');
    }

    public function get_description() {
        return "Учащемуся #{$this->other['student_id']} (userid {$this->relateduserid}) "
            . "начислено баллов: {$this->other['points']} (тип причины {$this->other['reason_type']}).";
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['student_id', 'points', 'reason_type'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("Ключ '{$key}' обязателен в other события points_awarded.");
            }
        }
    }
}
