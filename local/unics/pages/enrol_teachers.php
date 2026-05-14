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
    require_capability('local/unics:manage', $sys_ctx);
}

global $DB, $USER;

$methodist_org_id = 0;
if ($is_methodist) {
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/enrol_teachers.php'));
$PAGE->set_title('Запись педагогов на курс — УНИКС');
$PAGE->set_heading('Запись педагогов на курс');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $course_id       = required_param('course_id',      PARAM_INT);
    $teacher_ids     = optional_param_array('teacher_ids', [], PARAM_INT);
    $group_id        = optional_param('group_id',       0, PARAM_INT);
    $new_group       = trim(optional_param('new_group', '', PARAM_TEXT));
    $role_type       = optional_param('role_type', 'editingteacher', PARAM_ALPHA);
    $separate_groups = optional_param('separate_groups', 0, PARAM_INT);

    $teacher_ids = array_filter($teacher_ids);

    if (empty($course_id) || empty($teacher_ids)) {
        redirect(
            new moodle_url('/local/unics/pages/enrol_teachers.php'),
            'Выберите курс и хотя бы одного педагога.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // Создаём новую группу если указана
    if ($new_group !== '') {
        $grp           = new stdClass();
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

    // Определяем роль Moodle для записи
    $role_shortname = ($role_type === 'teacher') ? 'teacher' : 'editingteacher';
    $role_rec = $DB->get_record('role', ['shortname' => $role_shortname], 'id');
    $role_id  = $role_rec ? (int)$role_rec->id : 3;

    $ctx      = \context_course::instance($course_id);
    $enrolled = 0;
    $skipped  = 0;

    foreach ($teacher_ids as $tid) {
        $teacher = $DB->get_record('unics_teachers', ['id' => $tid]);
        if (!$teacher) continue;

        $mdl_uid = (int)$teacher->mdl_user_id;

        if (!is_enrolled($ctx, $mdl_uid)) {
            $enrol->enrol_user($instance, $mdl_uid, $role_id);
            $enrolled++;
        } else {
            $skipped++;
        }

        if ($group_id > 0) {
            if (!groups_is_member($group_id, $mdl_uid)) {
                groups_add_member($group_id, $mdl_uid);
            }
        }
    }

    // Настройка раздельных групп на курсе
    if ($separate_groups) {
        $DB->set_field('course', 'groupmode',      1, ['id' => $course_id]); // 1 = Separate groups
        $DB->set_field('course', 'groupmodeforce', 1, ['id' => $course_id]); // не даём активностям переопределять
        // Запрещаем accessallgroups для editingteacher на уровне курса
        $ctx_course = \context_course::instance($course_id);
        $et_role = $DB->get_record('role', ['shortname' => 'editingteacher'], 'id');
        if ($et_role) {
            assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $et_role->id, $ctx_course->id, true);
        }
        // Для teacher (тьютор) — тоже
        $t_role = $DB->get_record('role', ['shortname' => 'teacher'], 'id');
        if ($t_role) {
            assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $t_role->id, $ctx_course->id, true);
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
    if ($separate_groups) {
        $msg .= '. Режим «Раздельные группы» включён.';
    }

    redirect(
        new moodle_url('/local/unics/pages/enrol_teachers.php', ['course_id' => $course_id]),
        $msg, null, \core\output\notification::NOTIFY_SUCCESS
    );
}

// ----------------------------------------------------------------
// Данные для страницы
// ----------------------------------------------------------------
$selected_course = optional_param('course_id', 0, PARAM_INT);
$filter_org      = optional_param('org_id',    0, PARAM_INT);

if ($is_methodist && $methodist_org_id) {
    $filter_org = $methodist_org_id;
}

// Курсы
$courses_raw  = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname");
$courses_menu = [0 => '— выберите курс —'];
foreach ($courses_raw as $c) {
    $courses_menu[$c->id] = $c->fullname;
}

// Организации
$orgs_raw  = $DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name');
$orgs_menu = [0 => '— все организации —'];
foreach ($orgs_raw as $o) {
    $orgs_menu[$o->id] = $o->name;
}

// Группы выбранного курса
$groups_menu = [0 => '— без группы —'];
if ($selected_course > 0) {
    foreach (groups_get_all_groups($selected_course) as $g) {
        $groups_menu[$g->id] = $g->name;
    }
}

// Педагоги с фильтрацией
$sql_where  = 'u.deleted = 0 AND uo.unics_role IN (4, 5, 6)';
$sql_params = [];
if ($filter_org > 0) {
    $sql_where .= ' AND t.organization_id = :org_id';
    $sql_params['org_id'] = $filter_org;
}

$teachers = $DB->get_records_sql(
    "SELECT t.id AS teacher_id, u.id AS mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            t.subjects, uo.unics_role,
            o.name AS org_name
     FROM {unics_teachers} t
     JOIN {user} u ON u.id = t.mdl_user_id
     JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
     LEFT JOIN {unics_organizations} o ON o.id = t.organization_id
     WHERE {$sql_where}
     ORDER BY u.lastname, u.firstname",
    $sql_params
);

// Помечаем уже записанных + их группы
$enrolled_users  = [];
$teacher_groups  = [];
if ($selected_course > 0) {
    $ctx_course = \context_course::instance($selected_course);
    foreach ($teachers as $t) {
        if (is_enrolled($ctx_course, (int)$t->mdl_user_id)) {
            $enrolled_users[$t->teacher_id] = true;
        }
        $ugroups = groups_get_user_groups($selected_course, (int)$t->mdl_user_id);
        $gnames  = [];
        foreach ($ugroups[0] ?? [] as $gid) {
            $gnames[] = $DB->get_field('groups', 'name', ['id' => $gid]);
        }
        if ($gnames) {
            $teacher_groups[$t->mdl_user_id] = $gnames;
        }
    }
}

$unics_role_labels = [4 => 'Методист', 5 => 'Педагог', 6 => 'Тьютор'];

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Запись педагогов на курс');

$back_url   = $is_methodist
    ? new moodle_url('/local/unics/pages/dashboard.php')
    : new moodle_url('/local/unics/pages/users.php');
$back_label = $is_methodist ? 'На дашборд' : 'Назад к пользователям';
echo html_writer::link($back_url, $back_label,
    ['class' => 'btn btn-outline-secondary btn-sm mb-3 mr-2']);
echo html_writer::link(
    new moodle_url('/local/unics/pages/enrol_students.php'),
    'Запись учащихся',
    ['class' => 'btn btn-outline-primary btn-sm mb-3']
);

// --- Форма фильтров ---
$filter_url = new moodle_url('/local/unics/pages/enrol_teachers.php');
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
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'org_id',
        'value' => (int)$filter_org]);
} else {
    // Организация
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Организация', ['class' => 'd-block mb-1']);
    echo html_writer::select($orgs_menu, 'org_id', $filter_org, false,
        ['class' => 'form-control', 'style' => 'min-width:200px']);
    echo html_writer::end_tag('div');
}

