<?php
// ИИ-проверка развёрнутых ответов (эссе, открытые задания).
// Принцип «педагог в контуре»: ИИ даёт подсказку (балл в единой шкале +
// комментарий), оценку в Moodle педагог выставляет сам.
//
// Два режима:
//  - «по ученику»  (?student_id=) - все текстовые отправки одного учащегося;
//    доступ по системным capability + привязке unics_teacher_student.
//  - «по заданию»  (?cmid=)       - все отправки одного задания mod_assign;
//    доступ по mod/assign:grade в контексте модуля (педагог видит только свои
//    задания). Точка входа - «Ещё» на странице задания (см. lib.php).

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/essay_checker.php');
require_once(__DIR__ . '/../classes/grade_scale.php');

use local_unics\essay_checker;
use local_unics\grade_scale;

global $USER, $DB, $OUTPUT, $PAGE;

$student_id = optional_param('student_id', 0, PARAM_INT);
$cmid       = optional_param('cmid',       0, PARAM_INT);
$do_subid   = optional_param('submissionid', 0, PARAM_INT);

if (!$student_id && !$cmid) {
    throw new moodle_exception('missingparam', 'error', '', 'student_id|cmid');
}

$mode = $cmid ? 'assign' : 'student';
$fio  = '';

if ($mode === 'assign') {
    // --- Режим «по заданию»: доступ в контексте модуля. ---
    $cm     = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    require_login($course, false, $cm);
    $modctx = context_module::instance($cm->id);
    require_capability('mod/assign:grade', $modctx);

    $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

    $PAGE->set_context($modctx);
    $PAGE->set_cm($cm, $course);
    $PAGE->set_url(new moodle_url('/local/unics/pages/essay_check.php', ['cmid' => $cmid]));
    $PAGE->set_title('ИИ-проверка ответов: ' . format_string($assign->name));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->set_pagelayout('incourse');

    // Все последние отправки задания с онлайн-текстом.
    $submissions = $DB->get_records_sql(
        "SELECT s.id AS subid, s.userid, s.assignment, s.timemodified, s.status,
                a.name AS assign_name, a.intro AS assign_intro, a.grade AS maxgrade,
                c.fullname AS course_name,
                ot.onlinetext,
                ag.grade AS current_grade,
                u.firstname, u.lastname, u.middlename
           FROM {assign_submission} s
           JOIN {assign} a ON a.id = s.assignment
           JOIN {course} c ON c.id = a.course
           JOIN {assignsubmission_onlinetext} ot ON ot.submission = s.id
           JOIN {user} u ON u.id = s.userid AND u.deleted = 0
      LEFT JOIN {assign_grades} ag ON ag.assignment = s.assignment AND ag.userid = s.userid
          WHERE s.assignment = :aid AND s.latest = 1 AND s.status = 'submitted'
       ORDER BY u.lastname, u.firstname",
        ['aid' => $assign->id]
    );

    $heading_name = format_string($assign->name);
    $back_url     = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
    $back_label   = '← К заданию';
} else {
    // --- Режим «по ученику» (существующий). ---
    require_login();
    local_unics_require_not_student();

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
    $heading_name = $fio;
    $back_url     = new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]);
    $back_label   = '← Отчёт по учащемуся';
}

echo $OUTPUT->header();
if ($mode === 'student') {
    echo local_unics_dashboard_button();
}

echo '<div class="mb-3">';
echo html_writer::link($back_url, $back_label, ['class' => 'btn btn-outline-secondary btn-sm']);
echo '</div>';

echo '<div class="card mb-4"><div class="card-header bg-light"><strong>' . s($heading_name) . '</strong>'
   . ' <span class="text-muted">- проверка по шкале до ' . grade_scale::MAX . '</span></div>';
echo '<div class="card-body"><p class="mb-1 text-muted small">ИИ выдаёт предварительную '
   . 'оценку и комментарий. Итоговую оценку в журнал выставляет педагог.</p></div></div>';

if (empty($submissions)) {
    $emptymsg = $mode === 'assign'
        ? 'По этому заданию нет отправленных текстовых ответов (онлайн-текст).'
        : 'У учащегося нет отправленных текстовых ответов (онлайн-текст в заданиях Moodle).';
    echo $OUTPUT->notification($emptymsg, 'info');
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
        // Чей ответ (в режиме «по заданию» строк несколько - подписываем).
        $who = ($mode === 'assign' && isset($sub->lastname))
            ? trim($sub->lastname . ' ' . $sub->firstname) : '';

        try {
            $res = essay_checker::evaluate($question, $answer);
            $bc  = grade_scale::badge_class($res['score']);

            echo '<div class="card mb-4 border-primary">';
            echo '<div class="card-header bg-primary text-white">Результат ИИ-проверки: '
               . s($mode === 'assign' ? ($who !== '' ? $who : $sub->assign_name) : $sub->assign_name)
               . ' <span class="small">(' . s($sub->course_name) . ')</span></div>';
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
            echo $OUTPUT->notification('Ошибка ИИ-проверки (сервис ИИ может быть недоступен): '
               . s($e->getMessage()), 'error');
        }
    }
}

// --- Список отправок ---
echo '<h2 class="unics-section-title">'
   . ($mode === 'assign' ? 'Ответы учащихся по заданию' : 'Текстовые ответы учащегося')
   . '</h2>';
echo '<table class="table table-sm table-bordered table-hover">';
if ($mode === 'assign') {
    echo '<thead class="table-light"><tr>
        <th>Учащийся</th><th>Отправлено</th><th>Оценка в журнале</th><th></th>
    </tr></thead><tbody>';
} else {
    echo '<thead class="table-light"><tr>
        <th>Задание</th><th>Курс</th><th>Отправлено</th><th>Оценка в журнале</th><th></th>
    </tr></thead><tbody>';
}

foreach ($submissions as $sub) {
    $when = $sub->timemodified ? userdate($sub->timemodified, '%d.%m.%Y') : '-';

    if ($sub->current_grade !== null && $sub->current_grade >= 0 && $sub->maxgrade > 0) {
        $g10  = grade_scale::from_raw((float)$sub->current_grade, (float)$sub->maxgrade);
        $gbc  = grade_scale::badge_class($g10);
        $grade_cell = '<span class="badge badge-' . $gbc . '">' . grade_scale::format($g10) . '</span>';
    } else {
        $grade_cell = '<span class="text-muted">не оценено</span>';
    }

    $run_params = ['submissionid' => $sub->subid, 'sesskey' => sesskey()];
    $run_params += ($mode === 'assign') ? ['cmid' => $cmid] : ['student_id' => $student_id];
    $run_url = new moodle_url('/local/unics/pages/essay_check.php', $run_params);
    $run_link = html_writer::link($run_url, 'Проверить ИИ',
        ['class' => 'btn btn-sm btn-outline-primary']);

    echo '<tr>';
    if ($mode === 'assign') {
        $who = trim($sub->lastname . ' ' . $sub->firstname);
        echo '<td>' . s($who) . '</td>';
        echo '<td>' . $when . '</td>';
        echo '<td>' . $grade_cell . '</td>';
        echo '<td>' . $run_link . '</td>';
    } else {
        echo '<td>' . s($sub->assign_name) . '</td>';
        echo '<td>' . s($sub->course_name) . '</td>';
        echo '<td>' . $when . '</td>';
        echo '<td>' . $grade_cell . '</td>';
        echo '<td>' . $run_link . '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table>';

echo $OUTPUT->footer();
