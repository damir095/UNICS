<?php
// УНИКС: статистика (A5). Сводные срезы учебной активности по иерархии и домену
// (ОВЗ-категория / вид ОВЗ / класс / организация / муниципалитет / регион). Скоуп - по роли.
//
// Рендер - mustache (продолжение линии 2.5 аудита, см.
// [[session-kickoff-mustache-slices]] - архив метода): страница собирает ОДИН
// $context, разметка целиком в templates/statistics.mustache. Как в
// umk_status - таблицы срезов и кодификатора остаются доверенным пре-рендером
// html_writer::table() (служебные классы Moodle header/cN/lastcol/lastrow).
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/identity/scope_checker.php');
require_once(__DIR__ . '/../classes/identity/student_helper.php');
require_once(__DIR__ . '/../classes/analytics/stats_manager.php');

require_login();
local_unics_require_not_student();

global $DB, $USER;

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
// manageorg-роли (методист орг./района, региональн./районный админ) видят статистику
// по своему скоупу. Педагог (только viewstudents) пользуется отчётом по курсу.
$is_manageorg  = !$is_admin_user && has_capability('local/unics:manageorg', $sys_ctx);
if (!$is_admin_user && !$is_manageorg) {
    require_capability('local/unics:manage', $sys_ctx);
}

