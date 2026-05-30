<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Гейт как у «Сгенерировать УМК»: создатель контента / методист / админ.
$can_manage = !local_unics_is_nonediting_teacher() && (
    has_capability('local/unics:manage', context_system::instance())
    || has_capability('moodle/course:manageactivities', $context)
    || local_unics_is_methodist()
);
if (!$can_manage) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        'Недостаточно прав для управления итоговым экзаменом курса.',
        null, \core\output\notification::NOTIFY_WARNING);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_final_exam.php', ['course_id' => $course_id]));
$PAGE->set_title('Итоговый экзамен курса - УНИКС');
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

$current_final = \local_unics\activity_meta::get_final_cmid($course_id);

// --- POST: задать/снять итоговый ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $final_cmid = optional_param('final_cmid', 0, PARAM_INT);

    if ($final_cmid > 0) {
        if (!isset($quizzes[$final_cmid])) {
            $msg = 'Выбранный тест не принадлежит этому курсу.';
        } else {
            \local_unics\activity_meta::set_final($final_cmid, $course_id);
            $current_final = $final_cmid;
            $msg = 'Итоговый экзамен назначен: ' . format_string($quizzes[$final_cmid]->name);
        }
    } else {
        // Снять флаг с текущего итогового.
        if ($current_final) {
            \local_unics\activity_meta::set_flags($current_final, false, null);
        }
        $current_final = null;
        $msg = 'Итоговый экзамен снят.';
    }
}

// --- Открытые пересдачи курса (B7) ---
$retakes = $DB->get_records_sql(
    "SELECT r.id, r.mdl_user_id, r.grade, r.grademax, r.gradepass, r.timecreated,
            u.firstname, u.lastname
       FROM {unics_retakes} r
       JOIN {user} u ON u.id = r.mdl_user_id
      WHERE r.mdl_course_id = :course AND r.status = 0
      ORDER BY r.timecreated DESC",
    ['course' => $course_id]
);

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Итоговый экзамен курса');

if ($msg) {
    echo $OUTPUT->notification($msg, 'info');
}

echo '<p class="text-muted">Пометьте тест как <strong>итоговый экзамен</strong> курса. '
   . 'При провале итогового (балл ниже порога сдачи) учащемуся автоматически назначается '
   . 'пересдача, а его педагоги получают уведомление.</p>';

if (!$quizzes) {
    echo $OUTPUT->notification('В курсе пока нет тестов. Создайте тест, затем вернитесь сюда.', 'warning');
} else {
    $form_url = new moodle_url('/local/unics/pages/course_final_exam.php',
        ['course_id' => $course_id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
    echo '<div class="card mb-4"><div class="card-body">';

    foreach ($quizzes as $cmid => $q) {
        $checked = ((int)$current_final === (int)$cmid);
        echo '<div class="form-check mb-2">';
        echo html_writer::empty_tag('input', [
            'type'  => 'radio', 'name' => 'final_cmid', 'value' => $cmid,
            'id'    => 'fq' . $cmid, 'class' => 'form-check-input',
        ] + ($checked ? ['checked' => 'checked'] : []));
        echo html_writer::tag('label', format_string($q->name)
            . ($checked ? ' <span class="badge badge-success">итоговый</span>' : ''),
            ['for' => 'fq' . $cmid, 'class' => 'form-check-label']);
        echo '</div>';
    }

    echo '<div class="form-check mb-3">';
    echo html_writer::empty_tag('input', [
        'type' => 'radio', 'name' => 'final_cmid', 'value' => '0',
        'id' => 'fq0', 'class' => 'form-check-input',
    ] + (!$current_final ? ['checked' => 'checked'] : []));
    echo html_writer::tag('label', '— не задан —', ['for' => 'fq0', 'class' => 'form-check-label text-muted']);
    echo '</div>';

    echo html_writer::tag('button', 'Сохранить', ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo '</div></div>';
    echo html_writer::end_tag('form');
}

// --- Открытые пересдачи ---
echo $OUTPUT->heading('Открытые пересдачи', 4);
if (!$retakes) {
    echo '<p class="text-muted">Открытых пересдач нет.</p>';
} else {
    echo '<table class="table table-sm table-bordered">';
    echo '<thead class="table-light"><tr><th>Учащийся</th><th>Результат</th><th>Порог</th><th>Дата</th></tr></thead><tbody>';
    foreach ($retakes as $r) {
        $name = trim($r->lastname . ' ' . $r->firstname);
        $pct  = $r->grademax > 0 ? round($r->grade / $r->grademax * 100, 1) . '%' : '-';
        echo '<tr>';
        echo '<td>' . htmlspecialchars($name) . '</td>';
        echo '<td>' . format_float($r->grade, 2) . ' / ' . format_float($r->grademax, 2) . ' (' . $pct . ')</td>';
        echo '<td>' . format_float($r->gradepass, 2) . '</td>';
        echo '<td>' . userdate($r->timecreated, '%d.%m.%Y %H:%M') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();
