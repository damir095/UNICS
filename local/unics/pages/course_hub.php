<?php
/**
 * Хаб-страница курса УНИКС ([[course-hub-design]]): сводка «Требует внимания» + плитки
 * инструментов. Схлопывает девять прежних пунктов меню «Еще» в один пункт «УНИКС».
 *
 * Доступ: смотрящему доступна хотя бы одна плитка. Состав плиток и права на них считает
 * \local_unics\output\course_hub::tiles() - тот же вызов, что решает, показывать ли пункт меню.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\output\course_hub;

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Ни одной доступной плитки - страницы для этого пользователя нет.
if (!course_hub::tiles($course, $context)) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        get_string('hub_nopermission', 'local_unics'),
        null, \core\output\notification::NOTIFY_WARNING);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_hub.php', ['course_id' => $course_id]));
$PAGE->set_title(get_string('hub_title', 'local_unics') . ' - УНИКС');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->render_from_template('local_unics/course_hub',
    course_hub::build_context($OUTPUT, $course, (int)$USER->id));
echo $OUTPUT->footer();