$PAGE->set_context($sys_ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/statistics.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Статистика');
$PAGE->set_heading('Статистика обучения');

// --- Скоуп организаций ---
// Системный админ = вся система (null = без фильтра, включая учащихся без организации).
// Остальные - по своему скоупу (организация / муниципалитет / регион) через org_filter_sql.
if ($is_admin_user) {
    $org_ids = null;
} else {
    [$ofw, $ofp] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $org_ids = array_map('intval',
        $DB->get_fieldset_sql("SELECT o.id FROM {unics_organizations} o WHERE {$ofw}", $ofp));
}

// --- Действие: пересчитать сейчас (не ждать ночной задачи) ---
if (optional_param('action', '', PARAM_ALPHA) === 'rebuild' && confirm_sesskey()) {
    $res = \local_unics\analytics\stats_manager::rebuild_all();
    redirect($PAGE->url,
        "Статистика пересчитана: учащихся {$res['students']}, строк {$res['rows']}.",
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Ранняя выгрузка (Excel/CSV/ODS) общим хелпером - до любого вывода.
local_unics_export_student_stats($org_ids, 'unics-statistika');

$rows = \local_unics\analytics\stats_manager::get_student_rows($org_ids);

echo $OUTPUT->header();
echo local_unics_dashboard_button();

$rebuildurl = new moodle_url($PAGE->url, ['action' => 'rebuild', 'sesskey' => sesskey()]);
$context = ['rebuild_url' => (string)$rebuildurl];

if (empty($rows)) {
    $context['empty'] = ['html' => $OUTPUT->notification(
        'Нет данных в вашем скоупе. Если учащиеся есть, нажмите «Пересчитать сейчас».',
        \core\output\notification::NOTIFY_INFO)];
    echo $OUTPUT->render_from_template('local_unics/statistics', $context);
    echo $OUTPUT->footer();
    return;
}

// Кнопки выгрузки (показываем только при наличии данных).
$context['export_buttons_html'] = local_unics_export_buttons($PAGE->url);

// ==================== ИТОГО ====================
$totals = \local_unics\analytics\stats_manager::totals($rows);

$context['cards'] = [
    ['label' => 'Учащихся',        'value' => $totals->n_students],
    ['label' => 'Активны (14 дн)', 'value' => $totals->n_active],
    ['label' => 'Средний балл',    'value' => $totals->avg_score === null ? '-' : $totals->avg_score . '%'],
    ['label' => 'Завершаемость',   'value' => $totals->completion_pct === null ? '-' : $totals->completion_pct . '%'],
    ['label' => 'Просмотры',       'value' => $totals->sum_views],
    ['label' => 'Время',           'value' => \local_unics\analytics\stats_manager::format_minutes($totals->sum_time)],
    ['label' => 'Попытки тестов',  'value' => $totals->sum_attempts],
    ['label' => 'Выдано УМК',      'value' => $totals->sum_ai],
    ['label' => 'Смен уровня',     'value' => $totals->sum_levelchg],
];

// ==================== СРЕЗЫ ====================

/**
 * Данные среза (без разметки - разметка в templates/statistics.mustache).
 *
 * @param string $title   заголовок
 * @param string $colhead заголовок первого столбца (имя среза)
 * @param array  $aggs    [label => agg] (уже в нужном порядке)
 * @return array контекст одного элемента 'slices'
 */
function local_unics_build_slice_ctx(string $title, string $colhead, array $aggs): array {
    global $OUTPUT;
    if (empty($aggs)) {
        return ['title' => $title, 'has_data' => false];
    }
    // Бар-чарт среднего балла по группам. Грейсфул: рисуем только при >= 2 групп и хотя бы
    // одном непустом балле, иначе плоский/пустой чарт на тонких данных. null -> 0.
    $chart_labels = [];
    $chart_values = [];
    $has_score = false;
    foreach ($aggs as $label => $a) {
        $chart_labels[] = (string)$label;
        $chart_values[] = $a->avg_score === null ? 0 : (float)$a->avg_score;
        if ($a->avg_score !== null) {
            $has_score = true;
        }
    }
    $chart_html = null;
    if (count($aggs) >= 2 && $has_score) {
        $chart = new \core\chart_bar();
        $chart->add_series(new \core\chart_series('Средний балл, %', $chart_values));
        $chart->set_labels($chart_labels);
        $chart_html = $OUTPUT->render_chart($chart, false);
    }
    $t = new html_table();
    $t->attributes['class'] = 'table table-striped table-hover unics-table unics-compact';
    $t->head = [$colhead, 'Учащихся', 'Активны', 'Ср. балл', 'Завершаемость',
        'Просмотры', 'Время', 'Тесты', 'УМК', 'Смен уровня'];
    foreach ($aggs as $label => $a) {
        $t->data[] = [
            s((string)$label),
            $a->n_students,
            $a->n_active,
            $a->avg_score === null ? '-' : $a->avg_score . '%',
            $a->completion_pct === null ? '-' : $a->completion_pct . '%',
            $a->sum_views,
            \local_unics\analytics\stats_manager::format_minutes($a->sum_time),
            $a->sum_attempts,
            $a->sum_ai,
            $a->sum_levelchg,
        ];
    }
    return ['title' => $title, 'has_data' => true, 'chart_html' => $chart_html, 'table_html' => html_writer::table($t)];
}

/** Упорядочить агрегаты по заранее заданному списку меток (присутствующие - в порядке списка). */
function local_unics_order_aggs(array $aggs, array $order): array {
    $out = [];
    foreach ($order as $label) {
        if (isset($aggs[$label])) {
            $out[$label] = $aggs[$label];
        }
    }
    return $out;
}

$slices = [];

// --- По категории (ОВЗ / семейное / лечение / одарённые) ---
$by_cat = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => \local_unics\identity\student_helper::category_names($r->category));
$by_cat = local_unics_order_aggs($by_cat, \local_unics\identity\student_helper::category_labels_in_order());
$slices[] = local_unics_build_slice_ctx('По категории учащихся', 'Категория', $by_cat);

// --- По виду ОВЗ (только учащиеся с указанным видом ОВЗ) ---
$by_ovz = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => \local_unics\identity\student_helper::ovz_type_names($r->ovz_type));
$by_ovz = local_unics_order_aggs($by_ovz, \local_unics\identity\student_helper::ovz_labels_in_order());
$slices[] = local_unics_build_slice_ctx('По виду ОВЗ', 'Вид ОВЗ', $by_ovz);

// --- По классу ---
$by_class = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->class_number ? ((int)$r->class_number . ' класс') : 'Без класса');
uksort($by_class, function ($a, $b) {
    if ($a === 'Без класса') {
        return 1;
    }
    if ($b === 'Без класса') {
        return -1;
    }
    return ((int)$a) <=> ((int)$b);
});
$slices[] = local_unics_build_slice_ctx('По классу', 'Класс', $by_class);

