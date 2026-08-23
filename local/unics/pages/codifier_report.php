<?php
/**
 * Отчёт «% по элементам содержания» для учащегося - сквозной по курсам дисциплины.
 * [[codifier-design]]. Доступ как у student_report.php (педагог по привязке / методист
 * по скоупу / админ; ученик - свой; родитель - ребёнка). Детский вид (словами) для
 * самого ученика, подробный (% и число оценок) - для персонала.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\codifier_manager;
use local_unics\codifier_analytics;
use local_unics\learning\gap_manager;
use local_unics\learning\mastery_manager;

require_login();
global $USER, $DB;

$student_id  = required_param('student_id', PARAM_INT);
$codifier_id = optional_param('codifier_id', 0, PARAM_INT);
$ctx = context_system::instance();

$is_admin     = has_capability('local/unics:manage', $ctx);
$is_teacher   = has_capability('local/unics:viewstudents', $ctx);
$is_methodist = $is_teacher && !$is_admin && local_unics_is_methodist();
$is_staff = $is_admin || $is_teacher; // theta показываем только персоналу (не ребенку/родителю)

$student = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

// Контроль доступа (тот же порядок, что в student_report.php).
$access = false;
if ($is_admin) {
    $access = true;
} elseif ($is_methodist) {
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
    $access = $methodist_org_id > 0 && (int)$student->organization_id === $methodist_org_id;
} elseif ($is_teacher) {
    $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if ($teacher_rec) {
        $access = $DB->record_exists('unics_teacher_student',
            ['teacher_id' => $teacher_rec->id, 'student_id' => $student_id]);
    }
}
if (!$access) {
    $access = $DB->record_exists('unics_parent_student',
        ['parent_mdl_user_id' => $USER->id, 'student_id' => $student_id]);
}
$is_own_view = ($USER->id == $student->mdl_user_id);
if (!$access && $is_own_view) {
    $access = true;
}
if (!$access) {
    throw new moodle_exception('accessdenied', 'error');
}

// Кодификаторы, доступные по записанным курсам ученика (через категорию-дисциплину).
$codifiers = $DB->get_records_sql(
    "SELECT DISTINCT cf.id, cf.name
       FROM {unics_codifier} cf
       JOIN {course} c ON c.category = cf.mdl_category_id
       JOIN {enrol} e ON e.courseid = c.id
       JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :uid
      WHERE cf.status = :st
      ORDER BY cf.name",
    ['uid' => $student->mdl_user_id, 'st' => codifier_manager::STATUS_ACTIVE]);

$baseurl = new moodle_url('/local/unics/pages/codifier_report.php', ['student_id' => $student_id]);
$PAGE->set_context($ctx);
$PAGE->set_url($baseurl);
$PAGE->set_title('Элементы содержания - УНИКС');
$PAGE->set_heading('Элементы содержания');
$PAGE->set_pagelayout('standard');

// Выбор кодификатора: явный, либо единственный доступный.
$codifier = null;
if ($codifier_id && isset($codifiers[$codifier_id])) {
    $codifier = $codifiers[$codifier_id];
} elseif (count($codifiers) === 1) {
    $codifier = reset($codifiers);
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Элементы содержания: ' . s(fullname($mdl_user)));

if (!$codifiers) {
    echo $OUTPUT->notification('Для дисциплин учащегося пока нет кодификаторов.',
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    return;
}

// Несколько дисциплин - переключатель.
if (count($codifiers) > 1) {
    echo html_writer::start_tag('div', ['class' => 'mb-3']);
    if (!$codifier) {
        echo html_writer::tag('span', 'Выберите дисциплину: ', ['class' => 'mr-2']);
    }
    foreach ($codifiers as $cf) {
        $cls = ($codifier && $cf->id == $codifier->id) ? 'btn-primary' : 'btn-outline-primary';
        echo html_writer::link(new moodle_url($baseurl, ['codifier_id' => $cf->id]),
            s($cf->name), ['class' => "btn btn-sm $cls mr-2 mb-2"]);
    }
    echo html_writer::end_tag('div');
    if (!$codifier) {
        echo $OUTPUT->footer();
        return;
    }
}

$rows = codifier_analytics::student_element_progress((int)$student->mdl_user_id, (int)$codifier->id);
if (!$rows) {
    echo $OUTPUT->notification('В кодификаторе «' . s($codifier->name) . '» пока нет элементов.',
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    return;
}

$mastery = mastery_manager::get_student_mastery_map((int)$student_id);

// Постранично по корневым разделам (этап 3.1): дерево нельзя резать посреди
// поддерева, поэтому страница = целые корневые блоки, набранные до ~40 строк
// (один негабаритный блок остаётся целым). Пейджер скрыт, пока страница одна.
$el_page = optional_param('el_page', 0, PARAM_INT);
$blocks = [];
$bi = -1;
foreach ($rows as $r) {
    if ((int)$r->depth === 0 || $bi < 0) {
        $bi++;
        $blocks[$bi] = [];
    }
    $blocks[$bi][] = $r;
}
$chunks = [];
$cur = [];
foreach ($blocks as $b) {
    if ($cur && count($cur) + count($b) > 40) {
        $chunks[] = $cur;
        $cur = [];
    }
    $cur = array_merge($cur, $b);
}
if ($cur) {
    $chunks[] = $cur;
}
if ($el_page < 0 || $el_page >= count($chunks)) {
    $el_page = 0;
}
$rows_page = $chunks[$el_page];
$pager_url = new moodle_url($baseurl, ['codifier_id' => (int)$codifier->id]);

echo html_writer::tag('h4', s($codifier->name), ['class' => 'mt-2']);
echo html_writer::start_tag('table', ['class' => 'table']);
$head = html_writer::tag('th', 'Элемент содержания')
    . html_writer::tag('th', $is_own_view ? 'Результат' : 'Средний %');
if (!$is_own_view) {
    $head .= html_writer::tag('th', 'Оценок');
}
if (!$is_own_view) {
    $head .= html_writer::tag('th', 'Владение');
}
echo html_writer::tag('thead', html_writer::tag('tr', $head));
echo html_writer::start_tag('tbody');
$any_theta = false;

foreach ($rows_page as $r) {
    $indent = (int)$r->depth * 24;
    $name = html_writer::tag('span',
        (!$is_own_view && $r->code !== '' ? s($r->code) . ' ' : '') . s($r->title),
        ['style' => "margin-left:{$indent}px;" . ($r->depth == 0 ? 'font-weight:600;' : '')]);

    if ($r->pct === null) {
        $badge = html_writer::tag('span', $is_own_view ? 'еще не изучено' : '-',
            ['class' => 'badge badge-light']);
    } else {
        $p = (float)$r->pct;
        if ($p >= 85) {
            $cls = 'success';
            $word = 'отлично';
        } else if ($p >= 50) {
            $cls = 'warning';
            $word = 'хорошо';
        } else {
            $cls = 'danger';
            $word = 'нужно повторить';
        }
        $label = $is_own_view ? $word : ($p . '%');
        $pint  = (int)round($p);
        // aria-valuetext несет подпись по аудитории (ребенку - слово, сотруднику - %),
        // чтобы скринридер не озвучивал число ребенку; aria-label = имя элемента.
        $badge = html_writer::tag('div', $label, ['class' => 'mb-1'])
            . html_writer::tag('div',
                html_writer::tag('div', '', ['class' => 'unics-meter__fill',
                    'style' => 'width:' . $pint . '%;']),
                ['class' => "unics-meter is-$cls", 'role' => 'progressbar',
                 'aria-valuenow' => $pint, 'aria-valuemin' => 0, 'aria-valuemax' => 100,
                 'aria-valuetext' => $label, 'aria-label' => $r->title]);
    }

    $cells = html_writer::tag('td', $name) . html_writer::tag('td', $badge);
    if (!$is_own_view) {
        $cells .= html_writer::tag('td', $r->pct === null ? '-' : (int)$r->n);
    }
    if (!$is_own_view) {
        $m = $mastery[(int)$r->id] ?? null;
        if ($m === null) {
            $masterycell = html_writer::tag('span', '-', ['class' => 'text-muted']);
        } else {
            [$mtext, $mcls] = mastery_manager::band_label((int)$m->band, $is_own_view);
            $mattrs = ['class' => "badge badge-$mcls",
                'title' => 'балл ' . round($m->score) . '%, попыток ' . (int)$m->attempts_n];
            $masterycell = html_writer::tag('span', $mtext, $mattrs);
            // Оценка могла не достичь заявленной точности: проверка кончилась не тогда, когда
            // стало ясно, а когда кончились задания. Полоса при этом выглядит измеренной, и по
            // ней строится маршрут ([[cat-honest-precision]]).
            $senote = \local_unics\adaptive\estimate_precision::staff_note(
                $m->theta_se !== null ? (float)$m->theta_se : null);
            if ($senote !== '') {
                $masterycell .= html_writer::tag('div', 'предварительно',
                    ['class' => 'text-muted small', 'title' => $senote]);
            }
            // IRT-способность (theta +- SE) - только персоналу и только когда посчитана.
            if ($is_staff && $m->theta !== null) {
                $any_theta = true;
                $masterycell .= html_writer::tag('div',
                    'θ ' . round((float)$m->theta, 2) . ' ± ' . round((float)($m->theta_se ?? 0), 2),
                    ['class' => 'text-muted small']);
            }
        }
        $cells .= html_writer::tag('td', $masterycell);
    }
    echo html_writer::tag('tr', $cells);
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo local_unics_render_paging_bar(count($chunks), $el_page, 1, $pager_url, 'el_page');

if ($is_staff && $any_theta) {
    echo html_writer::tag('p',
        'θ - IRT-оценка способности по навыку (0 - средний уровень), ± - стандартная ошибка (неопределенность оценки).',
        ['class' => 'text-muted small']);
}

// Пробелы по элементам содержания (последняя завершённая попытка каждого теста).
$gaps = gap_manager::student_gaps_by_element((int)$student->mdl_user_id);
if ($gaps) {
    echo html_writer::tag('h4', $is_own_view ? 'Стоит повторить' : 'Пробелы по элементам',
        ['class' => 'mt-4']);
    if ($is_own_view) {
        echo html_writer::tag('p', 'Темы, где есть ошибки в последних работах - стоит повторить.',
            ['class' => 'text-muted']);
    }
    foreach ($gaps as $bucket) {
        $title = ((!$is_own_view && $bucket->code !== '') ? s($bucket->code) . ' ' : '')
            . s($bucket->title);
        if ($is_own_view) {
            echo html_writer::tag('h5', $title, ['class' => 'mt-3']);
        } else {
            echo html_writer::tag('h5',
                $title . ' ' . html_writer::tag('span', 'ошибок: ' . (int)$bucket->wrong_count,
                    ['class' => 'badge badge-light']),
                ['class' => 'mt-3']);
        }
        if (!$is_own_view) {
            echo html_writer::start_tag('ul');
            foreach ($bucket->questions as $q) {
                echo html_writer::tag('li',
                    s($q->qname) . ' ' . gap_manager::state_html((string)$q->state));
            }
            echo html_writer::end_tag('ul');
        }
    }
}

echo $OUTPUT->footer();
