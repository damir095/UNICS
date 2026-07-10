<?php
/**
 * Конструктор ИОМ (индивидуального образовательного маршрута) — A2 v1.
 *
 * Педагог/методист/админ собирает маршрут учащегося: цель + упорядоченные шаги
 * (шаг = тема: курс + тема + опц. УМК + план уровня + статус). Доступ к курсам
 * НЕ блокирует — это план/трекер. Наполнение ручное (v1).
 *
 * Точки входа: ростер-хаб «Учащиеся курса», отчёт по учащемуся.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/path_manager.php');

use local_unics\path_manager;

require_login();
local_unics_require_not_student();

global $USER, $DB;

$student_id = required_param('student_id', PARAM_INT);

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

if (!path_manager::can_edit($student_id, (int)$USER->id)) {
    throw new moodle_exception('accessdenied', 'error');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/path_builder.php', ['student_id' => $student_id]));
$PAGE->set_title('Маршрут учащегося - УНИКС');
$PAGE->set_heading('Образовательный маршрут');
$PAGE->set_pagelayout('admin');

$path = path_manager::get_or_create_path($student_id, (int)$USER->id);

$levels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

// ----------------------------------------------------------------
// POST (PRG: обработка → redirect на себя с сообщением).
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    $msg = '';

    if ($action === 'goal') {
        path_manager::set_goal((int)$path->id, optional_param('goal', '', PARAM_TEXT));
        $msg = 'Цель маршрута сохранена.';

    } else if ($action === 'pathstatus') {
        path_manager::set_path_status((int)$path->id, optional_param('status', 1, PARAM_INT));
        $msg = 'Статус маршрута обновлён.';

    } else if ($action === 'add') {
        $title = trim(optional_param('title', '', PARAM_TEXT));
        if ($title === '') {
            redirect($PAGE->url, 'Название шага обязательно.', null,
                \core\output\notification::NOTIFY_WARNING);
        }
        path_manager::add_step((int)$path->id, [
            'title'         => $title,
            'topic'         => optional_param('topic', '', PARAM_TEXT),
            'mdl_course_id' => optional_param('mdl_course_id', 0, PARAM_INT),
            'umk_id'        => optional_param('umk_id', 0, PARAM_INT),
            'target_level'  => optional_param('target_level', 0, PARAM_INT),
            'status'        => optional_param('status', path_manager::STEP_PLANNED, PARAM_INT),
            'note'          => optional_param('note', '', PARAM_TEXT),
        ]);
        $msg = 'Шаг добавлен.';

    } else if ($action === 'update') {
        $step_id = required_param('step_id', PARAM_INT);
        // Проверяем, что шаг принадлежит маршруту этого ученика.
        if ($DB->record_exists('unics_path_step', ['id' => $step_id, 'path_id' => $path->id])) {
            $title = trim(optional_param('title', '', PARAM_TEXT));
            if ($title === '') {
                redirect($PAGE->url, 'Название шага обязательно.', null,
                    \core\output\notification::NOTIFY_WARNING);
            }
            path_manager::update_step($step_id, [
                'title'         => $title,
                'topic'         => optional_param('topic', '', PARAM_TEXT),
                'mdl_course_id' => optional_param('mdl_course_id', 0, PARAM_INT),
                'umk_id'        => optional_param('umk_id', 0, PARAM_INT),
                'target_level'  => optional_param('target_level', 0, PARAM_INT),
                'status'        => optional_param('status', path_manager::STEP_PLANNED, PARAM_INT),
                'note'          => optional_param('note', '', PARAM_TEXT),
            ]);
            $msg = 'Шаг обновлён.';
        }

    } else if ($action === 'delete') {
        $step_id = required_param('step_id', PARAM_INT);
        if ($DB->record_exists('unics_path_step', ['id' => $step_id, 'path_id' => $path->id])) {
            path_manager::delete_step($step_id);
            $msg = 'Шаг удалён.';
        }

    } else if ($action === 'move') {
        $step_id = required_param('step_id', PARAM_INT);
        $dir     = optional_param('dir', '', PARAM_ALPHA);
        if (in_array($dir, ['up', 'down'], true)
                && $DB->record_exists('unics_path_step', ['id' => $step_id, 'path_id' => $path->id])) {
            path_manager::move_step($step_id, $dir);
        }
    }

    redirect($PAGE->url, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

// ----------------------------------------------------------------
// Данные для селекторов.
// ----------------------------------------------------------------
$courses_menu = [0 => '- без курса -'];
foreach ($DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = :uid AND ue.status = 0 AND c.id <> 1
      ORDER BY c.fullname",
    ['uid' => $student->mdl_user_id]) as $c) {
    $courses_menu[(int)$c->id] = format_string($c->fullname);
}

$umk_menu = [0 => '- без УМК -'];
foreach ($DB->get_records_sql(
    "SELECT u.id, u.title, u.topic
       FROM {unics_umk_students} us
       JOIN {unics_umk} u ON u.id = us.umk_id
      WHERE us.student_id = :sid AND u.status = 3
      ORDER BY u.generated_at DESC",
    ['sid' => $student_id]) as $u) {
    $umk_menu[(int)$u->id] = $u->title . ($u->topic ? ' (' . $u->topic . ')' : '');
}

$level_menu  = [0 => '- не задан -'] + $levels;
$status_menu = [
    path_manager::STEP_PLANNED    => path_manager::step_status_label(path_manager::STEP_PLANNED),
    path_manager::STEP_INPROGRESS => path_manager::step_status_label(path_manager::STEP_INPROGRESS),
    path_manager::STEP_DONE       => path_manager::step_status_label(path_manager::STEP_DONE),
];

$steps    = array_values(path_manager::get_steps((int)$path->id));
$progress = path_manager::progress((int)$path->id);

// ----------------------------------------------------------------
// Вывод.
// ----------------------------------------------------------------
$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]),
        '← К отчёту учащегося', ['class' => 'btn btn-outline-secondary btn-sm']),
    'mb-3'
);
echo $OUTPUT->heading('Маршрут: ' . s($fio));

$sesskey = sesskey();

/** Скрытые поля action+sesskey для компактных форм. */
$hidden = function(string $action, array $extra = []) use ($student_id, $sesskey) {
    $h  = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'student_id', 'value' => $student_id]);
    $h .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
    $h .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    foreach ($extra as $k => $v) {
        $h .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    return $h;
};

