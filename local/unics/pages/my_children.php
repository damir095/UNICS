<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
global $USER, $DB;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/my_children.php'));
$PAGE->set_title('Мои дети — УНИКС');
$PAGE->set_heading('Мои дети');
$PAGE->set_pagelayout('standard');

$children = $DB->get_records_sql(
    "SELECT ps.student_id,
            u.lastname, u.firstname, u.middlename, u.email,
            s.class_number, s.class_letter, s.category, s.difficulty_level,
            o.name AS org_name
     FROM {unics_parent_student} ps
     JOIN {unics_students} s        ON s.id  = ps.student_id
     JOIN {user} u                  ON u.id  = s.mdl_user_id
     LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
     WHERE ps.parent_mdl_user_id = :uid AND u.deleted = 0
     ORDER BY u.lastname, u.firstname",
    ['uid' => $USER->id]
);

echo $OUTPUT->header();
echo $OUTPUT->heading('Мои дети');

if (empty($children)) {
    echo $OUTPUT->notification(
        'Ваш аккаунт не связан ни с одним учащимся. Обратитесь к администратору системы.',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

$categories = [1 => 'ОВЗ', 2 => 'Семейное обучение', 3 => 'Длительное лечение', 4 => 'Одарённый'];
$levels     = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

echo '<div class="row">';
foreach ($children as $c) {
    $fio = trim("{$c->lastname} {$c->firstname} " . ($c->middlename ?? ''));
    $class_str = $c->class_number
        ? $c->class_number . ($c->class_letter ? " «{$c->class_letter}»" : '') . ' класс'
        : '—';

    echo '<div class="col-md-4 mb-4">';
    echo '<div class="card h-100 shadow-sm">';
    echo '<div class="card-header bg-primary text-white">';
    echo '<strong>' . s($fio) . '</strong>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<p class="mb-1"><b>Класс:</b> ' . s($class_str) . '</p>';
    echo '<p class="mb-1"><b>Категория:</b> ' . s($categories[$c->category] ?? '—') . '</p>';
    echo '<p class="mb-1"><b>Уровень:</b> ' . s($levels[$c->difficulty_level] ?? '—') . '</p>';
    if ($c->org_name) {
        echo '<p class="mb-1"><b>Организация:</b> ' . s($c->org_name) . '</p>';
    }
    echo '</div>';
    echo '<div class="card-footer bg-white">';
    echo html_writer::link(
        new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $c->student_id]),
        'Отчёт об успеваемости',
        ['class' => 'btn btn-primary btn-sm btn-block']
    );
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
echo '</div>';

echo $OUTPUT->footer();
