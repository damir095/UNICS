<?php
/**
 * Учащиеся курса — ростер-хаб (course-integration фаза 2).
 *
 * Центр доступа к per-student действиям из контекста курса. Строки = активные
 * учащиеся, записанные на курс (та же выборка, что в gradebook.php / course_report).
 * На каждого ученика — кнопки: Отчёт, Заметки (этого курса), Сгенерировать УМК.
 * (ИИ-проверка задания «по ученику» — отдельно, под ИИ-заморозкой.)
 *
 * Открывается из меню курса «Дополнительно» -> единственный пункт «УНИКС» -> хаб-страница
 * pages/course_hub.php, группа «Как идут дела».
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Просмотр — персонал курса (grade:viewall в контексте курса), методист или админ.
$can_view = has_capability('local/unics:manage', context_system::instance())
    || local_unics_is_methodist()
    || has_capability('moodle/grade:viewall', $context);
if (!$can_view) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        'Недостаточно прав для просмотра учащихся курса.',
        null, \core\output\notification::NOTIFY_WARNING);
}

// Кнопку УМК показываем только тем, кто создаёт контент (как в меню курса):
// не-editing teacher (роль 6) УМК не генерирует.
$can_umk = !local_unics_is_nonediting_teacher() && (
    has_capability('local/unics:manage', context_system::instance())
    || has_capability('moodle/course:manageactivities', $context)
    || local_unics_is_methodist()
);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_students.php', ['course_id' => $course_id]));
$PAGE->set_title('Учащиеся курса - УНИКС');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$levels = [1 => 'Базовый', 2 => 'Стандарт', 3 => 'Продвинут.'];

// ----------------------------------------------------------------
// Активные учащиеся, записанные на курс.
// ----------------------------------------------------------------
// Категории/ОВЗ - из нормализованных таблиц с прежними алиасами (этап 2.6-B).
[$catsql, $ovzsql] = \local_unics\identity\student_helper::taxonomy_select_sql('s');
$students = $DB->get_records_sql(
    "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, {$catsql}, {$ovzsql}, s.difficulty_level
       FROM {unics_students} s
       JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
       JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid
      ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
    ['cid' => $course_id]
);

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo html_writer::div(
    html_writer::link(new moodle_url('/course/view.php', ['id' => $course_id]),
        '← В курс', ['class' => 'btn btn-outline-secondary btn-sm']),
    'mb-3'
);
echo $OUTPUT->heading('Учащиеся курса');

if (empty($students)) {
    echo $OUTPUT->notification('На этот курс не записано активных учащихся.', 'info');
    echo $OUTPUT->footer();
    exit;
}

echo '<p class="text-muted">Записанные на курс учащиеся. По каждому — переход к отчёту, '
   . 'заметкам педагога этого курса' . ($can_umk ? ' и генерации УМК' : '') . '.</p>';

echo '<div class="table-responsive">';
echo '<table class="' . local_unics_table_class() . '">';
echo '<thead><tr>
    <th>Учащийся</th><th>Класс</th><th>Категория</th><th>Уровень</th><th>Действия</th>
</tr></thead><tbody>';

foreach ($students as $s) {
    $sid = (int)$s->student_id;
    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $class_str = $s->class_number
        ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
        : '-';

    $actions = [];
    $actions[] = html_writer::link(
        new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $sid]),
        'Отчёт', ['class' => 'btn btn-sm btn-outline-primary']);
    $actions[] = html_writer::link(
        new moodle_url('/local/unics/pages/course_notes.php',
            ['student_id' => $sid, 'courseid' => $course_id]),
        'Заметки', ['class' => 'btn btn-sm btn-outline-secondary']);
    $actions[] = html_writer::link(
        new moodle_url('/local/unics/pages/path_builder.php', ['student_id' => $sid]),
        'Маршрут', ['class' => 'btn btn-sm btn-outline-secondary']);
    if ($can_umk) {
        $actions[] = html_writer::link(
            new moodle_url('/local/unics/pages/generate_umk.php',
                ['course_id' => $course_id, 'student_id' => $sid]),
            'УМК', ['class' => 'btn btn-sm btn-outline-secondary']);
    }

    echo '<tr>';
    echo '<td>' . s($fio) . '</td>';
    echo '<td>' . s($class_str) . '</td>';
    echo '<td>' . s(\local_unics\identity\student_helper::format_categories($s) ?: '-') . '</td>';
    echo '<td>' . ($levels[$s->difficulty_level] ?? '-') . '</td>';
    echo '<td><div class="d-flex flex-wrap gap-1">' . implode(' ', $actions) . '</div></td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

echo $OUTPUT->footer();
