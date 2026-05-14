<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/group/lib.php');

require_login();
local_unics_require_not_student();

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$is_methodist  = !$is_admin_user
    && has_capability('local/unics:viewstudents', $sys_ctx)
    && local_unics_is_methodist();

if (!$is_admin_user && !$is_methodist) {
    require_capability('local/unics:manage', $sys_ctx); // throw с понятным сообщением
}

global $DB, $USER;

// Организация методиста — для последующего org-scoping списков.
$methodist_org_id = 0;
if ($is_methodist) {
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/enrol_students.php'));
$PAGE->set_title('Запись учащихся на курс — УНИКС');
$PAGE->set_heading('Запись учащихся на курс');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST: записать выбранных студентов на курс
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $course_id   = required_param('course_id',  PARAM_INT);
    $student_ids = optional_param_array('student_ids', [], PARAM_INT);
    $group_id    = optional_param('group_id',   0, PARAM_INT);   // 0 = без группы
    $new_group   = trim(optional_param('new_group', '', PARAM_TEXT)); // создать новую

    $student_ids = array_filter($student_ids);

    if (empty($course_id) || empty($student_ids)) {
        redirect(
            new moodle_url('/local/unics/pages/enrol_students.php'),
            'Выберите курс и хотя бы одного учащегося.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // Создаём новую группу если указана
    if ($new_group !== '') {
        $grp = new stdClass();
        $grp->courseid = $course_id;
        $grp->name     = $new_group;
        $group_id = groups_create_group($grp);
    }

    $enrol    = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);
    if (!$instance) {
        $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
        $enrol->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);
    }

    $student_role = $DB->get_record('role', ['shortname' => 'student'], 'id');
    $role_id  = $student_role ? (int)$student_role->id : 5;
    $ctx      = \context_course::instance($course_id);
    $enrolled = 0;
    $skipped  = 0;

    foreach ($student_ids as $student_id) {
        $student = $DB->get_record('unics_students', ['id' => $student_id]);
        if (!$student) continue;

        $mdl_uid = (int)$student->mdl_user_id;

        if (!is_enrolled($ctx, $mdl_uid)) {
            $enrol->enrol_user($instance, $mdl_uid, $role_id);
            $enrolled++;
        } else {
            $skipped++;
        }

        // Добавляем в группу (даже если уже был записан на курс)
        if ($group_id > 0) {
            if (!groups_is_member($group_id, $mdl_uid)) {
                groups_add_member($group_id, $mdl_uid);
            }
        }
    }

    $msg = "Записано: {$enrolled}";
    if ($skipped > 0) {
        $msg .= ", уже были записаны: {$skipped}";
    }
    if ($group_id > 0) {
        $grp_name = $DB->get_field('groups', 'name', ['id' => $group_id]);
        $msg .= ". Группа: «{$grp_name}»";
    }

    redirect(
        new moodle_url('/local/unics/pages/enrol_students.php', [
            'course_id' => $course_id,
        ]),
        $msg, null, \core\output\notification::NOTIFY_SUCCESS
    );
}

// ----------------------------------------------------------------
// Данные для страницы
// ----------------------------------------------------------------
$selected_course = optional_param('course_id',  0, PARAM_INT);
$filter_district = optional_param('district_id', 0, PARAM_INT);
$filter_org      = optional_param('org_id',      0, PARAM_INT);
$filter_class    = optional_param('class_num',   0, PARAM_INT);

// Методист видит только свою организацию: принудительно фиксируем фильтр.
if ($is_methodist && $methodist_org_id) {
    $filter_org      = $methodist_org_id;
    $filter_district = 0;
}

// Курсы
$courses_raw  = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname");
$courses_menu = [0 => '— выберите курс —'];
foreach ($courses_raw as $c) {
    $courses_menu[$c->id] = $c->fullname;
}

// Районы
$districts_raw  = $DB->get_records('unics_districts', null, 'name ASC', 'id, name');
$districts_menu = [0 => '— все районы —'];
foreach ($districts_raw as $d) {
    $districts_menu[$d->id] = $d->name;
}

// Организации (зависят от района)
$orgs_menu = [0 => '— все организации —'];
if ($filter_district > 0) {
    foreach ($DB->get_records('unics_organizations',
        ['district_id' => $filter_district, 'is_active' => 1], 'name ASC', 'id, name') as $o) {
        $orgs_menu[$o->id] = $o->name;
    }
}

