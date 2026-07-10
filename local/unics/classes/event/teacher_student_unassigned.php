<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: привязка педагог-ученик снята. Этап 4.4 аудита (отвязка).
 *
 * @package local_unics
 */
final class teacher_student_unassigned extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'unics_teacher_student';
    }

    public static function get_name() {
        return get_string('eventteacherstudentunassigned', 'local_unics');
    }

    public function get_description() {
        return "Снята привязка педагога #{$this->other['teacher_id']} к учащемуся "
            . "#{$this->other['student_id']}.";
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['teacher_id', 'student_id'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("Ключ '{$key}' обязателен в other события teacher_student_unassigned.");
            }
        }
    }
}
