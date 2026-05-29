<?php
// ИИ-проверка развёрнутых ответов (эссе, открытые задания) учащегося.
// Принцип «педагог в контуре»: ИИ даёт подсказку (балл в единой шкале +
// комментарий), оценку в Moodle педагог выставляет сам.

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/essay_checker.php');
require_once(__DIR__ . '/../classes/grade_scale.php');

require_login();
local_unics_require_not_student();
global $USER, $DB, $OUTPUT, $PAGE;

$student_id = required_param('student_id', PARAM_INT);
$do_subid   = optional_param('submissionid', 0, PARAM_INT);

$ctx          = context_system::instance();
$is_admin     = has_capability('local/unics:manage', $ctx);
$is_teacher   = has_capability('local/unics:viewstudents', $ctx);
$is_methodist = $is_teacher && !$is_admin && local_unics_is_methodist();

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

// Контроль доступа: как в student_report (админ / методист по орг / педагог по привязке).
$access = false;
if ($is_admin) {
    $access = true;
} elseif ($is_methodist) {
    $mrec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $morg = ($mrec && $mrec->organization_id) ? (int)$mrec->organization_id : 0;
    $access = $morg > 0 && (int)$student->organization_id === $morg;
} elseif ($is_teacher) {
    $trec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if ($trec) {
        $access = $DB->record_exists('unics_teacher_student', [
            'teacher_id' => $trec->id, 'student_id' => $student_id,
        ]);
    }
}
if (!$access) {
    throw new moodle_exception('accessdenied', 'error');
}

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/essay_check.php', ['student_id' => $student_id]));
$PAGE->set_title('ИИ-проверка развёрнутых ответов');
$PAGE->set_heading('ИИ-проверка развёрнутых ответов');
$PAGE->set_pagelayout('standard');

// Развёрнутые ответы учащегося = онлайн-текст отправки заданий mod_assign.
$submissions = $DB->get_records_sql(
    "SELECT s.id AS subid, s.assignment, s.timemodified, s.status,
            a.name AS assign_name, a.intro AS assign_intro, a.grade AS maxgrade,
            c.fullname AS course_name,
            ot.onlinetext,
            ag.grade AS current_grade
       FROM {assign_submission} s
       JOIN {assign} a  ON a.id = s.assignment
       JOIN {course} c  ON c.id = a.course
       JOIN {assignsubmission_onlinetext} ot ON ot.submission = s.id
  LEFT JOIN {assign_grades} ag ON ag.assignment = s.assignment AND ag.userid = s.userid
      WHERE s.userid = :uid AND s.latest = 1 AND s.status = 'submitted'
   ORDER BY s.timemodified DESC",
    ['uid' => $student->mdl_user_id]
);

$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo '<div class="mb-3">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]),
    '← Отчёт по учащемуся',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo '</div>';

echo '<div class="card mb-4"><div class="card-header bg-light"><strong>' . s($fio) . '</strong>'
   . ' <span class="text-muted">- проверка по шкале до ' . grade_scale::MAX . '</span></div>';
echo '<div class="card-body"><p class="mb-1 text-muted small">ИИ выдаёт предварительную '
   . 'оценку и комментарий. Итоговую оценку в журнал выставляет педагог.</p></div></div>';

if (empty($submissions)) {
    echo $OUTPUT->notification('У учащегося нет отправленных текстовых ответов '
        . '(онлайн-текст в заданиях Moodle).', 'info');
    echo $OUTPUT->footer();
    exit;
}

// --- Запуск проверки конкретной отправки ---
if ($do_subid && confirm_sesskey()) {
    $sub = $submissions[$do_subid] ?? null;
    if (!$sub) {
        echo $OUTPUT->notification('Отправка не найдена.', 'error');
    } else {
        $question = trim($sub->assign_name . "\n"
            . html_to_text($sub->assign_intro ?? '', 0, false));
        $answer   = html_to_text($sub->onlinetext ?? '', 0, false);

        try {
            $res = essay_checker::evaluate($question, $answer);
            $bc  = grade_scale::badge_class($res['score']);

            echo '<div class="card mb-4 border-primary">';
            echo '<div class="card-header bg-primary text-white">Результат ИИ-проверки: '
               . s($sub->assign_name) . ' <span class="small">(' . s($sub->course_name) . ')</span></div>';
            echo '<div class="card-body">';
            echo '<p><strong>Предварительный балл:</strong> '
               . '<span class="badge badge-' . $bc . '" style="font-size:1rem">'
               . grade_scale::format((float)$res['score']) . '</span></p>';
            echo '<p><strong>Комментарий ИИ:</strong></p>';
            echo '<div class="alert alert-light" style="white-space:pre-wrap">'
               . s($res['feedback']) . '</div>';
            echo '<details class="mb-2"><summary class="text-muted small">Показать ответ учащегося</summary>'
               . '<div class="border rounded p-2 mt-2" style="white-space:pre-wrap">'
               . s($answer) . '</div></details>';
            echo '<p class="text-muted small mb-0">Это рекомендация. Выставьте оценку в журнал '
               . 'Moodle на странице задания вручную.</p>';
            echo '</div></div>';
        } catch (\Throwable $e) {
            echo $OUTPUT->notification('Ошибка ИИ-проверки: ' . s($e->getMessage()), 'error');
        }
    }
}

// --- Список отправок ---
echo '<h2 class="unics-section-title">Текстовые ответы учащегося</h2>';
echo '<table class="table table-sm table-bordered table-hover">';
echo '<thead class="table-light"><tr>
    <th>Задание</th><th>Курс</th><th>Отправлено</th>
    <th>Оценка в журнале</th><th></th>
</tr></thead><tbody>';

foreach ($submissions as $sub) {
    $when = $sub->timemodified ? userdate($sub->timemodified, '%d.%m.%Y') : '-';

    if ($sub->current_grade !== null && $sub->current_grade >= 0 && $sub->maxgrade > 0) {
        $g10  = grade_scale::from_raw((float)$sub->current_grade, (float)$sub->maxgrade);
        $gbc  = grade_scale::badge_class($g10);
        $grade_cell = '<span class="badge badge-' . $gbc . '">' . grade_scale::format($g10) . '</span>';
    } else {
        $grade_cell = '<span class="text-muted">не оценено</span>';
    }

    $run_url = new moodle_url('/local/unics/pages/essay_check.php', [
        'student_id'   => $student_id,
        'submissionid' => $sub->subid,
        'sesskey'      => sesskey(),
    ]);

    echo '<tr>';
    echo '<td>' . s($sub->assign_name) . '</td>';
    echo '<td>' . s($sub->course_name) . '</td>';
    echo '<td>' . $when . '</td>';
    echo '<td>' . $grade_cell . '</td>';
    echo '<td>' . html_writer::link($run_url, 'Проверить ИИ',
        ['class' => 'btn btn-sm btn-outline-primary']) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo $OUTPUT->footer();
