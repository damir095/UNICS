<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/ai_generator.php');

require_login();

global $USER, $DB;

$is_admin   = has_capability('local/unics:manage', context_system::instance());
$is_teacher = has_capability('local/unics:viewstudents', context_system::instance());

if (!$is_admin && !$is_teacher) {
    require_capability('local/unics:viewstudents', context_system::instance());
}

$teacher_record = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/generate_umk.php'));
$PAGE->set_title('Генерация УМК — УНИКС');
$PAGE->set_heading('Сгенерировать учебный материал (ИИ)');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST: запуск генерации
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    $title          = required_param('title', PARAM_TEXT);
    $topic          = required_param('topic', PARAM_TEXT);
    $course_id      = required_param('course_id', PARAM_INT);
    $student_ids    = optional_param_array('student_ids', [], PARAM_INT);
    $generate_audio = optional_param('generate_audio', 0, PARAM_INT);
    $student_ids    = array_filter($student_ids);

    if (empty($title) || empty($topic) || empty($course_id) || empty($student_ids)) {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'Заполните все поля и выберите хотя бы одного учащегося.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    if (empty(get_config('local_unics', 'groq_api_key'))) {
        redirect(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'Не настроен Groq API key. Администрирование → Локальные плагины → УНИКС: Настройки ИИ',
            null, \core\output\notification::NOTIFY_ERROR
        );
    }

    $queued = 0;
    foreach ($student_ids as $student_id) {
        // Педагог может генерировать только для своих учеников
        if ($teacher_record && !$DB->record_exists('unics_teacher_student', [
            'teacher_id' => $teacher_record->id, 'student_id' => $student_id,
        ])) continue;

        $umk_id = $DB->insert_record('unics_umk', (object)[
            'student_id'    => (int)$student_id,
            'mdl_course_id' => (int)$course_id,
            'title'         => $title,
            'topic'         => $topic,
            'status'        => 1,
            'generated_at'  => date('Y-m-d H:i:s'),
        ]);
        $DB->insert_record('unics_ai_queue', (object)[
            'umk_id'         => $umk_id,
            'generate_text'  => 1,
            'generate_audio' => (int)$generate_audio,
            'status'         => 1,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $queued++;
    }

    $msg  = $queued > 0
        ? "Поставлено в очередь: {$queued} задач. Материалы появятся в курсе после обработки cron."
        : 'Не удалось добавить задачи — проверьте права доступа к учащимся.';
    $type = $queued > 0
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_WARNING;

    redirect(new moodle_url('/local/unics/pages/umk_status.php'), $msg, null, $type);
}

// ----------------------------------------------------------------
// Фильтры (GET)
// ----------------------------------------------------------------
$filter_org   = optional_param('filter_org',   0, PARAM_INT);
$filter_class = optional_param('filter_class', 0, PARAM_INT);

// Меню организаций
$orgs_menu = [0 => '— все организации —'];
foreach ($DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name') as $o) {
    $orgs_menu[$o->id] = $o->name;
}

// Меню классов
$classes_menu = [0 => '— все классы —'];
for ($i = 1; $i <= 11; $i++) { $classes_menu[$i] = "{$i} класс"; }

// Курсы
$courses = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname");

// ----------------------------------------------------------------
// Учащиеся с учётом фильтров и роли текущего пользователя
// ----------------------------------------------------------------
$where  = 'u.deleted = 0';
$params = [];

if ($teacher_record) {
    $where .= ' AND ts.teacher_id = :teacher_id';
    $params['teacher_id'] = $teacher_record->id;
}
if ($filter_org > 0) {
    $where .= ' AND s.organization_id = :org_id';
    $params['org_id'] = $filter_org;
}
if ($filter_class > 0) {
    $where .= ' AND s.class_number = :class_num';
    $params['class_num'] = $filter_class;
}

if ($teacher_record) {
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename,
                s.class_number, o.name AS org_name
         FROM {unics_teacher_student} ts
         JOIN {unics_students} s  ON s.id  = ts.student_id
         JOIN {user} u            ON u.id  = s.mdl_user_id
         LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE {$where}
         ORDER BY u.lastname, u.firstname",
        $params
    );
} else {
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename,
                s.class_number, o.name AS org_name
         FROM {unics_students} s
         JOIN {user} u            ON u.id  = s.mdl_user_id
         LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE {$where}
         ORDER BY u.lastname, u.firstname",
        $params
    );
}

$default_student = optional_param('student_id', 0, PARAM_INT);

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Сгенерировать учебный материал (ИИ)');

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

