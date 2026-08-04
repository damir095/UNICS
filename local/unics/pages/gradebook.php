<?php
/**
 * Электронный журнал УНИКС (#15).
 *
 * Класс × курс на Moodle grade API (ничего не пересчитывает). Два вида таблицы:
 *  - «order»  — столбцы = порядковые номера полученных оценок (1, 2, 3… по дате),
 *               задание/дата/средний по классу — в подсказке к ячейке;
 *  - «item»   — столбцы = конкретные задания/тесты курса, + средний по заданию.
 *
 * Доступ — нативная capability moodle/course:viewparticipants в контексте курса (админ —
 * везде, педагог — в своих курсах); роль parent ее не имеет, поэтому родителю список
 * курсов пуст [[parent-leak-fix-design]]. При раздельных группах без accessallgroups
 * строки сужаются до групп смотрящего. Предметная (категорийная) изоляция
 * придёт с кластером «Предметы» без переделки страницы.
 *
 * Рендер - mustache (2.5 аудита, [[session-kickoff-mustache-slices]]): страница
 * собирает ОДИН $context, разметка целиком в templates/gradebook.mustache.
 * Построитель local_unics\gradebook::matrix() и экспорт не менялись - оба
 * вида журнала сведены к одной генерической структуре headers/rows/footer_row.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/learning/grade_scale.php');
require_once($CFG->libdir . '/grouplib.php');

use local_unics\learning\grade_scale;

require_login();
local_unics_require_not_student();

global $USER, $DB;

$course_id     = optional_param('course_id', 0,        PARAM_INT);
$filter_cat    = optional_param('f_cat',     0,        PARAM_INT);
$filter_class  = optional_param('f_class',   0,        PARAM_INT);
$filter_letter = optional_param('f_letter',  '',       PARAM_TEXT);
$view          = optional_param('view',      'order',  PARAM_ALPHA);
$pg            = optional_param('page',      0,        PARAM_INT);
$perpage       = 25;
if (!in_array($view, ['order', 'item'], true)) {
    $view = 'order';
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/gradebook.php', ['course_id' => $course_id]));
$PAGE->set_title('Журнал - УНИКС');
$PAGE->set_heading('Электронный журнал');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Доступные курсы: те, где у смотрящего есть право видеть участников. Раньше стояло
// moodle/grade:viewall - его несет и роль parent, из-за чего родителю в селекторе
// показывались чужие курсы ([[parent-leak-fix-design]]). Методист имеет оба права
// (role_manager.php:400,415), поэтому его выдача не сужается.
// ----------------------------------------------------------------
$cap_courses = get_user_capability_course('moodle/course:viewparticipants', $USER->id, true,
    'fullname,shortname,visible,category');
$allowed = [];
if ($cap_courses) {
    foreach ($cap_courses as $c) {
        if ((int)$c->id === (int)SITEID) {
            continue;
        }
        $allowed[(int)$c->id] = $c;
    }
}

// Ранняя выгрузка журнала (Excel/CSV/ODS) - до вывода. Только доступный курс.
if ($course_id && isset($allowed[$course_id])) {
    local_unics_export_gradebook($course_id, $filter_class, $filter_letter);
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

$context = [];

if (empty($allowed)) {
    $context['no_courses'] = ['html' => $OUTPUT->notification(
        'Нет курсов, по которым вам доступен просмотр оценок. '
        . 'Журнал виден педагогам своих курсов и администраторам.',
        'info'
    )];
    echo $OUTPUT->render_from_template('local_unics/gradebook', $context);
    echo $OUTPUT->footer();
    exit;
}

// ----------------------------------------------------------------
// Меню категорий и курсов (раздельные селекторы).
// ----------------------------------------------------------------
[$in_sql, $in_params] = $DB->get_in_or_equal(array_keys($allowed), SQL_PARAMS_NAMED, 'cid');
$course_rows = $DB->get_records_sql(
    "SELECT c.id, c.fullname, cc.id AS catid, cc.name AS catname
       FROM {course} c
       JOIN {course_categories} cc ON cc.id = c.category
      WHERE c.id {$in_sql}
      ORDER BY cc.name, c.fullname",
    $in_params
);

$cat_menu     = [0 => 'Все категории'];
$course_catid = []; // course_id => catid
foreach ($course_rows as $cr) {
    $cat_menu[(int)$cr->catid] = $cr->catname;
    $course_catid[(int)$cr->id] = (int)$cr->catid;
}

// Выбранная категория вне доступных — сбрасываем.
if ($filter_cat > 0 && !isset($cat_menu[$filter_cat])) {
    $filter_cat = 0;
}
// Курс вне доступных или вне выбранной категории — сбрасываем.
if ($course_id > 0 && !isset($allowed[$course_id])) {
    $course_id = 0;
}
if ($course_id > 0 && $filter_cat > 0 && ($course_catid[$course_id] ?? 0) !== $filter_cat) {
    $course_id = 0;
}

$course_menu = [0 => '- Выберите курс -'];
foreach ($course_rows as $cr) {
    if ($filter_cat > 0 && (int)$cr->catid !== $filter_cat) {
        continue;
    }
    $course_menu[(int)$cr->id] = format_string($cr->fullname);
}

$class_menu = [0 => 'Все классы'];
for ($i = 1; $i <= 11; $i++) { $class_menu[$i] = $i . ' класс'; }
$letters_menu = ['' => 'Все буквы', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                 'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];
$view_menu = ['order' => 'Оценки по порядку', 'item' => 'По заданиям'];

// Список опций селектора: [{value, label, selected}] - разметка в шаблоне.
$build_options = function (array $menu, $selected): array {
    $out = [];
    foreach ($menu as $value => $label) {
        $out[] = ['value' => $value, 'label' => $label, 'selected' => (string)$value === (string)$selected];
    }
    return $out;
};
$context['filters'] = [
    'cat_options'    => $build_options($cat_menu, $filter_cat),
    'course_options' => $build_options($course_menu, $course_id),
    'class_options'  => $build_options($class_menu, $filter_class),
    'letter_options' => $build_options($letters_menu, $filter_letter),
    'view_options'   => $build_options($view_menu, $view),
];

if (!$course_id) {
    echo $OUTPUT->render_from_template('local_unics/gradebook', $context);
    echo $OUTPUT->footer();
    exit;
}

$course = get_course($course_id);

// Право правки оценок в контексте ЭТОГО курса ($allowed гарантирует лишь просмотр участников).
// Ядровый single-view требует все три cap (index.php стр. 63-65). [[grade-editing-design]]
$course_ctx = context_course::instance($course_id);
$can_edit = has_capability('moodle/grade:edit', $course_ctx)
         && has_capability('gradereport/singleview:view', $course_ctx);

$course_selected = [
    'course_name'         => $course->fullname,
    'export_buttons_html' => local_unics_export_buttons(
        new moodle_url($PAGE->url, ['f_class' => $filter_class, 'f_letter' => $filter_letter])),
];

// Данные журнала (групп.изоляция, фильтр, ученики, оценки, матрица) - общий построитель.
// Пагинация учеников (этап 3.1): страница строк; колонки/средние - по всей выборке.
$gb = local_unics_gradebook_matrix($course_id, $filter_class, $filter_letter, $pg, $perpage);
if ($gb['notice'] !== null) {
    $course_selected['notice'] = ['html' => $OUTPUT->notification($gb['notice']['text'], $gb['notice']['level'])];
    $context['course_selected'] = $course_selected;
    echo $OUTPUT->render_from_template('local_unics/gradebook', $context);
    echo $OUTPUT->footer();
    exit;
}
$students       = $gb['students'];
$by_user        = $gb['by_user'];
$item_meta      = $gb['item_meta'];
$item_class_avg = $gb['item_class_avg'];

/**
 * Подсказка к ячейке: задание, дата, средний по классу.
 */
