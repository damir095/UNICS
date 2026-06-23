<?php
/**
 * Ревью адаптивных предложений (S2 фаза 2). Список открытых предложений, которые
 * текущий пользователь вправе рассмотреть (path_manager::can_edit): админ - все,
 * методист - свой скоуп, педагог - привязанные ученики. Действия «Принять» (применить)
 * и «Отклонить». Применение/отклонение - suggestion_service (движок фазы 1).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/suggestion_service.php');
require_once(__DIR__ . '/../classes/path_manager.php');

use local_unics\suggestion_service;

require_login();
local_unics_require_not_student();

global $USER, $DB;
$ctx = context_system::instance();
require_capability('local/unics:viewstudents', $ctx);

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/adaptive_suggestions.php'));
$PAGE->set_title('Адаптивные предложения - УНИКС');
$PAGE->set_heading('Адаптивные предложения');
$PAGE->set_pagelayout('standard');

// ----------------------------------------------------------------
// POST: принять/отклонить предложение.
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $suggestion_id = required_param('suggestion_id', PARAM_INT);
    $action        = required_param('action', PARAM_ALPHA);
    $s = suggestion_service::get($suggestion_id);

    if (!$s || (int)$s->status !== suggestion_service::STATUS_PENDING) {
        redirect($PAGE->url, 'Предложение уже рассмотрено или не найдено.',
            null, \core\output\notification::NOTIFY_WARNING);
    } else if (!\local_unics\path_manager::can_edit((int)$s->student_id, (int)$USER->id)) {
        redirect($PAGE->url, 'Недостаточно прав для этого предложения.',
            null, \core\output\notification::NOTIFY_WARNING);
    } else if ($action === 'approve') {
        suggestion_service::approve($suggestion_id, (int)$USER->id);
        redirect($PAGE->url, 'Предложение принято и применено.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'reject') {
        suggestion_service::reject($suggestion_id, (int)$USER->id);
        redirect($PAGE->url, 'Предложение отклонено.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($PAGE->url);
}

$list = suggestion_service::list_open_for_user((int)$USER->id);

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Адаптивные предложения');
echo '<p class="text-muted">Предложения движка по результатам тестов: смена уровня сложности, '
   . 'повторение навыка с пробелом, продвижение по освоенному навыку. Примите, чтобы применить '
   . 'сразу, или отклоните. Не рассмотренные предложения применяются автоматически по истечении '
   . 'срока (настройка «Авто-применение предложений»).</p>';

if (empty($list)) {
    echo $OUTPUT->notification('Открытых предложений нет.', 'info');
    echo $OUTPUT->footer();
    exit;
}

echo '<table class="table table-sm table-bordered table-hover">';
echo '<thead class="table-light"><tr>'
   . '<th>Учащийся</th><th>Тип</th><th>Детали</th><th>Авто-применение</th><th>Действия</th>'
   . '</tr></thead><tbody>';

foreach ($list as $s) {
    $student = $DB->get_record('unics_students', ['id' => (int)$s->student_id], 'id, mdl_user_id');
    $mdl = $student ? $DB->get_record('user', ['id' => $student->mdl_user_id]) : null;
    $fio = $mdl ? fullname($mdl) : 'Учащийся #' . (int)$s->student_id;

    $auto = !empty($s->auto_apply_after)
        ? userdate((int)$s->auto_apply_after, '%d.%m.%Y')
        : '<span class="text-muted">сразу</span>';

    $form = new moodle_url('/local/unics/pages/adaptive_suggestions.php', ['sesskey' => sesskey()]);

    echo '<tr>';
    $details = suggestion_service::describe($s);
    if (!empty($s->rationale)) {
        $details .= '<div class="text-muted small mt-1"><em>Обоснование ИИ: '
            . s(mb_substr((string)$s->rationale, 0, 1000)) . '</em></div>';
    }

    echo '<td>' . s($fio) . '</td>';
    echo '<td>' . s(suggestion_service::kind_label((int)$s->kind)) . '</td>';
    echo '<td>' . $details . '</td>';
    echo '<td>' . $auto . '</td>';
    echo '<td class="text-nowrap">';
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form, 'class' => 'd-inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'suggestion_id', 'value' => (int)$s->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'approve']);
    echo html_writer::tag('button', 'Принять', ['type' => 'submit', 'class' => 'btn btn-success btn-sm']);
    echo html_writer::end_tag('form');
    echo ' ';
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form, 'class' => 'd-inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'suggestion_id', 'value' => (int)$s->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reject']);
    echo html_writer::tag('button', 'Отклонить', ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm']);
    echo html_writer::end_tag('form');
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo $OUTPUT->footer();
