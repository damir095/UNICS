<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: организация удалена (мягко, is_active=0). Этап 4.4 аудита.
 *
 * @package local_unics
 */
final class organization_deleted extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'unics_organizations';
    }

    public static function get_name() {
        return get_string('eventorganizationdeleted', 'local_unics');
    }

    public function get_description() {
        return "Удалена организация #{$this->objectid}.";
    }
}
