<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();
local_unics_require_not_student();
local_unics_require_manage_or_manageorg();

global $USER, $DB;

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$my_scope      = $is_admin_user
    ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
    : \local_unics\scope_checker::get_user_scope((int)$USER->id);
// Кнопка «На дашборд» вместо «К пользователям» — для всех, кому недоступна полная панель.
$is_scoped_role  = !$is_admin_user;
$methodist_org_id = $my_scope['organization_id'] ?? 0;

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

    // Scope-check для не-админов: педагог и каждый учащийся должны входить в скоуп.
    if (!$is_admin_user) {
        $t_uid = (int)$DB->get_field('unics_teachers', 'mdl_user_id', ['id' => $teacher_id]);
        if ($t_uid) { local_unics_require_manage_or_scope_user($t_uid); }
        foreach ($student_ids as $sid) {
            $s_uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => $sid]);
            if ($s_uid) { local_unics_require_manage_or_scope_user($s_uid); }
        }
    }

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
    $ts_id = required_param('id', PARAM_INT);
    if (!$is_admin_user) {
        // Проверяем, что student из этой пары входит в скоуп.
        $s_uid = $DB->get_field_sql(
            "SELECT s.mdl_user_id FROM {unics_teacher_student} ts
               JOIN {unics_students} s ON s.id = ts.student_id
              WHERE ts.id = :id", ['id' => $ts_id]);
        if ($s_uid) { local_unics_require_manage_or_scope_user((int)$s_uid); }
    }
    unics_user_manager::remove_teacher_student($ts_id);
    redirect(new moodle_url('/local/unics/pages/assign.php'),
        get_string('removed_ok', 'local_unics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'assign_ps' && confirm_sesskey()) {
    $parent_id  = required_param('parent_id', PARAM_INT);
    $student_id = required_param('student_id', PARAM_INT);
    if (!$is_admin_user) {
        // Родитель: parent_id — это mdl_user_id (см. unics_parent_student.parent_mdl_user_id).
        local_unics_require_manage_or_scope_user($parent_id);
        $s_uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => $student_id]);
        if ($s_uid) { local_unics_require_manage_or_scope_user($s_uid); }
    }
    $result = unics_user_manager::assign_parent_student($parent_id, $student_id);
    $msg  = $result ? get_string('assigned_ok', 'local_unics') : get_string('assign_error', 'local_unics');
    $type = $result ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect(new moodle_url('/local/unics/pages/assign.php'), $msg, null, $type);
}

