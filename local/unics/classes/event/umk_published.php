<?php
namespace local_unics\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Событие: УМК опубликован (материалы открыты учащимся). Этап 2.4 аудита.
 *
 * @package local_unics
 */
final class umk_published extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'unics_umk';
    }

    public static function get_name() {
        return get_string('eventumkpublished', 'local_unics');
    }

    public function get_description() {
        return "УМК #{$this->objectid} «{$this->other['title']}» (тема «{$this->other['topic']}») опубликован.";
    }

    protected function validate_data() {
        parent::validate_data();
        foreach (['title', 'topic'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("Ключ '{$key}' обязателен в other события umk_published.");
            }
        }
    }
}
