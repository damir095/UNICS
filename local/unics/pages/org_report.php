<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/organization_manager.php');

require_login();
local_unics_require_not_student();

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$is_methodist  = !$is_admin_user
    && has_capability('local/unics:viewstudents', $sys_ctx)
    && local_unics_is_methodist();

if (!$is_admin_user && !$is_methodist) {
    require_capability('local/unics:manage', $sys_ctx);
}
global $DB, $USER;

$org_id = optional_param('org_id', 0, PARAM_INT);

// Методист: всегда видит только свою организацию — фиксируем org_id.
if ($is_methodist) {
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/org_report.php', ['org_id' => $org_id]));
$PAGE->set_title('Сводный отчёт по организации');
$PAGE->set_heading('Сводный отчёт по организации');
$PAGE->set_pagelayout('admin');

$orgs = unics_organization_manager::get_organizations_grouped();

echo $OUTPUT->header();

echo '<div class="mb-3">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/users.php'),
    'Пользователи',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo '</div>';

// Селектор организации — только для админа. Методист видит свою орг автоматически.
if (!$is_methodist) {
    echo '<form method="get" class="form-inline mb-4">';
    echo '<label class="mr-2 font-weight-bold">Организация:</label>';
    echo '<select name="org_id" class="form-control mr-2" style="max-width:400px">';
    echo '<option value="0">— Выберите организацию —</option>';
    foreach ($orgs as $oid => $olabel) {
        $sel = ($oid == $org_id) ? ' selected' : '';
        echo '<option value="' . $oid . '"' . $sel . '>' . s($olabel) . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-primary">Показать</button>';
    echo '</form>';
}

if (!$org_id) {
    echo $OUTPUT->footer();
    exit;
}

$org = $DB->get_record('unics_organizations', ['id' => $org_id]);
if (!$org) {
    echo $OUTPUT->notification('Организация не найдена.', 'error');
    echo $OUTPUT->footer();
    exit;
}

echo '<h5 class="mb-3">' . s($org->name) . '</h5>';

$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, s.category, s.difficulty_level
     FROM {unics_students} s
     JOIN {user} u ON u.id = s.mdl_user_id
     WHERE s.organization_id = :orgid AND u.deleted = 0
     ORDER BY s.class_number, u.lastname, u.firstname",
    ['orgid' => $org_id]
);

