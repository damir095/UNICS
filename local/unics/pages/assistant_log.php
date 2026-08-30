<?php
/**
 * Журнал переписки с ИИ-помощником ([[assistant-design]]).
 *
 * Модерация ответов детям была одной из трех причин, по которым ассистента откладывали. Одной
 * записи в базу для этого мало: читать ее должно быть ГДЕ. Эта страница и есть та самая
 * модерация - педагог видит, о чем спрашивают его ученики и что им отвечают.
 *
 * Показываются и отказы: они говорят, чего не хватает в материале, а «вопрос из теста» - повод
 * поговорить с ребенком, а не наказать его.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\ai\assistant;

require_login();
global $USER, $DB, $PAGE, $OUTPUT;

local_unics_require_not_student();
// Право обязательно: require_not_student() отсекает только тех, у кого есть строка в
// unics_students, поэтому родитель и вообще любой пользователь без ролей страницу открывали.
// Сегодня они увидели бы пустой список, но безопасность держалась бы на этом «сегодня» - ровно
// та форма, в которой уже случалась утечка родителю (найдено ревью).
require_capability('local/unics:viewstudents', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/assistant_log.php'));
$PAGE->set_title('Вопросы помощнику - УНИКС');
$PAGE->set_heading('Вопросы помощнику');
$PAGE->set_pagelayout('standard');

$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$visible = assistant::visible_student_userids((int)$USER->id);

echo $OUTPUT->header();
echo local_unics_dashboard_button();

if ($visible !== null && !$visible) {
    echo $OUTPUT->notification(
        'За вами не закреплено учащихся, поэтому и переписки не видно.', 'info');
    echo $OUTPUT->footer();
    exit;
}

$where = '1=1';
$params = [];
if ($visible !== null) {
    [$insql, $params] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'u');
    $where = "m.mdl_user_id {$insql}";
}

$total = (int)$DB->count_records_sql(
    "SELECT COUNT(m.id) FROM {unics_assistant_message} m WHERE {$where}", $params);

$rows = $DB->get_records_sql(
    "SELECT m.*, u.firstname, u.lastname, c.fullname AS coursename
       FROM {unics_assistant_message} m
       JOIN {user} u ON u.id = m.mdl_user_id
  LEFT JOIN {course} c ON c.id = m.mdl_course_id
      WHERE {$where}
   ORDER BY m.timecreated DESC", $params, $page * $perpage, $perpage);

if (!$rows) {
    echo $OUTPUT->notification('Пока никто ничего не спрашивал.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Исход - словами для педагога. Здесь он ТЕХНИЧЕСКИ точен, в отличие от детской страницы: педагогу
// важно отличить «нет материала» от «ИИ не ответил», это разные поводы вмешаться.
$outcomes = [
    assistant::ANSWERED        => ['Ответил', 'success'],
    assistant::NO_MATERIAL     => ['Нет материала по курсу', 'warning'],
    assistant::LOOKS_LIKE_TASK => ['Вопрос из теста - отказ', 'info'],
    assistant::LIMIT           => ['Дневной лимит исчерпан', 'secondary'],
    assistant::AI_FAILED       => ['ИИ не ответил', 'danger'],
];

$table = new html_table();
$table->head = ['Когда', 'Учащийся', 'Курс', 'Вопрос', 'Ответ', 'Исход'];
$table->attributes['class'] = 'generaltable unics-table';

foreach ($rows as $row) {
    [$label, $style] = $outcomes[$row->outcome] ?? ['Неизвестный исход', 'secondary'];
    $table->data[] = [
        userdate((int)$row->timecreated, get_string('strftimedatetimeshort')),
        s(trim($row->lastname . ' ' . $row->firstname)),
        $row->coursename !== null ? format_string($row->coursename) : '-',
        s($row->question),
        $row->answer !== null
            ? ($row->outcome === assistant::AI_FAILED
                // У отказа в этом поле лежит техническая причина, а не ответ ребенку.
                ? html_writer::span(s($row->answer), 'text-muted')
                : format_text((string)$row->answer, FORMAT_MARKDOWN))
            : '-',
        html_writer::span($label, 'badge bg-' . $style),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
echo $OUTPUT->footer();
