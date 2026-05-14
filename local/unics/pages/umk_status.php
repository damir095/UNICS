<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
local_unics_require_not_student();

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$is_teacher    = has_capability('local/unics:viewstudents', $sys_ctx);

if (!$is_admin_user && !$is_teacher) {
    require_capability('local/unics:viewstudents', $sys_ctx);
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/umk_status.php'));
$PAGE->set_title('История генерации УМК - УНИКС');
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

// Отмена pending-задачи: удаляем запись из очереди, UMK помечаем status=5 (отменён).
$cancel_id = optional_param('cancel_id', 0, PARAM_INT);
if ($cancel_id && confirm_sesskey()) {
    $umk = $DB->get_record('unics_umk', ['id' => $cancel_id]);
    if ($umk && (int)$umk->status === 1) {
        $DB->delete_records('unics_ai_queue', ['umk_id' => $cancel_id]);
        $DB->set_field('unics_umk', 'status', 5, ['id' => $cancel_id]);
        redirect(
            new moodle_url('/local/unics/pages/umk_status.php'),
            'УМК #' . $cancel_id . ' отменён.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        'Можно отменить только записи в статусе «Ожидает».',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Массовая отмена всех pending.
$cancel_all = optional_param('cancel_all', 0, PARAM_INT);
if ($cancel_all && confirm_sesskey()) {
    $pending = $DB->get_records('unics_umk', ['status' => 1], '', 'id');
    if (!empty($pending)) {
        $ids = array_keys($pending);
        [$in_sql, $in_params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('unics_ai_queue', "umk_id {$in_sql}", $in_params);
        $DB->set_field_select('unics_umk', 'status', 5, "id {$in_sql}", $in_params);
    }
    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        'Отменено: ' . count($pending) . ' записей.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$status_labels = [
    1 => '<span class="badge badge-secondary">Ожидает</span>',
    2 => '<span class="badge badge-primary">Генерируется</span>',
    3 => '<span class="badge badge-success">Готов</span>',
    4 => '<span class="badge badge-danger">Ошибка</span>',
    5 => '<span class="badge badge-dark">Отменён</span>',
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

$pending_count = $DB->count_records('unics_umk', ['status' => 1]);

echo '<div class="mb-3 d-flex justify-content-between align-items-center">';
echo '<a href="generate_umk.php" class="btn btn-primary">Создать новый УМК</a>';
echo '<div class="d-flex gap-2">';
if ($pending_count > 0) {
    echo '<a href="?cancel_all=1&sesskey=' . sesskey() . '" class="btn btn-outline-danger btn-sm me-2"
            onclick="return confirm(\'Отменить все ' . $pending_count . ' ожидающих задачи?\')">'
        . 'Отменить все ожидающие (' . $pending_count . ')'
        . '</a>';
}
echo '<a href="?run_now=1&sesskey=' . sesskey() . '" class="btn btn-outline-secondary btn-sm"
        onclick="return confirm(\'Запустить обработку очереди прямо сейчас?\')">'
    . 'Запустить обработку сейчас'
    . '</a>';
echo '</div>';
echo '</div>';

if (empty($records)) {
    echo $OUTPUT->notification('Материалов пока нет. Создайте первый УМК.', 'info');
} else {
    $level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

    $table = new html_table();
    $table->head = ['Материал', 'Тема', 'Уровень', 'Учащихся', 'Курс', 'Статус', 'Дата', ''];
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
            : '-';

        $lvl_label = $level_labels[$r->difficulty_level] ?? ('Ур.' . $r->difficulty_level);

        $actions = '';
        if ((int)$r->status === 1) {
            $cancel_url = new moodle_url('/local/unics/pages/umk_status.php',
                ['cancel_id' => $r->id, 'sesskey' => sesskey()]);
            $actions = html_writer::link($cancel_url, 'Отменить',
                ['class' => 'btn btn-outline-danger btn-sm',
                 'onclick' => "return confirm('Отменить УМК #{$r->id}?')"]);
        }

        $table->data[] = [
            s($r->title),
            s($r->topic),
            $lvl_label,
            (int)$r->student_count,
            $course_link,
            $status,
            $r->generated_at ? userdate(strtotime($r->generated_at)) : '-',
            $actions,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
