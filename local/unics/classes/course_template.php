<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class course_template {

    const PROFILE_FIELD = 'profile_field_unics_level';

    public static function get_subjects(): array {
        return [
            'math'        => ['name' => 'Математика',       'sections' => 10],
            'russian'     => ['name' => 'Русский язык',     'sections' => 10],
            'literature'  => ['name' => 'Литература',        'sections' => 8],
            'history'     => ['name' => 'История',          'sections' => 10],
            'socials'     => ['name' => 'Обществознание',   'sections' => 8],
            'physics'     => ['name' => 'Физика',           'sections' => 10],
            'chemistry'   => ['name' => 'Химия',            'sections' => 9],
            'biology'     => ['name' => 'Биология',         'sections' => 9],
            'geography'   => ['name' => 'География',        'sections' => 8],
            'informatics' => ['name' => 'Информатика',      'sections' => 8],
            'english'     => ['name' => 'Английский язык',  'sections' => 10],
            'obzh'        => ['name' => 'ОБЖ',             'sections' => 6],
        ];
    }

    public static function get_level_labels(): array {
        return [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
    }

    /**
     * JSON-строка условного доступа по полю профиля unics_level.
     * showc[false] - полностью скрывает активность от учащихся другого уровня.
     */
    public static function profile_level_availability(int $level): string {
        return json_encode([
            'op'    => '&',
            'c'     => [[
                'type' => 'profile',
                'sf'   => self::PROFILE_FIELD,
                'op'   => 'isequalto',
                'v'    => (string)$level,
            ]],
            'showc' => [false],
        ]);
    }

    /**
     * Creates a Moodle course with activities split by difficulty level (via profile field).
     * Returns the created course record.
     */
    public static function create_from_template(
        string $subject_key,
        int    $class_num,
        int    $category_id = 0,
        ?int   $num_topics_override = null,
        ?array $topic_names_override = null
    ): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        // Ensure unics_level profile field exists
        self::ensure_profile_field();

        $subjects = self::get_subjects();

        if (!isset($subjects[$subject_key])) {
            throw new \invalid_parameter_exception("Unknown subject: {$subject_key}");
        }

        $subject    = $subjects[$subject_key];
        $num_topics = $subject['sections'];

        if ($num_topics_override !== null && $num_topics_override > 0) {
            $num_topics = max(1, min(20, $num_topics_override));
        }
        // Если задан список имён тем - он определяет и количество тем.
        if ($topic_names_override !== null && count($topic_names_override) > 0) {
            $num_topics = count($topic_names_override);
        }

        $fullname  = "{$subject['name']}. {$class_num} класс";
        $shortname = substr($subject_key, 0, 4) . $class_num . '_' . substr(uniqid(), -5);

        if (!$category_id) {
            $category_id = (int)($DB->get_field('course_categories', 'id', ['parent' => 0], IGNORE_MISSING) ?: 1);
        }

        $data                   = new \stdClass();
        $data->fullname         = $fullname;
        $data->shortname        = $shortname;
        $data->category         = $category_id;
        $data->format           = 'topics';
        $data->numsections      = $num_topics + 1; // темы + итоговый контроль
        $data->visible          = 1;
        $data->enablecompletion = 1;
        $data->completionnotify = 0;
        $data->groupmode        = 0;
        $data->groupmodeforce   = 0;
        $data->lang             = 'ru';
        $data->startdate        = mktime(0, 0, 0, 9, 1, (int)date('Y'));
        $data->summary          = self::build_summary($subject['name'], $class_num);
        $data->summaryformat    = FORMAT_HTML;

        $course = create_course($data);

        self::apply_section_names($course->id, $num_topics, $topic_names_override);

        rebuild_course_cache($course->id, true);

        return $course;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function apply_section_names(int $course_id, int $num_topics, ?array $topic_names = null): void {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $course_id], 'section ASC');

        foreach ($sections as $section) {
            $idx = (int)$section->section;

            if ($idx === 0) {
                $section->name    = 'Введение в курс';
                $section->summary = '<p>Ознакомительный раздел: цели курса, требования, порядок работы.</p>';
            } elseif ($idx <= $num_topics) {
                $custom = $topic_names[$idx - 1] ?? null;
                $section->name    = ($custom !== null && $custom !== '') ? $custom : "Тема {$idx}";
                $section->summary = '<p><em>Материалы темы разделены по уровням сложности - каждый учащийся видит только свой уровень.</em></p>';
            } else {
                $section->name    = 'Итоговый контроль';
                $section->summary = '<p>Итоговый тест по всему курсу.</p>';
            }

            $section->summaryformat = FORMAT_HTML;
            $DB->update_record('course_sections', $section);
        }
    }

    public static function ensure_profile_field(): int {
        global $DB;

        $field = $DB->get_record('user_info_field', ['shortname' => 'unics_level']);
        if ($field) {
            return (int)$field->id;
        }

        $cat = $DB->get_record('user_info_category', ['name' => 'УНИКС']);
        if (!$cat) {
            $cat            = new \stdClass();
            $cat->name      = 'УНИКС';
            $cat->sortorder = 999;
            $cat->id        = $DB->insert_record('user_info_category', $cat);
        }

        $field                    = new \stdClass();
        $field->shortname         = 'unics_level';
        $field->name              = 'Уровень сложности УНИКС';
        $field->datatype          = 'text';
        $field->description       = '1 - Базовый, 2 - Стандартный, 3 - Продвинутый';
        $field->descriptionformat = FORMAT_HTML;
        $field->categoryid        = $cat->id;
        $field->sortorder         = 1;
        $field->required          = 0;
        $field->locked            = 1;
        $field->visible           = 0;
        $field->forceunique       = 0;
        $field->signup            = 0;
        $field->defaultdata       = '2';
        $field->defaultdataformat = FORMAT_PLAIN;
        $field->param1            = '1';
        $field->param2            = '1';
        $field->param3            = '';
        $field->param4            = '';
        $field->param5            = '';
        $field->id = $DB->insert_record('user_info_field', $field);

        return (int)$field->id;
    }

    private static function build_summary(string $subject, int $class): string {
        return "<p>Курс: <strong>{$subject}</strong>, {$class} класс.</p>"
             . '<p>В каждой теме материалы разделены по уровням сложности: '
             . '<span class="badge badge-info">Базовый</span> '
             . '<span class="badge badge-primary">Стандартный</span> '
             . '<span class="badge badge-success">Продвинутый</span>. '
             . 'Учащийся видит только свой уровень (по полю профиля <code>unics_level</code>).</p>';
    }
}