// Кнопка
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('form');

if (empty($teachers)) {
    echo $OUTPUT->notification('Педагогов по выбранному фильтру не найдено.', 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($selected_course <= 0) {
    echo $OUTPUT->notification('Выберите курс, чтобы увидеть статус записи и записать педагогов.', 'info');
}

// --- Форма записи ---
$enrol_url = new moodle_url('/local/unics/pages/enrol_teachers.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $enrol_url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'course_id', 'value' => $selected_course]);

// --- Выбор группы и роли (только если курс выбран) ---
if ($selected_course > 0) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body py-2']);
    echo html_writer::start_tag('div', ['class' => 'form-row align-items-end']);

    // Роль на курсе
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Роль на курсе:', ['class' => 'font-weight-bold d-block mb-1']);
    echo html_writer::select(
        ['editingteacher' => 'Учитель (с правом редактирования)', 'teacher' => 'Учитель (без редактирования)'],
        'role_type', 'editingteacher', false,
        ['class' => 'form-control', 'style' => 'min-width:240px']
    );
    echo html_writer::end_tag('div');

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

    // Раздельные группы
    echo html_writer::tag('div',
        html_writer::tag('label', '', ['class' => 'd-block']) .
        html_writer::tag('div',
            html_writer::empty_tag('input', [
                'type'    => 'checkbox',
                'name'    => 'separate_groups',
                'id'      => 'separate_groups',
                'value'   => '1',
                'class'   => 'mr-1',
                'checked' => 'checked',
            ]) .
            html_writer::tag('label',
                '<strong>Включить режим «Раздельные группы» для курса</strong>' .
                html_writer::tag('br', '') .
                html_writer::tag('small',
                    'Педагоги будут видеть только участников своей группы. ' .
                    'Устанавливает groupmode=1 и запрещает accessallgroups на уровне курса.',
                    ['class' => 'text-muted font-weight-normal']
                ),
                ['for' => 'separate_groups', 'class' => 'mb-0']
            ),
            ['class' => 'form-check']
        ),
        ['class' => 'col-12 mt-2']
    );

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

// --- Таблица педагогов ---
$table = new html_table();
$table->head = [
    html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'check_all']) . ' Все',
        ['for' => 'check_all']
    ),
    'Педагог', 'Роль', 'Предметы', 'Организация', 'Статус', 'Группы в курсе'
];
$table->attributes['class'] = 'table table-sm table-bordered table-hover';

foreach ($teachers as $t) {
    $fio  = htmlspecialchars(trim("{$t->lastname} {$t->firstname} " . ($t->middlename ?? '')));
    $role = $unics_role_labels[$t->unics_role] ?? '—';

    $is_enrolled  = isset($enrolled_users[$t->teacher_id]);
    $status_badge = $is_enrolled
        ? html_writer::tag('span', 'Записан',    ['class' => 'badge badge-success'])
        : html_writer::tag('span', 'Не записан', ['class' => 'badge badge-secondary']);

    $checkbox = html_writer::empty_tag('input', [
        'type'  => 'checkbox',
        'name'  => 'teacher_ids[]',
        'value' => $t->teacher_id,
        'class' => 'teacher-check',
    ]);

    $gnames = $teacher_groups[$t->mdl_user_id] ?? [];
    $groups_cell = $gnames
        ? implode(', ', array_map(fn($g) => html_writer::tag('span', htmlspecialchars($g),
            ['class' => 'badge badge-info mr-1']), $gnames))
        : html_writer::tag('span', '—', ['class' => 'text-muted']);

    $row = new html_table_row([
        $checkbox,
        html_writer::tag('strong', $fio),
        $role,
        htmlspecialchars($t->subjects ?? '—'),
        htmlspecialchars($t->org_name ?? '—'),
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
    document.querySelectorAll('.teacher-check').forEach(function(cb) {
        cb.checked = document.getElementById('check_all').checked;
    });
});

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
