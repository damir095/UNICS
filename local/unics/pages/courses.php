<?php
// Управление курсами организации: архивирование = скрытие (course.visible).
// Решение 2026-05-26 (#5/#6): «архив» = тоггл visible, hard-delete НЕ делаем.
// Права: системный админ (local/unics:manage) ИЛИ методист/районный админ
// (local/unics:manageorg) в пределах своего скоупа.

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\scope_checker;

require_login();
local_unics_require_not_student();

global $DB, $USER, $CFG, $OUTPUT, $PAGE;

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$is_manageorg  = !$is_admin_user && has_capability('local/unics:manageorg', $sys_ctx);

if (!$is_admin_user && !$is_manageorg) {
    require_capability('local/unics:manage', $sys_ctx);
}

$org_id = optional_param('org_id', 0, PARAM_INT);

// Скоуп: если он зафиксирован на одну организацию — выбор не показываем.
$scope = $is_admin_user
    ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
    : scope_checker::get_user_scope((int)$USER->id);
$fixed_org = (!$is_admin_user && !empty($scope['organization_id']));
if ($fixed_org) {
    $org_id = (int)$scope['organization_id'];
} else if (!$is_admin_user && $org_id > 0
    && !scope_checker::user_can_access_org((int)$USER->id, $org_id)) {
    $org_id = 0; // организация вне скоупа — сбрасываем
}

// ---------------------------------------------------------------------------
// POST: переключение видимости курса (архив). PRG — после успеха redirect.
// ---------------------------------------------------------------------------
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'toggle_visibility' && confirm_sesskey()) {
    $courseid = required_param('courseid', PARAM_INT);
    $course   = $DB->get_record('course', ['id' => $courseid],
        'id, category, visible, fullname', MUST_EXIST);

    if ($course->id == SITEID) {
        throw new moodle_exception('cannotedit', 'error'); // главная страница — не курс
    }

    // Организация-владелец курса = та, чья категория совпадает с course.category.
    $owner_org = $DB->get_record('unics_organizations',
        ['mdl_category_id' => $course->category], 'id, name', IGNORE_MULTIPLE);

    // Scope-проверка: не-админ может трогать только курсы орг своего скоупа.
    if (!$is_admin_user) {
        require_capability('local/unics:manageorg', $sys_ctx);
        if (!$owner_org || !scope_checker::user_can_access_org((int)$USER->id, (int)$owner_org->id)) {
            throw new moodle_exception('nopermissions', 'error', '',
                'управление видимостью этого курса');
        }
    }

    require_once($CFG->dirroot . '/course/lib.php');
    $newshow = empty($course->visible);          // был скрыт → показать; был виден → скрыть
    course_change_visibility($course->id, $newshow);

    $redir = new moodle_url('/local/unics/pages/courses.php', ['org_id' => $org_id]);
    redirect($redir,
        $newshow ? 'Курс снова показан учащимся.' : 'Курс скрыт (архивирован). Статистика сохранена.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_context($sys_ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/courses.php', ['org_id' => $org_id]));
$PAGE->set_title('Курсы организации');
$PAGE->set_heading('Курсы организации');
$PAGE->set_pagelayout('admin');

