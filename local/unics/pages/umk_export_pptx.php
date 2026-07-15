<?php
// Экспорт УМК-презентации (mod_page со слайдами .unics-slide) в PPTX.
// Доступ: персонал (не ученик) с mod/page:view - файл для редактирования и показа
// на уроке; ученику остается PDF-экспорт (umk_export.php, офлайн-чтение).
// Дизайн: [[umk-pptx-export-design]] в LLM-вики.

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_unics\ai\pptx_writer;
use local_unics\ai\slide_parser;

$cmid = required_param('cmid', PARAM_INT);

$cm     = get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);           // уважает видимость/доступность модуля
$context = context_module::instance($cm->id);
require_capability('mod/page:view', $context);
local_unics_require_not_student();            // PPTX - только персоналу

$page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);

$slides = slide_parser::parse((string)$page->content);
if (empty($slides)) {
    throw new moodle_exception('generalexceptionmessage', 'error', '', 'Эта страница не является УМК-презентацией - PPTX-выгрузка недоступна.');
}

$pptx = pptx_writer::build($page->name, format_string($course->fullname), $slides);

\core\session\manager::write_close();
$filename = clean_filename($page->name . '.pptx');
header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="presentation.pptx"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($pptx));
echo $pptx;
