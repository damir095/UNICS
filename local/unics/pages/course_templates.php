<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
local_unics_require_not_student();

$ctx = context_system::instance();
if (!has_capability('local/unics:manage', $ctx)
    && !has_capability('local/unics:viewstudents', $ctx)) {
    require_capability('local/unics:viewstudents', $ctx); // throws с понятным сообщением
}

$is_admin_user = has_capability('local/unics:manage', $ctx);
$is_methodist  = !$is_admin_user && local_unics_is_methodist();

global $DB;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/course_templates.php'));
$PAGE->set_title('Шаблоны курсов - УНИКС');
$PAGE->set_heading('Создание курса по шаблону');
$PAGE->set_pagelayout('admin');

$subjects     = \local_unics\course_template::get_subjects();
$level_labels = \local_unics\course_template::get_level_labels();

// ----------------------------------------------------------------
// POST: создать курс из шаблона
// ----------------------------------------------------------------
$created_course = null;
$error_msg      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $subject_key  = required_param('subject',      PARAM_ALPHANUMEXT);
    $class_num    = required_param('class_num',    PARAM_INT);
    $category_id  = optional_param('category_id', 0, PARAM_INT);
    $num_topics   = optional_param('num_topics',   0, PARAM_INT);
    $topic_names_raw = optional_param('topic_names', '', PARAM_RAW);

    // Парсим список тем (по строкам, пустые отбрасываем, обрезаем пробелы).
    $topic_names = null;
    if (trim($topic_names_raw) !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $topic_names_raw);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
        if (count($lines) > 0) {
            $topic_names = array_slice($lines, 0, 20); // hard cap
        }
    }

    if (!isset($subjects[$subject_key])) {
        $error_msg = 'Неизвестный предмет.';
    } elseif ($class_num < 1 || $class_num > 11) {
        $error_msg = 'Класс должен быть от 1 до 11.';
    } else {
        try {
            $created_course = \local_unics\course_template::create_from_template(
                $subject_key, $class_num, $category_id,
                $num_topics > 0 ? $num_topics : null,
                $topic_names
            );

            // Автоматически записать создателя курса как editing teacher,
            // чтобы методист сразу мог редактировать только что созданный курс.
            // Админам это не нужно (у них site-wide capability), но запись
            // не вредит и упрощает логику.
            require_once($CFG->dirroot . '/enrol/manual/lib.php');
            $manual_plugin   = enrol_get_plugin('manual');
            $enrol_instance  = $DB->get_record('enrol',
                ['courseid' => $created_course->id, 'enrol' => 'manual']);
            if (!$enrol_instance && $manual_plugin) {
                $manual_plugin->add_default_instance(
                    $DB->get_record('course', ['id' => $created_course->id])
                );
                $enrol_instance = $DB->get_record('enrol',
                    ['courseid' => $created_course->id, 'enrol' => 'manual']);
            }
            $editingteacher_roleid = (int)$DB->get_field('role', 'id',
                ['shortname' => 'editingteacher']);
            if ($enrol_instance && $manual_plugin && $editingteacher_roleid) {
                $manual_plugin->enrol_user($enrol_instance, (int)$USER->id,
                    $editingteacher_roleid);
            }
        } catch (\Throwable $e) {
            $error_msg = 'Ошибка создания курса: ' . $e->getMessage();
        }
    }
}

// ----------------------------------------------------------------
// Данные для формы
// ----------------------------------------------------------------
$subject_menu = ['' => '- выберите предмет -'];
foreach ($subjects as $key => $s) {
    $subject_menu[$key] = $s['name'];
}

$class_menu = [0 => '- выберите класс -'];
for ($i = 1; $i <= 11; $i++) {
    $class_menu[$i] = "{$i} класс";
}

// Категории Moodle (для выбора, куда поместить курс)
$categories_raw = $DB->get_records_sql(
    "SELECT id, name, depth, path FROM {course_categories} WHERE visible = 1 ORDER BY path"
);
$category_menu = [0 => '- корневая категория -'];
foreach ($categories_raw as $cat) {
    $indent = str_repeat('&nbsp;&nbsp;', max(0, $cat->depth - 1));
    $category_menu[$cat->id] = $indent . $cat->name;
}

