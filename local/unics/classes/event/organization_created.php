<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: создана организация. Этап 4.4 аудита.
 *
 * @package local_unics
 */
final class organization_created extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'unics_organizations';
    }

    public static function get_name() {
        return get_string('eventorganizationcreated', 'local_unics');
    }

    public function get_description() {
        return "Создана организация #{$this->objectid} «{$this->other['name']}».";
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['name'])) {
            throw new \coding_exception("Ключ 'name' обязателен в other события organization_created.");
        }
    }
}
