<?php
/**
 * Адаптивные уровни учащихся курса (course-integration фаза 2).
 *
 * Список difficulty_level записанных учащихся: текущий уровень, средний балл по
 * последним тестам и ПРЕДЛОЖЕННЫЙ уровень (read-only preview движка). Кнопка
 * «Пересчитать» прогоняет adaptive_engine::evaluate_student по каждому учащемуся
 * (мутирует уровень + уведомления + баллы) — операция управленца, доступна
 * методисту/админу. Просмотр — всему персоналу курса.
 *
 * Открывается из меню «Ещё» → «УНИКС: Адаптивные уровни».
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/adaptive_engine.php');

use local_unics\adaptive_engine;

$course_id = required_param('course_id', PARAM_INT);
$course    = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);

require_login($course);
local_unics_require_not_student();

$context = context_course::instance($course_id);

// Просмотр — персонал курса (grade:viewall), методист или админ.
$can_view = has_capability('local/unics:manage', context_system::instance())
    || local_unics_is_methodist()
    || has_capability('moodle/grade:viewall', $context);
if (!$can_view) {
    redirect(new moodle_url('/course/view.php', ['id' => $course_id]),
        'Недостаточно прав для просмотра адаптивных уровней.',
        null, \core\output\notification::NOTIFY_WARNING);
}

// Пересчёт уровней (мутация) — только методист/админ.
$can_recalc = has_capability('local/unics:manage', context_system::instance())
    || local_unics_is_methodist();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/unics/pages/course_levels.php', ['course_id' => $course_id]));
$PAGE->set_title('Адаптивные уровни - УНИКС');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$levels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

// ----------------------------------------------------------------
// Активные учащиеся, записанные на курс.
// ----------------------------------------------------------------
$students = $DB->get_records_sql(
    "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            s.class_number, s.class_letter, s.difficulty_level
       FROM {unics_students} s
       JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
       JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
       JOIN {enrol} e ON e.id = ue.enrolid
      WHERE ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid
      ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
    ['cid' => $course_id]
);

// ----------------------------------------------------------------
// POST: пересчитать уровни записанных учащихся.
// ----------------------------------------------------------------
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if (!$can_recalc) {
        redirect($PAGE->url, 'Пересчёт доступен методисту или администратору.',
            null, \core\output\notification::NOTIFY_WARNING);
    }
    $changed = 0;
    foreach ($students as $s) {
        $new = adaptive_engine::evaluate_student((int)$s->student_id);
        if ($new !== null) {
            $changed++;
        }
    }
    $msg = $changed > 0
        ? "Пересчёт завершён. Уровень изменён у учащихся: {$changed}."
        : 'Пересчёт завершён. Изменений уровня нет.';
    // Перечитываем строки — difficulty_level мог обновиться.
    $students = $DB->get_records_sql(
        "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
                u.lastname, u.firstname, u.middlename,
                s.class_number, s.class_letter, s.difficulty_level
           FROM {unics_students} s
           JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
           JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid
          ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
        ['cid' => $course_id]
    );
}

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo html_writer::div(
    html_writer::link(new moodle_url('/course/view.php', ['id' => $course_id]),
        '← В курс', ['class' => 'btn btn-outline-secondary btn-sm']),
    'mb-3'
);
echo $OUTPUT->heading('Адаптивные уровни');

if ($msg) {
    echo $OUTPUT->notification($msg, 'info');
}

echo '<p class="text-muted">Текущий уровень сложности учащихся и предложение движка по '
   . 'последним тестам (повышение при среднем &ge; ' . adaptive_engine::THRESHOLD_UP . '%, '
   . 'понижение при &lt; ' . adaptive_engine::THRESHOLD_DOWN . '%, минимум '
   . adaptive_engine::MIN_GRADES . ' теста). Пересчёт применяет предложения, начисляет баллы '
   . 'за повышение и уведомляет педагогов, учащегося и родителей.</p>';

if (empty($students)) {
    echo $OUTPUT->notification('На этот курс не записано активных учащихся.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// ----------------------------------------------------------------
// Таблица: текущий → предложенный.
// ----------------------------------------------------------------
echo '<table class="table table-sm table-bordered table-hover">';
echo '<thead class="table-light"><tr>
    <th>Учащийся</th><th>Класс</th><th>Текущий уровень</th><th>Средний балл</th>
    <th>Предложение</th>
</tr></thead><tbody>';

foreach ($students as $s) {
    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $class_str = $s->class_number
        ? $s->class_number . ($s->class_letter ? " «{$s->class_letter}»" : '')
        : '-';
    $cur_lvl = (int)$s->difficulty_level;

    $p = adaptive_engine::preview_student((int)$s->student_id);

    if ($p['avg'] === null) {
        $avg_cell  = '<span class="text-muted">-</span>';
        $prop_cell = '<span class="text-muted">мало данных (' . $p['n'] . ')</span>';
    } else {
        $avg_cell = $p['avg'] . '%';
        $proposed = (int)$p['proposed'];
        if ($proposed === $cur_lvl) {
            $prop_cell = '<span class="badge badge-success">без изменений</span>';
        } elseif ($proposed > $cur_lvl) {
            $prop_cell = '<span class="badge badge-info">↑ ' . ($levels[$proposed] ?? $proposed) . '</span>';
        } else {
            $prop_cell = '<span class="badge badge-warning">↓ ' . ($levels[$proposed] ?? $proposed) . '</span>';
        }
    }

    echo '<tr>';
    echo '<td>' . s($fio) . '</td>';
    echo '<td>' . s($class_str) . '</td>';
    echo '<td>' . ($levels[$cur_lvl] ?? '-') . '</td>';
    echo '<td>' . $avg_cell . '</td>';
    echo '<td>' . $prop_cell . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

if ($can_recalc) {
    $form_url = new moodle_url('/local/unics/pages/course_levels.php',
        ['course_id' => $course_id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
    echo html_writer::tag('button', 'Пересчитать уровни',
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
} else {
    echo '<p class="text-muted">Пересчёт уровней доступен методисту или администратору.</p>';
}

echo $OUTPUT->footer();
