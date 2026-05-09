<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
global $USER, $DB;

$student_id = required_param('student_id', PARAM_INT);
$courseid   = required_param('courseid',   PARAM_INT);

$ctx        = context_system::instance();
$is_admin   = has_capability('local/unics:manage',       $ctx);
$is_teacher = has_capability('local/unics:viewstudents', $ctx);

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Контроль доступа
$access = $is_admin;
if (!$access && $is_teacher) {
    $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if ($teacher_rec) {
        $access = $DB->record_exists('unics_teacher_student', [
            'teacher_id' => $teacher_rec->id,
            'student_id' => $student_id,
        ]);
    }
}
if (!$access && $USER->id === (int)$student->mdl_user_id) {
    $access = true;
}
if (!$access) {
    $access = $DB->record_exists('unics_parent_student', [
        'parent_mdl_user_id' => $USER->id,
        'student_id'         => $student_id,
    ]);
}
if (!$access) {
    throw new moodle_exception('accessdenied', 'error');
}

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_notes.php', [
    'student_id' => $student_id,
    'courseid'   => $courseid,
]));
$PAGE->set_title('Заметки педагога — ' . $course->fullname);
$PAGE->set_heading('Заметки педагога в курсе');
$PAGE->set_pagelayout('standard');

// ----------------------------------------------------------------
// Загружаем заметки, привязанные к активностям этого курса
// ----------------------------------------------------------------
$notes = $DB->get_records_sql(
    "SELECT c.id, c.body, c.created_at, c.cmid,
            cm.instance AS cm_instance,
            m.name      AS modname,
            u.lastname, u.firstname
       FROM {unics_comments} c
       JOIN {user}           u  ON u.id  = c.teacher_mdl_user_id
       JOIN {course_modules} cm ON cm.id = c.cmid
       JOIN {modules}        m  ON m.id  = cm.module
      WHERE c.student_id = :sid
        AND cm.course    = :cid
      ORDER BY c.cmid ASC, c.created_at DESC",
    ['sid' => $student_id, 'cid' => $courseid]
);

// Разрешаем имена модулей пакетно
$mod_names = [];
foreach ($notes as $n) {
    $key = $n->modname . '_' . $n->cm_instance;
    if (!isset($mod_names[$key])) {
        $name = $DB->get_field($n->modname, 'name', ['id' => $n->cm_instance]);
        $mod_names[$key] = $name ?: ucfirst($n->modname) . ' #' . $n->cm_instance;
    }
}

// Группируем по cmid
$by_cm = [];
foreach ($notes as $n) {
    $by_cm[$n->cmid][] = $n;
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));
$mod_type_labels = ['quiz' => 'Тест', 'page' => 'Страница', 'assign' => 'Задание', 'resource' => 'Файл'];

echo $OUTPUT->header();

echo '<div class="mb-3 d-flex flex-wrap gap-2">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]),
    'Отчёт по учащемуся',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::link(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    'Перейти в курс →',
    ['class' => 'btn btn-outline-primary btn-sm']
);
echo '</div>';

echo '<h5 class="mb-1">Учащийся: <strong>' . s($fio) . '</strong></h5>';
echo '<p class="text-muted mb-4">Курс: ' . s($course->fullname) . '</p>';

if (empty($by_cm)) {
    echo '<div class="alert alert-info">Заметок педагога для этого учащегося в данном курсе пока нет.</div>';
    echo $OUTPUT->footer();
    exit;
}

foreach ($by_cm as $cmid => $cm_notes) {
    $first     = reset($cm_notes);
    $mod_key   = $first->modname . '_' . $first->cm_instance;
    $mod_label = $mod_type_labels[$first->modname] ?? ucfirst($first->modname);
    $mod_name  = $mod_names[$mod_key] ?? 'Активность #' . $cmid;

    echo '<div class="card mb-3 unics-comment-card">';
    echo '<div class="card-header d-flex justify-content-between align-items-center">';
    echo '<span class="font-weight-bold">';
    echo '<span class="badge badge-secondary mr-1">' . s($mod_label) . '</span>';
    echo html_writer::link(
        new moodle_url('/mod/' . $first->modname . '/view.php', ['id' => $cmid]),
        s($mod_name),
        ['target' => '_blank']
    );
    echo '</span>';
    if ($is_admin || $is_teacher) {
        echo html_writer::link(
            new moodle_url('/local/unics/pages/student_comments.php', [
                'student_id' => $student_id,
                'cmid'       => $cmid,
            ]),
            '+ Добавить заметку',
            ['class' => 'btn btn-sm btn-outline-primary']
        );
    }
    echo '</div>';
    echo '<div class="card-body py-2">';
    foreach ($cm_notes as $n) {
        $author = trim("{$n->lastname} {$n->firstname}");
        echo '<div class="unics-teacher-note mb-2">';
        echo '<div class="note-meta">';
        echo '<span class="note-author">' . s($author) . '</span>';
        echo '<span class="note-date">' . userdate($n->created_at, '%d.%m.%Y %H:%M') . '</span>';
        echo '</div>';
        echo '<p class="note-body">' . s($n->body) . '</p>';
        echo '</div>';
    }
    echo '</div></div>';
}

echo $OUTPUT->footer();