// История созданных из шаблонов курсов (с тегом УНИКС)
$existing_courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname, c.timecreated, cc.name AS catname
     FROM {course} c
     LEFT JOIN {course_categories} cc ON cc.id = c.category
     WHERE c.id <> 1
     ORDER BY c.timecreated DESC
     LIMIT 20"
);

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Создание курса по шаблону');

echo html_writer::link(
    $is_methodist
        ? new moodle_url('/local/unics/pages/dashboard.php')
        : new moodle_url('/local/unics/pages/users.php'),
    $is_methodist ? 'На дашборд' : 'Назад к пользователям',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Результат создания
if ($created_course) {
    $course_url   = new moodle_url('/course/view.php', ['id' => $created_course->id]);
    $generate_url = new moodle_url('/local/unics/pages/generate_umk.php',
        ['course_id' => $created_course->id]);
    echo $OUTPUT->notification(
        'Курс создан: <strong>' . htmlspecialchars($created_course->fullname) . '</strong> - '
        . html_writer::link($course_url, 'Открыть курс', ['class' => 'alert-link', 'target' => '_blank']),
        'success'
    );
    echo '<div class="card border-primary mb-4">';
    echo '<div class="card-body">';
    echo '<h5 class="card-title">Следующий шаг - наполнить курс материалом</h5>';
    echo '<p class="card-text mb-2">Структура готова (' .
        (int)$DB->count_records('course_sections', ['course' => $created_course->id]) .
        ' секций). Запустите ИИ-генерацию УМК, и материалы по темам появятся в курсе автоматически.</p>';
    echo html_writer::link($generate_url, 'Запустить ИИ-генерацию УМК для этого курса',
        ['class' => 'btn btn-primary']);
    echo '</div></div>';
}
if ($error_msg) {
    echo $OUTPUT->notification($error_msg, 'error');
}

// ---- Форма создания ----
$form_url = new moodle_url('/local/unics/pages/course_templates.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url]);
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::tag('div', '<strong>Параметры шаблона</strong>', ['class' => 'card-header']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::start_tag('div', ['class' => 'form-row']);

// Предмет
echo html_writer::start_tag('div', ['class' => 'col-md-3 mb-3']);
echo html_writer::tag('label', 'Предмет', ['class' => 'font-weight-bold d-block']);
echo html_writer::select($subject_menu, 'subject', '', false, ['class' => 'form-control', 'required' => 'required']);
echo html_writer::end_tag('div');

// Класс
echo html_writer::start_tag('div', ['class' => 'col-md-2 mb-3']);
echo html_writer::tag('label', 'Класс', ['class' => 'font-weight-bold d-block']);
echo html_writer::select($class_menu, 'class_num', 0, false, ['class' => 'form-control', 'required' => 'required']);
echo html_writer::end_tag('div');

// Кол-во тем (override)
echo html_writer::start_tag('div', ['class' => 'col-md-2 mb-3']);
echo html_writer::tag('label', 'Кол-во тем', ['class' => 'd-block']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'num_topics', 'value' => '', 'min' => '1', 'max' => '20',
    'placeholder' => 'авто', 'class' => 'form-control',
]);
echo html_writer::tag('small', 'Пусто = по умолчанию для предмета.',
    ['class' => 'form-text text-muted']);
echo html_writer::end_tag('div');

// Категория Moodle
echo html_writer::start_tag('div', ['class' => 'col-md-3 mb-3']);
echo html_writer::tag('label', 'Категория (Moodle)', ['class' => 'd-block']);
echo html_writer::select($category_menu, 'category_id', 0, false,
    ['class' => 'form-control', 'style' => 'min-width:200px']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div'); // form-row

// Список тем (опционально)
echo html_writer::start_tag('div', ['class' => 'form-group mb-3']);
echo html_writer::tag('label',
    'Названия тем '
    . html_writer::tag('span', '(необязательно - по одной в строке)',
        ['class' => 'text-muted font-weight-normal'])
);
echo html_writer::tag('textarea', '', [
    'name'        => 'topic_names',
    'class'       => 'form-control',
    'rows'        => '5',
    'placeholder' => "Дроби\nПроценты\nУравнения\nГеометрические фигуры",
]);
echo html_writer::tag('small',
    'Если задано - кол-во тем равно количеству строк (макс. 20). Эти названия станут именами секций и пойдут в ИИ как тема урока.',
    ['class' => 'form-text text-muted']
);
echo html_writer::end_tag('div');

echo '<div class="alert alert-info mb-3">'
   . 'В курсе создаётся <strong>один набор секций</strong> для всех уровней. '
   . 'Активности в каждой теме разделены по уровням сложности через условный доступ '
   . '(<code>profile_field_unics_level</code>): '
   . '<span class="badge badge-info">1 - Базовый</span> '
   . '<span class="badge badge-primary">2 - Стандартный</span> '
   . '<span class="badge badge-success">3 - Продвинутый</span>. '
   . 'Учащийся видит только материалы своего уровня.'
   . '</div>';

echo html_writer::tag('button', 'Создать курс по шаблону',
    ['type' => 'submit', 'class' => 'btn btn-primary']);

echo html_writer::end_tag('div'); // card-body
echo html_writer::end_tag('div'); // card
echo html_writer::end_tag('form');

// ---- Предварительный просмотр структуры ----
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>Структура создаваемого курса</strong></div>';
echo '<div class="card-body">';
echo '<table class="table table-sm table-bordered">';
echo '<thead class="thead-light"><tr><th>Секция</th><th>Название</th><th>Уровень 1</th><th>Уровень 2</th><th>Уровень 3</th></tr></thead>';
echo '<tbody>';
echo '<tr><td>0</td><td>Введение в курс</td><td colspan="3">Ознакомительный блок (одинаков для всех уровней)</td></tr>';
echo '<tr><td>1–N</td><td>Тема N</td>'
   . '<td colspan="3">'
   . '[Базовый] Инструкция + Тест &nbsp;|&nbsp; '
   . '[Стандартный] Инструкция + Тест &nbsp;|&nbsp; '
   . '[Продвинутый] Инструкция + Тест + Задание'
   . '<br><small class="text-muted">Каждая активность видна только учащимся с соответствующим profile_field_unics_level</small>'
   . '</td>'
   . '</tr>';
echo '<tr><td>Последняя</td><td>Итоговый контроль</td><td colspan="3">Финальный тест по всему курсу</td></tr>';
echo '</tbody></table>';

echo '<p class="text-muted mb-0"><small>';
echo 'Количество тем: Базовый = 80% от стандарта предмета; Стандартный = 100%; Продвинутый = 120%. ';
echo 'После создания курса наполните секции вручную или через модуль ИИ-генерации УМК.';
echo '</small></p>';
echo '</div></div>';

// ---- Последние 20 курсов ----
if ($existing_courses) {
    echo '<div class="card">';
    echo '<div class="card-header"><strong>Недавно созданные курсы</strong></div>';
    echo '<div class="card-body p-0">';
    echo '<table class="table table-sm table-bordered mb-0">';
    echo '<thead class="thead-light"><tr><th>Курс</th><th>Краткое имя</th><th>Категория</th><th>Создан</th><th></th></tr></thead>';
    echo '<tbody>';
    foreach ($existing_courses as $c) {
        $course_url = new moodle_url('/course/view.php', ['id' => $c->id]);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($c->fullname) . '</td>';
        echo '<td><code>' . htmlspecialchars($c->shortname) . '</code></td>';
        echo '<td>' . htmlspecialchars($c->catname ?? '-') . '</td>';
        echo '<td>' . userdate($c->timecreated, '%d.%m.%Y') . '</td>';
        echo '<td>' . html_writer::link($course_url, 'Открыть', ['target' => '_blank', 'class' => 'btn btn-sm btn-outline-primary']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div></div>';
}

echo $OUTPUT->footer();
