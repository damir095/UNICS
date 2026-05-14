<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/achievement_manager.php');

require_login();
global $USER, $DB;

$student_id = required_param('student_id', PARAM_INT);
$ctx        = context_system::instance();

$is_admin   = has_capability('local/unics:manage', $ctx);
$is_teacher = has_capability('local/unics:viewstudents', $ctx);

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

// Контроль доступа.
// Методист проверяется отдельно: у него есть запись в unics_teachers
// (там org-привязка), но он смотрит на учащихся своей организации,
// а не через unics_teacher_student.
$is_methodist = $is_teacher && !$is_admin && local_unics_is_methodist();
$access = $is_admin;
if (!$access && $is_methodist) {
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
    $access = $methodist_org_id > 0
        && (int)$student->organization_id === $methodist_org_id;
}
if (!$access && $is_teacher) {
    $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if ($teacher_rec) {
        $access = $DB->record_exists('unics_teacher_student', [
            'teacher_id' => $teacher_rec->id, 'student_id' => $student_id,
        ]);
    }
}
if (!$access) {
    $access = $DB->record_exists('unics_parent_student', [
        'parent_mdl_user_id' => $USER->id, 'student_id' => $student_id,
    ]);
}
if (!$access && $USER->id == $student->mdl_user_id) {
    $access = true;
}
if (!$access) {
    throw new moodle_exception('accessdenied', 'error');
}

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student_id]));
$PAGE->set_title('Достижения — УНИКС');
$PAGE->set_heading('Достижения учащегося');
$PAGE->set_pagelayout('standard');

// Достижения из БД
$awards     = $DB->get_records('unics_achievements', ['student_id' => $student_id], '', 'badge_type, awarded_at, note');
$badge_info = \local_unics\achievement_manager::get_badge_info();
$total_all  = count($badge_info);
$total_got  = count($awards);

echo $OUTPUT->header();

// Кнопка назад — только для педагога/администратора/родителя, не для самого учащегося
if ($USER->id !== $student->mdl_user_id) {
    echo html_writer::link(
        new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]),
        'Отчёт',
        ['class' => 'btn btn-outline-secondary btn-sm mb-3']
    );
}

$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));

echo '<div class="d-flex align-items-center mb-3">';
echo $OUTPUT->heading(s($fio), 4, 'mb-0 mr-3');
echo html_writer::tag('span',
    "Получено значков: {$total_got} / {$total_all}",
    ['class' => 'badge badge-' . ($total_got === $total_all ? 'success' : 'secondary') . ' ml-2 p-2']
);
echo '</div>';

echo '<div class="row">';
foreach ($badge_info as $badge_type => $info) {
    $awarded      = isset($awards[$badge_type]);
    $border_class = $awarded ? 'border-success' : 'border-light';
    $opacity      = $awarded ? '' : 'opacity:.35;';
    $bg_class     = $awarded ? 'bg-white' : 'bg-light';
    $awarded_date = $awarded ? userdate((int)$awards[$badge_type]->awarded_at, '%d.%m.%Y') : null;

    echo '<div class="col-lg-3 col-sm-6 mb-4">';
    echo '<div class="card h-100 ' . $border_class . ' ' . $bg_class . ' text-center shadow-sm">';
    echo '<div class="card-body d-flex flex-column align-items-center justify-content-center py-4">';
    echo '<div style="font-size:3.5rem;' . $opacity . '">' . $info['icon'] . '</div>';
    echo '<h5 class="card-title mt-2 mb-1">' . htmlspecialchars($info['name']) . '</h5>';
    echo '<p class="card-text text-muted small mb-3">' . htmlspecialchars($info['desc']) . '</p>';
    if ($awarded) {
        echo '<span class="badge badge-success px-3 py-2">Получен ' . $awarded_date . '</span>';
    } else {
        echo '<span class="badge badge-secondary px-3 py-2">Ещё не получен</span>';
    }
    echo '</div></div>';
    echo '</div>';
}
echo '</div>';

// Кнопка обновить достижения (только для педагога/администратора)
if ($is_admin || $is_teacher) {
    $recheck_url = new moodle_url('/local/unics/pages/achievements.php', [
        'student_id' => $student_id,
        'recheck'    => 1,
        'sesskey'    => sesskey(),
    ]);
    echo html_writer::link(
        $recheck_url,
        'Пересчитать достижения',
        ['class' => 'btn btn-outline-primary btn-sm mt-2']
    );
}

// Пересчёт по запросу
if (optional_param('recheck', 0, PARAM_INT) && confirm_sesskey() && ($is_admin || $is_teacher)) {
    require_once(__DIR__ . '/../classes/achievement_manager.php');
    $new_badges = \local_unics\achievement_manager::evaluate_student($student_id, (int)$student->mdl_user_id);
    if ($new_badges) {
        $names = [];
        foreach ($new_badges as $bt) {
            $names[] = $badge_info[$bt]['icon'] . ' ' . $badge_info[$bt]['name'];
        }
        echo $OUTPUT->notification('Новые значки: ' . implode(', ', $names), 'success');
    } else {
        echo $OUTPUT->notification('Новых значков нет.', 'info');
    }
    // Обновляем список
    echo '<script>setTimeout(function(){ location.href = location.href.replace(/[?&]recheck=1/,"").replace(/[?&]sesskey=[^&]*/,""); }, 1500);</script>';
}

echo $OUTPUT->footer();
