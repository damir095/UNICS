<?php
// УНИКС: статистика (A5). Сводные срезы учебной активности по иерархии и домену
// (ОВЗ-категория / вид ОВЗ / класс / организация / муниципалитет / регион). Скоуп - по роли.
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/scope_checker.php');
require_once(__DIR__ . '/../classes/student_helper.php');
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
    [$ofw, $ofp] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
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

// Кнопка пересчёта.
$rebuildurl = new moodle_url($PAGE->url, ['action' => 'rebuild', 'sesskey' => sesskey()]);
echo html_writer::start_div('mb-3');
echo html_writer::tag('a', 'Пересчитать сейчас',
    ['href' => $rebuildurl->out(false), 'class' => 'btn btn-secondary']);
echo ' ' . html_writer::tag('span',
    'Обычно статистика обновляется ночной задачей. Кнопка пересчитывает её немедленно.',
    ['class' => 'text-muted ml-2']);
echo html_writer::end_div();

if (empty($rows)) {
    echo $OUTPUT->notification(
        'Нет данных в вашем скоупе. Если учащиеся есть, нажмите «Пересчитать сейчас».',
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    return;
}

// Кнопки выгрузки (показываем только при наличии данных).
echo local_unics_export_buttons($PAGE->url);

// ==================== ИТОГО ====================
$totals = \local_unics\analytics\stats_manager::totals($rows);

$cards = [
    ['Учащихся',          $totals->n_students],
    ['Активны (14 дн)',   $totals->n_active],
    ['Средний балл',      $totals->avg_score === null ? '-' : $totals->avg_score . '%'],
    ['Завершаемость',     $totals->completion_pct === null ? '-' : $totals->completion_pct . '%'],
    ['Просмотры',         $totals->sum_views],
    ['Время',             \local_unics\analytics\stats_manager::format_minutes($totals->sum_time)],
    ['Попытки тестов',    $totals->sum_attempts],
    ['Выдано УМК',        $totals->sum_ai],
    ['Смен уровня',       $totals->sum_levelchg],
];
echo html_writer::tag('h4', 'Итого по скоупу', ['class' => 'mt-2']);
echo html_writer::start_div('d-flex flex-wrap mb-4', ['style' => 'gap:0.75rem;']);
foreach ($cards as [$label, $value]) {
    echo html_writer::start_div('card', ['style' => 'min-width:140px;flex:1 1 140px;']);
    echo html_writer::start_div('card-body p-3 text-center');
    echo html_writer::tag('div', s((string)$value), ['style' => 'font-size:1.6rem;font-weight:700;']);
    echo html_writer::tag('div', s($label), ['class' => 'text-muted', 'style' => 'font-size:0.85rem;']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

// ==================== СРЕЗЫ ====================

/**
 * Отрисовать таблицу среза.
 *
 * @param string $title   заголовок
 * @param string $colhead заголовок первого столбца (имя среза)
 * @param array  $aggs    [label => agg] (уже в нужном порядке)
 */
function local_unics_render_slice(string $title, string $colhead, array $aggs): void {
    global $OUTPUT;
    echo html_writer::tag('h4', s($title), ['class' => 'mt-4']);
    if (empty($aggs)) {
        echo html_writer::tag('p', 'Нет данных для этого среза.', ['class' => 'text-muted']);
        return;
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
    if (count($aggs) >= 2 && $has_score) {
        $chart = new \core\chart_bar();
        $chart->add_series(new \core\chart_series('Средний балл, %', $chart_values));
        $chart->set_labels($chart_labels);
        echo $OUTPUT->render_chart($chart, false);
    }
    $t = new html_table();
    $t->attributes['class'] = 'table table-striped table-hover';
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
    echo html_writer::table($t);
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

// --- По категории (ОВЗ / семейное / лечение / одарённые) ---
$by_cat = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => \local_unics\student_helper::category_names($r->category));
$by_cat = local_unics_order_aggs($by_cat, \local_unics\student_helper::category_labels_in_order());
local_unics_render_slice('По категории учащихся', 'Категория', $by_cat);

// --- По виду ОВЗ (только учащиеся с указанным видом ОВЗ) ---
$by_ovz = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => \local_unics\student_helper::ovz_type_names($r->ovz_type));
$by_ovz = local_unics_order_aggs($by_ovz, \local_unics\student_helper::ovz_labels_in_order());
local_unics_render_slice('По виду ОВЗ', 'Вид ОВЗ', $by_ovz);

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
local_unics_render_slice('По классу', 'Класс', $by_class);

// --- По организации ---
$by_org = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->organization_name ?: 'Без организации');
uksort($by_org, fn($a, $b) => strcoll((string)$a, (string)$b));
local_unics_render_slice('По организации', 'Организация', $by_org);

// --- По муниципалитету (показываем, если их несколько) ---
$by_dist = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->district_name ?: 'Без муниципалитета');
if (count($by_dist) > 1) {
    uksort($by_dist, fn($a, $b) => strcoll((string)$a, (string)$b));
    local_unics_render_slice('По муниципалитету', 'Муниципалитет', $by_dist);
}

// --- По региону (показываем, если их несколько) ---
$by_region = \local_unics\analytics\stats_manager::aggregate($rows,
    fn($r) => $r->region_name ?: 'Без региона');
if (count($by_region) > 1) {
    uksort($by_region, fn($a, $b) => strcoll((string)$a, (string)$b));
    local_unics_render_slice('По региону', 'Регион', $by_region);
}

// ==================== ПО ЭЛЕМЕНТАМ СОДЕРЖАНИЯ (КОДИФИКАТОР) ====================
echo html_writer::tag('h4', 'По элементам содержания', ['class' => 'mt-4']);
$codifiers_all = $DB->get_records('unics_codifier',
    ['status' => \local_unics\codifier_manager::STATUS_ACTIVE], 'name ASC', 'id, name');
if (!$codifiers_all) {
    echo html_writer::tag('p', 'Кодификаторы ещё не созданы.', ['class' => 'text-muted']);
} else {
    $codifier_id = optional_param('codifier_id', 0, PARAM_INT);
    echo html_writer::start_div('mb-2');
    foreach ($codifiers_all as $cf) {
        $cls = $cf->id == $codifier_id ? 'btn-primary' : 'btn-outline-primary';
        echo html_writer::link(new moodle_url($PAGE->url, ['codifier_id' => $cf->id]),
            s($cf->name), ['class' => "btn btn-sm $cls mr-2 mb-2"]);
    }
    echo html_writer::end_div();

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
            echo html_writer::tag('p', 'В этом кодификаторе нет элементов.', ['class' => 'text-muted']);
        } else {
            $t = new html_table();
            $t->attributes['class'] = 'table table-striped table-hover';
            $t->head = ['Элемент содержания', 'Средний %', 'Оценок (пар)'];
            foreach ($prog as $r) {
                $indent = str_repeat('&nbsp;&nbsp;&nbsp;', (int)$r->depth);
                $t->data[] = [
                    $indent . ($r->code !== '' ? s($r->code) . ' ' : '') . s($r->title),
                    $r->pct === null ? '-' : $r->pct . '%',
                    $r->pct === null ? '-' : (int)$r->n,
                ];
            }
            echo html_writer::table($t);
        }
    } else {
        echo html_writer::tag('p', 'Выберите дисциплину, чтобы увидеть средние по элементам содержания.',
            ['class' => 'text-muted']);
    }
}

echo html_writer::tag('p',
    'Время на курсе - оценка по интервалам между событиями журнала (Moodle не хранит точное '
    . 'время на странице). Завершаемость считается по активностям с включённым отслеживанием.',
    ['class' => 'text-muted mt-4', 'style' => 'font-size:0.85rem;']);

echo $OUTPUT->footer();
