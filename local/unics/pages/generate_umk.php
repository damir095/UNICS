<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../classes/ai_generator.php');

require_login();

global $USER, $DB;

$is_admin   = has_capability('local/unics:manage', context_system::instance());
$is_teacher = has_capability('local/unics:viewstudents', context_system::instance());

if (!$is_admin && !$is_teacher) {
    require_capability('local/unics:viewstudents', context_system::instance());
}

// Определяем запись педагога
$teacher_record = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/generate_umk.php'));
$PAGE->set_title('Генерация УМК — УНИКС');
$PAGE->set_heading('Сгенерировать учебный материал (ИИ)');
$PAGE->set_pagelayout('admin');

class generate_umk_form extends moodleform {

    // Передаётся через customdata: ['teacher_id' => int|null, 'default_student' => int]
    public function definition(): void {
        global $DB;
        $mform = $this->_form;

        $teacher_id      = $this->_customdata['teacher_id'] ?? null;
        $default_student = (int)($this->_customdata['default_student'] ?? 0);

        $mform->addElement('text', 'title', 'Название материала', ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required');

        $mform->addElement('text', 'topic', 'Тема урока', ['size' => 60]);
        $mform->setType('topic', PARAM_TEXT);
        $mform->addRule('topic', null, 'required');

        // Все курсы сайта
        $courses = $DB->get_records_menu('course', null, 'fullname ASC', 'id, fullname');
        unset($courses[1]); // убираем главную страницу (site)
        $mform->addElement('select', 'course_id', 'Курс', $courses);
        $mform->addRule('course_id', null, 'required');

        // Учащиеся: все (для админа) или только назначенные (для педагога)
        if ($teacher_id) {
            $students = $DB->get_records_sql(
                "SELECT s.id, u.lastname, u.firstname, u.middlename
                 FROM {unics_teacher_student} ts
                 JOIN {unics_students} s ON s.id = ts.student_id
                 JOIN {user} u ON u.id = s.mdl_user_id
                 WHERE ts.teacher_id = :teacher_id AND u.deleted = 0
                 ORDER BY u.lastname, u.firstname",
                ['teacher_id' => $teacher_id]
            );
        } else {
            $students = $DB->get_records_sql(
                "SELECT s.id, u.lastname, u.firstname, u.middlename
                 FROM {unics_students} s
                 JOIN {user} u ON u.id = s.mdl_user_id
                 WHERE u.deleted = 0
                 ORDER BY u.lastname, u.firstname"
            );
        }

        $student_opts = [];
        foreach ($students as $s) {
            $student_opts[$s->id] = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
        }

        if (empty($student_opts)) {
            $hint = $teacher_id
                ? 'Нет назначенных учащихся. Обратитесь к администратору для назначения.'
                : 'Нет учащихся в системе. Сначала создайте пользователя с ролью «Учащийся».';
            $mform->addElement('static', 'no_students', 'Учащийся',
                '<span class="text-danger">' . $hint . '</span>');
        } else {
            $mform->addElement('select', 'student_id', 'Учащийся', $student_opts);
            $mform->addRule('student_id', null, 'required');
            if ($default_student && isset($student_opts[$default_student])) {
                $mform->setDefault('student_id', $default_student);
            }
        }

        $mform->addElement('advcheckbox', 'generate_audio', 'Сгенерировать аудиоматериал (TTS)', '', [], [0, 1]);
        $mform->setDefault('generate_audio', 1);

        $this->add_action_buttons(true, 'Запустить генерацию');
    }
}

$default_student = optional_param('student_id', 0, PARAM_INT);
$form = new generate_umk_form(null, [
    'teacher_id'      => $teacher_record ? (int)$teacher_record->id : null,
    'default_student' => $default_student,
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/unics/pages/umk_status.php'));

} elseif ($data = $form->get_data()) {

    // Проверяем что ключи настроены
    $groq_key = get_config('local_unics', 'groq_api_key');
    if (empty($groq_key)) {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'Не настроен Groq API key. Перейдите: Администрирование → Локальные плагины → УНИКС: Настройки ИИ',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $umk_id = $DB->insert_record('unics_umk', (object)[
        'student_id'    => (int)$data->student_id,
        'mdl_course_id' => (int)$data->course_id,
        'title'         => $data->title,
        'topic'         => $data->topic,
        'status'        => 1,
        'generated_at'  => date('Y-m-d H:i:s'),
    ]);

    $DB->insert_record('unics_ai_queue', (object)[
        'umk_id'         => $umk_id,
        'generate_text'  => 1,
        'generate_audio' => (int)$data->generate_audio,
        'status'         => 1,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        'Задача поставлена в очередь. Материал появится в курсе после обработки cron (каждые 5 минут).',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

// Проверка наличия ключей
$groq_key     = get_config('local_unics', 'groq_api_key');
$voicerss_key = get_config('local_unics', 'voicerss_api_key');

if (empty($groq_key)) {
    echo $OUTPUT->notification(
        'Groq API key не настроен. <a href="/admin/settings.php?section=local_unics_ai">Открыть настройки</a>',
        'warning'
    );
}
if (empty($voicerss_key)) {
    echo $OUTPUT->notification(
        'VoiceRSS API key не настроен — аудио генерироваться не будет. <a href="/admin/settings.php?section=local_unics_ai">Открыть настройки</a>',
        'info'
    );
}

echo '<div class="mb-3">';
echo '<a href="umk_status.php" class="btn btn-outline-secondary btn-sm">&larr; История генерации</a>';
echo '</div>';

$form->display();

echo $OUTPUT->footer();
