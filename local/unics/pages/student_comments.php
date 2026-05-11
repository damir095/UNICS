<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
global $USER, $DB;

$student_id = required_param('student_id', PARAM_INT);
$cmid       = optional_param('cmid', 0, PARAM_INT);   // 0 = общие заметки

$ctx        = context_system::instance();
$is_admin   = has_capability('local/unics:manage',       $ctx);
$is_teacher = has_capability('local/unics:viewstudents', $ctx);

if (!$is_admin && !$is_teacher) {
    throw new moodle_exception('accessdenied', 'error');
}

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

// Педагог может комментировать только своих учащихся
if (!$is_admin) {
    $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if (!$teacher_rec || !$DB->record_exists('unics_teacher_student', [
        'teacher_id' => $teacher_rec->id,
        'student_id' => $student_id,
    ])) {
        throw new moodle_exception('accessdenied', 'error');
    }
}

// Если указан cmid — проверяем и получаем информацию об активности
$cm_info      = null;
$module_name  = '';
$module_label = '';
if ($cmid > 0) {
    $cm_info = $DB->get_record_sql(
        "SELECT cm.id, cm.instance, cm.course, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.id = :cmid",
        ['cmid' => $cmid]
    );
    if ($cm_info) {
        $module_name = $DB->get_field($cm_info->modname, 'name', ['id' => $cm_info->instance]);
        $type_labels = ['quiz' => 'Тест', 'page' => 'Страница', 'assign' => 'Задание', 'resource' => 'Файл'];
        $module_label = ($type_labels[$cm_info->modname] ?? ucfirst($cm_info->modname))
                      . ': ' . ($module_name ?: '#' . $cm_info->instance);
    } else {
        $cmid = 0; // некорректный cmid — сбрасываем
    }
}

$page_url = new moodle_url('/local/unics/pages/student_comments.php',
    array_filter(['student_id' => $student_id, 'cmid' => $cmid ?: null]));
$PAGE->set_context($ctx);
$PAGE->set_url($page_url);
$PAGE->set_title('Заметки педагога');
$PAGE->set_heading('Заметки педагога');
$PAGE->set_pagelayout('standard');

// ----------------------------------------------------------------
// Обработка POST: добавить заметку
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $body = trim(required_param('body', PARAM_TEXT));
    if (mb_strlen($body) > 0) {
        $rec = (object)[
            'student_id'          => $student_id,
            'teacher_mdl_user_id' => $USER->id,
            'body'                => $body,
            'created_at'          => time(),
        ];
        if ($cmid > 0) {
            $rec->cmid = $cmid;
        }
        $DB->insert_record('unics_comments', $rec);

        // Уведомить учащегося о новой заметке
        try {
            require_once(__DIR__ . '/../classes/notification_manager.php');
            $teacher_name = trim($USER->lastname . ' ' . $USER->firstname);
            $context_lbl  = $cmid > 0 && $cm_info
                ? ($module_label ?: 'активность курса')
                : '';
            \local_unics\notification_manager::notify_new_comment(
                (int)$student->mdl_user_id,
                $teacher_name,
                $context_lbl
            );
        } catch (\Throwable $e) {
            // Нефатально
        }
    }
    redirect($page_url);
}

// ----------------------------------------------------------------
// Загружаем комментарии
// ----------------------------------------------------------------
if ($cmid > 0) {
    // Заметки к конкретной активности
    $comments = $DB->get_records_sql(
        "SELECT c.id, c.body, c.created_at,
                u.lastname, u.firstname, u.middlename
           FROM {unics_comments} c
           JOIN {user} u ON u.id = c.teacher_mdl_user_id
          WHERE c.student_id = :sid
            AND c.cmid       = :cmid
          ORDER BY c.created_at DESC",
        ['sid' => $student_id, 'cmid' => $cmid]
    );
} else {
    // Все заметки без привязки к активности (cmid IS NULL)
    $comments = $DB->get_records_sql(
        "SELECT c.id, c.body, c.created_at,
                u.lastname, u.firstname, u.middlename
           FROM {unics_comments} c
           JOIN {user} u ON u.id = c.teacher_mdl_user_id
          WHERE c.student_id = :sid
            AND c.cmid IS NULL
          ORDER BY c.created_at DESC",
        ['sid' => $student_id]
    );
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));

echo $OUTPUT->header();

echo '<div class="mb-3 d-flex flex-wrap gap-2">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]),
    'Отчёт по учащемуся',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
if ($cmid > 0 && $cm_info) {
    echo html_writer::link(
        new moodle_url('/local/unics/pages/course_notes.php', [
            'student_id' => $student_id,
            'courseid'   => $cm_info->course,
        ]),
        'Все заметки по курсу',
        ['class' => 'btn btn-outline-info btn-sm']
    );
    echo html_writer::link(
        new moodle_url('/mod/' . $cm_info->modname . '/view.php', ['id' => $cmid]),
        'Перейти к активности',
        ['class' => 'btn btn-outline-primary btn-sm', 'target' => '_blank']
    );
}
echo '</div>';

$heading = $cmid > 0
    ? 'Заметки педагога к «' . s($module_label) . '»'
    : 'Заметки педагога';
echo $OUTPUT->heading($heading . ': ' . s($fio));

if ($cmid > 0) {
    echo '<p class="text-muted mb-3 small">Заметки привязаны к конкретной активности курса и видны в разделе «Заметки педагога» этого курса.</p>';
}

// Форма добавления
$form_url = new moodle_url('/local/unics/pages/student_comments.php',
    array_filter(['student_id' => $student_id, 'cmid' => $cmid ?: null, 'sesskey' => sesskey()]));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url, 'class' => 'mb-4']);
echo html_writer::start_tag('div', ['class' => 'form-group']);
$placeholder = $cmid > 0
    ? 'Наблюдение, рекомендация или комментарий к этой активности…'
    : 'Наблюдение, рекомендация или пожелание для учащегося или его родителей…';
echo html_writer::tag('label', 'Новая заметка', ['class' => 'font-weight-bold']);
echo html_writer::tag('textarea', '', [
    'name'        => 'body',
    'class'       => 'form-control',
    'rows'        => 4,
    'required'    => 'required',
    'placeholder' => $placeholder,
]);
echo html_writer::end_tag('div');
echo html_writer::tag('button', 'Сохранить заметку', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// Список заметок
if (empty($comments)) {
    echo html_writer::tag('p', 'Заметок пока нет.', ['class' => 'text-muted']);
} else {
    foreach ($comments as $cm) {
        $author = trim("{$cm->lastname} {$cm->firstname} " . ($cm->middlename ?? ''));
        echo '<div class="card mb-3 unics-comment-card">';
        echo '<div class="card-header d-flex justify-content-between align-items-center">';
        echo '<span class="font-weight-bold">' . s($author) . '</span>';
        echo '<small class="text-muted">' . userdate($cm->created_at, '%d.%m.%Y %H:%M') . '</small>';
        echo '</div>';
        echo '<div class="card-body py-2">';
        echo '<p class="mb-0" style="white-space:pre-wrap">' . s($cm->body) . '</p>';
        echo '</div>';
        echo '</div>';
    }
}

echo $OUTPUT->footer();
