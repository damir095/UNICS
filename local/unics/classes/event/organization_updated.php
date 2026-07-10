<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: обновлена организация. Этап 4.4 аудита.
 *
 * @package local_unics
 */
final class organization_updated extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'unics_organizations';
    }

    public static function get_name() {
        return get_string('eventorganizationupdated', 'local_unics');
    }

    public function get_description() {
        $name = $this->other['name'] ?? '';
        return "Обновлена организация #{$this->objectid}" . ($name !== '' ? " «{$name}»" : '') . ".";
    }
}
