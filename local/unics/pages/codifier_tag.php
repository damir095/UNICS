<?php
/**
 * Тегирование контента кодификатором: привязка активностей к элементам содержания.
 * [[codifier-design]]. Два режима:
 *  - ?cmid=     - одна активность: дерево с чекбоксами, отметить/снять привязки.
 *  - ?courseid= - курсовой вид: активности курса с их тегами + переход к тегированию.
 *
 * Доступ: владелец курса (moodle/course:manageactivities в контексте), методист или
 * системный админ. Дерево здесь НЕ правят (это страница «Кодификатор» регметодиста).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\codifier_manager;
use local_unics\codifier_link_manager;

/**
 * Конкретные вопросы теста (слоты) со стабильным bankentryid и именем последней версии.
 * Случайные вопросы (qtype=random) живут в question_set_references и сюда не попадают.
 * @return array<int,object> объекты {slot, bankentryid, name}
 */
function local_unics_quiz_slot_questions(int $quizid): array {
    global $DB;
    return array_values($DB->get_records_sql(
        "SELECT qs.slot, qr.questionbankentryid AS bankentryid, q.name
           FROM {quiz_slots} qs
           JOIN {question_references} qr
                ON qr.component = 'mod_quiz' AND qr.questionarea = 'slot' AND qr.itemid = qs.id
           JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                   WHERE v.questionbankentryid = qbe.id)
           JOIN {question} q ON q.id = qv.questionid
          WHERE qs.quizid = :quizid
       ORDER BY qs.slot",
        ['quizid' => $quizid]));
}

$cmid     = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
if (!$cmid && !$courseid) {
    throw new moodle_exception('missingparam', 'error', '', 'cmid|courseid');
}

if ($cmid) {
    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    require_login($course, false, $cm);
    $ctx = context_module::instance($cm->id);
    $pageurl = new moodle_url('/local/unics/pages/codifier_tag.php', ['cmid' => $cmid]);
} else {
    $course = get_course($courseid);
    require_login($course);
    $ctx = context_course::instance($courseid);
    $pageurl = new moodle_url('/local/unics/pages/codifier_tag.php', ['courseid' => $courseid]);
}
local_unics_require_not_student();

$can = has_capability('local/unics:manage', context_system::instance())
    || local_unics_is_methodist()
    || has_capability('moodle/course:manageactivities', $ctx);
if (!$can) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]),
        'Недостаточно прав для тегирования кодификатором.', null,
        \core\output\notification::NOTIFY_WARNING);
}

$codifier = codifier_manager::get_codifier_for_course((int)$course->id);

$PAGE->set_context($ctx);
if ($cmid) {
    $PAGE->set_cm($cm, $course);
}
$PAGE->set_url($pageurl);
$PAGE->set_title('Кодификатор: тегирование - УНИКС');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

