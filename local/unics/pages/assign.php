<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/assign.php'));
$PAGE->set_title(get_string('assignments', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

// Получаем org_id текущего администратора
global $USER, $DB;
$user_org = $DB->get_record('unics_user_org', ['mdl_user_id' => $USER->id]);
$org_id   = $user_org ? (int)$user_org->organization_id : 0;

// Обработка действий
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'assign_ts' && confirm_sesskey()) {
    $teacher_id = required_param('teacher_id', PARAM_INT);
    $student_id = required_param('student_id', PARAM_INT);
    $result = unics_user_manager::assign_teacher_student($teacher_id, $student_id, $USER->id);
    $msg  = $result ? get_string('assigned_ok', 'local_unics') : get_string('assign_error', 'local_unics');
    $type = $result ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect(new moodle_url('/local/unics/pages/assign.php'), $msg, null, $type);
}

if ($action === 'remove_ts' && confirm_sesskey()) {
    $id = required_param('id', PARAM_INT);
    unics_user_manager::remove_teacher_student($id);
    redirect(new moodle_url('/local/unics/pages/assign.php'),
        get_string('removed_ok', 'local_unics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'assign_ps' && confirm_sesskey()) {
    $parent_id  = required_param('parent_id', PARAM_INT);
    $student_id = required_param('student_id', PARAM_INT);
    $result = unics_user_manager::assign_parent_student($parent_id, $student_id);
    $msg  = $result ? get_string('assigned_ok', 'local_unics') : get_string('assign_error', 'local_unics');
    $type = $result ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect(new moodle_url('/local/unics/pages/assign.php'), $msg, null, $type);
}

if ($action === 'remove_ps' && confirm_sesskey()) {
    $id = required_param('id', PARAM_INT);
    unics_user_manager::remove_parent_student($id);
    redirect(new moodle_url('/local/unics/pages/assign.php'),
        get_string('removed_ok', 'local_unics'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Данные для форм
$teachers = unics_user_manager::get_teachers($org_id);
$students  = unics_user_manager::get_students($org_id);

$teachers_menu = ['' => get_string('select_teacher', 'local_unics')];
foreach ($teachers as $t) {
    $teachers_menu[$t->teacher_id] = "{$t->lastname} {$t->firstname}";
}

$students_menu = ['' => get_string('select_student', 'local_unics')];
foreach ($students as $s) {
    $students_menu[$s->student_id] = "{$s->lastname} {$s->firstname} (кл. {$s->class_number})";
}

$parents_menu = ['' => get_string('select_parent', 'local_unics')];
$parents = unics_user_manager::get_users($org_id, 8); // роль 8 = родитель
foreach ($parents as $p) {
    $parents_menu[$p->id] = "{$p->lastname} {$p->firstname}";
}

// Существующие привязки
$ts_pairs = $DB->get_records_sql(
    "SELECT ts.id, u_t.lastname AS t_last, u_t.firstname AS t_first,
            u_s.lastname AS s_last, u_s.firstname AS s_first
     FROM {unics_teacher_student} ts
     JOIN {unics_teachers} t ON t.id = ts.teacher_id
     JOIN {user} u_t ON u_t.id = t.mdl_user_id
     JOIN {unics_students} s ON s.id = ts.student_id
     JOIN {user} u_s ON u_s.id = s.mdl_user_id
     WHERE t.organization_id = :org_id",
    ['org_id' => $org_id]
);

$ps_pairs = $DB->get_records_sql(
    "SELECT ps.id, u_p.lastname AS p_last, u_p.firstname AS p_first,
            u_s.lastname AS s_last, u_s.firstname AS s_first
     FROM {unics_parent_student} ps
     JOIN {user} u_p ON u_p.id = ps.parent_mdl_user_id
     JOIN {unics_students} s ON s.id = ps.student_id
     JOIN {user} u_s ON u_s.id = s.mdl_user_id
     WHERE s.organization_id = :org_id",
    ['org_id' => $org_id]
);

// --- Вывод ---
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('assignments', 'local_unics'));

$back_url = new moodle_url('/local/unics/pages/users.php');
echo html_writer::link($back_url, '← Назад к пользователям', ['class' => 'btn btn-outline-secondary mb-3']);

// === Блок: Педагог → Учащийся ===
echo $OUTPUT->heading(get_string('teacher_student', 'local_unics'), 4);

$assign_ts_url = new moodle_url('/local/unics/pages/assign.php', ['action' => 'assign_ts', 'sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $assign_ts_url, 'class' => 'form-inline mb-2']);
echo html_writer::select($teachers_menu, 'teacher_id', '', false, ['class' => 'form-control mr-2']);
echo html_writer::select($students_menu, 'student_id', '', false, ['class' => 'form-control mr-2']);
echo html_writer::tag('button', get_string('assign', 'local_unics'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// Таблица существующих привязок педагог → учащийся
if (!empty($ts_pairs)) {
    $table = new html_table();
    $table->head = ['Педагог', 'Учащийся', ''];
    $table->attributes['class'] = 'table table-sm table-bordered mb-4';
    foreach ($ts_pairs as $pair) {
        $remove_url = new moodle_url('/local/unics/pages/assign.php', [
            'action'  => 'remove_ts',
            'id'      => $pair->id,
            'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            "{$pair->t_last} {$pair->t_first}",
            "{$pair->s_last} {$pair->s_first}",
            html_writer::link($remove_url, get_string('remove', 'local_unics'),
                ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', 'Привязок нет', ['class' => 'text-muted mb-4']);
}

// === Блок: Родитель → Учащийся ===
echo $OUTPUT->heading(get_string('parent_student', 'local_unics'), 4);

$assign_ps_url = new moodle_url('/local/unics/pages/assign.php', ['action' => 'assign_ps', 'sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $assign_ps_url, 'class' => 'form-inline mb-2']);
echo html_writer::select($parents_menu, 'parent_id', '', false, ['class' => 'form-control mr-2']);
echo html_writer::select($students_menu, 'student_id', '', false, ['class' => 'form-control mr-2']);
echo html_writer::tag('button', get_string('assign', 'local_unics'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!empty($ps_pairs)) {
    $table = new html_table();
    $table->head = ['Родитель', 'Учащийся', ''];
    $table->attributes['class'] = 'table table-sm table-bordered';
    foreach ($ps_pairs as $pair) {
        $remove_url = new moodle_url('/local/unics/pages/assign.php', [
            'action'  => 'remove_ps',
            'id'      => $pair->id,
            'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            "{$pair->p_last} {$pair->p_first}",
            "{$pair->s_last} {$pair->s_first}",
            html_writer::link($remove_url, get_string('remove', 'local_unics'),
                ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', 'Привязок нет', ['class' => 'text-muted']);
}

echo $OUTPUT->footer();
