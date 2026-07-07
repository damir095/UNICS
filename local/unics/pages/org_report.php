<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/identity/organization_manager.php');
require_once(__DIR__ . '/../classes/learning/grade_scale.php');

use local_unics\learning\grade_scale;

require_login();
local_unics_require_not_student();

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
// manageorg-роли (методист орг./района, региональн./районный админ) видят отчёты
// по организациям своего скоупа.
$is_manageorg  = !$is_admin_user && has_capability('local/unics:manageorg', $sys_ctx);

if (!$is_admin_user && !$is_manageorg) {
    require_capability('local/unics:manage', $sys_ctx);
}
global $DB, $USER;

$org_id = optional_param('org_id', 0, PARAM_INT);

// Скоуп: если он = одна организация, фиксируем выбор; район/регион → селектор по скоупу.
$scope = $is_admin_user
    ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
    : \local_unics\identity\scope_checker::get_user_scope((int)$USER->id);
$fixed_org = (!$is_admin_user && !empty($scope['organization_id']));
if ($fixed_org) {
    $org_id = (int)$scope['organization_id'];
} else if (!$is_admin_user && $org_id > 0
    && !\local_unics\identity\scope_checker::user_can_access_org((int)$USER->id, $org_id)) {
    $org_id = 0; // организация вне скоупа — сбрасываем
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/org_report.php', ['org_id' => $org_id]));
$PAGE->set_title('Сводный отчёт по организации');
$PAGE->set_heading('Сводный отчёт по организации');
$PAGE->set_pagelayout('admin');

// Ранняя выгрузка статистики орг (Excel/CSV/ODS) - до любого вывода. $org_id уже в скоупе (выше).
if ($org_id) {
    local_unics_export_student_stats([(int)$org_id], 'unics-otchet-org-' . $org_id);
}

// Список организаций для селектора — по скоупу.
if ($is_admin_user) {
    $orgs = unics_organization_manager::get_organizations_grouped();
} else {
    [$ofw, $ofp] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $orgs = [];
    foreach ($DB->get_records_sql(
        "SELECT o.id, o.name FROM {unics_organizations} o
          WHERE o.is_active = 1 AND ({$ofw}) ORDER BY o.name", $ofp) as $r) {
        $orgs[$r->id] = $r->name;
    }
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo '<div class="mb-3">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/users.php'),
    'Пользователи',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo '</div>';

// Селектор организации: показываем, если скоуп не зафиксирован на одну орг.
if (!$fixed_org) {
    echo '<form method="get" class="form-inline mb-4">';
    echo '<label class="mr-2 font-weight-bold">Организация:</label>';
    echo '<select name="org_id" class="form-control mr-2" style="max-width:400px">';
    echo '<option value="0">- Выберите организацию -</option>';
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
echo local_unics_export_buttons($PAGE->url);

$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, s.category, s.ovz_type, s.difficulty_level
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

// 1. Оценки за тесты: до 6 последних на пользователя (для среднего и тренда).
//    Используем recordset: первый столбец (userid) не уникален, get_records_sql
//    схлопнул бы строки по userid - в группах риска нужен реальный список оценок.
$all_grades_raw = [];
if ($user_ids) {
    [$in_sql, $in_params] = $DB->get_in_or_equal($user_ids);
    $rs = $DB->get_recordset_sql(
        "SELECT g.id, g.userid, g.finalgrade, gi.grademax, g.timemodified
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
    foreach ($rs as $gr) {
        $uid = (int)$gr->userid;
        if (!isset($all_grades_raw[$uid])) {
            $all_grades_raw[$uid] = [];
        }
        if (count($all_grades_raw[$uid]) < 6) {
            $all_grades_raw[$uid][] = $gr;
        }
    }
    $rs->close();
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
$levels = [1 => 'Базовый', 2 => 'Стандарт', 3 => 'Продвинут.'];

// Пороги выявления группы риска (эвристика, без ML - см. core-logic-rework §C-1).
const UNICS_RISK_LOW_AVG    = 50;  // средний балл ниже -> риск
const UNICS_RISK_TREND_DROP = 10;  // падение свежей половины относительно прежней, п.п.
const UNICS_RISK_IDLE_DAYS  = 21;  // нет сданных тестов дольше -> риск

$only_risk = optional_param('risk', 0, PARAM_INT);

// ---- Подсчёт по каждому учащемуся: средний балл, динамика, риск ----
$rows      = [];
$all_avgs  = [];
$level_dist = [1 => 0, 2 => 0, 3 => 0];
$cnt_risk = 0;
$cnt_ok   = 0;
$cnt_nodata = 0;

foreach ($students as $s) {
    $uid    = (int)$s->mdl_user_id;
    $grades = $all_grades_raw[$uid] ?? []; // newest-first, до 6
    $pcts   = array_map(fn($g) => $g->finalgrade / $g->grademax * 100, $grades);
    $n      = count($pcts);

    $avg = null;
    if ($n > 0) {
        $last5 = array_slice($pcts, 0, 5);
        $avg   = round(array_sum($last5) / count($last5), 1);
        $all_avgs[] = $avg;
    }

    // Признаки риска.
    $risk_reasons = [];
    if ($avg !== null && $avg < UNICS_RISK_LOW_AVG) {
        $risk_reasons[] = 'низкий средний балл';
    }
    if ($n >= 4) {
        $half   = (int)ceil($n / 2);
        $recent = array_slice($pcts, 0, $half);
        $older  = array_slice($pcts, $half);
        $ra = array_sum($recent) / count($recent);
        $oa = array_sum($older) / count($older);
        if ($ra + UNICS_RISK_TREND_DROP < $oa) {
            $risk_reasons[] = 'отрицательная динамика';
        }
    }
    if ($n > 0) {
        $idle_days = (int)floor((time() - (int)$grades[0]->timemodified) / 86400);
        if ($idle_days > UNICS_RISK_IDLE_DAYS) {
            $risk_reasons[] = 'нет активности ' . $idle_days . ' дн.';
        }
    }

    $nodata  = ($n === 0);
    $is_risk = !empty($risk_reasons);
    if ($is_risk)       { $cnt_risk++; }
    elseif ($nodata)    { $cnt_nodata++; }
    else                { $cnt_ok++; }

    $lvl = (int)$s->difficulty_level;
    if (isset($level_dist[$lvl])) { $level_dist[$lvl]++; }

    $rows[] = [
        's'         => $s,
        'avg'       => $avg,
        'avg_scale' => $avg === null ? null : grade_scale::from_percent((float)$avg),
        'reasons'   => $risk_reasons,
        'is_risk'   => $is_risk,
        'nodata'    => $nodata,
        'courses'   => $course_counts[$uid] ?? 0,
        'umk'       => $umk_counts[(int)$s->student_id] ?? 0,
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

// ---- Визуализация (C-2): распределение по уровням + риск/норма ----
if ($total > 0) {
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
}

// ---- Фильтр ----
$base_url = new moodle_url('/local/unics/pages/org_report.php', ['org_id' => $org_id]);
if ($cnt_risk > 0) {
    if ($only_risk) {
        echo '<p>' . html_writer::link($base_url, '← Показать всех учащихся',
            ['class' => 'btn btn-sm btn-outline-secondary']) . '</p>';
    } else {
        $risk_url = new moodle_url('/local/unics/pages/org_report.php',
            ['org_id' => $org_id, 'risk' => 1]);
        echo '<p>' . html_writer::link($risk_url, 'Показать только группу риска (' . $cnt_risk . ')',
            ['class' => 'btn btn-sm btn-outline-danger']) . '</p>';
    }
}

// ---- Таблица ----
echo '<table class="table table-sm table-bordered table-hover">';
echo '<thead class="table-light"><tr>
    <th>Учащийся</th><th>Класс</th><th>Категория</th><th>Уровень</th>
    <th>Средний балл</th><th>Риск</th><th>Курсов</th><th>УМК</th><th>Отчёт</th>
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
    echo '<td>' . $r['courses'] . '</td>';
    echo '<td>' . $r['umk'] . '</td>';
    echo '<td>' . $report_link . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

if (!empty($all_avgs)) {
    $org_avg_pct   = round(array_sum($all_avgs) / count($all_avgs), 1);
    $org_avg_scale = grade_scale::from_percent((float)$org_avg_pct);
    $bc            = grade_scale::badge_class($org_avg_scale);
    echo '<p class="mt-2"><strong>Средний балл по организации:</strong> '
        . '<span class="badge badge-' . $bc . ' badge-lg">' . grade_scale::format($org_avg_scale) . '</span>'
        . ' (по последним 5 тестам каждого учащегося)</p>';
}

if ($cnt_risk > 0) {
    $low_avg_scale = grade_scale::from_percent((float)UNICS_RISK_LOW_AVG);
    echo '<div class="alert alert-warning mt-3"><strong>Рекомендации по группе риска:</strong> '
       . 'индивидуальная консультация педагога; пересмотр уровня сложности; '
       . 'генерация повторного УМК по слабым темам; контакт с родителями. '
       . 'Критерии риска: средний балл &lt; ' . grade_scale::format($low_avg_scale) . ', '
       . 'падение динамики &gt; ' . UNICS_RISK_TREND_DROP . ' п.п., '
       . 'нет сданных тестов &gt; ' . UNICS_RISK_IDLE_DAYS . ' дн.</div>';
}

echo $OUTPUT->footer();
