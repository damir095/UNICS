<?php
/**
 * Делегирование курсов скоупу (роли v3 фаза 3, [[role-model-v3-2026-06-11]]).
 * Региональный администратор / методист назначает, на какие курсы муниципальные
 * методисты и методисты организаций могут записывать учеников. Делегируем
 * муниципалитету или организации (наследование вниз - см. delegation_manager).
 *
 * Доступ: системный админ (local/unics:manage) либо региональная роль
 * (region_admin / region_methodist, через local_unics_is_scoped_admin).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\delegation_manager;

require_login();
local_unics_require_not_student();

$syscontext = context_system::instance();
$is_admin = has_capability('local/unics:manage', $syscontext);
if (!$is_admin && !local_unics_is_scoped_admin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'делегирование курсов');
}

$baseurl = new moodle_url('/local/unics/pages/course_delegation.php');
$PAGE->set_context($syscontext);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Делегирование курсов - УНИКС');
$PAGE->set_heading('Делегирование курсов');

// ----------------------------------------------------------------
// POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'add') {
        $courseid = required_param('courseid', PARAM_INT);
        $scope = required_param('scope', PARAM_RAW_TRIMMED); // "district:ID" | "org:ID"
        [$stype, $sid] = array_pad(explode(':', $scope, 2), 2, '');
        if (in_array($stype, [delegation_manager::SCOPE_DISTRICT, delegation_manager::SCOPE_ORG], true)
                && (int)$sid > 0 && $courseid > 0) {
            delegation_manager::delegate($courseid, $stype, (int)$sid, (int)$USER->id);
            redirect($baseurl, 'Курс делегирован.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($baseurl, 'Выберите курс и кому делегировать.', null,
            \core\output\notification::NOTIFY_WARNING);
    }
    if ($action === 'remove') {
        delegation_manager::undelegate(required_param('delegation_id', PARAM_INT));
        redirect($baseurl, 'Делегирование снято.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($baseurl);
}

// ----------------------------------------------------------------
// Данные
// ----------------------------------------------------------------
// Курсы (кроме главной страницы сайта).
$courses = [];
foreach ($DB->get_records_select('course', 'id <> :site', ['site' => SITEID], 'fullname ASC', 'id, fullname') as $c) {
    $courses[(int)$c->id] = format_string($c->fullname);
}
// Скоупы делегирования: муниципалитеты + активные организации (один регион).
$districts = $DB->get_records('unics_districts', null, 'name ASC', 'id, name');
$orgs = $DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name');

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo html_writer::tag('p',
    'Методист муниципалитета или организации записывает учеников только на делегированные ему курсы. '
    . 'Региональные роли и педагог-создатель видят все курсы.', ['class' => 'text-muted']);

// --- Форма добавления ---
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline mb-4 p-3',
    'style' => 'background:#f5f6f8;border-radius:8px;gap:.5rem;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'add']);

$course_opts = '<option value="">- курс -</option>';
foreach ($courses as $cid => $cname) {
    $course_opts .= html_writer::tag('option', s($cname), ['value' => $cid]);
}
echo html_writer::tag('label', 'Курс: ', ['class' => 'mr-1', 'for' => 'del_course']);
echo html_writer::tag('select', $course_opts,
    ['name' => 'courseid', 'id' => 'del_course', 'class' => 'custom-select mr-2']);

$scope_opts = '<option value="">- кому делегировать -</option>';
if ($districts) {
    $scope_opts .= '<optgroup label="Муниципалитеты">';
    foreach ($districts as $d) {
        $scope_opts .= html_writer::tag('option', s($d->name), ['value' => 'district:' . (int)$d->id]);
    }
    $scope_opts .= '</optgroup>';
}
if ($orgs) {
    $scope_opts .= '<optgroup label="Организации">';
    foreach ($orgs as $o) {
        $scope_opts .= html_writer::tag('option', s($o->name), ['value' => 'org:' . (int)$o->id]);
    }
    $scope_opts .= '</optgroup>';
}
echo html_writer::tag('label', 'Кому: ', ['class' => 'mr-1', 'for' => 'del_scope']);
echo html_writer::tag('select', $scope_opts,
    ['name' => 'scope', 'id' => 'del_scope', 'class' => 'custom-select mr-2']);
echo html_writer::tag('button', 'Делегировать', ['type' => 'submit', 'class' => 'btn btn-success']);
echo html_writer::end_tag('form');

// --- Текущие делегирования (курс -> кому) ---
$rows = $DB->get_records('unics_course_delegation', null, 'courseid');
echo html_writer::tag('h4', 'Текущие делегирования');
if (!$rows) {
    echo html_writer::tag('p', 'Пока ничего не делегировано. По умолчанию методисты не видят ни одного курса для назначения.',
        ['class' => 'text-muted']);
} else {
    // группируем по курсу
    $by_course = [];
    foreach ($rows as $r) {
        $by_course[(int)$r->courseid][] = $r;
    }
    $t = new html_table();
    $t->attributes['class'] = 'table table-hover';
    $t->head = ['Курс', 'Делегирован'];
    foreach ($by_course as $cid => $dels) {
        $chips = '';
        foreach ($dels as $d) {
            $label = delegation_manager::scope_label($d->scope_type, (int)$d->scope_id);
            $rm = html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
            $rm .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $rm .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'remove']);
            $rm .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'delegation_id', 'value' => (int)$d->id]);
            $rm .= html_writer::tag('button', 'x', ['type' => 'submit', 'class' => 'btn btn-sm btn-link p-0 ml-1',
                'title' => 'Снять делегирование', 'style' => 'text-decoration:none;']);
            $rm .= html_writer::end_tag('form');
            $chips .= html_writer::tag('span', s($label) . $rm,
                ['class' => 'badge badge-light border mr-2 mb-1', 'style' => 'font-size:.95rem;padding:.4rem .6rem;']);
        }
        $t->data[] = [$courses[$cid] ?? ('Курс #' . $cid), $chips];
    }
    echo html_writer::table($t);
}

echo $OUTPUT->footer();
