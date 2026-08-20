<?php
/**
 * Разметка банка вопросов кодификатором с помощью ИИ ([[codifier-bank-tagging-design]]).
 *
 * Три шага в одном файле: форма -> предпросмотр -> применение. В базу не пишется ничего, пока
 * методист не нажал «Привязать отмеченные»: ошибочная привязка отправит ребенка на задание не по
 * своей теме, и в пуле этого уже не видно.
 *
 * Доступ: тот же гейт, что у codifier.php - системный администратор (local/unics:manage) либо
 * региональный администратор / методист (local_unics_is_scoped_admin).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\codifier_manager;
use local_unics\ai\question_tagger;

require_login();
local_unics_require_not_student();

$syscontext = context_system::instance();
$is_admin = has_capability('local/unics:manage', $syscontext);
if (!$is_admin && !local_unics_is_scoped_admin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'управление кодификатором');
}

$categoryid = required_param('categoryid', PARAM_INT);
$backurl = new moodle_url('/local/unics/pages/codifier.php', ['categoryid' => $categoryid]);

$codifier = codifier_manager::get_codifier_for_category($categoryid);
if (!$codifier) {
    redirect($backurl, 'Сначала создайте кодификатор для этой дисциплины.', null,
        \core\output\notification::NOTIFY_WARNING);
}
$baseurl = new moodle_url('/local/unics/pages/codifier_bank_ai.php', ['categoryid' => $categoryid]);

$PAGE->set_context($syscontext);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Разметка банка вопросов - УНИКС');
$PAGE->set_heading('Разметка банка вопросов кодификатором');

$action = optional_param('action', '', PARAM_ALPHA);
$batch  = optional_param('batch', question_tagger::BATCH, PARAM_INT);
$error  = '';
$rows   = [];
$tree   = codifier_manager::get_tree((int)$codifier->id);

// ----------------------------------------------------------------
// POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $batch = min(question_tagger::BATCH, max(1, $batch));

    // Кнопка «Предложить заново» на предпросмотре перебивает скрытое action=apply.
    if (optional_param('regenerate', 0, PARAM_INT)) {
        $action = 'propose';
    }

    if ($action === 'propose') {
        // Худший случай: две попытки разбора, в каждой до двух обращений к модели по 60 секунд
        // плюс авторизация.
        \core_php_time_limit::raise(question_tagger::PARSE_ATTEMPTS * 2 * 60 + 60);
        $questions = question_tagger::untagged((int)$codifier->id, $batch);
        $elements  = question_tagger::elements_for_prompt((int)$codifier->id);
        if (!$questions) {
            redirect($backurl, 'Неразмеченных вопросов нет.', null,
                \core\output\notification::NOTIFY_WARNING);
        }
        if (!$elements) {
            redirect($backurl, 'В кодификаторе нет элементов: сначала наполните структуру.', null,
                \core\output\notification::NOTIFY_WARNING);
        }
        try {
            $tags = (new question_tagger())->propose($questions, $elements);
            // Модель говорит кодами, форма выбирает id элемента.
            $bycode = [];
            foreach ($tree as $e) {
                $bycode[(string)$e->code] = (int)$e->id;
            }
            $suggest = [];
            foreach ($tags as $t) {
                $suggest[$t['n']] = $t;
            }
            foreach ($questions as $i => $q) {
                $t = $suggest[$i + 1] ?? null;
                $rows[] = [
                    'bankentryid' => $q['bankentryid'],
                    'name'        => $q['name'],
                    'text'        => $q['text'],
                    'element_id'  => $t ? ($bycode[$t['code']] ?? 0) : 0,
                    'sure'        => $t ? $t['sure'] : false,
                ];
            }
        } catch (moodle_exception $e) {
            $error = local_unics_ai_error_text($e);
        }
    }

    if ($action === 'apply') {
        $take = optional_param_array('take', [], PARAM_INT);
        $elem = optional_param_array('element', [], PARAM_INT);
        $pairs = [];
        foreach ($elem as $beid => $elid) {
            if (isset($take[$beid])) {
                $pairs[] = ['bankentryid' => (int)$beid, 'element_id' => (int)$elid];
            }
        }
        $n = question_tagger::apply((int)$codifier->id, $pairs, (int)$USER->id);
        if ($n > 0) {
            redirect($backurl, 'Привязано вопросов: ' . $n, null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        // Отмеченные строки были, но ни одной новой привязки: элемент оставлен на «не
        // привязывать» либо пачку подтвердили повторно. Говорить «ничего не отмечено» тут неправда.
        redirect($backurl, $pairs
            ? 'Новых привязок не появилось: выбранные вопросы уже привязаны или оставлены без элемента.'
            : 'Ничего не отмечено, банк не изменился.',
            null, \core\output\notification::NOTIFY_WARNING);
    }
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Разметка банка вопросов кодификатором');
if ($error !== '') {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

if (!$rows) {
    // Шаг 1: форма.
    $left = question_tagger::untagged_count((int)$codifier->id);
    echo html_writer::tag('p', 'Кодификатор: ' . s($codifier->name)
        . '. Вопросов без разметки: ' . $left . '.');
    if ($left === 0) {
        echo html_writer::link($backurl, 'Назад к кодификатору', ['class' => 'btn btn-primary']);
        echo $OUTPUT->footer();
        return;
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'p-3 bg-light rounded']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'propose']);
    echo html_writer::div(
        html_writer::tag('label', 'Разметить за раз:', ['for' => 'batch', 'class' => 'mr-2'])
        . html_writer::empty_tag('input', ['type' => 'number', 'name' => 'batch', 'id' => 'batch',
            'value' => min($batch, $left), 'min' => 1, 'max' => question_tagger::BATCH,
            'class' => 'form-control mr-3 unics-num']),
        'form-inline mb-2');
    echo html_writer::tag('button', 'Предложить разметку',
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo ' ' . html_writer::link($backurl, 'Назад к кодификатору', ['class' => 'btn btn-link']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    return;
}

// Шаг 2: предпросмотр.
echo html_writer::tag('p', 'Проверьте предложенное. Там, где ИИ не уверен, галочка снята: '
    . 'ошибочная привязка отправит ребенка на задание не по своей теме.', ['class' => 'text-muted']);

// Молчание модели о части вопросов методист иначе не отличит от «подходящего элемента нет»:
// ответ мог оборваться, а мог просто не покрыть всю пачку.
$proposed = 0;
foreach ($rows as $r) {
    if ($r['element_id']) {
        $proposed++;
    }
}
if ($proposed < count($rows)) {
    echo $OUTPUT->notification('ИИ предложил элемент для ' . $proposed . ' вопросов из '
        . count($rows) . '. Остальным выберите элемент вручную или пропустите их.',
        \core\output\notification::NOTIFY_WARNING);
}

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'batch', 'value' => $batch]);
// Действие задано скрытым полем, а не только значением кнопки: отправка без значения кнопки
// иначе теряла бы все правки методиста.
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'apply']);

// Подписи экранируются: html_writer::select отдает содержимое option через tag(), а тот его НЕ
// экранирует. Названия элементов приходят в том числе импортом xlsx, где разметка не чистится.
$options = [0 => 'не привязывать'];
foreach ($tree as $e) {
    $options[(int)$e->id] = trim(s($e->code) . ' ' . s($e->title));
}
echo html_writer::start_div('table-responsive');
echo html_writer::start_tag('table', ['class' => local_unics_table_class()]);
echo html_writer::tag('thead', html_writer::tag('tr',
    html_writer::tag('th', 'Привязать') . html_writer::tag('th', 'Вопрос')
    . html_writer::tag('th', 'Элемент кодификатора')));
echo html_writer::start_tag('tbody');
foreach ($rows as $r) {
    $beid = (int)$r['bankentryid'];
    // Доступное имя обязательно: без него читалка объявит десятки безымянных галочек подряд.
    $attrs = ['type' => 'checkbox', 'name' => 'take[' . $beid . ']', 'value' => 1,
              'aria-label' => 'Привязать вопрос: ' . $r['name']];
    if ($r['sure'] && $r['element_id']) {
        $attrs['checked'] = 'checked';
    }
    $box = html_writer::empty_tag('input', $attrs);
    $q = html_writer::tag('div', s($r['name']))
        . html_writer::tag('div', s($r['text']), ['class' => 'small text-muted']);
    $select = html_writer::select($options, 'element[' . $beid . ']', $r['element_id'], false,
        ['class' => 'custom-select', 'aria-label' => 'Элемент для вопроса: ' . $r['name']]);
    if (!$r['sure']) {
        $select .= html_writer::div('ИИ не уверен', 'small text-muted');
    }
    echo html_writer::tag('tr', html_writer::tag('td', $box) . html_writer::tag('td', $q)
        . html_writer::tag('td', $select));
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

echo html_writer::tag('button', 'Привязать отмеченные',
    ['type' => 'submit', 'class' => 'btn btn-success mr-2']);
echo html_writer::tag('button', 'Предложить заново',
    ['type' => 'submit', 'name' => 'regenerate', 'value' => 1,
     'class' => 'btn btn-outline-secondary mr-2']);
echo html_writer::link($backurl, 'Назад к кодификатору', ['class' => 'btn btn-link']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
