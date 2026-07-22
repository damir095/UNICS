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

// ----------------------------------------------------------------
// Сборка контекста шаблона (2.5 аудита: разметка целиком в
// templates/org_report.mustache; здесь - только данные).
// ----------------------------------------------------------------

$context = ['users_url' => (string)new moodle_url('/local/unics/pages/users.php')];

// Список организаций для селектора — по скоупу.
if (!$fixed_org) {
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
    $context['selector'] = ['options' => array_map(fn($oid, $olabel) => [
        'id'       => $oid,
        'label'    => $olabel,
        'selected' => $oid == $org_id,
    ], array_keys($orgs), array_values($orgs))];
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

if (!$org_id) {
    echo $OUTPUT->render_from_template('local_unics/org_report', $context);
    echo $OUTPUT->footer();
    exit;
}

$org = $DB->get_record('unics_organizations', ['id' => $org_id]);
if (!$org) {
    $context['not_found'] = ['html' => $OUTPUT->notification('Организация не найдена.', 'error')];
    echo $OUTPUT->render_from_template('local_unics/org_report', $context);
    echo $OUTPUT->footer();
    exit;
}

// Заголовок орг. + кнопки выгрузки показываются и при пустом списке учащихся
// (оригинал печатал их ДО проверки $students) - общая шапка вне ветки report.
$context['header'] = [
    'org_name'            => $org->name,
    'export_buttons_html' => local_unics_export_buttons($PAGE->url),
];

// Категории/ОВЗ - из нормализованных таблиц с прежними алиасами (этап 2.6-B).
[$catsql, $ovzsql] = \local_unics\identity\student_helper::taxonomy_select_sql('s');
$students = $DB->get_records_sql(
    "SELECT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, {$catsql}, {$ovzsql}, s.difficulty_level
     FROM {unics_students} s
     JOIN {user} u ON u.id = s.mdl_user_id
     WHERE s.organization_id = :orgid AND u.deleted = 0
     ORDER BY s.class_number, u.lastname, u.firstname",
    ['orgid' => $org_id]
);

if (empty($students)) {
    $context['no_students'] = ['html' => $OUTPUT->notification('В этой организации нет учащихся.', 'info')];
    echo $OUTPUT->render_from_template('local_unics/org_report', $context);
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
// Данные
// ----------------------------------------------------------------
$levels = [1 => 'Базовый', 2 => 'Стандарт', 3 => 'Продвинут.'];

// Пороги выявления группы риска (эвристика, без ML - см. core-logic-rework §C-1).
const UNICS_RISK_LOW_AVG    = 50;  // средний балл ниже -> риск
const UNICS_RISK_TREND_DROP = 10;  // падение свежей половины относительно прежней, п.п.
const UNICS_RISK_IDLE_DAYS  = 21;  // нет сданных тестов дольше -> риск

$only_risk = optional_param('risk', 0, PARAM_INT);

// ---- Подсчёт по каждому учащемуся: средний балл, динамика, риск ----
$rows       = [];
$all_avgs   = [];
$level_dist = [1 => 0, 2 => 0, 3 => 0];
$cnt_risk   = 0;
$cnt_ok     = 0;
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

// ----------------------------------------------------------------
// Сборка контекста «report» (данные - разметка целиком в шаблоне).
// ----------------------------------------------------------------
$report = [];

// ---- Мини-карточки сводки ----
$report['stats'] = [
    ['value' => $total,      'label' => 'Учащихся',       'value_class' => null,           'card_class' => null],
    ['value' => $cnt_risk,   'label' => 'В группе риска', 'value_class' => 'text-danger',  'card_class' => $cnt_risk ? 'border-danger' : null],
    ['value' => $cnt_ok,     'label' => 'Без риска',      'value_class' => 'text-success', 'card_class' => null],
    ['value' => $cnt_nodata, 'label' => 'Нет данных',     'value_class' => 'text-muted',   'card_class' => null],
];

// ---- Визуализация (C-2): распределение по уровням + риск/норма ----
$chart_lvl = new \core\chart_bar();
$chart_lvl->add_series(new \core\chart_series('Учащихся',
    [$level_dist[1], $level_dist[2], $level_dist[3]]));
$chart_lvl->set_labels(['Базовый', 'Стандарт', 'Продвинут.']);

$chart_risk = new \core\chart_bar();
$chart_risk->add_series(new \core\chart_series('Учащихся',
    [$cnt_risk, $cnt_ok, $cnt_nodata]));
$chart_risk->set_labels(['В группе риска', 'Без риска', 'Нет данных']);

$report['charts'] = [
    ['title' => 'Распределение по уровням', 'html' => $OUTPUT->render_chart($chart_lvl, false)],
    ['title' => 'Группа риска',             'html' => $OUTPUT->render_chart($chart_risk, false)],
];

// ---- Фильтр «группа риска» ----
$base_url = new moodle_url('/local/unics/pages/org_report.php', ['org_id' => $org_id]);
if ($cnt_risk > 0) {
    if ($only_risk) {
        $report['risk_toggle'] = [
            'url' => (string)$base_url, 'label' => '← Показать всех учащихся',
            'cls' => 'btn btn-sm btn-outline-secondary',
        ];
    } else {
        $risk_url = new moodle_url('/local/unics/pages/org_report.php',
            ['org_id' => $org_id, 'risk' => 1]);
        $report['risk_toggle'] = [
            'url' => (string)$risk_url, 'label' => 'Показать только группу риска (' . $cnt_risk . ')',
            'cls' => 'btn btn-sm btn-outline-danger',
        ];
    }
}

// ---- Таблица ----
$report['rows'] = [];
foreach ($rows as $r) {
    if ($only_risk && !$r['is_risk']) {
        continue;
    }
    $s         = $r['s'];
    $avg_scale = $r['avg_scale'];

    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $class_str = $s->class_number
        ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
        : '-';

    $report['rows'][] = [
        'row_class'  => $r['is_risk'] ? 'table-danger' : '',
        'fio'        => $fio,
        'class_str'  => $class_str,
        'cat_label'  => \local_unics\identity\student_helper::format_categories($s) ?: '-',
        'level_label' => $levels[$s->difficulty_level] ?? '-',
        'avg_badge'  => $avg_scale === null ? null : [
            'class' => grade_scale::badge_class($avg_scale),
            'text'  => grade_scale::format($avg_scale),
        ],
        'risk'       => $r['is_risk'] ? ['reasons' => implode('; ', $r['reasons'])] : null,
        'nodata'     => $r['nodata'],
        'courses'    => $r['courses'],
        'umk'        => $r['umk'],
        'report_url' => (string)new moodle_url('/local/unics/pages/student_report.php',
            ['student_id' => (int)$s->student_id]),
    ];
}

if (!empty($all_avgs)) {
    $org_avg_pct   = round(array_sum($all_avgs) / count($all_avgs), 1);
    $org_avg_scale = grade_scale::from_percent((float)$org_avg_pct);
    $report['org_avg'] = [
        'badge_class' => grade_scale::badge_class($org_avg_scale),
        'text'        => grade_scale::format($org_avg_scale),
    ];
}

if ($cnt_risk > 0) {
    $low_avg_scale = grade_scale::from_percent((float)UNICS_RISK_LOW_AVG);
    $report['risk_alert'] = [
        'low_avg_text' => grade_scale::format($low_avg_scale),
        'trend_drop'   => UNICS_RISK_TREND_DROP,
        'idle_days'    => UNICS_RISK_IDLE_DAYS,
    ];
}

$context['report'] = $report;

// ----------------------------------------------------------------
// Вывод (header/dashboard_button уже выведены выше, до ранних exit).
// ----------------------------------------------------------------
echo $OUTPUT->render_from_template('local_unics/org_report', $context);
echo $OUTPUT->footer();
