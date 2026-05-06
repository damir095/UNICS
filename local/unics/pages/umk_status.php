<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/umk_status.php'));
$PAGE->set_title('История генерации УМК — УНИКС');
$PAGE->set_heading('История генерации материалов');
$PAGE->set_pagelayout('admin');

// Ручной запуск обработки очереди (для отладки)
$run_now = optional_param('run_now', 0, PARAM_INT);
if ($run_now && confirm_sesskey()) {
    require_once(__DIR__ . '/../classes/ai_generator.php');
    require_once(__DIR__ . '/../classes/course_builder.php');
    $task = new \local_unics\task\process_ai_queue();
    ob_start();
    $task->execute();
    $log = ob_get_clean();
    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        $log ?: 'Очередь обработана.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$status_labels = [
    1 => '<span class="badge badge-secondary">Ожидает</span>',
    2 => '<span class="badge badge-primary">Генерируется</span>',
    3 => '<span class="badge badge-success">Готов</span>',
    4 => '<span class="badge badge-danger">Ошибка</span>',
];

$records = $DB->get_records_sql(
    "SELECT u.id, u.title, u.topic, u.difficulty_level, u.status, u.generated_at, u.mdl_course_id,
            q.error_message, q.processed_at,
            c.fullname AS course_name,
            (SELECT COUNT(*) FROM {unics_umk_students} us WHERE us.umk_id = u.id) AS student_count
     FROM {unics_umk} u
     LEFT JOIN {course} c          ON c.id    = u.mdl_course_id
     LEFT JOIN {unics_ai_queue} q  ON q.umk_id = u.id
     ORDER BY u.generated_at DESC
     LIMIT 50"
);

echo $OUTPUT->header();

echo '<div class="mb-3 d-flex justify-content-between align-items-center">';
echo '<a href="generate_umk.php" class="btn btn-primary">Создать новый УМК</a>';
echo '<a href="?run_now=1&sesskey=' . sesskey() . '" class="btn btn-outline-secondary btn-sm"
        onclick="return confirm(\'Запустить обработку очереди прямо сейчас?\')">'
    . 'Запустить обработку сейчас'
    . '</a>';
echo '</div>';

if (empty($records)) {
    echo $OUTPUT->notification('Материалов пока нет. Создайте первый УМК.', 'info');
} else {
    $level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

    $table = new html_table();
    $table->head = ['Материал', 'Тема', 'Уровень', 'Учащихся', 'Курс', 'Статус', 'Дата'];
    $table->attributes['class'] = 'table table-striped table-sm';

    foreach ($records as $r) {
        $status = $status_labels[$r->status] ?? '<span class="badge badge-light">?</span>';

        if ($r->status == 4 && $r->error_message) {
            $status .= '<br><small class="text-danger">' . s($r->error_message) . '</small>';
        }

        $course_link = $r->mdl_course_id
            ? html_writer::link(
                new moodle_url('/course/view.php', ['id' => $r->mdl_course_id]),
                s($r->course_name ?: 'Курс #' . $r->mdl_course_id),
                ['target' => '_blank']
              )
            : '—';

        $lvl_label = $level_labels[$r->difficulty_level] ?? ('Ур.' . $r->difficulty_level);

        $table->data[] = [
            s($r->title),
            s($r->topic),
            $lvl_label,
            (int)$r->student_count,
            $course_link,
            $status,
            $r->generated_at ? userdate(strtotime($r->generated_at)) : '—',
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
