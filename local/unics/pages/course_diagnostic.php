<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Гейт как у «Сгенерировать УМК» / «Итоговый экзамен»: создатель контента /
// методист / админ. Non-editing teacher контент не настраивает.
$can_manage = !local_unics_is_nonediting_teacher() && (
    has_capability('local/unics:manage', context_system::instance())
    || has_capability('moodle/course:manageactivities', $context)
    || local_unics_is_methodist()
);
if (!$can_manage) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        'Недостаточно прав для настройки входной диагностики курса.',
        null, \core\output\notification::NOTIFY_WARNING);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_diagnostic.php', ['course_id' => $course_id]));
$PAGE->set_title('Входная диагностика курса - УНИКС');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// --- Тесты курса ---
$quizzes = $DB->get_records_sql(
    "SELECT cm.id AS cmid, q.name
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
       JOIN {quiz} q ON q.id = cm.instance
      WHERE cm.course = :course AND cm.deletioninprogress = 0
      ORDER BY q.name",
    ['course' => $course_id]
);

$current = \local_unics\learning\activity_meta::get_diagnostic_cmid($course_id);

// --- POST: задать/снять входную диагностику ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $diag_cmid = optional_param('diag_cmid', 0, PARAM_INT);

    if ($diag_cmid > 0) {
        if (!isset($quizzes[$diag_cmid])) {
            $msg = 'Выбранный тест не принадлежит этому курсу.';
        } else {
            \local_unics\learning\activity_meta::set_diagnostic($diag_cmid, $course_id);
            $current = $diag_cmid;
            $msg = 'Входная диагностика назначена: ' . format_string($quizzes[$diag_cmid]->name);
        }
    } else {
        if ($current) {
            \local_unics\learning\activity_meta::set_flags($current, null, null, false);
        }
        $current = null;
        $msg = 'Входная диагностика снята.';
    }
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Входная диагностика курса');

if ($msg) {
    echo $OUTPUT->notification($msg, 'info');
}

echo '<p class="text-muted">Пометьте тест как <strong>входную диагностику</strong> курса. '
   . 'По его результату учащемуся <strong>один раз</strong> определяется стартовый уровень '
   . 'сложности: 80% и выше - продвинутый, ниже 50% - базовый, между - стандартный. '
   . 'Повторное прохождение стартовый уровень не меняет (дальше работает обычная адаптация).</p>';

if (!$quizzes) {
    echo $OUTPUT->notification('В курсе пока нет тестов. Создайте тест, затем вернитесь сюда.', 'warning');
} else {
    $form_url = new moodle_url('/local/unics/pages/course_diagnostic.php',
        ['course_id' => $course_id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
    echo '<div class="card mb-4"><div class="card-body">';

    foreach ($quizzes as $cmid => $q) {
        $checked = ((int)$current === (int)$cmid);
        echo '<div class="form-check mb-2">';
        echo html_writer::empty_tag('input', [
            'type'  => 'radio', 'name' => 'diag_cmid', 'value' => $cmid,
            'id'    => 'dq' . $cmid, 'class' => 'form-check-input',
        ] + ($checked ? ['checked' => 'checked'] : []));
        echo html_writer::tag('label', format_string($q->name)
            . ($checked ? ' <span class="badge badge-info">входная диагностика</span>' : ''),
            ['for' => 'dq' . $cmid, 'class' => 'form-check-label']);
        echo '</div>';
    }

    echo '<div class="form-check mb-3">';
    echo html_writer::empty_tag('input', [
        'type' => 'radio', 'name' => 'diag_cmid', 'value' => '0',
        'id' => 'dq0', 'class' => 'form-check-input',
    ] + (!$current ? ['checked' => 'checked'] : []));
    echo html_writer::tag('label', '- не задана -', ['for' => 'dq0', 'class' => 'form-check-label text-muted']);
    echo '</div>';

    echo html_writer::tag('button', 'Сохранить', ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo '</div></div>';
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