// --- По организации ---
// Единственный неограниченный срез (организаций в регионе - сотни): страницами
// по 25 (этап 3.1). Агрегаты считаются по всем, страница ограничивает только вывод.
$org_page    = optional_param('org_page', 0, PARAM_INT);
$org_perpage = 25;
$by_org = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->organization_name ?: 'Без организации');
uksort($by_org, fn($a, $b) => strcoll((string)$a, (string)$b));
$org_total = count($by_org);
if ($org_page * $org_perpage >= $org_total) {
    $org_page = 0;
}
$org_slice = local_unics_build_slice_ctx('По организации', 'Организация',
    array_slice($by_org, $org_page * $org_perpage, $org_perpage, true));
$org_slice['paging_html'] = local_unics_render_paging_bar($org_total, $org_page, $org_perpage,
    new moodle_url($PAGE->url, ['codifier_id' => optional_param('codifier_id', 0, PARAM_INT)]),
    'org_page');
$slices[] = $org_slice;

// --- По муниципалитету (показываем, если их несколько) ---
$by_dist = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->district_name ?: 'Без муниципалитета');
if (count($by_dist) > 1) {
    uksort($by_dist, fn($a, $b) => strcoll((string)$a, (string)$b));
    $slices[] = local_unics_build_slice_ctx('По муниципалитету', 'Муниципалитет', $by_dist);
}

// --- По региону (показываем, если их несколько) ---
$by_region = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->region_name ?: 'Без региона');
if (count($by_region) > 1) {
    uksort($by_region, fn($a, $b) => strcoll((string)$a, (string)$b));
    $slices[] = local_unics_build_slice_ctx('По региону', 'Регион', $by_region);
}

$context['slices'] = $slices;

// ==================== ПО ЭЛЕМЕНТАМ СОДЕРЖАНИЯ (КОДИФИКАТОР) ====================
$codifiers_all = $DB->get_records('unics_codifier',
    ['status' => \local_unics\codifier_manager::STATUS_ACTIVE], 'name ASC', 'id, name');
$codifier_ctx = [];
if (!$codifiers_all) {
    $codifier_ctx['no_codifiers'] = true;
} else {
    $codifier_id = optional_param('codifier_id', 0, PARAM_INT);
    $tabs = [];
    foreach ($codifiers_all as $cf) {
        $tabs[] = [
            'url'   => (string)new moodle_url($PAGE->url, ['codifier_id' => $cf->id]),
            'label' => $cf->name,
            'cls'   => $cf->id == $codifier_id ? 'btn-primary' : 'btn-outline-primary',
        ];
    }
    $codifier_ctx['tabs'] = $tabs;

    if ($codifier_id && isset($codifiers_all[$codifier_id])) {
        // Когорта в текущем скоупе (org_ids уже посчитан выше).
        if ($org_ids === null) {
            $userids = $DB->get_fieldset_select('unics_students', 'DISTINCT mdl_user_id', 'archived_at IS NULL');
        } else if ($org_ids) {
            list($insql, $inparams) = $DB->get_in_or_equal($org_ids, SQL_PARAMS_NAMED);
            $userids = $DB->get_fieldset_select('unics_students', 'DISTINCT mdl_user_id',
                "archived_at IS NULL AND organization_id $insql", $inparams);
        } else {
            $userids = [];
        }
        $prog = \local_unics\codifier_analytics::cohort_element_progress(
            array_map('intval', $userids), $codifier_id);
        if (!$prog) {
            $codifier_ctx['no_elements'] = true;
        } else {
            $t = new html_table();
            $t->attributes['class'] = 'table table-striped table-hover unics-table';
            $t->head = ['Элемент содержания', 'Средний %', 'Оценок (пар)'];
            foreach ($prog as $r) {
                $indent = str_repeat('&nbsp;&nbsp;&nbsp;', (int)$r->depth);
                $t->data[] = [
                    $indent . ($r->code !== '' ? s($r->code) . ' ' : '') . s($r->title),
                    $r->pct === null ? '-' : $r->pct . '%',
                    $r->pct === null ? '-' : (int)$r->n,
                ];
            }
            $codifier_ctx['table_html'] = html_writer::table($t);
        }
    } else {
        $codifier_ctx['pick_message'] = true;
    }
}
$context['codifier'] = $codifier_ctx;

echo $OUTPUT->render_from_template('local_unics/statistics', $context);
echo $OUTPUT->footer();
