<?php
/**
 * Предложение структуры кодификатора моделью ([[codifier-ai-proposal-design]]).
 *
 * Три шага в одном файле, как в генерации УМК: форма -> предпросмотр -> применение. В базу не
 * пишется ничего, пока методист не нажал «Добавить отмеченные»: мусор в живом кодификаторе тянет
 * за собой привязки заданий, оценки владения и сессии CAT.
 *
 * Доступ: тот же гейт, что у codifier.php - системный администратор (local/unics:manage) либо
 * региональный администратор / методист (local_unics_is_scoped_admin).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\codifier_manager;
use local_unics\ai\codifier_proposer;

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

$subject = (string)$DB->get_field('course_categories', 'name', ['id' => $categoryid]);
$baseurl = new moodle_url('/local/unics/pages/codifier_ai.php', ['categoryid' => $categoryid]);

$PAGE->set_context($syscontext);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Структура кодификатора от ИИ - УНИКС');
$PAGE->set_heading('Структура кодификатора: предложение ИИ');

$action       = optional_param('action', '', PARAM_ALPHA);
$class_number = optional_param('class_number', 6, PARAM_INT);
$sections_n   = optional_param('sections_n', 6, PARAM_INT);
$topics_n     = optional_param('topics_n', 5, PARAM_INT);
$extra        = optional_param('extra', '', PARAM_TEXT);
$error        = '';
$plan         = [];

/**
 * Собрать план из полей предпросмотра.
 *
 * Имена полей плоские: optional_param_array не читает вложенные массивы, поэтому связь темы с
 * разделом закодирована в ключе «индекс раздела _ индекс темы».
 *
 * Галочка «взять» приходит только когда отмечена, поэтому отбор идет по НАЛИЧИЮ ключа. Снятый
 * раздел уносит свои темы: тема без родителя повисла бы в воздухе.
 */
function local_unics_codifier_ai_plan_from_post(): array {
    $stake  = optional_param_array('s_take', [], PARAM_INT);
    $scode  = optional_param_array('s_code', [], PARAM_TEXT);
    $stitle = optional_param_array('s_title', [], PARAM_TEXT);
    $sdesc  = optional_param_array('s_desc', [], PARAM_TEXT);
    $ttake  = optional_param_array('t_take', [], PARAM_INT);
    $tcode  = optional_param_array('t_code', [], PARAM_TEXT);
    $ttitle = optional_param_array('t_title', [], PARAM_TEXT);
    $tdesc  = optional_param_array('t_desc', [], PARAM_TEXT);

    $out = [];
    foreach ($stitle as $i => $title) {
        if (!isset($stake[$i])) {
            continue;
        }
        $topics = [];
        foreach ($ttitle as $key => $tt) {
            if (strpos((string)$key, $i . '_') !== 0 || !isset($ttake[$key])) {
                continue;
            }
            $topics[] = ['code' => (string)($tcode[$key] ?? ''), 'title' => (string)$tt,
                         'description' => (string)($tdesc[$key] ?? '')];
        }
        $out[] = ['code' => (string)($scode[$i] ?? ''), 'natural' => (string)((int)$i + 1),
                  'shifted' => false, 'title' => (string)$title,
                  'description' => (string)($sdesc[$i] ?? ''), 'topics' => $topics];
    }
    return $out;
}

/**
 * Текст ошибки для методиста.
 *
 * Берем параметр исключения, а не getMessage(): языковая строка generalexceptionmessage
 * подставляет текст в шаблон «Исключение - {$a}», и методист видел бы служебное слово
 * «Исключение» там, где написано понятное «Код «1» уже занят».
 */
function local_unics_codifier_ai_error_text(moodle_exception $e): string {
    return is_string($e->a) && $e->a !== '' ? $e->a : $e->getMessage();
}