$build_tip = function(array $g) use ($item_class_avg) {
    $tip = 'Задание: ' . $g['name'];
    if ($g['time'] > 0) {
        $tip .= "\nДата: " . userdate($g['time'], '%d.%m.%Y');
    }
    $tip .= "\nСредний по классу: " . grade_scale::format($item_class_avg[$g['itemid']]);
    return $tip;
};

// Ячейка с оценкой - данные (без разметки), разметка в шаблоне.
$cell_ctx = function(array $g) use ($build_tip): array {
    return [
        'has_value'   => true,
        'td_cls'      => 'text-center',
        'badge_class' => grade_scale::badge_class($g['val']),
        'value'       => $g['val'],
        'tip'         => $build_tip($g),
    ];
};
$empty_cell_ctx = ['has_value' => false, 'td_cls' => 'text-muted text-center'];

// Карандаш «Править оценки ученика» -> ядровый single-view (item=user). Только при праве.
// Доверенный пре-рендер (pix_icon+html_writer::link - ядровое экранирование).
$edit_user_html = function(int $uid, string $fio) use ($can_edit, $course_id, $OUTPUT): ?string {
    if (!$can_edit) {
        return null;
    }
    $label = 'Править оценки: ' . $fio;
    $url = new moodle_url('/grade/report/singleview/index.php',
        ['id' => $course_id, 'item' => 'user', 'itemid' => $uid]);
    return ' ' . html_writer::link($url, $OUTPUT->pix_icon('t/edit', $label),
        ['class' => 'unics-grade-edit', 'title' => $label]);
};

