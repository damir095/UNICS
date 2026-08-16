<?php
/**
 * Эксплуатационное здоровье УНИКС: одна страница для администратора без знания PHP.
 * [[health-page-design]]
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\health\check_result;
use local_unics\health\health_report;

require_login();
$context = context_system::instance();
require_capability('local/unics:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/health.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Здоровье системы - УНИКС');
$PAGE->set_heading('Здоровье системы');

// Прогон дорогих проверок - только по явной кнопке: они ходят по сети.
// sesskey читается через optional_param: у confirm_sesskey() без аргумента внутри
// required_param, и адрес ?checknow=1 без ключа ронял бы страницу сообщением про
// недостающий параметр вместо показа дешевых проверок (найдено ревью).
$sesskey = optional_param('sesskey', '', PARAM_RAW);
$runexpensive = optional_param('checknow', 0, PARAM_INT) === 1
    && $sesskey !== '' && confirm_sesskey($sesskey);

echo $OUTPUT->header();

echo html_writer::tag('p', 'Страница отвечает на один вопрос: работает ли система на самом деле. '
    . 'Проверки внешних сервисов выполняются по кнопке, потому что требуют обращения по сети.',
    ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'checknow', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('button', 'Проверить внешние сервисы сейчас',
    ['type' => 'submit', 'class' => 'btn btn-outline-primary']);
echo html_writer::end_tag('form');

$badges = [
    check_result::OK        => ['success', 'в порядке'],
    check_result::ATTENTION => ['warning', 'внимание'],
    check_result::ALARM     => ['danger',  'не работает'],
];

$table = new html_table();
$table->head = ['Проверка', 'Состояние', 'Что происходит', 'Что делать'];
$table->attributes['class'] = 'generaltable';

foreach (health_report::checks() as $check) {
    if (!$check->is_cheap() && !$runexpensive) {
        $table->data[] = [
            s($check->title()),
            html_writer::tag('span', 'не проверялось', ['class' => 'badge badge-secondary']),
            'Нажмите «Проверить внешние сервисы сейчас».',
            '',
        ];
        continue;
    }
    try {
        $r = $check->run();
    } catch (\Throwable $e) {
        $table->data[] = [s($check->title()),
            html_writer::tag('span', 'сбой проверки', ['class' => 'badge badge-danger']),
            s($e->getMessage()), 'Сообщите разработчику.'];
        continue;
    }
    [$cls, $label] = $badges[$r->level];
    $what = s($r->summary);
    foreach ($r->details as $k => $v) {
        $what .= html_writer::tag('div', s($k . ': ' . $v), ['class' => 'text-muted small']);
    }
    $table->data[] = [
        s($check->title()),
        html_writer::tag('span', $label, ['class' => 'badge badge-' . $cls]),
        $what,
        s($r->action),
    ];
}

echo html_writer::table($table);

// Полоса тревоги кешируется - после ручного прогона показываем свежее состояние.
health_report::forget();

echo $OUTPUT->footer();
