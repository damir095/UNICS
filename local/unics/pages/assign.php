<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

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

global $USER, $DB;

$methodist_org_id = 0;
if ($is_methodist) {
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/assign.php'));
$PAGE->set_title(get_string('assignments', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST-действий
// ----------------------------------------------------------------
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($action === 'assign_ts' && confirm_sesskey()) {
    $teacher_id  = required_param('teacher_id', PARAM_INT);
    $student_ids = optional_param_array('student_ids', [], PARAM_INT);
    $student_ids = array_filter($student_ids);

    $added = 0; $skipped = 0;
    foreach ($student_ids as $sid) {
        unics_user_manager::assign_teacher_student($teacher_id, $sid, $USER->id) ? $added++ : $skipped++;
    }

    $msg  = $added > 0
        ? "Привязано: {$added}" . ($skipped > 0 ? ", уже существовало: {$skipped}" : '')
        : 'Все выбранные учащиеся уже привязаны к этому педагогу.';
    $type = $added > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect(new moodle_url('/local/unics/pages/assign.php'), $msg, null, $type);
}

if ($action === 'remove_ts' && confirm_sesskey()) {
    unics_user_manager::remove_teacher_student(required_param('id', PARAM_INT));
    redirect(new moodle_url('/local/unics/pages/assign.php'),
        get_string('removed_ok', 'local_unics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'assign_ps' && confirm_sesskey()) {
    $result = unics_user_manager::assign_parent_student(
        required_param('parent_id', PARAM_INT),
        required_param('student_id', PARAM_INT)
    );
    $msg  = $result ? get_string('assigned_ok', 'local_unics') : get_string('assign_error', 'local_unics');
    $type = $result ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect(new moodle_url('/local/unics/pages/assign.php'), $msg, null, $type);
}

if ($action === 'remove_ps' && confirm_sesskey()) {
    unics_user_manager::remove_parent_student(required_param('id', PARAM_INT));
    redirect(new moodle_url('/local/unics/pages/assign.php'),
        get_string('removed_ok', 'local_unics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// ----------------------------------------------------------------
// Фильтры (GET)
// ----------------------------------------------------------------
$filter_org   = optional_param('filter_org',   0, PARAM_INT);
$filter_class = optional_param('filter_class', 0, PARAM_INT);

// Методист видит только свою организацию.
if ($is_methodist && $methodist_org_id) {
    $filter_org = $methodist_org_id;
}

// Списки для фильтров
$orgs_menu = [0 => '- все организации -'];
foreach ($DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name') as $o) {
    $orgs_menu[$o->id] = $o->name;
}

$classes_menu = [0 => '- все классы -'];
for ($i = 1; $i <= 11; $i++) {
    $classes_menu[$i] = "{$i} класс";
}

// ----------------------------------------------------------------
// Учащиеся с учётом фильтров
// ----------------------------------------------------------------
$where  = 'u.deleted = 0';
$params = [];

if ($filter_org > 0) {
    $where .= ' AND s.organization_id = :org_id';
    $params['org_id'] = $filter_org;
}
if ($filter_class > 0) {
    $where .= ' AND s.class_number = :class_num';
    $params['class_num'] = $filter_class;
}

$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename,
            s.class_number, o.name AS org_name
     FROM {unics_students} s
     JOIN {user} u ON u.id = s.mdl_user_id
     LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
     WHERE {$where}
     ORDER BY u.lastname, u.firstname",
    $params
);

// ----------------------------------------------------------------
// Педагоги и родители. Для методиста - только своей организации.
// ----------------------------------------------------------------
$scope_org_id = ($is_methodist && $methodist_org_id) ? $methodist_org_id : 0;

$teachers     = unics_user_manager::get_teachers($scope_org_id);
$teachers_menu = ['' => get_string('select_teacher', 'local_unics')];
foreach ($teachers as $t) {
    $teachers_menu[$t->teacher_id] = "{$t->lastname} {$t->firstname}";
}

$parents_raw  = unics_user_manager::get_users($scope_org_id, 8);
$parents_menu = ['' => get_string('select_parent', 'local_unics')];
foreach ($parents_raw as $p) {
    $parents_menu[$p->id] = "{$p->lastname} {$p->firstname}";
}

$students_menu = ['' => get_string('select_student', 'local_unics')];
foreach ($students as $s) {
    $students_menu[$s->student_id] = "{$s->lastname} {$s->firstname}"
        . ($s->class_number ? " ({$s->class_number} кл.)" : '');
}

// ----------------------------------------------------------------
// Существующие привязки
// ----------------------------------------------------------------
$ts_pairs = $DB->get_records_sql(
    "SELECT ts.id, u_t.lastname AS t_last, u_t.firstname AS t_first,
            u_s.lastname AS s_last, u_s.firstname AS s_first,
            s.class_number
     FROM {unics_teacher_student} ts
     JOIN {unics_teachers} t  ON t.id  = ts.teacher_id
     JOIN {user} u_t          ON u_t.id = t.mdl_user_id
     JOIN {unics_students} s  ON s.id  = ts.student_id
     JOIN {user} u_s          ON u_s.id = s.mdl_user_id
     ORDER BY u_t.lastname, u_s.lastname"
);

$ps_pairs = $DB->get_records_sql(
    "SELECT ps.id, u_p.lastname AS p_last, u_p.firstname AS p_first,
            u_s.lastname AS s_last, u_s.firstname AS s_first
     FROM {unics_parent_student} ps
     JOIN {user} u_p           ON u_p.id = ps.parent_mdl_user_id
     JOIN {unics_students} s   ON s.id   = ps.student_id
     JOIN {user} u_s           ON u_s.id = s.mdl_user_id
     ORDER BY u_p.lastname, u_s.lastname"
);

// ----------------------------------------------------------------
// Уже привязанные student_id для каждого педагога (для визуального маркера)
// ----------------------------------------------------------------
$already_assigned = []; // [teacher_id => [student_id => true]]
foreach ($ts_pairs as $p) {
    // Получим teacher_id и student_id
}
$ts_map = $DB->get_records('unics_teacher_student', null, '', 'id, teacher_id, student_id');
foreach ($ts_map as $row) {
    $already_assigned[$row->teacher_id][$row->student_id] = true;
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('assignments', 'local_unics'));

$back_url   = $is_methodist
    ? new moodle_url('/local/unics/pages/dashboard.php')
    : new moodle_url('/local/unics/pages/users.php');
$back_label = $is_methodist ? 'На дашборд' : 'Назад к пользователям';
echo html_writer::link($back_url, $back_label,
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']);

// ================================================================
// Блок: Педагог → Учащийся
// ================================================================
echo $OUTPUT->heading('Педагог - Учащийся', 4);

// --- Панель фильтров ---
$filter_url = new moodle_url('/local/unics/pages/assign.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'form-inline mb-3 p-3 bg-light border rounded']);
echo html_writer::tag('strong', 'Фильтр учащихся:', ['class' => 'mr-3']);

if ($is_methodist) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_org',
        'value' => (int)$filter_org]);
} else {
    echo html_writer::tag('label', 'Организация:', ['class' => 'mr-1']);
    echo html_writer::select($orgs_menu, 'filter_org', $filter_org, false,
        ['class' => 'form-control form-control-sm mr-3']);
}

echo html_writer::tag('label', 'Класс:', ['class' => 'mr-1']);
echo html_writer::select($classes_menu, 'filter_class', $filter_class, false,
    ['class' => 'form-control form-control-sm mr-3']);

echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-sm btn-secondary']);
echo html_writer::end_tag('form');

// --- Форма привязки ---
$assign_url = new moodle_url('/local/unics/pages/assign.php', [
    'action'  => 'assign_ts',
    'sesskey' => sesskey(),
]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $assign_url]);
// Сохраняем фильтры в hidden чтобы после POST вернуться с теми же фильтрами
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_org',   'value' => $filter_org]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_class', 'value' => $filter_class]);

echo html_writer::start_tag('div', ['class' => 'row']);

// Левая колонка: педагог
echo html_writer::start_tag('div', ['class' => 'col-md-4 mb-3']);
echo html_writer::tag('label', 'Педагог:', ['class' => 'font-weight-bold']);
echo html_writer::select($teachers_menu, 'teacher_id', '', false,
    ['class' => 'form-control', 'id' => 'teacher_select']);
echo html_writer::end_tag('div');

// Правая колонка: чекбоксы учащихся
echo html_writer::start_tag('div', ['class' => 'col-md-8 mb-3']);
echo html_writer::tag('label', 'Учащиеся:', ['class' => 'font-weight-bold d-block']);

if (empty($students)) {
    echo html_writer::tag('p', 'Нет учащихся по выбранному фильтру.', ['class' => 'text-muted']);
} else {
    // Кнопка "выбрать всех"
    echo html_writer::tag('a', 'Выбрать всех', [
        'href'    => '#',
        'onclick' => 'document.querySelectorAll(".student-cb").forEach(cb=>cb.checked=true);return false;',
        'class'   => 'btn btn-link btn-sm p-0 mr-3',
    ]);
    echo html_writer::tag('a', 'Снять все', [
        'href'    => '#',
        'onclick' => 'document.querySelectorAll(".student-cb").forEach(cb=>cb.checked=false);return false;',
        'class'   => 'btn btn-link btn-sm p-0',
    ]);

    echo html_writer::start_tag('div', [
        'class' => 'border rounded p-2 mt-1',
        'style' => 'max-height:280px;overflow-y:auto;background:#fff',
    ]);

    foreach ($students as $s) {
        $fio = htmlspecialchars("{$s->lastname} {$s->firstname}"
            . ($s->class_number ? " - {$s->class_number} кл." : '')
            . ($s->org_name ? " ({$s->org_name})" : ''));

        echo html_writer::start_tag('div', ['class' => 'form-check']);
        echo html_writer::empty_tag('input', [
            'type'  => 'checkbox',
            'name'  => 'student_ids[]',
            'value' => $s->student_id,
            'id'    => "s_{$s->student_id}",
            'class' => 'form-check-input student-cb',
        ]);
        echo html_writer::tag('label', $fio, [
            'for'   => "s_{$s->student_id}",
            'class' => 'form-check-label',
        ]);
        echo html_writer::end_tag('div');
    }

    echo html_writer::end_tag('div');
}

echo html_writer::end_tag('div'); // col
echo html_writer::end_tag('div'); // row

echo html_writer::tag('button', 'Привязать выбранных',
    ['type' => 'submit', 'class' => 'btn btn-primary mb-4']);
echo html_writer::end_tag('form');

// --- Таблица существующих привязок ---
if (!empty($ts_pairs)) {
    $table = new html_table();
    $table->head = ['Педагог', 'Учащийся', 'Класс', ''];
    $table->attributes['class'] = 'table table-sm table-bordered mb-4';
    foreach ($ts_pairs as $pair) {
        $remove_url = new moodle_url('/local/unics/pages/assign.php', [
            'action' => 'remove_ts', 'id' => $pair->id, 'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            "{$pair->t_last} {$pair->t_first}",
            "{$pair->s_last} {$pair->s_first}",
            $pair->class_number ? "{$pair->class_number} кл." : '-',
            html_writer::link($remove_url, 'Убрать', ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', 'Привязок нет', ['class' => 'text-muted mb-4']);
}

// ================================================================
// Блок: Родитель → Учащийся
// ================================================================
echo $OUTPUT->heading('Родитель - Учащийся', 4);

$assign_ps_url = new moodle_url('/local/unics/pages/assign.php',
    ['action' => 'assign_ps', 'sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $assign_ps_url,
    'class' => 'form-inline mb-2']);
echo html_writer::select($parents_menu, 'parent_id', '', false, ['class' => 'form-control mr-2']);
echo html_writer::select($students_menu, 'student_id', '', false, ['class' => 'form-control mr-2']);
echo html_writer::tag('button', 'Привязать', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!empty($ps_pairs)) {
    $table = new html_table();
    $table->head = ['Родитель', 'Учащийся', ''];
    $table->attributes['class'] = 'table table-sm table-bordered';
    foreach ($ps_pairs as $pair) {
        $remove_url = new moodle_url('/local/unics/pages/assign.php', [
            'action' => 'remove_ps', 'id' => $pair->id, 'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            "{$pair->p_last} {$pair->p_first}",
            "{$pair->s_last} {$pair->s_first}",
            html_writer::link($remove_url, 'Убрать', ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', 'Привязок нет', ['class' => 'text-muted']);
}

echo $OUTPUT->footer();