$row_ctx = function (object $s) use ($by_user, $edit_user_html): array {
    $uid = (int)$s->mdl_user_id;
    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $cls = $s->class_number
        ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
        : '-';
    return [
        'fio'        => $fio,
        'report_url' => (string)new moodle_url('/local/unics/pages/student_report.php',
            ['student_id' => (int)$s->student_id]),
        'edit_html'  => $edit_user_html($uid, $fio),
        'class_str'  => $cls,
    ];
};

$table = ['headers' => [], 'rows' => []];

if ($view === 'item') {
    // ------------------------------------------------------------
    // Вид «по заданиям»: столбцы = задания, нижняя строка = средний по заданию.
    // ------------------------------------------------------------
    uasort($item_meta, fn($a, $b) => $a['sortorder'] <=> $b['sortorder']);
    $ordered_iids = array_keys($item_meta);

    // Быстрый доступ: [userid][itemid] = оценка.
    $grade_by_ui = [];
    foreach ($by_user as $uid => $list) {
        foreach ($list as $g) {
            $grade_by_ui[$uid][$g['itemid']] = $g;
        }
    }

    foreach ($ordered_iids as $iid) {
        $hname = $item_meta[$iid]['name'];
        $edit_item = null;
        if ($can_edit) {
            $ilabel = 'Править оценки за задание: ' . $hname;
            $iurl = new moodle_url('/grade/report/singleview/index.php',
                ['id' => $course_id, 'item' => 'grade', 'itemid' => $iid]);
            $edit_item = ' ' . html_writer::link($iurl, $OUTPUT->pix_icon('t/edit', $ilabel),
                ['class' => 'unics-grade-edit', 'title' => $ilabel]);
        }
        $table['headers'][] = ['label' => $hname, 'title' => $hname, 'edit_html' => $edit_item];
    }

    foreach ($students as $s) {
        $uid = (int)$s->mdl_user_id;
        $row = $row_ctx($s);

        $row_pcts = [];
        foreach ($ordered_iids as $iid) {
            if (isset($grade_by_ui[$uid][$iid])) {
                $g = $grade_by_ui[$uid][$iid];
                $row_pcts[] = $g['pct'];
                $row['cells'][] = $cell_ctx($g);
            } else {
                $row['cells'][] = $empty_cell_ctx;
            }
        }

        if (!empty($row_pcts)) {
            $rv = grade_scale::from_percent(array_sum($row_pcts) / count($row_pcts));
            $row['avg'] = ['has_value' => true, 'badge_class' => grade_scale::badge_class($rv), 'value' => $rv];
        } else {
            $row['avg'] = ['has_value' => false];
        }
        $table['rows'][] = $row;
    }

    // Средний по заданию (по столбцу) — корректен, т.к. это одно и то же задание.
    $table['footer_row'] = ['cells' => array_map(fn($iid) => [
        'badge_class' => grade_scale::badge_class($item_class_avg[$iid]),
        'value'       => $item_class_avg[$iid],
    ], $ordered_iids)];

} else {
    // ------------------------------------------------------------
    // Вид «оценки по порядку»: столбцы = № оценки по дате. Без среднего
    // по столбцу — позиции относятся к разным заданиям, среднее некорректно.
    // ------------------------------------------------------------
    $maxcols = 0;
    foreach ($by_user as $list) {
        $maxcols = max($maxcols, count($list));
    }

    for ($i = 1; $i <= $maxcols; $i++) {
        $table['headers'][] = ['label' => (string)$i, 'cls' => 'text-center'];
    }

    foreach ($students as $s) {
        $uid  = (int)$s->mdl_user_id;
        $row  = $row_ctx($s);
        $list = $by_user[$uid] ?? [];

        for ($i = 0; $i < $maxcols; $i++) {
            $row['cells'][] = isset($list[$i]) ? $cell_ctx($list[$i]) : $empty_cell_ctx;
        }

        if (!empty($list)) {
            $pcts = array_column($list, 'pct');
            $rv = grade_scale::from_percent(array_sum($pcts) / count($pcts));
            $row['avg'] = ['has_value' => true, 'badge_class' => grade_scale::badge_class($rv), 'value' => $rv];
        } else {
            $row['avg'] = ['has_value' => false];
        }
        $table['rows'][] = $row;
    }
}

// Пагинация строк-учеников; фильтры сохраняются в ссылках страниц.
$table['paging_html'] = local_unics_render_paging_bar($gb['total'], $pg, $perpage,
    new moodle_url('/local/unics/pages/gradebook.php', [
        'course_id' => $course_id, 'f_cat' => $filter_cat,
        'f_class' => $filter_class, 'f_letter' => $filter_letter, 'view' => $view,
    ]));

$course_selected['table'] = $table;
$context['course_selected'] = $course_selected;

echo $OUTPUT->render_from_template('local_unics/gradebook', $context);
echo $OUTPUT->footer();