if (empty($students)) {
    echo $OUTPUT->notification('В этой организации нет учащихся.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// ----------------------------------------------------------------
// Bulk-запросы: одни на всех учащихся вместо N+1
// ----------------------------------------------------------------
$user_ids    = array_unique(array_column((array)$students, 'mdl_user_id'));
$student_ids = array_unique(array_column((array)$students, 'student_id'));

// 1. Все оценки за тесты (последние 5 на пользователя считаем в PHP)
$all_grades_raw = [];
if ($user_ids) {
    [$in_sql, $in_params] = $DB->get_in_or_equal($user_ids);
    $grade_rows = $DB->get_records_sql(
        "SELECT g.userid, g.finalgrade, gi.grademax
           FROM {grade_grades} g
           JOIN {grade_items} gi ON gi.id = g.itemid
          WHERE g.userid {$in_sql}
            AND gi.itemtype   = 'mod'
            AND gi.itemmodule = 'quiz'
            AND g.finalgrade IS NOT NULL
            AND gi.grademax   > 0
          ORDER BY g.userid, g.timemodified DESC",
        $in_params
    );
    foreach ($grade_rows as $gr) {
        $uid = (int)$gr->userid;
        if (!isset($all_grades_raw[$uid])) {
            $all_grades_raw[$uid] = [];
        }
        if (count($all_grades_raw[$uid]) < 5) {
            $all_grades_raw[$uid][] = $gr;
        }
    }
}

// 2. Количество курсов на пользователя
$course_counts = [];
if ($user_ids) {
    [$in_sql, $in_params] = $DB->get_in_or_equal($user_ids);
    $cc_rows = $DB->get_records_sql(
        "SELECT ue.userid, COUNT(DISTINCT e.courseid) AS cnt
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE ue.userid {$in_sql}
            AND ue.status    = 0
            AND e.courseid  != 1
          GROUP BY ue.userid",
        $in_params
    );
    foreach ($cc_rows as $row) {
        $course_counts[(int)$row->userid] = (int)$row->cnt;
    }
}

// 3. Количество УМК на учащегося (через unics_umk_students)
$umk_counts = [];
if ($student_ids) {
    [$in_sql, $in_params] = $DB->get_in_or_equal($student_ids);
    $umk_rows = $DB->get_records_sql(
        "SELECT us.student_id, COUNT(DISTINCT us.umk_id) AS cnt
           FROM {unics_umk_students} us
          WHERE us.student_id {$in_sql}
          GROUP BY us.student_id",
        $in_params
    );
    foreach ($umk_rows as $row) {
        $umk_counts[(int)$row->student_id] = (int)$row->cnt;
    }
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
$categories = [1 => 'ОВЗ', 2 => 'Сем. обуч.', 3 => 'Лечение', 4 => 'Одарённый'];
$levels     = [1 => 'Базовый', 2 => 'Стандарт', 3 => 'Продвинут.'];

$total    = count($students);
$all_avgs = [];

// Карточка-шапка
echo '<div class="row mb-3">';
echo '<div class="col-md-3"><div class="card text-center p-2"><div class="h4">' . $total . '</div><small>Учащихся</small></div></div>';
echo '</div>';

echo '<table class="table table-sm table-bordered table-hover">';
echo '<thead class="thead-light"><tr>
    <th>Учащийся</th><th>Класс</th><th>Категория</th><th>Уровень</th>
    <th>Средний балл</th><th>Курсов</th><th>УМК</th><th>Отчёт</th>
</tr></thead><tbody>';

foreach ($students as $s) {
    $uid     = (int)$s->mdl_user_id;
    $sid     = (int)$s->student_id;
    $grades  = $all_grades_raw[$uid] ?? [];

    if (!empty($grades)) {
        $total_pts = array_sum(array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades));
        $avg       = round($total_pts / count($grades), 1);
        $all_avgs[] = $avg;
        $bc        = $avg >= 85 ? 'success' : ($avg >= 50 ? 'warning' : 'danger');
        $avg_cell  = '<span class="badge badge-' . $bc . '">' . $avg . '%</span>';
    } else {
        $avg_cell = '<span class="text-muted">—</span>';
    }

    $course_count = $course_counts[$uid] ?? 0;
    $umk_count    = $umk_counts[$sid]   ?? 0;
    $fio          = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $class_str    = $s->class_number
        ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
        : '—';

    $report_link = html_writer::link(
        new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $sid]),
        'Открыть',
        ['class' => 'btn btn-sm btn-outline-primary']
    );

    echo '<tr>';
    echo '<td>' . s($fio) . '</td>';
    echo '<td>' . s($class_str) . '</td>';
    echo '<td>' . ($categories[$s->category] ?? '—') . '</td>';
    echo '<td>' . ($levels[$s->difficulty_level] ?? '—') . '</td>';
    echo '<td>' . $avg_cell . '</td>';
    echo '<td>' . $course_count . '</td>';
    echo '<td>' . $umk_count . '</td>';
    echo '<td>' . $report_link . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

if (!empty($all_avgs)) {
    $org_avg = round(array_sum($all_avgs) / count($all_avgs), 1);
    $bc = $org_avg >= 85 ? 'success' : ($org_avg >= 50 ? 'warning' : 'danger');
    echo '<p class="mt-2"><strong>Средний балл по организации:</strong> '
        . '<span class="badge badge-' . $bc . ' badge-lg">' . $org_avg . '%</span>'
        . ' (по последним 5 тестам каждого учащегося)</p>';
}

echo $OUTPUT->footer();