// ----------------------------------------------------------------
// POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // Потолки держат ответ модели в пределах max_tokens.
    $class_number = min(11, max(1, $class_number));
    $sections_n   = min(codifier_proposer::MAX_SECTIONS, max(3, $sections_n));
    $topics_n     = min(codifier_proposer::MAX_TOPICS, max(2, $topics_n));

    // Кнопка «Предложить заново» на предпросмотре перебивает скрытое action=apply.
    if (optional_param('regenerate', 0, PARAM_INT)) {
        $action = 'propose';
    }

    if ($action === 'propose') {
        // Худший случай: PARSE_ATTEMPTS попыток, в каждой до двух обращений к модели по 60 секунд
        // плюс авторизация. 180 секунд не хватало, и методист получал не сообщение, а обрыв.
        \core_php_time_limit::raise(codifier_proposer::PARSE_ATTEMPTS * 2 * 60 + 60);
        try {
            $parsed = (new codifier_proposer())->propose($subject, $class_number, $sections_n,
                $topics_n, $extra, codifier_proposer::existing_titles((int)$codifier->id));
            $plan = codifier_proposer::plan(codifier_proposer::existing_codes((int)$codifier->id), $parsed);
        } catch (moodle_exception $e) {
            $error = local_unics_codifier_ai_error_text($e);
        }
    }

    if ($action === 'apply') {
        $plan = local_unics_codifier_ai_plan_from_post();
        try {
            $n = codifier_proposer::apply((int)$codifier->id, $plan);
            if ($n === 0) {
                // Снято все до единого: «Добавлено элементов: 0» выглядело бы как успех записи.
                redirect($backurl, 'Ничего не отмечено, кодификатор не изменился.', null,
                    \core\output\notification::NOTIFY_WARNING);
            }
            redirect($backurl, 'Добавлено элементов: ' . $n, null,
                \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            // Остаемся на предпросмотре: значения, которые методист уже поправил, не теряются.
            $error = local_unics_codifier_ai_error_text($e);
        }
    }
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Структура кодификатора: предложение ИИ');
if ($error !== '') {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

if (!$plan) {
    // Шаг 1: форма.
    echo html_writer::tag('p', 'Дисциплина: ' . s($subject) . '. Кодификатор: ' . s($codifier->name));
    $titles = codifier_proposer::existing_titles((int)$codifier->id);
    echo html_writer::tag('p', $titles
        ? 'Уже в кодификаторе: ' . s(implode('; ', array_slice($titles, 0, 20)))
        : 'Кодификатор пока пуст.', ['class' => 'text-muted']);

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'p-3 bg-light rounded']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'propose']);
    $number = function (string $name, string $label, int $value, int $min, int $max) {
        $out  = html_writer::tag('label', $label, ['for' => $name, 'class' => 'mr-2']);
        $out .= html_writer::empty_tag('input', ['type' => 'number', 'name' => $name, 'id' => $name,
            'value' => $value, 'min' => $min, 'max' => $max, 'class' => 'form-control mr-3 unics-num']);
        return html_writer::div($out, 'form-inline mb-2');
    };
    echo $number('class_number', 'Класс:', $class_number, 1, 11);
    echo $number('sections_n', 'Разделов:', $sections_n, 3, codifier_proposer::MAX_SECTIONS);
    echo $number('topics_n', 'Тем в разделе:', $topics_n, 2, codifier_proposer::MAX_TOPICS);
    echo html_writer::tag('label', 'Указания методиста (необязательно):',
        ['for' => 'extra', 'class' => 'd-block']);
    echo html_writer::tag('textarea', s($extra), ['name' => 'extra', 'id' => 'extra', 'rows' => 3,
        'class' => 'form-control mb-2',
        'placeholder' => 'например: по учебнику Мерзляка, только алгебра']);
    echo html_writer::tag('button', 'Предложить структуру',
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo ' ' . html_writer::link($backurl, 'Назад к кодификатору', ['class' => 'btn btn-link']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    return;
}

// Шаг 2: предпросмотр.
echo html_writer::tag('p', 'Проверьте предложенное, поправьте названия и коды, снимите лишнее. '
    . 'В кодификатор попадет только отмеченное.', ['class' => 'text-muted']);

// Недобор разделов методист иначе не отличит от «модель так решила»: ответ мог оборваться на
// лимите токенов, и восстановление вернуло то, что успело прийти целым.
if ($action === 'propose' && count($plan) < $sections_n) {
    echo $OUTPUT->notification('Модель вернула разделов: ' . count($plan) . ' из '
        . $sections_n . ' запрошенных. Можно попросить заново или добавить недостающее руками.',
        \core\output\notification::NOTIFY_WARNING);
}

// Каждому полю нужно ДОСТУПНОЕ ИМЯ: placeholder именем не считается и исчезает, как только в
// поле появляется значение. Без него читалка объявляет «поле ввода» на каждой из десятков строк,
// а методист именно здесь решает, что попадет в кодификатор (WCAG 2.2 AA, 4.1.2).
$field = function (string $name, string $value, string $label, int $size) {
    return html_writer::empty_tag('input', ['type' => 'text', 'name' => $name, 'value' => $value,
        'class' => 'form-control', 'placeholder' => $label, 'aria-label' => $label, 'size' => $size]);
};
$takebox = function (string $name, string $label) {
    return html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => $name, 'value' => 1,
        'checked' => 'checked', 'aria-label' => $label]);
};
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach (['class_number' => $class_number, 'sections_n' => $sections_n,
          'topics_n' => $topics_n, 'extra' => $extra] as $k => $v) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
}
echo html_writer::start_div('table-responsive');
echo html_writer::start_tag('table', ['class' => local_unics_table_class()]);
echo html_writer::tag('thead', html_writer::tag('tr',
    html_writer::tag('th', 'Взять')
    . html_writer::tag('th', 'Код')
    . html_writer::tag('th', 'Название')
    . html_writer::tag('th', 'Описание')));
