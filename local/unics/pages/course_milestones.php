<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\learning\activity_meta;
use local_unics\learning\milestone_manager;

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Гейт как у «Итоговый экзамен» / «Сгенерировать УМК»: создатель контента /
// методист / админ. Non-editing teacher контент не настраивает.
$can_manage = !local_unics_is_nonediting_teacher() && (
    has_capability('local/unics:manage', context_system::instance())
    || has_capability('moodle/course:manageactivities', $context)
    || local_unics_is_methodist()
);
if (!$can_manage) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        'Недостаточно прав для управления контрольными точками курса.',
        null, \core\output\notification::NOTIFY_WARNING);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_milestones.php', ['course_id' => $course_id]));
$PAGE->set_title('Контрольные точки курса - УНИКС');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// --- Тесты курса (кандидаты в контрольные точки) ---
$quizzes = $DB->get_records_sql(
    "SELECT cm.id AS cmid, cm.instance, q.name
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
       JOIN {quiz} q ON q.id = cm.instance
      WHERE cm.course = :course AND cm.deletioninprogress = 0
      ORDER BY cm.section, q.name",
    ['course' => $course_id]
);

// --- POST: сохранить набор контрольных точек ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $checked = optional_param_array('milestone_cmids', [], PARAM_INT);
    $checked = array_map('intval', $checked);
    // Перебираем только тесты ЭТОГО курса — чужой cmid не тронем.
    foreach ($quizzes as $cmid => $q) {
        activity_meta::set_flags((int)$cmid, null, in_array((int)$cmid, $checked, true));
    }
    $count = count(array_intersect(array_keys($quizzes), $checked));
    $msg = $count > 0
        ? "Контрольных точек отмечено: {$count}."
        : 'Все контрольные точки сняты.';
}

$current = activity_meta::get_milestone_cmids($course_id);

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Контрольные точки курса');

if ($msg) {
    echo $OUTPUT->notification($msg, 'info');
}

echo '<p class="text-muted">Отметьте тесты как <strong>контрольные точки</strong> '
   . '(промежуточная аттестация). Ниже — сводка результатов по учащимся курса: '
   . 'пройдена ли контрольная точка, балл и дата. Контрольная точка считается '
   . '<strong>пройденной</strong>, если балл не ниже порога сдачи теста (а если порог '
   . 'не задан — не ниже 50% от максимума).</p>';

// ----------------------------------------------------------------
// Форма пометки
// ----------------------------------------------------------------
if (!$quizzes) {
    echo $OUTPUT->notification('В курсе пока нет тестов. Создайте тест, затем вернитесь сюда.', 'warning');
} else {
    $form_url = new moodle_url('/local/unics/pages/course_milestones.php',
        ['course_id' => $course_id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<p class="font-weight-bold mb-2">Отметьте контрольные точки:</p>';

    foreach ($quizzes as $cmid => $q) {
        $cmid    = (int)$cmid;
        $checked = in_array($cmid, $current, true);
        echo '<div class="form-check mb-2">';
        echo html_writer::empty_tag('input', [
            'type'  => 'checkbox', 'name' => 'milestone_cmids[]', 'value' => $cmid,
            'id'    => 'ms' . $cmid, 'class' => 'form-check-input',
        ] + ($checked ? ['checked' => 'checked'] : []));
        echo html_writer::tag('label', format_string($q->name)
            . ($checked ? ' <span class="badge badge-info">контрольная точка</span>' : ''),
            ['for' => 'ms' . $cmid, 'class' => 'form-check-label']);
        echo '</div>';
    }

    echo html_writer::tag('button', 'Сохранить', ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
    echo '</div></div>';
    echo html_writer::end_tag('form');
}

// ----------------------------------------------------------------
// Сводка по учащимся (матрица: учащиеся × контрольные точки)
// ----------------------------------------------------------------
echo $OUTPUT->heading('Результаты по учащимся', 4);

$milestones = milestone_manager::get_course_milestones($course_id);

if (!$milestones) {
    echo '<p class="text-muted">Контрольные точки не отмечены — отметьте хотя бы один тест выше.</p>';
} else {
    // Записанные на курс учащиеся УНИКС (по строке в unics_students).
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname
           FROM {user} u
           JOIN {unics_students} s ON s.mdl_user_id = u.id
           JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
           JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :course
          WHERE u.deleted = 0
          ORDER BY u.lastname, u.firstname",
        ['course' => $course_id]
    );

    if (!$students) {
        echo '<p class="text-muted">На курс не записан ни один учащийся УНИКС.</p>';
    } else {
        $userids = array_map(static fn($u) => (int)$u->id, $students);

        // Для каждой контрольной точки — результаты всех учащихся одним вызовом.
        $cells = []; // [cmid][userid] => result
        foreach ($milestones as $cmid => $m) {
            $cells[(int)$cmid] = milestone_manager::evaluate_many($course_id, (int)$m->instance, $userids);
        }

        echo '<div class="table-responsive">';
        echo '<table class="' . local_unics_table_class(true) . '">';
        echo '<thead><tr><th>Учащийся</th>';
        foreach ($milestones as $m) {
            echo '<th>' . format_string($m->name) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($students as $u) {
            $uid  = (int)$u->id;
            $name = trim($u->lastname . ' ' . $u->firstname);
            echo '<tr><td>' . s($name) . '</td>';
            foreach ($milestones as $cmid => $m) {
                $r = $cells[(int)$cmid][$uid];
                echo '<td>' . milestone_manager::status_html($r)
                   . '<br><span class="small">' . milestone_manager::grade_text($r) . '</span>';
                if (!empty($r->timemodified)) {
                    echo '<br><span class="text-muted small">' . userdate($r->timemodified, '%d.%m.%Y') . '</span>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}

echo $OUTPUT->footer();
