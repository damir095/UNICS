<?php
/**
 * Отчёт по курсу (course-integration фаза 2).
 *
 * Курс-версия org_report: группа риска и средние по учащимся, ЗАПИСАННЫМ на
 * курс (та же выборка, что в gradebook.php). Риск и средний считаются по оценкам
 * за тесты ЭТОГО курса. Доступ — в контексте курса (moodle/grade:viewall: педагог
 * видит только свои курсы), либо методист/системный админ.
 *
 * Открывается из меню курса «Дополнительно» -> единственный пункт «УНИКС» -> хаб-страница
 * pages/course_hub.php, группа «Как идут дела».
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/learning/grade_scale.php');

use local_unics\learning\grade_scale;

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Просмотр — персонал курса (grade:viewall в контексте курса), методист или админ.
$can_view = has_capability('local/unics:manage', context_system::instance())
    || local_unics_is_methodist()
    || has_capability('moodle/grade:viewall', $context);
if (!$can_view) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        'Недостаточно прав для просмотра отчёта по курсу.',
        null, \core\output\notification::NOTIFY_WARNING);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_report.php', ['course_id' => $course_id]));
$PAGE->set_title('Отчёт по курсу - УНИКС');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$only_risk = optional_param('risk', 0, PARAM_INT);

// Пороги выявления группы риска (эвристика — как в org_report).
const UNICS_CR_RISK_LOW_AVG    = 50;  // средний балл ниже -> риск
const UNICS_CR_RISK_TREND_DROP = 10;  // падение свежей половины относительно прежней, п.п.
const UNICS_CR_RISK_IDLE_DAYS  = 21;  // нет сданных тестов дольше -> риск

// ----------------------------------------------------------------
// Активные учащиеся, записанные на курс (та же выборка, что в gradebook.php).
// ----------------------------------------------------------------
// Категории/ОВЗ - из нормализованных таблиц с прежними алиасами (этап 2.6-B).
[$catsql, $ovzsql] = \local_unics\identity\student_helper::taxonomy_select_sql('s');
$students = $DB->get_records_sql(
    "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, {$catsql}, {$ovzsql}, s.difficulty_level
       FROM {unics_students} s
       JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
       JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid
      ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
    ['cid' => $course_id]
);

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo html_writer::div(
    html_writer::link(new moodle_url('/course/view.php', ['id' => $course_id]),
        '← В курс', ['class' => 'btn btn-outline-secondary btn-sm']),
    'mb-3'
);
echo $OUTPUT->heading('Отчёт по курсу');

if (empty($students)) {
    echo $OUTPUT->notification('На этот курс не записано активных учащихся.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// ----------------------------------------------------------------
// Оценки за тесты ЭТОГО курса: до 6 последних на пользователя.
// recordset — первый столбец (userid) не уникален.
// ----------------------------------------------------------------
$user_ids = array_unique(array_map('intval', array_column((array)$students, 'mdl_user_id')));
$grades_by_user = [];
[$in_sql, $in_params] = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NAMED, 'u');
$rs = $DB->get_recordset_sql(
    "SELECT g.id, g.userid, g.finalgrade, gi.grademax, g.timemodified
       FROM {grade_grades} g
       JOIN {grade_items} gi ON gi.id = g.itemid
      WHERE g.userid {$in_sql}
        AND gi.courseid   = :cid
        AND gi.itemtype   = 'mod'
        AND gi.itemmodule = 'quiz'
        AND g.finalgrade IS NOT NULL
        AND gi.grademax   > 0
      ORDER BY g.userid, g.timemodified DESC",
    $in_params + ['cid' => $course_id]
);
foreach ($rs as $gr) {
    $uid = (int)$gr->userid;
    if (!isset($grades_by_user[$uid])) {
        $grades_by_user[$uid] = [];
    }
    if (count($grades_by_user[$uid]) < 6) {
        $grades_by_user[$uid][] = $gr;
    }
}
$rs->close();

// ----------------------------------------------------------------
// Подсчёт по каждому учащемуся: средний балл, динамика, риск.
// ----------------------------------------------------------------
$levels     = [1 => 'Базовый', 2 => 'Стандарт', 3 => 'Продвинут.'];
$level_dist = [1 => 0, 2 => 0, 3 => 0];
$rows       = [];
$all_avgs   = [];
$cnt_risk = 0; $cnt_ok = 0; $cnt_nodata = 0;

foreach ($students as $s) {
    $uid    = (int)$s->mdl_user_id;
    $grades = $grades_by_user[$uid] ?? []; // newest-first, до 6
    $pcts   = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades);
    $n      = count($pcts);

    $avg = null;
    if ($n > 0) {
        $last5 = array_slice($pcts, 0, 5);
        $avg   = round(array_sum($last5) / count($last5), 1);
        $all_avgs[] = $avg;
    }

    $risk_reasons = [];
    if ($avg !== null && $avg < UNICS_CR_RISK_LOW_AVG) {
        $risk_reasons[] = 'низкий средний балл';
    }
    if ($n >= 4) {
        $half   = (int)ceil($n / 2);
        $recent = array_slice($pcts, 0, $half);
        $older  = array_slice($pcts, $half);
        $ra = array_sum($recent) / count($recent);
        $oa = array_sum($older) / count($older);
        if ($ra + UNICS_CR_RISK_TREND_DROP < $oa) {
            $risk_reasons[] = 'отрицательная динамика';
        }
    }
    if ($n > 0) {
        $idle_days = (int)floor((time() - (int)$grades[0]->timemodified) / 86400);
        if ($idle_days > UNICS_CR_RISK_IDLE_DAYS) {
            $risk_reasons[] = 'нет активности ' . $idle_days . ' дн.';
        }
    }

    $nodata  = ($n === 0);
    $is_risk = !empty($risk_reasons);
    if ($is_risk)    { $cnt_risk++; }
    elseif ($nodata) { $cnt_nodata++; }
    else             { $cnt_ok++; }

    $lvl = (int)$s->difficulty_level;
    if (isset($level_dist[$lvl])) { $level_dist[$lvl]++; }

    $rows[] = [
        's'         => $s,
        'avg_scale' => $avg === null ? null : grade_scale::from_percent((float)$avg),
        'reasons'   => $risk_reasons,
        'is_risk'   => $is_risk,
        'nodata'    => $nodata,
    ];
}

$total = count($students);

// ---- Карточки-сводка ----
echo '<div class="row mb-3">';
echo '<div class="col-md-3"><div class="card text-center p-2"><div class="h4">' . $total . '</div><small>Учащихся</small></div></div>';
echo '<div class="col-md-3"><div class="card text-center p-2 ' . ($cnt_risk ? 'border-danger' : '')
   . '"><div class="h4 text-danger">' . $cnt_risk . '</div><small>В группе риска</small></div></div>';
echo '<div class="col-md-3"><div class="card text-center p-2"><div class="h4 text-success">' . $cnt_ok . '</div><small>Без риска</small></div></div>';
echo '<div class="col-md-3"><div class="card text-center p-2"><div class="h4 text-muted">' . $cnt_nodata . '</div><small>Нет данных</small></div></div>';
echo '</div>';

// ---- Визуализация: распределение по уровням + риск/норма ----
echo '<div class="row mb-4">';
$chart_lvl = new \core\chart_bar();
$chart_lvl->add_series(new \core\chart_series('Учащихся',
    [$level_dist[1], $level_dist[2], $level_dist[3]]));
$chart_lvl->set_labels(['Базовый', 'Стандарт', 'Продвинут.']);
echo '<div class="col-md-6"><h3 class="unics-section-title">Распределение по уровням</h3>'
   . $OUTPUT->render_chart($chart_lvl, false) . '</div>';

$chart_risk = new \core\chart_bar();
$chart_risk->add_series(new \core\chart_series('Учащихся',
    [$cnt_risk, $cnt_ok, $cnt_nodata]));
$chart_risk->set_labels(['В группе риска', 'Без риска', 'Нет данных']);
echo '<div class="col-md-6"><h3 class="unics-section-title">Группа риска</h3>'
   . $OUTPUT->render_chart($chart_risk, false) . '</div>';
echo '</div>';

// ---- Фильтр ----
$base_url = new moodle_url('/local/unics/pages/course_report.php', ['course_id' => $course_id]);
if ($cnt_risk > 0) {
    if ($only_risk) {
        echo '<p>' . html_writer::link($base_url, '← Показать всех учащихся',
            ['class' => 'btn btn-sm btn-outline-secondary']) . '</p>';
    } else {
        $risk_url = new moodle_url('/local/unics/pages/course_report.php',
            ['course_id' => $course_id, 'risk' => 1]);
        echo '<p>' . html_writer::link($risk_url, 'Показать только группу риска (' . $cnt_risk . ')',
            ['class' => 'btn btn-sm btn-outline-danger']) . '</p>';
    }
}

// ---- Таблица ----
echo '<table class="table table-sm table-bordered table-hover">';
echo '<thead class="table-light"><tr>
    <th>Учащийся</th><th>Класс</th><th>Категория</th><th>Уровень</th>
    <th>Средний балл</th><th>Риск</th><th>Отчёт</th>
</tr></thead><tbody>';

foreach ($rows as $r) {
    if ($only_risk && !$r['is_risk']) {
        continue;
    }
    $s         = $r['s'];
    $sid       = (int)$s->student_id;
    $avg_scale = $r['avg_scale'];

    if ($avg_scale === null) {
        $avg_cell = '<span class="text-muted">-</span>';
    } else {
        $bc = grade_scale::badge_class($avg_scale);
        $avg_cell = '<span class="badge badge-' . $bc . '">' . grade_scale::format($avg_scale) . '</span>';
    }

    if ($r['is_risk']) {
        $risk_cell = '<span class="badge badge-danger" title="'
            . s(implode('; ', $r['reasons'])) . '">' . s(implode('; ', $r['reasons'])) . '</span>';
    } elseif ($r['nodata']) {
        $risk_cell = '<span class="text-muted">нет данных</span>';
    } else {
        $risk_cell = '<span class="badge badge-success">-</span>';
    }

    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $class_str = $s->class_number
        ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
        : '-';

    $report_link = html_writer::link(
        new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $sid]),
        'Открыть',
        ['class' => 'btn btn-sm btn-outline-primary']
    );

    echo '<tr' . ($r['is_risk'] ? ' class="table-danger"' : '') . '>';
    echo '<td>' . s($fio) . '</td>';
    echo '<td>' . s($class_str) . '</td>';
    echo '<td>' . s(\local_unics\identity\student_helper::format_categories($s) ?: '-') . '</td>';
    echo '<td>' . ($levels[$s->difficulty_level] ?? '-') . '</td>';
    echo '<td>' . $avg_cell . '</td>';
    echo '<td>' . $risk_cell . '</td>';
    echo '<td>' . $report_link . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

if (!empty($all_avgs)) {
    $avg_pct   = round(array_sum($all_avgs) / count($all_avgs), 1);
    $avg_scale = grade_scale::from_percent((float)$avg_pct);
    $bc        = grade_scale::badge_class($avg_scale);
    echo '<p class="mt-2"><strong>Средний балл по курсу:</strong> '
        . '<span class="badge badge-' . $bc . ' badge-lg">' . grade_scale::format($avg_scale) . '</span>'
        . ' (по последним 5 тестам курса у каждого учащегося)</p>';
}

if ($cnt_risk > 0) {
    $low_avg_scale = grade_scale::from_percent((float)UNICS_CR_RISK_LOW_AVG);
    echo '<div class="alert alert-warning mt-3"><strong>Рекомендации по группе риска:</strong> '
       . 'индивидуальная консультация педагога; пересмотр уровня сложности; '
       . 'генерация повторного УМК по слабым темам; контакт с родителями. '
       . 'Критерии риска: средний балл &lt; ' . grade_scale::format($low_avg_scale) . ', '
       . 'падение динамики &gt; ' . UNICS_CR_RISK_TREND_DROP . ' п.п., '
       . 'нет сданных тестов &gt; ' . UNICS_CR_RISK_IDLE_DAYS . ' дн.</div>';
}

echo $OUTPUT->footer();
