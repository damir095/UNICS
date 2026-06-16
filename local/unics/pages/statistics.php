<?php
// УНИКС: статистика (A5). Сводные срезы учебной активности по иерархии и домену
// (ОВЗ-категория / вид ОВЗ / класс / организация / муниципалитет / регион). Скоуп - по роли.
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/scope_checker.php');
require_once(__DIR__ . '/../classes/student_helper.php');
require_once(__DIR__ . '/../classes/stats_manager.php');

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
    $res = \local_unics\stats_manager::rebuild_all();
    redirect($PAGE->url,
        "Статистика пересчитана: учащихся {$res['students']}, строк {$res['rows']}.",
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$rows = \local_unics\stats_manager::get_student_rows($org_ids);

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

// ==================== ИТОГО ====================
$totals = \local_unics\stats_manager::totals($rows);

$cards = [
    ['Учащихся',          $totals->n_students],
    ['Активны (14 дн)',   $totals->n_active],
    ['Средний балл',      $totals->avg_score === null ? '-' : $totals->avg_score . '%'],
    ['Завершаемость',     $totals->completion_pct === null ? '-' : $totals->completion_pct . '%'],
    ['Просмотры',         $totals->sum_views],
    ['Время',             \local_unics\stats_manager::format_minutes($totals->sum_time)],
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
    echo html_writer::tag('h4', s($title), ['class' => 'mt-4']);
    if (empty($aggs)) {
        echo html_writer::tag('p', 'Нет данных для этого среза.', ['class' => 'text-muted']);
        return;
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
            \local_unics\stats_manager::format_minutes($a->sum_time),
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
$by_cat = \local_unics\stats_manager::aggregate($rows,
    fn($r) => \local_unics\student_helper::category_names($r->category));
$by_cat = local_unics_order_aggs($by_cat, \local_unics\student_helper::category_labels_in_order());
local_unics_render_slice('По категории учащихся', 'Категория', $by_cat);

// --- По виду ОВЗ (только учащиеся с указанным видом ОВЗ) ---
$by_ovz = \local_unics\stats_manager::aggregate($rows,
    fn($r) => \local_unics\student_helper::ovz_type_names($r->ovz_type));
$by_ovz = local_unics_order_aggs($by_ovz, \local_unics\student_helper::ovz_labels_in_order());
local_unics_render_slice('По виду ОВЗ', 'Вид ОВЗ', $by_ovz);

// --- По классу ---
$by_class = \local_unics\stats_manager::aggregate($rows,
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
$by_org = \local_unics\stats_manager::aggregate($rows,
    fn($r) => $r->organization_name ?: 'Без организации');
uksort($by_org, fn($a, $b) => strcoll((string)$a, (string)$b));
local_unics_render_slice('По организации', 'Организация', $by_org);

// --- По муниципалитету (показываем, если их несколько) ---
$by_dist = \local_unics\stats_manager::aggregate($rows,
    fn($r) => $r->district_name ?: 'Без муниципалитета');
if (count($by_dist) > 1) {
    uksort($by_dist, fn($a, $b) => strcoll((string)$a, (string)$b));
    local_unics_render_slice('По муниципалитету', 'Муниципалитет', $by_dist);
}

// --- По региону (показываем, если их несколько) ---
$by_region = \local_unics\stats_manager::aggregate($rows,
    fn($r) => $r->region_name ?: 'Без региона');
if (count($by_region) > 1) {
    uksort($by_region, fn($a, $b) => strcoll((string)$a, (string)$b));
    local_unics_render_slice('По региону', 'Регион', $by_region);
}

echo html_writer::tag('p',
    'Время на курсе - оценка по интервалам между событиями журнала (Moodle не хранит точное '
    . 'время на странице). Завершаемость считается по активностям с включённым отслеживанием.',
    ['class' => 'text-muted mt-4', 'style' => 'font-size:0.85rem;']);

echo $OUTPUT->footer();
