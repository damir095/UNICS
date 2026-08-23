<?php
/**
 * Менеджер кодификатора содержания: выбор дисциплины (категории курсов), CRUD дерева
 * элементов (как кодификаторы ФИПИ) и импорт из xlsx/csv. [[codifier-design]].
 *
 * Доступ: системный администратор (local/unics:manage) либо региональный
 * администратор / региональный методист (local_unics_is_scoped_admin). Тегирование
 * контента и аналитика - на отдельных страницах (codifier_tag / codifier_report).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\codifier_manager;
use local_unics\codifier_analytics;

require_login();
local_unics_require_not_student();

$syscontext = context_system::instance();
$is_admin = has_capability('local/unics:manage', $syscontext);
if (!$is_admin && !local_unics_is_scoped_admin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'управление кодификатором');
}

$categoryid = optional_param('categoryid', 0, PARAM_INT);
$edit       = optional_param('edit', 0, PARAM_INT);   // element_id для инлайн-переименования
$addto      = optional_param('addto', -1, PARAM_INT); // parent_id для добавления (0 = корень)

$baseurl = new moodle_url('/local/unics/pages/codifier.php',
    $categoryid ? ['categoryid' => $categoryid] : []);

$PAGE->set_context($syscontext);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Кодификатор - УНИКС');
$PAGE->set_heading('Кодификатор содержания');

$codifier = $categoryid ? codifier_manager::get_codifier_for_category($categoryid) : null;

// ----------------------------------------------------------------
// POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'create' && $categoryid) {
        codifier_manager::create_codifier($categoryid, required_param('name', PARAM_TEXT), (int)$USER->id);
        redirect($baseurl, 'Кодификатор создан.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($codifier) {
        switch ($action) {
            case 'addroot':
                codifier_manager::add_element($codifier->id, null,
                    required_param('code', PARAM_TEXT), required_param('title', PARAM_TEXT));
                break;
            case 'addchild':
                codifier_manager::add_element($codifier->id, required_param('parent_id', PARAM_INT),
                    required_param('code', PARAM_TEXT), required_param('title', PARAM_TEXT));
                break;
            case 'rename':
                codifier_manager::update_element(required_param('element_id', PARAM_INT),
                    ['code'        => required_param('code', PARAM_TEXT),
                     'title'       => required_param('title', PARAM_TEXT),
                     'description' => optional_param('description', '', PARAM_TEXT)]);
                break;
            case 'move':
                codifier_manager::move_ordinal(required_param('element_id', PARAM_INT),
                    required_param('dir', PARAM_ALPHA));
                break;
            case 'delete':
                codifier_manager::delete_element(required_param('element_id', PARAM_INT));
                break;
            case 'import':
                if (isset($_FILES['importfile']) && $_FILES['importfile']['error'] === UPLOAD_ERR_OK) {
                    $tmp = $_FILES['importfile']['tmp_name'];
                    $ext = strtolower(pathinfo($_FILES['importfile']['name'], PATHINFO_EXTENSION));
                    $rows = [];
                    // Формат кодификатора ФИПИ: столбец 1 - код (иерархия закодирована
                    // в номере: 1 / 1.1 / 1.1.1), столбец 2 - название. Родитель не
                    // задаётся отдельной колонкой - его выводит import_from_rows из кода.
                    if ($ext === 'csv') {
                        foreach (file($tmp, FILE_IGNORE_NEW_LINES) as $i => $line) {
                            if ($i === 0) {
                                continue; // шапка
                            }
                            $c = str_getcsv($line, ';');
                            if (count($c) < 2) {
                                $c = str_getcsv($line, ',');
                            }
                            $rows[] = ['code' => trim((string)($c[0] ?? '')),
                                       'title' => trim((string)($c[1] ?? '')),
                                       'parent_code' => ''];
                        }
                    } else {
                        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
                        foreach ($sheet->toArray() as $i => $c) {
                            if ($i === 0) {
                                continue;
                            }
                            $rows[] = ['code' => trim((string)($c[0] ?? '')),
                                       'title' => trim((string)($c[1] ?? '')),
                                       'parent_code' => ''];
                        }
                    }
                    $n = codifier_manager::import_from_rows($codifier->id, $rows);
                    redirect($baseurl, "Импортировано элементов: $n", null,
                        \core\output\notification::NOTIFY_SUCCESS);
                }
                break;
        }
    }
    redirect($baseurl);
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Кодификатор содержания');

// Выбор дисциплины (категории курсов).
$cats = codifier_manager::list_subject_categories();
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
echo html_writer::tag('label', 'Дисциплина (категория курсов): ', ['class' => 'mr-2', 'for' => 'cat']);
$options = '';
foreach ($cats as $cid => $cname) {
    $options .= html_writer::tag('option', s($cname),
        ['value' => $cid] + ($cid == $categoryid ? ['selected' => 'selected'] : []));
}
echo html_writer::tag('select', $options, ['name' => 'categoryid', 'id' => 'cat', 'class' => 'custom-select mr-2']);
echo html_writer::tag('button', 'Открыть', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if (!$categoryid) {
    echo html_writer::tag('p', 'Выберите дисциплину, чтобы открыть или создать кодификатор.', ['class' => 'text-muted']);
    echo $OUTPUT->footer();
    return;
}

if (!$codifier) {
    // Нет кодификатора для дисциплины - предложить создать.
    echo html_writer::tag('p', 'Для этой дисциплины кодификатор ещё не создан.');
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'class' => 'form-control mr-2',
        'placeholder' => 'Название, например Информатика', 'required' => 'required', 'size' => 40]);
    echo html_writer::tag('button', 'Создать кодификатор', ['type' => 'submit', 'class' => 'btn btn-success']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    return;
}

echo html_writer::tag('h4', s($codifier->name), ['class' => 'mt-3']);

// Импорт.
// Фон панели - КЛАССОМ, не инлайновым стилем. Инлайновый `background:#f5f6f8` тема перебить
// не может в принципе (инлайн выше любого правила), и в темной схеме подпись получала
// светлый цвет от текст-руля на светлой панели: замер 2026-08-12 дал 1.11:1. В CSS этого
// цвета нет вообще, поэтому и `contrast_guard` его не видел.
echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data',
    'class' => 'form-inline mb-3 p-2 bg-light rounded']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'import']);
echo html_writer::tag('label', 'Импорт (xlsx или csv: столбцы код, название, код родителя): ',
    ['class' => 'mr-2', 'for' => 'importfile']);
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'importfile', 'id' => 'importfile',
    'accept' => '.xlsx,.csv', 'class' => 'mr-2', 'required' => 'required']);
echo html_writer::tag('button', 'Импортировать', ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
echo html_writer::end_tag('form');

// Предложение структуры ([[codifier-ai-proposal-design]]) и разметка банка вопросов
// ([[codifier-bank-tagging-design]]) моделью.
echo html_writer::div(
    html_writer::link(new moodle_url('/local/unics/pages/codifier_ai.php', ['categoryid' => $categoryid]),
        'Предложить структуру с помощью ИИ', ['class' => 'btn btn-outline-primary mr-2'])
    . html_writer::link(new moodle_url('/local/unics/pages/codifier_bank_ai.php',
        ['categoryid' => $categoryid]),
        'Разметить банк вопросов', ['class' => 'btn btn-outline-primary']),
    'mb-3');

// Дерево.
$tree = codifier_manager::get_tree($codifier->id);

// Готовность банка к CAT по элементам (read-only индикатор). [[cat-readiness-indicator-design]]
$readiness = [];
foreach (codifier_analytics::element_bank_readiness((int)$codifier->id) as $rr) {
    $readiness[(int)$rr->id] = $rr;
}
$readycell = function (?object $rr) {
    if ($rr === null) {
        return html_writer::tag('td', '-', ['class' => 'text-muted']);
    }
    $map = [
        'no_tags'   => ['нет тегов', 'light'],
        'low_calib' => ['мало калибровки', 'warning'],
        'ready'     => ['CAT-готов', 'success'],
    ];
    list($text, $cls) = $map[$rr->verdict] ?? ['нет тегов', 'light'];
    $badge = html_writer::tag('span', $text, ['class' => "badge badge-$cls"]);
    if ($rr->verdict === 'no_tags') {
        $counts = html_writer::tag('div', '-', ['class' => 'text-muted small']);
    } else {
        $line = 'тегов ' . (int)$rr->tagged_n . ' / калибр. ' . (int)$rr->calibrated_n
            . ' / 2PL ' . (int)$rr->ready_2pl_n;
        // Ноль в колонке 2PL сам по себе ничего не говорит: копится ли что-то, методисту
        // не видно. Порог оценки дискриминации у сервиса вдвое выше нашего порога
        // достоверности, поэтому показываем, сколько ответов еще нужно.
        if ((int)$rr->ready_2pl_n === 0 && (int)$rr->to_2pl_n > 0) {
            $line .= ' (до 2PL еще ' . (int)$rr->to_2pl_n . ' ответов)';
        }
        $counts = html_writer::tag('div', $line, ['class' => 'text-muted small']);
    }
    return html_writer::tag('td', $badge . $counts);
};

// Хелпер: инлайн-форма добавления элемента.
$add_form = function (?int $parent_id) use ($baseurl) {
    $action = $parent_id ? 'addchild' : 'addroot';
    $out = html_writer::start_tag('form', ['method' => 'post', 'class' => 'form-inline my-2 ml-3']);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    if ($parent_id) {
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'parent_id', 'value' => $parent_id]);
    }
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'code', 'class' => 'form-control mr-2',
        'placeholder' => 'Код', 'size' => 8]);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'class' => 'form-control mr-2',
        'placeholder' => 'Название элемента', 'required' => 'required', 'size' => 40]);
    $out .= html_writer::tag('button', 'Добавить', ['type' => 'submit', 'class' => 'btn btn-sm btn-success']);
    $out .= ' ' . html_writer::link($baseurl, 'Отмена', ['class' => 'btn btn-sm btn-link']);
    $out .= html_writer::end_tag('form');
    return $out;
};

echo html_writer::start_div('table-responsive');
echo html_writer::start_tag('table', ['class' => local_unics_table_class()]);
echo html_writer::tag('thead', html_writer::tag('tr',
    html_writer::tag('th', 'Код') . html_writer::tag('th', 'Элемент содержания') .
    html_writer::tag('th', 'Готовность к CAT') .
    html_writer::tag('th', 'Действия', ['style' => 'width:340px;'])));
echo html_writer::start_tag('tbody');

foreach ($tree as $e) {
    $depth = (int)($e->depth ?? 0);
    $indent = $depth * 24;

    if ($edit == $e->id) {
        // Инлайн-редактирование: код, название, описание.
        $cell = html_writer::start_tag('form', ['method' => 'post']);
        $cell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $cell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rename']);
        $cell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'element_id', 'value' => $e->id]);
        $cell .= html_writer::start_div('form-inline mb-2');
        $cell .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'code', 'value' => s($e->code),
            'class' => 'form-control mr-2', 'size' => 8]);
        $cell .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'value' => s($e->title),
            'class' => 'form-control mr-2', 'size' => 40, 'required' => 'required']);
        $cell .= html_writer::end_div();
        $cell .= html_writer::tag('textarea', s($e->description ?? ''),
            ['name' => 'description', 'class' => 'form-control mb-2', 'rows' => 3,
             'placeholder' => 'Описание: какие компетенции проверяются/формируются']);
        $cell .= html_writer::tag('button', 'Сохранить', ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']);
        $cell .= ' ' . html_writer::link($baseurl, 'Отмена', ['class' => 'btn btn-sm btn-link']);
        $cell .= html_writer::end_tag('form');
        echo html_writer::tag('tr', html_writer::tag('td', $cell, ['colspan' => 4, 'style' => "padding-left:{$indent}px;"]));
        continue;
    }

    $code = html_writer::tag('td', s($e->code), ['class' => 'text-muted']);
    $title_html = html_writer::tag('span', s($e->title), ['style' => "margin-left:{$indent}px;"]);
    if (trim((string)($e->description ?? '')) !== '') {
        $title_html .= html_writer::tag('div', nl2br(s($e->description)),
            ['class' => 'small text-muted mt-1', 'style' => "margin-left:{$indent}px;"]);
    }
    $title = html_writer::tag('td', $title_html);

    // Действия.
    $mkbtn = function (string $act, array $extra, string $label, string $cls) {
        $f = html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $act]);
        foreach ($extra as $k => $v) {
            $f .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
        }
        $f .= html_writer::tag('button', $label, ['type' => 'submit', 'class' => "btn btn-sm $cls"]);
        $f .= html_writer::end_tag('form');
        return $f;
    };
    $actions  = $mkbtn('move', ['element_id' => $e->id, 'dir' => 'up'], 'Вверх', 'btn-outline-secondary') . ' ';
    $actions .= $mkbtn('move', ['element_id' => $e->id, 'dir' => 'down'], 'Вниз', 'btn-outline-secondary') . ' ';
    $actions .= html_writer::link(new moodle_url($baseurl, ['edit' => $e->id]), 'Редактировать',
        ['class' => 'btn btn-sm btn-outline-primary']) . ' ';
    $actions .= html_writer::link(new moodle_url($baseurl, ['addto' => $e->id]), 'Подраздел',
        ['class' => 'btn btn-sm btn-outline-success']) . ' ';
    // Удаление с подтверждением (POST + onsubmit confirm).
    $delf = html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;',
        'onsubmit' => "return confirm('Удалить элемент и всё его поддерево вместе со связями?');"]);
    $delf .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $delf .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete']);
    $delf .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'element_id', 'value' => $e->id]);
    $delf .= html_writer::tag('button', 'Удалить', ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
    $delf .= html_writer::end_tag('form');
    $actions .= $delf;

    echo html_writer::tag('tr', $code . $title
        . $readycell($readiness[(int)$e->id] ?? null)
        . html_writer::tag('td', $actions));

    // Инлайн-форма добавления подраздела под этим элементом.
    if ($addto == $e->id) {
        echo html_writer::tag('tr', html_writer::tag('td', $add_form((int)$e->id), ['colspan' => 4]));
    }
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

// Добавить корневой раздел.
if ($addto === 0) {
    echo $add_form(null);
} else {
    echo html_writer::link(new moodle_url($baseurl, ['addto' => 0]), 'Добавить корневой раздел',
        ['class' => 'btn btn-success']);
}

echo $OUTPUT->footer();
