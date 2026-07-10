<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: обновлен профиль пользователя УНИКС. Этап 4.4 аудита.
 * other['changed'] - список ключей измененных полей (без значений-ПДн).
 *
 * @package local_unics
 */
final class user_updated extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'user';
    }

    public static function get_name() {
        return get_string('eventuserupdated', 'local_unics');
    }

    public function get_description() {
        $fields = implode(', ', (array)($this->other['changed'] ?? []));
        return "Обновлен пользователь УНИКС userid {$this->relateduserid} (поля: {$fields}).";
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['changed'])) {
            throw new \coding_exception("Ключ 'changed' обязателен в other события user_updated.");
        }
    }
}
