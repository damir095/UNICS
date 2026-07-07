<?php
// Управление курсами ПО КАТЕГОРИИ (предмету): архивирование = скрытие (course.visible).
// Решение 2026-05-26 (#5/#6): «архив» = тоггл visible, hard-delete НЕ делаем.
// 2026-06-15: ось фильтра переведена с организации на КАТЕГОРИЮ курсов (предмет) -
// курсы общие и организованы по предметным категориям ([[subject-binding-design]]).
// Права: системный админ (local/unics:manage) — все категории; методист/районный/
// региональный (local/unics:manageorg) — категории организаций своего скоупа.

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\identity\scope_checker;

require_login();
local_unics_require_not_student();

global $DB, $USER, $CFG, $OUTPUT, $PAGE;

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$is_manageorg  = !$is_admin_user && has_capability('local/unics:manageorg', $sys_ctx);

if (!$is_admin_user && !$is_manageorg) {
    require_capability('local/unics:manage', $sys_ctx);
}

// ---------------------------------------------------------------------------
// Доступные категории (предметы) по роли/скоупу.
// Админ — все категории курсов; не-админ — категории организаций своего скоупа.
// ---------------------------------------------------------------------------
$cats = []; // catid => подпись (имя + счётчик курсов)
if ($is_admin_user) {
    foreach ($DB->get_records('course_categories', null, 'name ASC',
        'id, name, coursecount') as $r) {
        $cats[(int)$r->id] = $r->name . ' (' . (int)$r->coursecount . ')';
    }
} else {
    [$ofw, $ofp] = scope_checker::org_filter_sql((int)$USER->id, 'o');
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT o.mdl_category_id AS catid
           FROM {unics_organizations} o
          WHERE o.is_active = 1 AND o.mdl_category_id IS NOT NULL AND ({$ofw})", $ofp);
    $catids = array_filter(array_map(fn($r) => (int)$r->catid, $rows));
    if ($catids) {
        [$cin, $cp] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'c');
        foreach ($DB->get_records_select('course_categories', "id $cin", $cp,
            'name ASC', 'id, name, coursecount') as $r) {
            $cats[(int)$r->id] = $r->name . ' (' . (int)$r->coursecount . ')';
        }
    }
}

$cat_id = optional_param('cat_id', 0, PARAM_INT);
// Если скоуп даёт ровно одну категорию — фиксируем её, селектор не показываем.
$fixed_cat = (!$is_admin_user && count($cats) === 1);
if ($fixed_cat) {
    $cat_id = (int)array_key_first($cats);
} else if ($cat_id > 0 && !isset($cats[$cat_id])) {
    $cat_id = 0; // категория вне доступа — сбрасываем
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

    // Доступ: админ — любой курс; не-админ — только курсы доступных ему категорий.
    if (!$is_admin_user) {
        require_capability('local/unics:manageorg', $sys_ctx);
        if (!isset($cats[(int)$course->category])) {
            throw new moodle_exception('nopermissions', 'error', '',
                'управление видимостью этого курса');
        }
    }

    require_once($CFG->dirroot . '/course/lib.php');
    $newshow = empty($course->visible);          // был скрыт → показать; был виден → скрыть
    course_change_visibility($course->id, $newshow);

    $redir = new moodle_url('/local/unics/pages/courses.php', ['cat_id' => $cat_id]);
    redirect($redir,
        $newshow ? 'Курс снова показан учащимся.' : 'Курс скрыт (архивирован). Статистика сохранена.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_context($sys_ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/courses.php', ['cat_id' => $cat_id]));
$PAGE->set_title('Курсы (по категории)');
$PAGE->set_heading('Курсы (по категории)');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo html_writer::tag('p',
    'Архивирование курса = скрытие от учащихся (как «скрыть курс» в Moodle). '
    . 'Оценки, материалы и статистика при этом сохраняются. Жёсткого удаления здесь нет. '
    . 'Курсы сгруппированы по категории (предмету).',
    ['class' => 'text-muted']);

if (empty($cats)) {
    echo $OUTPUT->notification(
        'Нет доступных категорий курсов. Категория задаётся организации '
        . '(mdl_category_id) или существует как предмет в каталоге курсов.',
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

// Селектор категории (если скоуп не зафиксирован на одну).
if (!$fixed_cat) {
    echo '<form method="get" class="row g-2 align-items-end mb-4">';
    echo '<div class="col-auto">';
    echo html_writer::tag('label', 'Категория (предмет)', ['class' => 'fw-bold d-block mb-1', 'for' => 'cat_id']);
    echo '<select name="cat_id" id="cat_id" class="form-control" style="max-width:400px" onchange="this.form.submit()">';
    echo '<option value="0">- Выберите категорию -</option>';
    foreach ($cats as $cid => $clabel) {
        $sel = ($cid == $cat_id) ? ' selected' : '';
        echo '<option value="' . $cid . '"' . $sel . '>' . s($clabel) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="col-auto"><button type="submit" class="btn btn-primary">Показать</button></div>';
    echo '</form>';
}

if ($cat_id <= 0) {
    echo $OUTPUT->footer();
    exit;
}

$catname = $DB->get_field('course_categories', 'name', ['id' => $cat_id]);
echo $OUTPUT->heading(s($catname ?: ('Категория #' . $cat_id)), 4);

$courses = $DB->get_records('course', ['category' => $cat_id],
    'visible DESC, fullname ASC', 'id, fullname, shortname, visible');

if (!$courses) {
    echo $OUTPUT->notification('В этой категории пока нет курсов.',
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
        . '<input type="hidden" name="cat_id"   value="' . $cat_id . '">'
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