// ----------------------------------------------------------------
// POST (cmid-режим): сохранить набор связей активности (diff link/unlink).
// ----------------------------------------------------------------
if ($cmid && $codifier && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $checked = array_fill_keys(optional_param_array('el', [], PARAM_INT), true);
    $current = []; // element_id => link_id
    foreach (codifier_link_manager::get_elements_for_activity($cmid) as $e) {
        $current[(int)$e->id] = (int)$e->link_id;
    }
    foreach (codifier_manager::get_tree($codifier->id) as $e) {
        $eid = (int)$e->id;
        $ischecked = isset($checked[$eid]);
        $islinked  = isset($current[$eid]);
        if ($ischecked && !$islinked) {
            codifier_link_manager::link_activity($eid, $cmid, (int)$USER->id);
        } else if (!$ischecked && $islinked) {
            codifier_link_manager::unlink($current[$eid]);
        }
    }
    // Вопросы теста: diff по bankentryid (поля q[<beid>][]=element_id).
    if ($cm->modname === 'quiz') {
        // Поле q[<beid>][] двумерное - optional_param_array его не поддерживает; читаем
        // напрямую с явным приведением к int (sesskey уже проверен выше).
        $qsubmitted = [];
        $qraw = isset($_POST['q']) && is_array($_POST['q']) ? $_POST['q'] : [];
        foreach ($qraw as $beid => $eids) {
            if (is_array($eids)) {
                $qsubmitted[(int)$beid] = array_map('intval', $eids);
            }
        }
        foreach (local_unics_quiz_slot_questions((int)$cm->instance) as $slot) {
            $beid = (int)$slot->bankentryid;
            $want = array_fill_keys(array_map('intval', $qsubmitted[$beid] ?? []), true);
            $have = []; // element_id => link_id
            foreach (codifier_link_manager::get_elements_for_question($beid) as $e) {
                $have[(int)$e->id] = (int)$e->link_id;
            }
            foreach (array_keys($want) as $eid) {
                if (!isset($have[$eid])) {
                    codifier_link_manager::link_question($eid, $beid, (int)$USER->id);
                }
            }
            foreach ($have as $eid => $lid) {
                if (!isset($want[$eid])) {
                    codifier_link_manager::unlink($lid);
                }
            }
        }
    }
    redirect($pageurl, 'Привязки сохранены.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();

if (!$codifier) {
    echo $OUTPUT->notification(
        'У дисциплины этого курса ещё нет кодификатора. Его создаёт региональный методист на странице «Кодификатор».',
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    return;
}

if ($cmid) {
    // Дерево с чекбоксами.
    echo $OUTPUT->heading('Кодификатор: ' . format_string($cm->name));
    echo html_writer::tag('p', 'Отметьте элементы содержания, которые проверяет эта активность.',
        ['class' => 'text-muted']);

    $linked = [];
    foreach (codifier_link_manager::get_elements_for_activity($cmid) as $e) {
        $linked[(int)$e->id] = true;
    }

    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach (codifier_manager::get_tree($codifier->id) as $e) {
        $indent = (int)($e->depth ?? 0) * 24;
        $label = ($e->code !== '' ? s($e->code) . ' ' : '') . s($e->title);
        echo html_writer::start_tag('div', ['class' => 'form-check', 'style' => "margin-left:{$indent}px;"]);
        $attrs = ['type' => 'checkbox', 'name' => 'el[]', 'value' => $e->id,
            'id' => 'el' . $e->id, 'class' => 'form-check-input'];
        if (isset($linked[(int)$e->id])) {
            $attrs['checked'] = 'checked';
        }
        echo html_writer::empty_tag('input', $attrs);
        echo html_writer::tag('label', $label, ['for' => 'el' . $e->id, 'class' => 'form-check-label']);
        echo html_writer::end_tag('div');
    }
    // Блок вопросов теста (если модуль - тест и есть конкретные вопросы).
    if ($cm->modname === 'quiz') {
        $slots = local_unics_quiz_slot_questions((int)$cm->instance);
        if ($slots) {
            $tree = codifier_manager::get_tree($codifier->id);
            echo html_writer::tag('h5', 'Вопросы теста', ['class' => 'mt-4']);
            echo html_writer::tag('p',
                'Привязка вопроса действует во всех тестах, где он используется.',
                ['class' => 'text-muted']);
            foreach ($slots as $slot) {
                $beid = (int)$slot->bankentryid;
                $sel = [];
                foreach (codifier_link_manager::get_elements_for_question($beid) as $e) {
                    $sel[(int)$e->id] = true;
                }
                echo html_writer::start_tag('div', ['class' => 'form-group']);
                echo html_writer::tag('label',
                    (int)$slot->slot . '. ' . format_string($slot->name),
                    ['for' => 'q' . $beid, 'class' => 'font-weight-bold']);
                $opts = '';
                foreach ($tree as $e) {
                    $prefix = str_repeat('- ', (int)($e->depth ?? 0));
                    $label = $prefix . ($e->code !== '' ? s($e->code) . ' ' : '') . s($e->title);
                    $attrs = ['value' => (int)$e->id];
                    if (isset($sel[(int)$e->id])) {
                        $attrs['selected'] = 'selected';
                    }
                    $opts .= html_writer::tag('option', $label, $attrs);
                }
                echo html_writer::tag('select', $opts, [
                    'name' => 'q[' . $beid . '][]', 'id' => 'q' . $beid,
                    'multiple' => 'multiple', 'class' => 'form-control', 'size' => 4]);
                echo html_writer::end_tag('div');
            }
        }
    }
    echo html_writer::tag('button', 'Сохранить привязки', ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
    echo html_writer::end_tag('form');
} else {
    // Курсовой вид: активности + теги.
    echo $OUTPUT->heading('Кодификатор курса');
    $modinfo = get_fast_modinfo($course);
    echo html_writer::start_tag('table', ['class' => 'table table-sm']);
    echo html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', 'Активность') . html_writer::tag('th', 'Элементы содержания') .
        html_writer::tag('th', '', ['style' => 'width:140px;'])));
    echo html_writer::start_tag('tbody');
    foreach ($modinfo->get_cms() as $cm0) {
        if (!$cm0->uservisible || !$cm0->has_view()) {
            continue;
        }
        $tags = codifier_link_manager::get_elements_for_activity((int)$cm0->id);
        if ($tags) {
            $tagtxt = implode(', ', array_map(
                fn($t) => ($t->code !== '' ? s($t->code) . ' ' : '') . s($t->title), $tags));
        } else {
            $tagtxt = html_writer::tag('span', 'не привязано', ['class' => 'text-muted']);
        }
        $taglink = html_writer::link(
            new moodle_url('/local/unics/pages/codifier_tag.php', ['cmid' => $cm0->id]),
            'Тегировать', ['class' => 'btn btn-sm btn-outline-primary']);
        echo html_writer::tag('tr',
            html_writer::tag('td', format_string($cm0->name)) .
            html_writer::tag('td', $tagtxt) .
            html_writer::tag('td', $taglink));
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
