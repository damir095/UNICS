<?php
/**
 * «Мой маршрут» — детский вид ИОМ для учащегося (и родителя) — A2 v1.
 *
 * Показывает цель, прогресс (N из M), текущий шаг (выделен), следующие шаги и
 * бейджи статусов. Маршрут НЕ блокирует доступ к курсам — это план/трекер.
 * Крупные элементы, единая шкала, читается с доступностью ([[accessibility-features-design]]).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/path_manager.php');

use local_unics\path_manager;

require_login();
global $USER, $DB;

// Учащийся смотрит свой маршрут (student_id не нужен); родитель/педагог - по student_id.
$student_id = optional_param('student_id', 0, PARAM_INT);
if (!$student_id) {
    $own = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
    if ($own) {
        $student_id = (int)$own->id;
    }
}
if (!$student_id) {
    throw new moodle_exception('accessdenied', 'error');
}

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

$access = path_manager::access_level($student_id, (int)$USER->id);
if ($access === 'none') {
    throw new moodle_exception('accessdenied', 'error');
}
$can_edit = ($access === 'edit');
$is_own   = ((int)$student->mdl_user_id === (int)$USER->id);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/my_path.php', ['student_id' => $student_id]));
$PAGE->set_title('Мой маршрут - УНИКС');
$PAGE->set_heading($is_own ? 'Мой образовательный маршрут' : 'Образовательный маршрут');
$PAGE->set_pagelayout('standard');

$levels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

$path  = path_manager::get_active_path($student_id);
$steps = $path ? array_values(path_manager::get_steps((int)$path->id)) : [];

echo $OUTPUT->header();
echo local_unics_dashboard_button();

if (!$is_own) {
    $fio = trim("{$mdl_user->lastname} {$mdl_user->firstname}");
    echo '<p class="lead">' . s($fio) . '</p>';
}

// Конструктор - для педагога/методиста/админа.
if ($can_edit) {
    echo html_writer::div(
        html_writer::link(new moodle_url('/local/unics/pages/path_builder.php', ['student_id' => $student_id]),
            'Редактировать маршрут', ['class' => 'btn btn-outline-primary btn-sm']),
        'mb-3'
    );
}

if (!$path || !$steps) {
    echo $OUTPUT->notification(
        $is_own
            ? 'Твой образовательный маршрут пока не составлен. Педагог скоро его подготовит.'
            : 'Маршрут пока не составлен.',
        'info'
    );
    echo $OUTPUT->footer();
    exit;
}

$progress = path_manager::progress((int)$path->id);
$current_id = $progress['current'] ? (int)$progress['current']->id : 0;
$pct = $progress['total'] > 0 ? round($progress['done'] / $progress['total'] * 100) : 0;

// --- Цель ---
if (!empty($path->goal)) {
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h2 class="unics-section-title mt-0">Цель</h2>';
    echo '<p class="mb-0" style="white-space:pre-wrap;font-size:1.15rem;">' . s($path->goal) . '</p>';
    echo '</div></div>';
}

// --- Прогресс ---
echo '<h2 class="unics-section-title">Прогресс</h2>';
echo '<p style="font-size:1.15rem;">Пройдено <strong>' . $progress['done'] . '</strong> из <strong>'
   . $progress['total'] . '</strong> шагов.</p>';
echo '<div class="progress mb-4" style="height:24px;">';
echo '<div class="progress-bar bg-success" role="progressbar" aria-label="Прогресс маршрута" '
   . 'style="width:' . $pct . '%;" '
   . 'aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">' . $pct . '%</div>';
echo '</div>';

// --- Шаги (сгруппированы по курсу) ---
echo '<h2 class="unics-section-title">Шаги маршрута</h2>';

foreach (path_manager::steps_grouped_by_course((int)$path->id) as $group) {
    // Заголовок группы (курс) + мини-прогресс.
    echo '<h3 class="unics-section-title" style="font-size:1.25rem;margin-top:1.5rem;">'
       . s($group['course_name'])
       . ' <span class="badge badge-light" style="font-size:0.9rem;font-weight:normal;">'
       . $group['done'] . ' из ' . $group['total'] . '</span></h3>';

    foreach ($group['steps'] as $gi => $s) {
        $is_current = ((int)$s->id === $current_id);
        $border = $is_current ? ' border-primary' : '';

        echo '<div class="card mb-3' . $border . '">';
        echo '<div class="card-body">';

        echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">';
        echo '<h4 class="mt-0 mb-0" style="font-size:1.15rem;">';
        echo '<span class="text-muted">' . ($gi + 1) . '.</span> ' . s($s->title);
        if ($is_current) {
            echo ' <span class="badge badge-primary">текущий шаг</span>';
        }
        echo '</h4>';
        echo '<span class="badge badge-' . path_manager::step_status_badge((int)$s->status) . '" '
           . 'style="font-size:0.95rem;">' . path_manager::step_status_label((int)$s->status) . '</span>';
        echo '</div>';

        $meta = [];
        if (!empty($s->topic)) {
            $meta[] = 'Тема: ' . s($s->topic);
        }
        if (!empty($s->target_level)) {
            $meta[] = ($is_own ? 'Уровень: ' : 'Цель уровня: ')
                . s($levels[(int)$s->target_level] ?? '-');
        }
        if ($meta) {
            echo '<p class="mt-2 mb-1 text-muted">' . implode(' · ', $meta) . '</p>';
        }
        if (!empty($s->note)) {
            echo '<p class="mb-2" style="white-space:pre-wrap;">' . s($s->note) . '</p>';
        }

        // Ссылка «к материалу» (выводимая из навыка/курса).
        $mat = path_manager::step_material_url($s);
        if ($mat !== null) {
            $label = $mat['kind'] === 'activity' ? 'Перейти к материалу' : 'Открыть курс';
            echo html_writer::link($mat['url'], $label, ['class' => 'unics-cta']);
        }

        echo '</div></div>';
    }
}

echo $OUTPUT->footer();