// Классы
$classes_menu = [0 => '— все классы —'];
for ($i = 1; $i <= 11; $i++) { $classes_menu[$i] = "{$i} класс"; }

// Группы выбранного курса
$groups_menu = [0 => '— без группы —'];
if ($selected_course > 0) {
    foreach (groups_get_all_groups($selected_course) as $g) {
        $groups_menu[$g->id] = $g->name;
    }
}

// Учащиеся с фильтрацией
$sql_where  = 'u.deleted = 0';
$sql_params = [];
if ($filter_org > 0) {
    $sql_where .= ' AND s.organization_id = :org_id';
    $sql_params['org_id'] = $filter_org;
} elseif ($filter_district > 0) {
    $sql_where .= ' AND o.district_id = :dist_id';
    $sql_params['dist_id'] = $filter_district;
}
if ($filter_class > 0) {
    $sql_where .= ' AND s.class_number = :class_num';
    $sql_params['class_num'] = $filter_class;
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

// Помечаем уже записанных + их группы
$enrolled_users  = [];
$student_groups  = []; // mdl_user_id => [group_name, ...]
if ($selected_course > 0) {
    $ctx_course = \context_course::instance($selected_course);
    foreach ($students as $s) {
        if (is_enrolled($ctx_course, (int)$s->mdl_user_id)) {
            $enrolled_users[$s->student_id] = true;
        }
        // Группы пользователя в курсе
        $ugroups = groups_get_user_groups($selected_course, (int)$s->mdl_user_id);
        $gnames  = [];
        foreach ($ugroups[0] ?? [] as $gid) {
            $gnames[] = $DB->get_field('groups', 'name', ['id' => $gid]);
        }
        if ($gnames) {
            $student_groups[$s->mdl_user_id] = $gnames;
        }
    }
}

$categories = [1 => 'ОВЗ', 2 => 'Семейное', 3 => 'Лечение', 4 => 'Одарённый'];

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Запись учащихся на курс');

$back_url   = $is_methodist
    ? new moodle_url('/local/unics/pages/dashboard.php')
    : new moodle_url('/local/unics/pages/users.php');
$back_label = $is_methodist ? 'На дашборд' : 'Назад к пользователям';
echo html_writer::link($back_url, $back_label,
    ['class' => 'btn btn-outline-secondary btn-sm mb-3 mr-2']);
echo html_writer::link(
    new moodle_url('/local/unics/pages/enrol_teachers.php'),
    'Запись педагогов',
    ['class' => 'btn btn-outline-primary btn-sm mb-3']
);

// --- Форма фильтров ---
$filter_url = new moodle_url('/local/unics/pages/enrol_students.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'p-3 bg-light border rounded mb-4']);

echo html_writer::start_tag('div', ['class' => 'form-row align-items-end']);

// Курс
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('label', 'Курс', ['class' => 'font-weight-bold d-block mb-1']);
echo html_writer::select($courses_menu, 'course_id', $selected_course, false,
    ['class' => 'form-control', 'style' => 'min-width:250px', 'onchange' => 'this.form.submit()']);
echo html_writer::end_tag('div');

if ($is_methodist) {
    // Методист: район/организация фиксированы — отдаём как hidden,
    // чтобы фильтр сохранялся при submit, но не показывался селектором.
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'org_id',
        'value' => (int)$filter_org]);
} else {
    // Район
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Район', ['class' => 'd-block mb-1']);
    echo html_writer::select($districts_menu, 'district_id', $filter_district, false,
        ['class' => 'form-control', 'style' => 'min-width:170px']);
    echo html_writer::end_tag('div');

    // Организация
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Организация', ['class' => 'd-block mb-1']);
    echo html_writer::select($orgs_menu, 'org_id', $filter_org, false,
        ['class' => 'form-control', 'style' => 'min-width:170px']);
    echo html_writer::end_tag('div');
}

// Класс
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('label', 'Класс', ['class' => 'd-block mb-1']);
echo html_writer::select($classes_menu, 'class_num', $filter_class, false,
    ['class' => 'form-control', 'style' => 'min-width:120px']);
echo html_writer::end_tag('div');

// Кнопка
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // form-row
echo html_writer::end_tag('form');

