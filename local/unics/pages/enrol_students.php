<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/group/lib.php');

require_login();
local_unics_require_not_student();
local_unics_require_manage_or_manageorg();

global $DB, $USER;

$sys_ctx          = context_system::instance();
$is_admin_user    = has_capability('local/unics:manage', $sys_ctx);
$is_scoped_role   = !$is_admin_user;
$my_scope         = $is_admin_user
    ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
    : \local_unics\identity\scope_checker::get_user_scope((int)$USER->id);
$methodist_org_id = $my_scope['organization_id'] ?? 0;

// Делегирование курсов (роли v3 фаза 3, [[role-model-v3-2026-06-11]]): муниципальный
// методист / методист организации записывает учеников только на делегированные ему
// курсы. NULL = фильтр не применяется (региональные роли, педагог-создатель, админ).
$deleg_course_ids = \local_unics\identity\delegation_manager::get_delegated_course_ids_for_user((int)$USER->id);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/enrol_students.php'));
$PAGE->set_title('Запись учащихся на курс - УНИКС');
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

    // Защита: курс должен быть делегирован (для методиста). Региональные роли/админ - $deleg_course_ids = null.
    if ($deleg_course_ids !== null && !in_array((int)$course_id, $deleg_course_ids, true)) {
        redirect(
            new moodle_url('/local/unics/pages/enrol_students.php'),
            'Этот курс вам не делегирован.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // Scope-check: каждый учащийся должен входить в скоуп текущего пользователя.
    if (!$is_admin_user) {
        foreach ($student_ids as $sid) {
            $s_uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => $sid]);
            if ($s_uid) { local_unics_require_manage_or_scope_user($s_uid); }
        }
    }

    // Создаём новую группу если указана
    if ($new_group !== '') {
        $grp = new stdClass();
        $grp->courseid = $course_id;
        $grp->name     = $new_group;
        $group_id = groups_create_group($grp);
        // #12 (наблюдение 2026-05-28): создатель курса при separate-groups не видит
        // участников, пока сам не в группе. Авто-добавляем себя в новую группу,
        // если уже записан на курс.
        if (is_enrolled(\context_course::instance($course_id), (int)$USER->id)) {
            groups_add_member($group_id, (int)$USER->id);
        }
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
$filter_letter   = optional_param('class_letter', '', PARAM_TEXT); // буква класса (кириллица)

// Если у пользователя скоуп = одна орг, форсим фильтр на неё.
if (!$is_admin_user && $methodist_org_id) {
    $filter_org      = $methodist_org_id;
    $filter_district = 0;
} else if (!$is_admin_user) {
    // Не-админ выбрал орг/район вне скоупа — сбрасываем.
    if ($filter_org > 0
        && !\local_unics\identity\scope_checker::user_can_access_org((int)$USER->id, $filter_org)) {
        $filter_org = 0;
    }
    if ($filter_district > 0
        && !\local_unics\identity\scope_checker::user_can_access_district((int)$USER->id, $filter_district)) {
        $filter_district = 0;
    }
}

// Курсы
$courses_raw  = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname");
// Фильтр делегирования: методисту показываем только делегированные курсы.
if ($deleg_course_ids !== null) {
    $allow = array_fill_keys($deleg_course_ids, true);
    $courses_raw = array_filter($courses_raw, fn($c) => isset($allow[(int)$c->id]));
}
$courses_menu = [0 => '- выберите курс -'];
foreach ($courses_raw as $c) {
    $courses_menu[$c->id] = $c->fullname;
}

// Районы — фильтр по скоупу.
if ($is_admin_user) {
    $districts_raw = $DB->get_records('unics_districts', null, 'name ASC', 'id, name');
} else if ($my_scope['region_id'] !== null) {
    $districts_raw = $DB->get_records('unics_districts',
        ['region_id' => $my_scope['region_id']], 'name ASC', 'id, name');
} else if ($my_scope['district_id'] !== null) {
    $districts_raw = $DB->get_records('unics_districts',
        ['id' => $my_scope['district_id']], 'name ASC', 'id, name');
} else {
    $districts_raw = [];
}
$districts_menu = [0 => '- все муниципалитеты -'];
foreach ($districts_raw as $d) {
    $districts_menu[$d->id] = $d->name;
}

// Организации (зависят от района) — фильтр по скоупу, если выбран не админ.
$orgs_menu = [0 => '- все организации -'];
if ($filter_district > 0) {
    $org_filters = ['district_id' => $filter_district, 'is_active' => 1];
    foreach ($DB->get_records('unics_organizations', $org_filters, 'name ASC', 'id, name') as $o) {
        if ($is_admin_user
            || \local_unics\identity\scope_checker::user_can_access_org((int)$USER->id, (int)$o->id)) {
            $orgs_menu[$o->id] = $o->name;
        }
    }
}

// Классы
$classes_menu = [0 => '- все классы -'];
for ($i = 1; $i <= 11; $i++) { $classes_menu[$i] = "{$i} класс"; }

// Буквы класса (кириллица А–Ж)
$letters_menu = ['' => '- все буквы -', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                 'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];

// Группы выбранного курса
$groups_menu = [0 => '- без группы -'];
if ($selected_course > 0) {
    foreach (groups_get_all_groups($selected_course) as $g) {
        $groups_menu[$g->id] = $g->name;
    }
}

// Учащиеся с фильтрацией
$sql_where  = 'u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL';
$sql_params = [];
if ($filter_org > 0) {
    $sql_where .= ' AND s.organization_id = :org_id';
    $sql_params['org_id'] = $filter_org;
} elseif ($filter_district > 0) {
    $sql_where .= ' AND o.district_id = :dist_id';
    $sql_params['dist_id'] = $filter_district;
} else if (!$is_admin_user) {
    // Не-админ без явного фильтра — ограничиваем своим скоупом.
    [$scope_where, $scope_params] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o2');
    $sql_where .= " AND s.organization_id IN (SELECT o2.id FROM {unics_organizations} o2 WHERE {$scope_where})";
    $sql_params = array_merge($sql_params, $scope_params);
}
if ($filter_class > 0) {
    $sql_where .= ' AND s.class_number = :class_num';
    $sql_params['class_num'] = $filter_class;
}
if ($filter_letter !== '') {
    $sql_where .= ' AND s.class_letter = :class_let';
    $sql_params['class_let'] = $filter_letter;
}

// Категории/ОВЗ - из нормализованных таблиц с прежними алиасами (этап 2.6-B).
[$catsql, $ovzsql] = \local_unics\identity\student_helper::taxonomy_select_sql('s');
$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, u.id AS mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, {$catsql}, {$ovzsql},
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
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Запись учащихся на курс');

echo html_writer::link(
    new moodle_url('/local/unics/pages/enrol_teachers.php'),
    'Запись педагогов',
    ['class' => 'btn btn-outline-primary btn-sm mb-3']
);

// --- Форма фильтров ---
$filter_url = new moodle_url('/local/unics/pages/enrol_students.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'p-3 bg-light border rounded mb-4']);

echo html_writer::start_tag('div', ['class' => 'row g-2 align-items-end']);

// Курс
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('label', 'Курс', ['class' => 'font-weight-bold d-block mb-1']);
echo html_writer::select($courses_menu, 'course_id', $selected_course, false,
    ['class' => 'form-control', 'style' => 'min-width:250px', 'onchange' => 'this.form.submit()']);
echo html_writer::end_tag('div');

if (!$is_admin_user && $methodist_org_id) {
    // Скоуп = одна орг, фиксируем через hidden.
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'org_id',
        'value' => (int)$filter_org]);
} else {
    // Муниципалитет
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Муниципалитет', ['class' => 'd-block mb-1']);
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

// Буква класса
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('label', 'Буква', ['class' => 'd-block mb-1']);
echo html_writer::select($letters_menu, 'class_letter', $filter_letter, false,
    ['class' => 'form-control', 'style' => 'min-width:110px']);
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
    echo html_writer::start_tag('div', ['class' => 'row g-2 align-items-end']);

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
            'Если указана новая группа - она будет создана и приоритетна над выбором из списка.',
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
    $cat = \local_unics\identity\student_helper::format_categories($s) ?: '-';

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
        : html_writer::tag('span', '-', ['class' => 'text-muted']);

    $row = new html_table_row([
        $checkbox,
        html_writer::tag('strong', $fio),
        $s->class_number ? "{$s->class_number} кл." : '-',
        $cat,
        htmlspecialchars($s->org_name ?? '-'),
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

// Если заполнено поле новой группы - сбрасываем select существующей и наоборот
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
