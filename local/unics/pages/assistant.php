<?php
/**
 * ИИ-ассистент ученика: чат по материалу своего курса ([[assistant-design]]).
 *
 * Отказы показываются ребенку ДОБРОЖЕЛАТЕЛЬНО и по-русски, а не кодом исхода: «вопрос из теста»
 * не должно читаться как «ты сделал что-то плохое». Технический исход остается в журнале для
 * педагога.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\ai\assistant;
use local_unics\access;

require_login();
global $USER, $DB, $PAGE, $OUTPUT;

// Страница ДЕТСКАЯ: смотрит ее сам ученик. Проверка идет по строке unics_students, а не по
// Moodle-роли - так же, как в навигации: неверно назначенная роль не должна открывать чужое.
$student = access::student_record((int)$USER->id);
if (!$student) {
    throw new moodle_exception('accessdenied', 'error');
}

$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/assistant.php', ['courseid' => $courseid]));
$PAGE->set_title('Помощник - УНИКС');
$PAGE->set_heading('Помощник');
$PAGE->set_pagelayout('standard');

// Курсы, на которые ребенок записан: спрашивать можно только про свое.
$mycourses = enrol_get_users_courses((int)$USER->id, true, 'id, fullname');
if ($courseid && !isset($mycourses[$courseid])) {
    throw new moodle_exception('accessdenied', 'error');
}
if (!$courseid && count($mycourses) === 1) {
    $courseid = (int)reset($mycourses)->id;
}

$helper = new assistant();

// Отправка вопроса. После записи - редирект, иначе обновление страницы повторяет вопрос и жжет
// дневной лимит впустую.
if ($courseid && optional_param('ask', 0, PARAM_INT) && confirm_sesskey()) {
    $question = trim(optional_param('question', '', PARAM_TEXT));
    if ($question !== '') {
        // Сессию отпускаем ДО похода в сеть: обращение к ИИ занимает секунды, и все это время
        // остальные вкладки ребенка висели бы на блокировке сессии (найдено ревью).
        \core\session\manager::write_close();
        $helper->ask((int)$USER->id, $courseid, $question);
    }
    redirect(new moodle_url('/local/unics/pages/assistant.php', ['courseid' => $courseid]));
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();

if (!$mycourses) {
    echo $OUTPUT->notification('Ты пока не записан ни на один курс - спросить не о чем.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Выбор курса: помощник отвечает по материалу КОНКРЕТНОГО курса, а не вообще.
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4']);
echo html_writer::tag('label', 'О каком курсе спросить?',
    ['for' => 'unics-assistant-course', 'class' => 'form-label']);
$options = [];
foreach ($mycourses as $c) {
    $options[(int)$c->id] = format_string($c->fullname);
}
echo html_writer::select($options, 'courseid', $courseid, ['' => 'Выбери курс'],
    ['id' => 'unics-assistant-course', 'class' => 'form-select', 'onchange' => 'this.form.submit()']);
echo html_writer::end_tag('form');

if (!$courseid) {
    echo $OUTPUT->footer();
    exit;
}

$left = assistant::DAILY_LIMIT - $helper->asked_today((int)$USER->id);

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ask', 'value' => 1]);
echo html_writer::tag('label', 'Что непонятно?',
    ['for' => 'unics-assistant-q', 'class' => 'form-label']);
echo html_writer::tag('textarea', '', [
    'id' => 'unics-assistant-q', 'name' => 'question', 'class' => 'form-control mb-3',
    'rows' => 3, 'maxlength' => 500,
    'placeholder' => 'Например: не понимаю, как сравнивать дроби',
    'required' => 'required',
]);
if ($left > 0) {
    echo html_writer::tag('button', 'Спросить', ['type' => 'submit', 'class' => 'unics-cta']);
} else {
    echo $OUTPUT->notification('На сегодня вопросов хватит. Возвращайся завтра - помощник отдохнет.', 'info');
}
echo html_writer::end_tag('form');

// Переписка по этому курсу, свежее сверху.
$rows = $DB->get_records('unics_assistant_message',
    ['mdl_user_id' => (int)$USER->id, 'mdl_course_id' => $courseid],
    'timecreated DESC', '*', 0, 20);

if (!$rows) {
    echo $OUTPUT->notification('Задай первый вопрос - помощник читает материал твоего урока.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Отказ ребенку объясняем словами, а не кодом. Формулировки НЕ винят его: «вопрос из теста» -
// это про правило, а не про проступок.
$refusals = [
    assistant::NO_MATERIAL     => 'По этому курсу у меня пока нет материала. Спроси, пожалуйста, педагога.',
    assistant::LOOKS_LIKE_TASK => 'Это вопрос из теста - его лучше решить самому. Загляни в материал урока: там есть, на что опереться.',
    assistant::LIMIT           => 'На сегодня вопросов хватит. Возвращайся завтра.',
    assistant::AI_FAILED       => 'Не получилось ответить. Попробуй, пожалуйста, чуть позже.',
];

foreach ($rows as $row) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('p', s($row->question), ['class' => 'fw-bold mb-2']);

    if ($row->outcome === assistant::ANSWERED) {
        echo html_writer::div(format_text((string)$row->answer, FORMAT_MARKDOWN));
    } else {
        echo html_writer::div($refusals[$row->outcome] ?? $refusals[assistant::AI_FAILED],
            'unics-assistant-note');
    }

    echo html_writer::tag('p', userdate((int)$row->timecreated, get_string('strftimedatetimeshort')),
        ['class' => 'text-muted mt-2 mb-0']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