if (empty($students)) {
    echo $OUTPUT->notification('Учащихся по выбранному фильтру не найдено.', 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($selected_course <= 0) {
    echo $OUTPUT->notification('Выберите курс, чтобы увидеть статус записи и записать учащихся.', 'info');
}

// --- Форма записи ---
$enrol_url = new moodle_url('/local/unics/pages/enrol_students.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $enrol_url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'course_id', 'value' => $selected_course]);

// --- Выбор группы (показываем только если курс выбран) ---
if ($selected_course > 0) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body py-2']);
    echo html_writer::start_tag('div', ['class' => 'form-row align-items-end']);

    // Существующая группа
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Добавить в группу курса:', ['class' => 'font-weight-bold d-block mb-1']);
    echo html_writer::select($groups_menu, 'group_id', 0, false,
        ['class' => 'form-control', 'style' => 'min-width:200px']);
    echo html_writer::end_tag('div');

    // Разделитель
    echo html_writer::start_tag('div', ['class' => 'col-auto align-self-end mb-2']);
    echo html_writer::tag('span', 'или', ['class' => 'text-muted']);
    echo html_writer::end_tag('div');

    // Новая группа
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Создать новую группу:', ['class' => 'd-block mb-1']);
    echo html_writer::empty_tag('input', [
        'type'        => 'text',
        'name'        => 'new_group',
        'class'       => 'form-control',
        'placeholder' => 'Название новой группы',
        'style'       => 'min-width:220px',
    ]);
    echo html_writer::end_tag('div');

    echo html_writer::tag('div',
        html_writer::tag('small',
            'Если указана новая группа — она будет создана и приоритетна над выбором из списка.',
            ['class' => 'text-muted']
        ),
        ['class' => 'col-12 mt-1']
    );

    echo html_writer::end_tag('div'); // form-row
    echo html_writer::end_tag('div'); // card-body
    echo html_writer::end_tag('div'); // card
}

// --- Таблица учащихся ---
$table = new html_table();
$table->head = [
    html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'check_all']) . ' Все',
        ['for' => 'check_all']
    ),
    'Учащийся', 'Класс', 'Категория', 'Организация', 'Статус', 'Группы в курсе'
];
$table->attributes['class'] = 'table table-sm table-bordered table-hover';

foreach ($students as $s) {
    $fio = htmlspecialchars(trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? '')));
    $cat = $categories[$s->category] ?? '—';

    $is_enrolled  = isset($enrolled_users[$s->student_id]);
    $status_badge = $is_enrolled
        ? html_writer::tag('span', 'Записан',    ['class' => 'badge badge-success'])
        : html_writer::tag('span', 'Не записан', ['class' => 'badge badge-secondary']);

    $checkbox = html_writer::empty_tag('input', [
        'type'  => 'checkbox',
        'name'  => 'student_ids[]',
        'value' => $s->student_id,
        'class' => 'student-check',
    ]);

    // Группы пользователя
    $gnames = $student_groups[$s->mdl_user_id] ?? [];
    $groups_cell = $gnames
        ? implode(', ', array_map(fn($g) => html_writer::tag('span', htmlspecialchars($g),
            ['class' => 'badge badge-info mr-1']), $gnames))
        : html_writer::tag('span', '—', ['class' => 'text-muted']);

    $row = new html_table_row([
        $checkbox,
        html_writer::tag('strong', $fio),
        $s->class_number ? "{$s->class_number} кл." : '—',
        $cat,
        htmlspecialchars($s->org_name ?? '—'),
        $status_badge,
        $groups_cell,
    ]);
    $row->attributes['class'] = $is_enrolled ? 'table-light' : '';
    $table->data[] = $row;
}

echo html_writer::table($table);

if ($selected_course > 0) {
    echo html_writer::tag('button', 'Записать выбранных на курс',
        ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
}

echo html_writer::end_tag('form');

echo html_writer::script("
document.getElementById('check_all').addEventListener('change', function() {
    document.querySelectorAll('.student-check').forEach(function(cb) {
        cb.checked = document.getElementById('check_all').checked;
    });
});

// Если заполнено поле новой группы — сбрасываем select существующей и наоборот
var newGroupInput = document.querySelector('input[name=new_group]');
var groupSelect   = document.querySelector('select[name=group_id]');
if (newGroupInput && groupSelect) {
    newGroupInput.addEventListener('input', function() {
        if (this.value.trim() !== '') groupSelect.value = '0';
    });
    groupSelect.addEventListener('change', function() {
        if (this.value !== '0') newGroupInput.value = '';
    });
}
");

echo $OUTPUT->footer();