echo html_writer::link(
    new moodle_url('/local/unics/pages/umk_status.php'),
    'История генерации',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// --- Панель фильтров учащихся ---
$filter_url = new moodle_url('/local/unics/pages/generate_umk.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'form-inline mb-4 p-3 bg-light border rounded']);
echo html_writer::tag('strong', 'Фильтр учащихся:', ['class' => 'mr-3']);

echo html_writer::tag('label', 'Организация:', ['class' => 'mr-1']);
echo html_writer::select($orgs_menu, 'filter_org', $filter_org, false,
    ['class' => 'form-control form-control-sm mr-3']);

echo html_writer::tag('label', 'Класс:', ['class' => 'mr-1']);
echo html_writer::select($classes_menu, 'filter_class', $filter_class, false,
    ['class' => 'form-control form-control-sm mr-3']);

echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary']);
echo html_writer::end_tag('form');

// --- Основная форма генерации ---
$form_url = new moodle_url('/local/unics/pages/generate_umk.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);

echo html_writer::start_tag('div', ['class' => 'row']);

// Левая колонка: параметры материала
echo html_writer::start_tag('div', ['class' => 'col-md-5']);

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Название материала <span class="text-danger">*</span>');
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'title', 'class' => 'form-control', 'required' => 'required',
]);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Тема урока <span class="text-danger">*</span>');
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'topic', 'class' => 'form-control', 'required' => 'required',
]);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Курс <span class="text-danger">*</span>');
$course_opts = '';
foreach ($courses as $c) {
    $course_opts .= html_writer::tag('option', htmlspecialchars($c->fullname), ['value' => $c->id]);
}
echo html_writer::tag('select', $course_opts, [
    'name' => 'course_id', 'class' => 'form-control', 'required' => 'required',
]);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-check mb-3']);
echo html_writer::empty_tag('input', [
    'type' => 'checkbox', 'id' => 'gen_audio', 'name' => 'generate_audio',
    'value' => '1', 'checked' => 'checked', 'class' => 'form-check-input',
]);
echo html_writer::tag('label', 'Сгенерировать аудиоматериал (TTS)',
    ['for' => 'gen_audio', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // col-md-5

// Правая колонка: чекбоксы учащихся
echo html_writer::start_tag('div', ['class' => 'col-md-7']);
echo html_writer::tag('label',
    'Учащиеся <span class="text-danger">*</span> ' .
    html_writer::tag('small',
        html_writer::tag('a', 'Выбрать всех', [
            'href'    => '#',
            'onclick' => 'document.querySelectorAll(".umk-cb").forEach(c=>c.checked=true);return false;',
        ]) . ' / ' .
        html_writer::tag('a', 'Снять все', [
            'href'    => '#',
            'onclick' => 'document.querySelectorAll(".umk-cb").forEach(c=>c.checked=false);return false;',
        ]),
        ['class' => 'text-muted ml-2']
    )
);

if (empty($students)) {
    echo html_writer::tag('p', 'Нет учащихся по выбранному фильтру.', ['class' => 'text-muted']);
} else {
    echo html_writer::start_tag('div', [
        'class' => 'border rounded p-2',
        'style' => 'max-height:320px;overflow-y:auto;background:#fff',
    ]);

    foreach ($students as $s) {
        $fio = htmlspecialchars(
            "{$s->lastname} {$s->firstname}"
            . ($s->class_number ? " — {$s->class_number} кл." : '')
            . ($s->org_name ? " ({$s->org_name})" : '')
        );
        $checked = ($default_student && $s->student_id == $default_student) ? ['checked' => 'checked'] : [];

        echo html_writer::start_tag('div', ['class' => 'form-check']);
        echo html_writer::empty_tag('input', array_merge([
            'type'  => 'checkbox',
            'name'  => 'student_ids[]',
            'value' => $s->student_id,
            'id'    => "u_{$s->student_id}",
            'class' => 'form-check-input umk-cb',
        ], $checked));
        echo html_writer::tag('label', $fio,
            ['for' => "u_{$s->student_id}", 'class' => 'form-check-label']);
        echo html_writer::end_tag('div');
    }

    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div'); // col-md-7
echo html_writer::end_tag('div'); // row

echo html_writer::tag('button', 'Запустить генерацию',
    ['type' => 'submit', 'class' => 'btn btn-primary mt-3 mr-2']);
echo html_writer::link(
    new moodle_url('/local/unics/pages/umk_status.php'),
    'Отмена', ['class' => 'btn btn-outline-secondary mt-3']
);

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