// --- Прогресс + статус маршрута ---
echo '<div class="card mb-4"><div class="card-body">';
echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">';
echo '<div><strong>Прогресс:</strong> ' . $progress['done'] . ' из ' . $progress['total'] . ' шагов'
   . ' <span class="badge badge-light">' . path_manager::status_label((int)$path->status) . '</span></div>';
// Смена статуса маршрута.
echo '<form method="post" class="form-inline gap-1">' . $hidden('pathstatus');
$path_status_menu = [
    path_manager::STATUS_ACTIVE   => path_manager::status_label(path_manager::STATUS_ACTIVE),
    path_manager::STATUS_DONE     => path_manager::status_label(path_manager::STATUS_DONE),
    path_manager::STATUS_ARCHIVED => path_manager::status_label(path_manager::STATUS_ARCHIVED),
];
echo html_writer::select($path_status_menu, 'status', (int)$path->status, false, ['class' => 'form-control form-control-sm mr-1']);
echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Сменить статус</button>';
echo '</form>';
echo '</div>';

// --- Цель ---
echo '<form method="post" class="mt-3">' . $hidden('goal');
echo '<label class="font-weight-bold">Цель маршрута</label>';
echo html_writer::tag('textarea', s((string)($path->goal ?? '')),
    ['name' => 'goal', 'class' => 'form-control', 'rows' => 2,
     'placeholder' => 'Например: освоить раздел «Нефть» на стандартном уровне к концу четверти']);
echo '<button type="submit" class="btn btn-sm btn-primary mt-2">Сохранить цель</button>';
echo '</form>';
echo '</div></div>';

/**
 * Рендер полей шага (используется и в строке редактирования, и в форме добавления).
 */
