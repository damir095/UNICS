<?php
/**
 * Электронный журнал УНИКС (#15).
 *
 * Класс × курс на Moodle grade API (ничего не пересчитывает). Два вида таблицы:
 *  - «order»  — столбцы = порядковые номера полученных оценок (1, 2, 3… по дате),
 *               задание/дата/средний по классу — в подсказке к ячейке;
 *  - «item»   — столбцы = конкретные задания/тесты курса, + средний по заданию.
 *
 * Доступ — нативная capability moodle/grade:viewall в контексте курса (админ —
 * везде, педагог — в своих курсах). При раздельных группах без accessallgroups
 * строки сужаются до групп смотрящего. Предметная (категорийная) изоляция
 * придёт с кластером «Предметы» без переделки страницы.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/grade_scale.php');
require_once($CFG->libdir . '/grouplib.php');

use local_unics\grade_scale;

require_login();
local_unics_require_not_student();

global $USER, $DB;

$course_id     = optional_param('course_id', 0,        PARAM_INT);
$filter_cat    = optional_param('f_cat',     0,        PARAM_INT);
$filter_class  = optional_param('f_class',   0,        PARAM_INT);
$filter_letter = optional_param('f_letter',  '',       PARAM_TEXT);
$view          = optional_param('view',      'order',  PARAM_ALPHA);
if (!in_array($view, ['order', 'item'], true)) {
    $view = 'order';
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/gradebook.php', ['course_id' => $course_id]));
$PAGE->set_title('Журнал - УНИКС');
$PAGE->set_heading('Электронный журнал');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Доступные курсы: те, где у смотрящего есть moodle/grade:viewall.
// ----------------------------------------------------------------
$cap_courses = get_user_capability_course('moodle/grade:viewall', $USER->id, true,
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

echo $OUTPUT->header();
echo local_unics_dashboard_button();

if (empty($allowed)) {
    echo $OUTPUT->notification(
        'Нет курсов, по которым вам доступен просмотр оценок. '
        . 'Журнал виден педагогам своих курсов и администраторам.',
        'info'
    );
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
    $course_menu[(int)$cr->id] = $cr->fullname;
}

$class_menu = [0 => 'Все классы'];
for ($i = 1; $i <= 11; $i++) { $class_menu[$i] = $i . ' класс'; }
$letters_menu = ['' => 'Все буквы', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                 'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];
$view_menu = ['order' => 'Оценки по порядку', 'item' => 'По заданиям'];

echo html_writer::start_tag('form',
    ['method' => 'get', 'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3']);
// Смена категории / вида перестраивает список курсов и таблицу сразу.
echo html_writer::select($cat_menu, 'f_cat', $filter_cat, false,
    ['class' => 'form-control', 'onchange' => 'this.form.submit()']);
echo html_writer::select($course_menu, 'course_id', $course_id, false,
    ['class' => 'form-control', 'style' => 'max-width:340px']);
echo html_writer::select($class_menu,   'f_class',  $filter_class,  false, ['class' => 'form-control']);
echo html_writer::select($letters_menu, 'f_letter', $filter_letter, false, ['class' => 'form-control']);
echo html_writer::select($view_menu, 'view', $view, false,
    ['class' => 'form-control', 'onchange' => 'this.form.submit()']);
echo html_writer::tag('button', 'Показать', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!$course_id) {
    echo $OUTPUT->footer();
    exit;
}

$course     = get_course($course_id);
$course_ctx = context_course::instance($course_id);

echo '<h5 class="mb-3">' . s($course->fullname) . '</h5>';

// ----------------------------------------------------------------
// Групповая изоляция: раздельные группы без accessallgroups → только
// учащиеся из групп смотрящего.
// ----------------------------------------------------------------
$restrict_uids = null; // null = без ограничения
if (groups_get_course_groupmode($course) == SEPARATEGROUPS
        && !has_capability('moodle/site:accessallgroups', $course_ctx)) {
    $my_groups = groups_get_user_groups($course_id, $USER->id);
    $gids = !empty($my_groups[0]) ? $my_groups[0] : [];
    $restrict_uids = [];
    foreach ($gids as $gid) {
        foreach (groups_get_members($gid, 'u.id') as $m) {
            $restrict_uids[(int)$m->id] = true;
        }
    }
    $restrict_uids = array_keys($restrict_uids);
    if (empty($restrict_uids)) {
        echo $OUTPUT->notification(
            'Вы не состоите ни в одной группе этого курса, а курс использует раздельные группы. '
            . 'Обратитесь к методисту для добавления в группу.',
            'warning'
        );
        echo $OUTPUT->footer();
        exit;
    }
}

// ----------------------------------------------------------------
// Строки: активные учащиеся, записанные на курс (+ фильтр класса).
// ----------------------------------------------------------------
$where  = 'ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid';
$params = ['cid' => $course_id];
if ($filter_class > 0)     { $where .= ' AND s.class_number = :fclass';  $params['fclass']  = $filter_class; }
if ($filter_letter !== '') { $where .= ' AND s.class_letter = :fletter'; $params['fletter'] = $filter_letter; }
if ($restrict_uids !== null) {
    [$ruin, $ruparams] = $DB->get_in_or_equal($restrict_uids, SQL_PARAMS_NAMED, 'ru');
    $where .= " AND s.mdl_user_id {$ruin}";
    $params += $ruparams;
}

$students = $DB->get_records_sql(
    "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter
       FROM {unics_students} s
       JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
       JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE {$where}
      ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
    $params
);

if (empty($students)) {
    echo $OUTPUT->notification('На этот курс не записано активных учащихся по выбранным условиям.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// ----------------------------------------------------------------
// Оценки одним запросом по показанным ученикам.
// ----------------------------------------------------------------
$user_ids = array_map('intval', array_column((array)$students, 'mdl_user_id'));
[$uin, $uparams] = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NAMED, 'u');
$grade_rows = $DB->get_records_sql(
    "SELECT g.id, g.userid, g.itemid, g.finalgrade, gi.grademax, gi.itemname, gi.itemmodule,
            gi.sortorder, COALESCE(g.timemodified, g.timecreated, 0) AS gtime
       FROM {grade_grades} g
       JOIN {grade_items} gi ON gi.id = g.itemid
      WHERE g.userid {$uin}
        AND gi.courseid   = :cid
        AND gi.itemtype   = 'mod'
        AND gi.grademax   > 0
        AND g.finalgrade IS NOT NULL
      ORDER BY g.userid, gtime, g.id",
    $uparams + ['cid' => $course_id]
);

$by_user    = []; // userid => [ {itemid,pct,val,name,time} ] в порядке получения
$item_meta  = []; // itemid => {name, sortorder}
$item_sum   = []; // itemid => сумма pct (для среднего по классу)
$item_cnt   = []; // itemid => число оценок
foreach ($grade_rows as $gr) {
    $uid = (int)$gr->userid;
    $iid = (int)$gr->itemid;
    $pct = $gr->finalgrade / (float)$gr->grademax * 100;
    $by_user[$uid][] = [
        'itemid' => $iid,
        'pct'    => $pct,
        'val'    => grade_scale::from_percent($pct),
        'name'   => $gr->itemname ?: $gr->itemmodule,
        'time'   => (int)$gr->gtime,
    ];
    if (!isset($item_meta[$iid])) {
        $item_meta[$iid] = ['name' => $gr->itemname ?: $gr->itemmodule, 'sortorder' => (int)$gr->sortorder];
        $item_sum[$iid]  = 0.0;
        $item_cnt[$iid]  = 0;
    }
    $item_sum[$iid] += $pct;
    $item_cnt[$iid]++;
}

if (empty($grade_rows)) {
    echo $OUTPUT->notification('Оценок по этому курсу ещё нет.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Средний по классу (единая шкала) на каждое задание — для подсказки.
$item_class_avg = [];
foreach ($item_sum as $iid => $sum) {
    $item_class_avg[$iid] = grade_scale::from_percent($sum / $item_cnt[$iid]);
}

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

$cell = function(array $g) use ($build_tip) {
    return '<span class="badge badge-' . grade_scale::badge_class($g['val'])
        . '" title="' . s($build_tip($g)) . '">' . $g['val'] . '</span>';
};

echo '<div class="table-responsive">';
echo '<table class="table table-sm table-bordered table-hover unics-gradebook">';

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

    echo '<thead class="table-light"><tr>';
    echo '<th>Учащийся</th><th>Класс</th>';
    foreach ($ordered_iids as $iid) {
        echo '<th title="' . s($item_meta[$iid]['name']) . '">' . s($item_meta[$iid]['name']) . '</th>';
    }
    echo '<th>Средний</th>';
    echo '</tr></thead><tbody>';

    foreach ($students as $s) {
        $uid = (int)$s->mdl_user_id;
        $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
        $cls = $s->class_number
            ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
            : '-';

        echo '<tr>';
        echo '<td>' . html_writer::link(
            new moodle_url('/local/unics/pages/student_report.php', ['student_id' => (int)$s->student_id]),
            s($fio)
        ) . '</td>';
        echo '<td>' . s($cls) . '</td>';

        $row_pcts = [];
        foreach ($ordered_iids as $iid) {
            if (isset($grade_by_ui[$uid][$iid])) {
                $g = $grade_by_ui[$uid][$iid];
                $row_pcts[] = $g['pct'];
                echo '<td class="text-center">' . $cell($g) . '</td>';
            } else {
                echo '<td class="text-muted text-center">–</td>';
            }
        }

        if (!empty($row_pcts)) {
            $rv = grade_scale::from_percent(array_sum($row_pcts) / count($row_pcts));
            echo '<td><span class="badge badge-' . grade_scale::badge_class($rv) . '">' . $rv . '</span></td>';
        } else {
            echo '<td class="text-muted text-center">–</td>';
        }
        echo '</tr>';
    }

    // Средний по заданию (по столбцу) — корректен, т.к. это одно и то же задание.
    echo '<tr class="table-light"><th>Средний по заданию</th><th></th>';
    foreach ($ordered_iids as $iid) {
        echo '<th class="text-center"><span class="badge badge-' . grade_scale::badge_class($item_class_avg[$iid])
           . '">' . $item_class_avg[$iid] . '</span></th>';
    }
    echo '<th></th></tr>';

} else {
    // ------------------------------------------------------------
    // Вид «оценки по порядку»: столбцы = № оценки по дате. Без среднего
    // по столбцу — позиции относятся к разным заданиям, среднее некорректно.
    // ------------------------------------------------------------
    $maxcols = 0;
    foreach ($by_user as $list) {
        $maxcols = max($maxcols, count($list));
    }

    echo '<thead class="table-light"><tr>';
    echo '<th>Учащийся</th><th>Класс</th>';
    for ($i = 1; $i <= $maxcols; $i++) {
        echo '<th class="text-center">' . $i . '</th>';
    }
    echo '<th>Средний</th>';
    echo '</tr></thead><tbody>';

    foreach ($students as $s) {
        $uid  = (int)$s->mdl_user_id;
        $fio  = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
        $cls  = $s->class_number
            ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
            : '-';
        $list = $by_user[$uid] ?? [];

        echo '<tr>';
        echo '<td>' . html_writer::link(
            new moodle_url('/local/unics/pages/student_report.php', ['student_id' => (int)$s->student_id]),
            s($fio)
        ) . '</td>';
        echo '<td>' . s($cls) . '</td>';

        for ($i = 0; $i < $maxcols; $i++) {
            if (isset($list[$i])) {
                echo '<td class="text-center">' . $cell($list[$i]) . '</td>';
            } else {
                echo '<td class="text-muted text-center">–</td>';
            }
        }

        if (!empty($list)) {
            $pcts = array_column($list, 'pct');
            $rv = grade_scale::from_percent(array_sum($pcts) / count($pcts));
            echo '<td><span class="badge badge-' . grade_scale::badge_class($rv) . '">' . $rv . '</span></td>';
        } else {
            echo '<td class="text-muted text-center">–</td>';
        }
        echo '</tr>';
    }
}

echo '</tbody></table>';
echo '</div>';

echo $OUTPUT->footer();
