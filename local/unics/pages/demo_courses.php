<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
local_unics_require_not_student();

$ctx = context_system::instance();
if (!has_capability('local/unics:manage', $ctx)
    && !has_capability('local/unics:viewstudents', $ctx)) {
    require_capability('local/unics:viewstudents', $ctx);
}

global $DB;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/demo_courses.php'));
$PAGE->set_title('Демонстрационные курсы - УНИКС');
$PAGE->set_heading('Создание демонстрационных курсов');
$PAGE->set_pagelayout('admin');

$created = [];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $category_id = optional_param('category_id', 0, PARAM_INT);

    try {
        $seeder  = new \local_unics\demo_seeder();
        $created = $seeder->seed_math_demo($category_id);
    } catch (\Throwable $e) {
        $error = 'Ошибка при создании демо-курсов: ' . $e->getMessage();
    }
}

// Categories for selector
$cats_raw    = $DB->get_records_sql(
    "SELECT id, name, depth FROM {course_categories} WHERE visible = 1 ORDER BY depth, name"
);
$cat_menu    = [0 => '- корневая категория -'];
foreach ($cats_raw as $c) {
    $cat_menu[$c->id] = str_repeat('  ', max(0, $c->depth - 1)) . $c->name;
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Создание демонстрационных курсов');

echo html_writer::link(
    new moodle_url('/local/unics/pages/course_templates.php'),
    'Шаблоны курсов',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Result
if ($created) {
    echo $OUTPUT->notification(
        'Создано ' . count($created) . ' демонстрационных курса:',
        'success'
    );
    echo '<ul>';
    $level_labels = \local_unics\course_template::get_level_labels();
    foreach ($created as $lvl => $course) {
        $url = new moodle_url('/course/view.php', ['id' => $course->id]);
        echo '<li>' . htmlspecialchars($level_labels[$lvl]) . ': '
             . html_writer::link($url, htmlspecialchars($course->fullname), ['target' => '_blank'])
             . '</li>';
    }
    echo '</ul>';
}
if ($error) {
    echo $OUTPUT->notification($error, 'error');
}

// Form
$form_url = new moodle_url('/local/unics/pages/demo_courses.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>Параметры</strong></div>';
echo '<div class="card-body">';

echo '<p>Будут созданы <strong>3 демонстрационных курса</strong> по предмету '
   . '<strong>Математика, 5 класс</strong> - по одному на каждый уровень сложности.</p>';

echo '<div class="row mb-3">';
$level_info = [
    ['color' => 'info',    'title' => 'Базовый (уровень 1)',      'desc' => '8 тем. Теория + тест. Для учащихся ОВЗ и детей на лечении.'],
    ['color' => 'primary', 'title' => 'Стандартный (уровень 2)', 'desc' => '10 тем. Теория + аудио + тест. Стандартная программа.'],
    ['color' => 'success', 'title' => 'Продвинутый (уровень 3)', 'desc' => '12 тем. Теория + аудио + тест + задание. Для одарённых.'],
];
foreach ($level_info as $li) {
    echo '<div class="col-md-4">';
    echo '<div class="alert alert-' . $li['color'] . ' py-2">';
    echo '<strong>' . $li['title'] . '</strong><br><small>' . $li['desc'] . '</small>';
    echo '</div></div>';
}
echo '</div>';

echo '<div class="form-group mb-3">';
echo html_writer::tag('label', 'Категория Moodle для курсов', ['class' => 'font-weight-bold']);
echo html_writer::select($cat_menu, 'category_id', 0, false, ['class' => 'form-control', 'style' => 'max-width:400px']);
echo '</div>';

echo '<p class="text-muted small">Первые 3–4 темы каждого курса будут заполнены учебным контентом по математике. '
   . 'Остальные темы содержат инструкции-шаблоны. Тесты создаются пустыми - вопросы добавляет педагог или ИИ-модуль.</p>';

echo html_writer::tag('button', 'Создать демонстрационные курсы',
    ['type' => 'submit', 'class' => 'btn btn-primary',
     'onclick' => "return confirm('Создать 3 демонстрационных курса по математике?');"]
);

echo '</div></div>';
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
