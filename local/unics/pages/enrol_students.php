<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

global $DB;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/enrol_students.php'));
$PAGE->set_title('Запись учащихся на курс — УНИКС');
$PAGE->set_heading('Запись учащихся на курс');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST: записать выбранных студентов на курс
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $course_id  = required_param('course_id', PARAM_INT);
    $student_ids = optional_param_array('student_ids', [], PARAM_INT);

    if (empty($course_id) || empty($student_ids)) {
        redirect(
            new moodle_url('/local/unics/pages/enrol_students.php'),
            'Выберите курс и хотя бы одного учащегося.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $enrol    = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);

    if (!$instance) {
        $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
        $enrol->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);
    }

    $student_role = $DB->get_record('role', ['shortname' => 'student'], 'id');
    $role_id = $student_role ? (int)$student_role->id : 5;

    $ctx     = \context_course::instance($course_id);
    $enrolled = 0;
    $skipped  = 0;

    foreach ($student_ids as $student_id) {
        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) continue;

        if (is_enrolled($ctx, (int)$student->mdl_user_id)) {
            $skipped++;
        } else {
            $enrol->enrol_user($instance, (int)$student->mdl_user_id, $role_id);
            $enrolled++;
        }
    }

    $msg = "Записано: {$enrolled}";
    if ($skipped > 0) {
        $msg .= ", уже были записаны: {$skipped}";
    }
    redirect(
        new moodle_url('/local/unics/pages/enrol_students.php', ['course_id' => $course_id]),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ----------------------------------------------------------------
// Данные для страницы
// ----------------------------------------------------------------
$selected_course = optional_param('course_id', 0, PARAM_INT);
$filter_district = optional_param('district_id', 0, PARAM_INT);
$filter_org      = optional_param('org_id', 0, PARAM_INT);

// Все курсы (кроме главной страницы сайта)
$courses_raw = $DB->get_records_sql(
    "SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname"
);
$courses_menu = [0 => '— выберите курс —'];
foreach ($courses_raw as $c) {
    $courses_menu[$c->id] = $c->fullname;
}

// Районы для фильтра
$districts_raw = $DB->get_records('unics_districts', null, 'name ASC', 'id, name');
$districts_menu = [0 => '— все районы —'];
foreach ($districts_raw as $d) {
    $districts_menu[$d->id] = $d->name;
}

// Организации для фильтра (зависят от района)
$orgs_menu = [0 => '— все организации —'];
if ($filter_district > 0) {
    $orgs_raw = $DB->get_records('unics_organizations', ['district_id' => $filter_district, 'is_active' => 1], 'name ASC', 'id, name');
    foreach ($orgs_raw as $o) {
        $orgs_menu[$o->id] = $o->name;
    }
}

// Учащиеся с фильтрацией
$sql_where = 'u.deleted = 0';
$sql_params = [];

if ($filter_org > 0) {
    $sql_where .= ' AND s.organization_id = :org_id';
    $sql_params['org_id'] = $filter_org;
} elseif ($filter_district > 0) {
    $sql_where .= ' AND o.district_id = :dist_id';
    $sql_params['dist_id'] = $filter_district;
}

$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, u.id AS mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.category,
            o.name AS org_name
     FROM {unics_students} s
     JOIN {user} u ON u.id = s.mdl_user_id
     LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
     WHERE {$sql_where}
     ORDER BY u.lastname, u.firstname",
    $sql_params
);

// Если выбран курс — помечаем уже записанных
$enrolled_users = [];
if ($selected_course > 0) {
    $ctx_course = \context_course::instance($selected_course);
    foreach ($students as $s) {
        if (is_enrolled($ctx_course, (int)$s->mdl_user_id)) {
            $enrolled_users[$s->student_id] = true;
        }
    }
}

$categories = [1 => 'ОВЗ', 2 => 'Семейное', 3 => 'Лечение', 4 => 'Одарённый'];

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Запись учащихся на курс');

echo html_writer::link(
    new moodle_url('/local/unics/pages/users.php'),
    'Назад к пользователям',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// --- Форма фильтров и выбора курса ---
$filter_url = new moodle_url('/local/unics/pages/enrol_students.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url, 'class' => 'form-inline mb-3 gap-2']);

echo html_writer::tag('label', 'Курс:', ['class' => 'mr-1 font-weight-bold']);
echo html_writer::select($courses_menu, 'course_id', $selected_course, false, ['class' => 'form-control mr-3', 'style' => 'max-width:300px']);

echo html_writer::tag('label', 'Район:', ['class' => 'mr-1']);
echo html_writer::select($districts_menu, 'district_id', $filter_district, false, ['class' => 'form-control mr-2', 'style' => 'max-width:200px']);

echo html_writer::tag('label', 'Организация:', ['class' => 'mr-1']);
echo html_writer::select($orgs_menu, 'org_id', $filter_org, false, ['class' => 'form-control mr-2', 'style' => 'max-width:200px']);

echo html_writer::tag('button', 'Фильтр', ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

if (empty($students)) {
    echo $OUTPUT->notification('Учащихся по выбранному фильтру не найдено.', 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($selected_course <= 0) {
    echo $OUTPUT->notification('Выберите курс чтобы увидеть статус записи и записать учащихся.', 'info');
}

// --- Форма записи ---
$enrol_url = new moodle_url('/local/unics/pages/enrol_students.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $enrol_url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'course_id', 'value' => $selected_course]);

$table = new html_table();
$table->head = [
    html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'check_all']) . ' Все',
        ['for' => 'check_all']
    ),
    'Учащийся', 'Класс', 'Категория', 'Организация', 'Статус на курсе'
];
$table->attributes['class'] = 'table table-sm table-bordered table-hover';

foreach ($students as $s) {
    $fio = htmlspecialchars(trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? '')));
    $cat = $categories[$s->category] ?? '—';

    $is_enrolled = isset($enrolled_users[$s->student_id]);
    $status_badge = $is_enrolled
        ? html_writer::tag('span', 'Записан', ['class' => 'badge badge-success'])
        : html_writer::tag('span', 'Не записан', ['class' => 'badge badge-secondary']);

    $checkbox = html_writer::empty_tag('input', [
        'type'  => 'checkbox',
        'name'  => 'student_ids[]',
        'value' => $s->student_id,
        'class' => 'student-check',
        ($is_enrolled ? 'disabled' : '') => '',
    ]);

    $table->data[] = [
        $checkbox,
        html_writer::tag('strong', $fio),
        $s->class_number ? "{$s->class_number} кл." : '—',
        $cat,
        htmlspecialchars($s->org_name ?? '—'),
        $status_badge,
    ];
}

echo html_writer::table($table);

if ($selected_course > 0) {
    echo html_writer::tag('button', 'Записать выбранных на курс',
        ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
}

echo html_writer::end_tag('form');

// JS: checkbox "выбрать все"
echo html_writer::script("
document.getElementById('check_all').addEventListener('change', function() {
    document.querySelectorAll('.student-check:not([disabled])').forEach(function(cb) {
        cb.checked = document.getElementById('check_all').checked;
    });
});
");

echo $OUTPUT->footer();
