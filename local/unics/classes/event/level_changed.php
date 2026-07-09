<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: изменен уровень сложности учащегося. Этап 2.4 аудита.
 * source: 'apply' (пересчет/предложения), 'placement' (стартовая диагностика),
 * 'umk_adapt' (адаптация при генерации УМК).
 *
 * @package local_unics
 */
final class level_changed extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'unics_students';
    }

    public static function get_name() {
        return get_string('eventlevelchanged', 'local_unics');
    }

    public function get_description() {
        return "Уровень учащегося #{$this->objectid} (userid {$this->relateduserid}) изменен: "
            . "{$this->other['old_level']} -> {$this->other['new_level']} (источник {$this->other['source']}).";
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['old_level', 'new_level', 'source'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("Ключ '{$key}' обязателен в other события level_changed.");
            }
        }
    }
}
