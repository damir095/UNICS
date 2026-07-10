<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: создан пользователь УНИКС (с ролью и скоупом). Этап 4.4 аудита.
 *
 * @package local_unics
 */
final class user_created extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'user';
    }

    public static function get_name() {
        return get_string('eventusercreated', 'local_unics');
    }

    public function get_description() {
        return "Создан пользователь УНИКС userid {$this->relateduserid} "
            . "с ролью {$this->other['unics_role']}.";
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['unics_role'])) {
            throw new \coding_exception("Ключ 'unics_role' обязателен в other события user_created.");
        }
    }
}