$step_fields = function(?object $s) use ($courses_menu, $umk_menu, $level_menu, $status_menu) {
    $cur_course = $s ? (int)($s->mdl_course_id ?? 0) : 0;
    $cur_umk    = $s ? (int)($s->umk_id ?? 0) : 0;
    $cur_level  = $s ? (int)($s->target_level ?? 0) : 0;
    $cur_status = $s ? (int)$s->status : path_manager::STEP_PLANNED;
    $cur_title  = $s ? (string)$s->title : '';
    $cur_topic  = $s ? (string)($s->topic ?? '') : '';
    $cur_note   = $s ? (string)($s->note ?? '') : '';

    $out  = '<div class="form-row">';
    $out .= '<div class="col-md-4 mb-2">' . html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'title', 'value' => $cur_title, 'required' => 'required',
        'class' => 'form-control form-control-sm', 'placeholder' => 'Название шага *']) . '</div>';
    $out .= '<div class="col-md-4 mb-2">' . html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'topic', 'value' => $cur_topic,
        'class' => 'form-control form-control-sm', 'placeholder' => 'Тема']) . '</div>';
    $out .= '<div class="col-md-4 mb-2">'
          . html_writer::select($courses_menu, 'mdl_course_id', $cur_course, false,
                ['class' => 'form-control form-control-sm']) . '</div>';
    $out .= '<div class="col-md-4 mb-2">'
          . html_writer::select($umk_menu, 'umk_id', $cur_umk, false,
                ['class' => 'form-control form-control-sm']) . '</div>';
    $out .= '<div class="col-md-3 mb-2">'
          . html_writer::select($level_menu, 'target_level', $cur_level, false,
                ['class' => 'form-control form-control-sm']) . '</div>';
    $out .= '<div class="col-md-3 mb-2">'
          . html_writer::select($status_menu, 'status', $cur_status, false,
                ['class' => 'form-control form-control-sm']) . '</div>';
    $out .= '<div class="col-md-2 mb-2">' . html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'note', 'value' => $cur_note,
        'class' => 'form-control form-control-sm', 'placeholder' => 'Заметка']) . '</div>';
    $out .= '</div>';
    return $out;
};

// --- Список шагов (каждый = форма редактирования) ---
echo $OUTPUT->heading('Шаги маршрута', 4);
if (!$steps) {
    echo '<p class="text-muted">Шагов пока нет. Добавьте первый шаг ниже.</p>';
} else {
    $last = count($steps) - 1;
    foreach ($steps as $i => $s) {
        $hint = '';
        if (!empty($s->mdl_course_id)) {
            $avg = path_manager::course_grade_hint((int)$student->mdl_user_id, (int)$s->mdl_course_id);
            if ($avg !== null) {
                $looks = path_manager::looks_done((int)$student->mdl_user_id, (int)$s->mdl_course_id);
                $hint = ' <span class="text-muted small">средний по курсу: ' . $avg . '%'
                      . ($looks && (int)$s->status !== path_manager::STEP_DONE
                            ? ' - по оценкам похоже на пройденный' : '')
                      . '</span>';
            }
        }

        echo '<div class="card mb-2"><div class="card-body py-2">';
        echo '<div class="d-flex align-items-center justify-content-between mb-2">';
        echo '<span><strong>Шаг ' . ($i + 1) . '</strong> '
           . '<span class="badge badge-' . path_manager::step_status_badge((int)$s->status) . '">'
           . path_manager::step_status_label((int)$s->status) . '</span>' . $hint . '</span>';

        // Кнопки перемещения / удаления (отдельные мини-формы).
        echo '<span class="d-flex gap-1">';
        if ($i > 0) {
            echo '<form method="post" class="d-inline">' . $hidden('move', ['step_id' => $s->id, 'dir' => 'up'])
               . '<button type="submit" class="btn btn-sm btn-outline-secondary" title="Вверх">↑</button></form>';
        }
        if ($i < $last) {
            echo '<form method="post" class="d-inline">' . $hidden('move', ['step_id' => $s->id, 'dir' => 'down'])
               . '<button type="submit" class="btn btn-sm btn-outline-secondary" title="Вниз">↓</button></form>';
        }
        echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Удалить шаг?\');">'
           . $hidden('delete', ['step_id' => $s->id])
           . '<button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">✕</button></form>';
        echo '</span>';
        echo '</div>';

        // Форма редактирования шага.
        echo '<form method="post">' . $hidden('update', ['step_id' => $s->id]);
        echo $step_fields($s);
        echo '<button type="submit" class="btn btn-sm btn-primary">Сохранить шаг</button>';
        echo '</form>';
        echo '</div></div>';
    }
}

// --- Добавить шаг ---
echo $OUTPUT->heading('Добавить шаг', 4);
echo '<div class="card mb-4"><div class="card-body">';
echo '<form method="post">' . $hidden('add');
echo $step_fields(null);
echo '<button type="submit" class="btn btn-success">Добавить шаг</button>';
echo '</form>';
echo '</div></div>';

echo $OUTPUT->footer();
