<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class course_builder {

    /**
     * Добавить текстовую страницу (mod_page) в секцию курса.
     * Возвращает cmid.
     */
    public function add_text_page(int $course_id, int $section_num, string $title, string $content): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $page                = new \stdClass();
        $page->course        = $course_id;
        $page->name          = $title;
        $page->intro         = '';
        $page->introformat   = FORMAT_HTML;
        $page->content       = '<p>' . nl2br(s($content)) . '</p>';
        $page->contentformat = FORMAT_HTML;
        $page->display       = 5;
        $page->timemodified  = time();
        $page->id = $DB->insert_record('page', $page);

        return $this->attach_to_section($course_id, $section_num, 'page', $page->id);
    }

    /**
     * Добавить аудиофайл (MP3) как ресурс (mod_resource).
     * Возвращает cmid.
     */
    public function add_audio_resource(int $course_id, int $section_num, string $title, string $mp3_data): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $resource               = new \stdClass();
        $resource->course       = $course_id;
        $resource->name         = $title . ' (аудио)';
        $resource->intro        = '';
        $resource->introformat  = FORMAT_HTML;
        $resource->display      = 0;
        $resource->timemodified = time();
        $resource->id = $DB->insert_record('resource', $resource);

        $cmid = $this->attach_to_section($course_id, $section_num, 'resource', $resource->id);

        $ctx = \context_module::instance($cmid);
        $fs  = get_file_storage();
        $fs->create_file_from_string([
            'contextid'    => $ctx->id,
            'component'    => 'mod_resource',
            'filearea'     => 'content',
            'itemid'       => 0,
            'filepath'     => '/',
            'filename'     => 'audio_umk_' . time() . '.mp3',
            'timecreated'  => time(),
            'timemodified' => time(),
        ], $mp3_data);

        return $cmid;
    }

    /**
     * Прикрепить экземпляр модуля к секции курса.
     * Если секции не существует — создаёт её.
     */
    private function attach_to_section(int $course_id, int $section_num, string $mod_name, int $instance_id): int {
        global $DB;

        $module = $DB->get_record('modules', ['name' => $mod_name], '*', MUST_EXIST);

        // Убедимся что секция существует
        $section = $DB->get_record('course_sections', ['course' => $course_id, 'section' => $section_num]);
        if (!$section) {
            $section           = new \stdClass();
            $section->course   = $course_id;
            $section->section  = $section_num;
            $section->sequence = '';
            $section->visible  = 1;
            $section->id = $DB->insert_record('course_sections', $section);
        }

        $cm           = new \stdClass();
        $cm->course   = $course_id;
        $cm->module   = $module->id;
        $cm->instance = $instance_id;
        $cm->section  = $section->id;
        $cm->visible  = 1;
        $cm->added    = time();
        $cm->id = $DB->insert_record('course_modules', $cm);

        $seq = array_filter(explode(',', $section->sequence ?? ''));
        $seq[] = $cm->id;
        $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);

        rebuild_course_cache($course_id, true);
        return $cm->id;
    }
}
