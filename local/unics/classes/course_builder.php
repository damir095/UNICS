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
        $page->content       = $content;
        $page->contentformat = FORMAT_MARKDOWN;
        $page->display       = 5;
        $page->timemodified  = time();
        $page->id = $DB->insert_record('page', $page);

        return $this->attach_to_section($course_id, $section_num, 'page', $page->id);
    }

    /**
     * Добавить аудиофайл (MP3) как ресурс (mod_resource).
     * Возвращает cmid.
     */
    public function add_audio_resource(int $course_id, int $section_num, string $title, string $audio_data, string $ext = 'mp3'): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $resource               = new \stdClass();
        $resource->course       = $course_id;
        $resource->name         = $title . ' (аудио)';
        $resource->intro        = '';
        $resource->introformat  = FORMAT_HTML;
        $resource->display      = 6; // display inline player
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
            'filename'     => 'audio_umk_' . time() . '.' . $ext,
            'timecreated'  => time(),
            'timemodified' => time(),
        ], $audio_data);

        return $cmid;
    }

    /**
     * Добавить пустой тест (mod_quiz) в секцию курса.
     * Возвращает cmid.
     */
    public function add_quiz(
        int    $course_id,
        int    $section_num,
        string $title,
        int    $attempts  = 0,
        int    $timelimit = 0
    ): int {
        global $DB;

        $quiz                              = new \stdClass();
        $quiz->course                      = $course_id;
        $quiz->name                        = $title;
        $quiz->intro                       = '';
        $quiz->introformat                 = FORMAT_HTML;
        $quiz->timeopen                    = 0;
        $quiz->timeclose                   = 0;
        $quiz->timelimit                   = $timelimit;
        $quiz->overduehandling             = 'autosubmit';
        $quiz->graceperiod                 = 0;
        $quiz->preferredbehaviour          = 'deferredfeedback';
        $quiz->canredoquestions            = 0;
        $quiz->attemptonlast               = 0;
        $quiz->grademethod                 = 1;
        $quiz->decimalpoints               = 2;
        $quiz->questiondecimalpoints       = -1;
        $quiz->reviewattempt               = 69888;
        $quiz->reviewcorrectness           = 4352;
        $quiz->reviewmarks                 = 4352;
        $quiz->reviewspecificfeedback      = 4352;
        $quiz->reviewgeneralfeedback       = 4352;
        $quiz->reviewrightanswer           = 4352;
        $quiz->reviewoverallfeedback       = 4352;
        $quiz->questionsperpage            = 0;
        $quiz->navmethod                   = 'free';
        $quiz->shuffleanswers              = 1;
        $quiz->sumgrades                   = 0;
        $quiz->grade                       = 10;
        $quiz->timecreated                 = time();
        $quiz->timemodified                = time();
        $quiz->password                    = '';
        $quiz->subnet                      = '';
        $quiz->browsersecurity             = '-';
        $quiz->delay1                      = 0;
        $quiz->delay2                      = 0;
        $quiz->showuserpicture             = 0;
        $quiz->showblocks                  = 0;
        $quiz->completionattemptsexhausted = 0;
        $quiz->completionminattempts       = 0;
        $quiz->allowofflineattempts        = 0;
        $quiz->attempts                    = $attempts;
        $quiz->id = $DB->insert_record('quiz', $quiz);

        return $this->attach_to_section($course_id, $section_num, 'quiz', $quiz->id);
    }

    /**
     * Добавить задание (mod_assign) в секцию курса.
     * Возвращает cmid.
     */
    public function add_assignment(
        int    $course_id,
        int    $section_num,
        string $title,
        string $description,
        int    $duedate = 0
    ): int {
        global $DB;

        $assign                                    = new \stdClass();
        $assign->course                            = $course_id;
        $assign->name                              = $title;
        $assign->intro                             = '<p>' . s($description) . '</p>';
        $assign->introformat                       = FORMAT_HTML;
        $assign->alwaysshowdescription             = 1;
        $assign->nosubmissions                     = 0;
        $assign->submissiondrafts                  = 0;
        $assign->sendnotifications                 = 0;
        $assign->sendlatenotifications             = 0;
        $assign->sendstudentnotifications          = 1;
        $assign->duedate                           = $duedate;
        $assign->allowsubmissionsfromdate          = 0;
        $assign->grade                             = 100;
        $assign->timemodified                      = time();
        $assign->requiresubmissionstatement        = 0;
        $assign->completionsubmit                  = 0;
        $assign->cutoffdate                        = 0;
        $assign->gradingduedate                    = 0;
        $assign->teamsubmission                    = 0;
        $assign->requireallteammemberssubmit       = 0;
        $assign->teamsubmissiongroupingid          = 0;
        $assign->blindmarking                      = 0;
        $assign->hidegrader                        = 0;
        $assign->revealidentities                  = 0;
        $assign->attemptreopenmethod               = 'none';
        $assign->maxattempts                       = -1;
        $assign->markingworkflow                   = 0;
        $assign->markingallocation                 = 0;
        $assign->markinganonymous                  = 0;
        $assign->preventsubmissionnotingroup       = 0;
        $assign->activity                          = null;
        $assign->activityformat                    = 0;
        $assign->timelimit                         = 0;
        $assign->submissionattachments             = 0;
        $assign->gradepenalty                      = 0;
        $assign->id = $DB->insert_record('assign', $assign);

        // Enable online text submission
        $cfg             = new \stdClass();
        $cfg->assignment = $assign->id;
        $cfg->plugin     = 'onlinetext';
        $cfg->subtype    = 'assignsubmission';
        $cfg->name       = 'enabled';
        $cfg->value      = '1';
        $DB->insert_record('assign_plugin_config', $cfg);

        return $this->attach_to_section($course_id, $section_num, 'assign', $assign->id);
    }

    /**
     * Ограничить видимость активности по уровню сложности (profile_field_unics_level).
     */
    public function set_cm_availability_level(int $cmid, int $level): void {
        global $DB;
        $DB->set_field('course_modules', 'availability',
            course_template::profile_level_availability($level),
            ['id' => $cmid]
        );
    }

    /**
     * Ограничить секцию курса так, чтобы её видел только указанный учащийся.
     * Педагоги видят всё автоматически через capability ignoreavailabilityrestrictions.
     */
    public function restrict_section_to_student_group(int $course_id, int $section_num, int $mdl_user_id): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        // Один постоянный idnumber на пару (курс, студент), чтобы не плодить группы
        $idnumber = 'umk_s' . $mdl_user_id . '_c' . $course_id;

        $group = $DB->get_record('groups', ['courseid' => $course_id, 'idnumber' => $idnumber]);
        if (!$group) {
            $user = $DB->get_record('user', ['id' => $mdl_user_id]);
            $data             = new \stdClass();
            $data->courseid   = $course_id;
            $data->name       = 'УМК: ' . fullname($user);
            $data->idnumber   = $idnumber;
            $data->id = groups_create_group($data);
            $group = $DB->get_record('groups', ['id' => $data->id]);
        }

        if (!groups_is_member($group->id, $mdl_user_id)) {
            groups_add_member($group->id, $mdl_user_id);
        }

        $section = $DB->get_record('course_sections', ['course' => $course_id, 'section' => $section_num]);
        if (!$section) {
            return;
        }

        $DB->set_field('course_sections', 'availability', json_encode([
            'op'   => '&',
            'show' => false,
            'c'    => [['type' => 'group', 'id' => (int)$group->id]],
        ]), ['id' => $section->id]);

        rebuild_course_cache($course_id, true);
    }

    /**
     * Вернуть номер следующей свободной секции курса (max + 1).
     */
    public function get_next_section_num(int $course_id): int {
        global $DB;
        $max = (int)$DB->get_field_sql(
            "SELECT COALESCE(MAX(section), 0) FROM {course_sections} WHERE course = :course",
            ['course' => $course_id]
        );
        return $max + 1;
    }

    /**
     * Найти секцию курса по имени или создать новую.
     * Секции с одинаковым именем темы используются всеми учащимися совместно.
     * Возвращает номер секции (section).
     */
    public function get_or_create_topic_section(int $course_id, string $topic_name): int {
        global $DB;

        $existing = $DB->get_record_select(
            'course_sections',
            'course = :course AND name = :name',
            ['course' => $course_id, 'name' => $topic_name]
        );
        if ($existing) {
            return (int)$existing->section;
        }

        $section_num       = $this->get_next_section_num($course_id);
        $section           = new \stdClass();
        $section->course   = $course_id;
        $section->section  = $section_num;
        $section->name     = $topic_name;
        $section->summary  = '';
        $section->summaryformat = FORMAT_HTML;
        $section->sequence = '';
        $section->visible  = 1;
        $DB->insert_record('course_sections', $section);

        rebuild_course_cache($course_id, true);
        return $section_num;
    }

    /**
     * Ограничить одну активность (course_module) так, чтобы её видел только указанный учащийся.
     * Использует ту же группу-идентификатор, что и restrict_section_to_student_group.
     * show:false — педагоги с capability ignoreavailabilityrestrictions видят всё.
     */
    public function restrict_activity_to_student_group(int $cmid, int $course_id, int $mdl_user_id): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $idnumber = 'umk_s' . $mdl_user_id . '_c' . $course_id;

        $group = $DB->get_record('groups', ['courseid' => $course_id, 'idnumber' => $idnumber]);
        if (!$group) {
            $user             = $DB->get_record('user', ['id' => $mdl_user_id]);
            $data             = new \stdClass();
            $data->courseid   = $course_id;
            $data->name       = 'УМК: ' . fullname($user);
            $data->idnumber   = $idnumber;
            $data->id = groups_create_group($data);
            $group = $DB->get_record('groups', ['id' => $data->id]);
        }

        if (!groups_is_member($group->id, $mdl_user_id)) {
            groups_add_member($group->id, $mdl_user_id);
        }

        $DB->set_field('course_modules', 'availability', json_encode([
            'op'   => '&',
            'show' => false,
            'c'    => [['type' => 'group', 'id' => (int)$group->id]],
        ]), ['id' => $cmid]);
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