if ($action === 'remove_ps' && confirm_sesskey()) {
    $ps_id = required_param('id', PARAM_INT);
    if (!$is_admin_user) {
        $s_uid = $DB->get_field_sql(
            "SELECT s.mdl_user_id FROM {unics_parent_student} ps
               JOIN {unics_students} s ON s.id = ps.student_id
              WHERE ps.id = :id", ['id' => $ps_id]);
        if ($s_uid) { local_unics_require_manage_or_scope_user((int)$s_uid); }
    }
    unics_user_manager::remove_parent_student($ps_id);
    redirect(new moodle_url('/local/unics/pages/assign.php'),
        get_string('removed_ok', 'local_unics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// ----------------------------------------------------------------
// Фильтры (GET)
// ----------------------------------------------------------------
$filter_org    = optional_param('filter_org',    0, PARAM_INT);
$filter_class  = optional_param('filter_class',  0, PARAM_INT);
// Буква класса — кириллица (А–Ж), поэтому PARAM_TEXT, не PARAM_ALPHA. Паттерн из users.php.
$filter_letter = optional_param('filter_letter', '', PARAM_TEXT);

// Пагинация двух таблиц-пар (свои pagevar, чтобы не сталкивались).
$ts_page = optional_param('tspage', 0, PARAM_INT);
$ps_page = optional_param('pspage', 0, PARAM_INT);
$perpage = 25;

// Если у пользователя скоуп = одна организация, форсим фильтр на неё.
if (!$is_admin_user && $methodist_org_id) {
    $filter_org = $methodist_org_id;
} else if (!$is_admin_user && $filter_org > 0
    && !\local_unics\scope_checker::user_can_access_org((int)$USER->id, $filter_org)) {
    // Не-админ выбрал орг вне скоупа — сбрасываем фильтр.
    $filter_org = 0;
}

// Списки для фильтров — для не-админа только орг своего скоупа.
$orgs_menu = [0 => '- все организации -'];
if ($is_admin_user) {
    $orgs_rows = $DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name');
} else {
    [$org_where, $org_params] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $orgs_rows = $DB->get_records_sql(
        "SELECT o.id, o.name FROM {unics_organizations} o
           WHERE o.is_active = 1 AND ({$org_where})
           ORDER BY o.name", $org_params);
}
foreach ($orgs_rows as $o) { $orgs_menu[$o->id] = $o->name; }

$classes_menu = [0 => '- все классы -'];
for ($i = 1; $i <= 11; $i++) {
    $classes_menu[$i] = "{$i} класс";
}

$letters_menu = ['' => '- все буквы -', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                 'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];

// ----------------------------------------------------------------
// Учащиеся с учётом фильтров
// ----------------------------------------------------------------
$where  = 'u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL';
$params = [];

if ($filter_org > 0) {
    $where .= ' AND s.organization_id = :org_id';
    $params['org_id'] = $filter_org;
} else if (!$is_admin_user) {
    // Не-админ без явного фильтра — ограничиваем своим скоупом.
    [$scope_where, $scope_params] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o2');
    $where .= " AND s.organization_id IN (SELECT o2.id FROM {unics_organizations} o2 WHERE {$scope_where})";
    $params = array_merge($params, $scope_params);
}
if ($filter_class > 0) {
    $where .= ' AND s.class_number = :class_num';
    $params['class_num'] = $filter_class;
}
if ($filter_letter !== '') {
    $where .= ' AND s.class_letter = :class_let';
    $params['class_let'] = $filter_letter;
}

$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, o.name AS org_name
     FROM {unics_students} s
     JOIN {user} u ON u.id = s.mdl_user_id
     LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
     WHERE {$where}
     ORDER BY u.lastname, u.firstname",
    $params
);

// ----------------------------------------------------------------
// Педагоги и родители. Для не-админов — только в пределах скоупа.
// ----------------------------------------------------------------
if ($is_admin_user) {
    $teachers    = unics_user_manager::get_teachers(0);
    $parents_raw = unics_user_manager::get_users(0, 8);
} else if ($methodist_org_id) {
    $teachers    = unics_user_manager::get_teachers($methodist_org_id);
    $parents_raw = unics_user_manager::get_users($methodist_org_id, 8);
} else {
    // Скоуп = район или регион: фильтр по нескольким орг через org_filter_sql.
    [$scope_where, $scope_params] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $teachers = $DB->get_records_sql(
        "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                t.id AS teacher_id, t.subjects, uo.unics_role
           FROM {user} u
           JOIN {unics_teachers} t       ON t.mdl_user_id = u.id
           JOIN {unics_user_org} uo      ON uo.mdl_user_id = u.id
           JOIN {unics_organizations} o  ON o.id = t.organization_id
          WHERE u.deleted = 0 AND uo.unics_role IN (4, 5, 6) AND ({$scope_where})
          ORDER BY u.lastname, u.firstname",
        $scope_params);
    $parents_raw = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname
           FROM {user} u
           JOIN {unics_user_org} uo      ON uo.mdl_user_id = u.id
           JOIN {unics_organizations} o  ON o.id = uo.organization_id
          WHERE u.deleted = 0 AND uo.unics_role = 8 AND ({$scope_where})
          ORDER BY u.lastname, u.firstname",
        $scope_params);
}

$teachers_menu = ['' => get_string('select_teacher', 'local_unics')];
foreach ($teachers as $t) {
    $teachers_menu[$t->teacher_id] = "{$t->lastname} {$t->firstname}";
}

$parents_menu = ['' => get_string('select_parent', 'local_unics')];
foreach ($parents_raw as $p) {
    $parents_menu[$p->id] = "{$p->lastname} {$p->firstname}";
}

$students_menu = ['' => get_string('select_student', 'local_unics')];
foreach ($students as $s) {
    $cls = $s->class_number ? " ({$s->class_number}" . ($s->class_letter ?? '') . " кл.)" : '';
    $students_menu[$s->student_id] = "{$s->lastname} {$s->firstname}" . $cls;
}

// ----------------------------------------------------------------
// Существующие привязки
// ----------------------------------------------------------------
// Фильтр существующих привязок по скоупу: для не-админа учащийся пары должен входить в скоуп.
if ($is_admin_user) {
    $ts_extra = '';
    $ps_extra = '';
    $extra_params = [];
} else {
    [$scope_where, $extra_params] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o_pair');
    $ts_extra = " AND s.organization_id IN (SELECT o_pair.id FROM {unics_organizations} o_pair WHERE {$scope_where})";
    $ps_extra = $ts_extra;
}

$ts_total = (int)$DB->count_records_sql(
    "SELECT COUNT(ts.id)
     FROM {unics_teacher_student} ts
     JOIN {unics_teachers} t  ON t.id  = ts.teacher_id
     JOIN {user} u_t          ON u_t.id = t.mdl_user_id
     JOIN {unics_students} s  ON s.id  = ts.student_id
     JOIN {user} u_s          ON u_s.id = s.mdl_user_id
     WHERE 1=1 {$ts_extra}",
    $extra_params
);
$ts_pairs = $DB->get_records_sql(
    "SELECT ts.id, u_t.lastname AS t_last, u_t.firstname AS t_first,
            u_s.lastname AS s_last, u_s.firstname AS s_first,
            s.class_number, s.class_letter
     FROM {unics_teacher_student} ts
     JOIN {unics_teachers} t  ON t.id  = ts.teacher_id
     JOIN {user} u_t          ON u_t.id = t.mdl_user_id
     JOIN {unics_students} s  ON s.id  = ts.student_id
     JOIN {user} u_s          ON u_s.id = s.mdl_user_id
     WHERE 1=1 {$ts_extra}
     ORDER BY u_t.lastname, u_s.lastname",
    $extra_params, $ts_page * $perpage, $perpage
);

$ps_total = (int)$DB->count_records_sql(
    "SELECT COUNT(ps.id)
     FROM {unics_parent_student} ps
     JOIN {user} u_p           ON u_p.id = ps.parent_mdl_user_id
     JOIN {unics_students} s   ON s.id   = ps.student_id
     JOIN {user} u_s           ON u_s.id = s.mdl_user_id
     WHERE 1=1 {$ps_extra}",
    $extra_params
);
$ps_pairs = $DB->get_records_sql(
    "SELECT ps.id, u_p.lastname AS p_last, u_p.firstname AS p_first,
            u_s.lastname AS s_last, u_s.firstname AS s_first
     FROM {unics_parent_student} ps
     JOIN {user} u_p           ON u_p.id = ps.parent_mdl_user_id
     JOIN {unics_students} s   ON s.id   = ps.student_id
     JOIN {user} u_s           ON u_s.id = s.mdl_user_id
     WHERE 1=1 {$ps_extra}
     ORDER BY u_p.lastname, u_s.lastname",
    $extra_params, $ps_page * $perpage, $perpage
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
echo local_unics_dashboard_button();
echo $OUTPUT->heading(get_string('assignments', 'local_unics'));

// ================================================================
// Блок: Педагог → Учащийся
// ================================================================
echo $OUTPUT->heading('Педагог - Учащийся', 4);

// --- Панель фильтров ---
$filter_url = new moodle_url('/local/unics/pages/assign.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'form-inline mb-3 p-3 bg-light border rounded']);
echo html_writer::tag('strong', 'Фильтр учащихся:', ['class' => 'mr-3']);

if (!$is_admin_user && $methodist_org_id) {
    // Скоуп пользователя — одна орг, фильтр форсирован, селект не показываем.
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

echo html_writer::tag('label', 'Буква:', ['class' => 'mr-1']);
echo html_writer::select($letters_menu, 'filter_letter', $filter_letter, false,
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
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_org',    'value' => $filter_org]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_class',  'value' => $filter_class]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_letter', 'value' => $filter_letter]);

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
        $cls = $s->class_number
            ? ' - ' . $s->class_number . ($s->class_letter ?? '') . ' кл.'
            : '';
        $fio = htmlspecialchars("{$s->lastname} {$s->firstname}"
            . $cls
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
        $cls_cell = $pair->class_number
            ? $pair->class_number . ($pair->class_letter ?? '') . ' кл.'
            : '-';
        $table->data[] = [
            "{$pair->t_last} {$pair->t_first}",
            "{$pair->s_last} {$pair->s_first}",
            $cls_cell,
            html_writer::link($remove_url, 'Убрать', ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
    echo local_unics_render_paging_bar($ts_total, $ts_page, $perpage,
        new moodle_url('/local/unics/pages/assign.php', [
            'filter_org'    => $filter_org,
            'filter_class'  => $filter_class,
            'filter_letter' => $filter_letter,
            'pspage'        => $ps_page,
        ]), 'tspage');
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
    echo local_unics_render_paging_bar($ps_total, $ps_page, $perpage,
        new moodle_url('/local/unics/pages/assign.php', [
            'filter_org'    => $filter_org,
            'filter_class'  => $filter_class,
            'filter_letter' => $filter_letter,
            'tspage'        => $ts_page,
        ]), 'pspage');
} else {
    echo html_writer::tag('p', 'Привязок нет', ['class' => 'text-muted']);
}

echo $OUTPUT->footer();