echo html_writer::start_tag('tbody');
foreach ($plan as $i => $sec) {
    // Подпись объясняет сдвиг, но НЕ утверждает, что занят именно номер natural: он мог быть
    // занят и соседним разделом этой же пачки. Живой заход 2026-08-20 показал подпись «код 3
    // занят», хотя в кодификаторе код 3 свободен.
    $note = !empty($sec['shifted'])
        ? html_writer::div('нумерация сдвинута: занятые коды пропущены', 'small text-muted')
        : '';
    echo html_writer::tag('tr',
        html_writer::tag('td', $takebox('s_take[' . $i . ']', 'Взять раздел: ' . $sec['title']))
        . html_writer::tag('td', $field('s_code[' . $i . ']', (string)$sec['code'], 'Код', 6) . $note)
        . html_writer::tag('td', $field('s_title[' . $i . ']', (string)$sec['title'], 'Название раздела', 40))
        . html_writer::tag('td', $field('s_desc[' . $i . ']', (string)$sec['description'], 'Описание', 40)));
    foreach ($sec['topics'] as $j => $t) {
        $key = $i . '_' . $j;
        echo html_writer::tag('tr',
            html_writer::tag('td', $takebox('t_take[' . $key . ']', 'Взять тему: ' . $t['title']))
            . html_writer::tag('td', $field('t_code[' . $key . ']', (string)$t['code'], 'Код', 6),
                ['class' => 'pl-4'])
            . html_writer::tag('td', $field('t_title[' . $key . ']', (string)$t['title'], 'Название темы', 40))
            . html_writer::tag('td', $field('t_desc[' . $key . ']', (string)$t['description'], 'Описание', 40)));
    }
}
echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();
// Действие по умолчанию задано СКРЫТЫМ полем, а не только значением кнопки: отправка без
// значения кнопки (неявная отправка, расширение браузера, вспомогательная технология) молча
// теряла бы все правки методиста, потому что обе ветки POST пропускались.
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'apply']);
echo html_writer::tag('button', 'Добавить отмеченные',
    ['type' => 'submit', 'class' => 'btn btn-success mr-2']);
echo html_writer::tag('button', 'Предложить заново',
    ['type' => 'submit', 'name' => 'regenerate', 'value' => 1,
     'class' => 'btn btn-outline-secondary mr-2']);
echo html_writer::link($backurl, 'Назад к кодификатору', ['class' => 'btn btn-link']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