// Список организаций для селектора — по скоупу.
$orgs = [];
if ($is_admin_user) {
    foreach ($DB->get_records_select('unics_organizations', 'is_active = 1',
        null, 'name ASC', 'id, name') as $r) {
        $orgs[(int)$r->id] = $r->name;
    }
} else {
    [$ofw, $ofp] = scope_checker::org_filter_sql((int)$USER->id, 'o');
    foreach ($DB->get_records_sql(
        "SELECT o.id, o.name FROM {unics_organizations} o
          WHERE o.is_active = 1 AND ({$ofw}) ORDER BY o.name", $ofp) as $r) {
        $orgs[(int)$r->id] = $r->name;
    }
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo html_writer::tag('p',
    'Архивирование курса = скрытие от учащихся (как «скрыть курс» в Moodle). '
    . 'Оценки, материалы и статистика при этом сохраняются. Жёсткого удаления здесь нет.',
    ['class' => 'text-muted']);

// Селектор организации (если скоуп не зафиксирован на одну орг).
if (!$fixed_org) {
    echo '<form method="get" class="row g-2 align-items-end mb-4">';
    echo '<div class="col-auto">';
    echo html_writer::tag('label', 'Организация', ['class' => 'fw-bold d-block mb-1', 'for' => 'org_id']);
    echo '<select name="org_id" id="org_id" class="form-control" style="max-width:400px" onchange="this.form.submit()">';
    echo '<option value="0">- Выберите организацию -</option>';
    foreach ($orgs as $oid => $olabel) {
        $sel = ($oid == $org_id) ? ' selected' : '';
        echo '<option value="' . $oid . '"' . $sel . '>' . s($olabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="col-auto"><button type="submit" class="btn btn-primary">Показать</button></div>';
    echo '</form>';
}

if ($org_id <= 0) {
    echo $OUTPUT->footer();
    exit;
}

// Организация выбрана — проверяем доступ ещё раз и грузим её категорию.
if (!$is_admin_user && !scope_checker::user_can_access_org((int)$USER->id, $org_id)) {
    throw new moodle_exception('nopermissions', 'error', '', 'просмотр курсов этой организации');
}
$org = $DB->get_record('unics_organizations', ['id' => $org_id],
    'id, name, mdl_category_id', MUST_EXIST);

echo $OUTPUT->heading(s($org->name), 4);

if (empty($org->mdl_category_id)) {
    echo $OUTPUT->notification(
        'У организации не задана категория курсов (mdl_category_id). '
        . 'Курсы привязываются к категории при создании через «Шаблоны курсов».',
        \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

$courses = $DB->get_records('course', ['category' => $org->mdl_category_id],
    'visible DESC, fullname ASC', 'id, fullname, shortname, visible');

if (!$courses) {
    echo $OUTPUT->notification('В категории этой организации пока нет курсов.',
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

echo '<table class="table table-sm table-bordered align-middle">';
echo '<thead class="table-light"><tr>'
    . '<th>Курс</th><th>Краткое имя</th><th>Статус</th><th>Учащихся</th><th>Действие</th>'
    . '</tr></thead><tbody>';

foreach ($courses as $c) {
    $coursectx = context_course::instance($c->id);
    $nstudents = count_enrolled_users($coursectx);

    $status = $c->visible
        ? '<span class="badge bg-success">Активен</span>'
        : '<span class="badge bg-secondary">Скрыт (архив)</span>';

    // Кнопка-тоггл (POST + sesskey, с подтверждением при скрытии).
    $btn_label = $c->visible ? 'Скрыть (архивировать)' : 'Показать';
    $btn_class = $c->visible ? 'btn-outline-warning' : 'btn-outline-success';
    $confirm   = $c->visible
        ? 'onsubmit="return confirm(\'Скрыть курс от учащихся? Статистика сохранится, действие обратимо.\')"'
        : '';
    $toggle = '<form method="post" class="d-inline" ' . $confirm . '>'
        . '<input type="hidden" name="action"   value="toggle_visibility">'
        . '<input type="hidden" name="courseid" value="' . $c->id . '">'
        . '<input type="hidden" name="org_id"   value="' . $org_id . '">'
        . '<input type="hidden" name="sesskey"  value="' . sesskey() . '">'
        . '<button type="submit" class="btn btn-sm ' . $btn_class . '">' . $btn_label . '</button>'
        . '</form>';

    $course_link = html_writer::link(
        new moodle_url('/course/view.php', ['id' => $c->id]), s($c->fullname),
        ['target' => '_blank']);

    echo '<tr>';
    echo '<td>' . $course_link . '</td>';
    echo '<td>' . s($c->shortname) . '</td>';
    echo '<td>' . $status . '</td>';
    echo '<td>' . $nstudents . '</td>';
    echo '<td>' . $toggle . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

echo $OUTPUT->footer();
