<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();

global $USER, $DB;

local_unics_require_not_student();

$is_admin = has_capability('local/unics:manage', context_system::instance());
$is_teacher = has_capability('local/unics:viewstudents', context_system::instance());

if (!$is_admin && !$is_teacher) {
    require_capability('local/unics:viewstudents', context_system::instance());
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/my_students.php'));
$PAGE->set_title('Мои учащиеся — УНИКС');
$PAGE->set_heading('Мои учащиеся');
$PAGE->set_pagelayout('standard');

// Определяем режим доступа.
// Методист имеет запись в unics_teachers (там хранится привязка к организации),
// поэтому проверку методиста делаем ДО ветки педагога.
$teacher_record = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
$is_methodist   = $is_teacher && !$is_admin && local_unics_is_methodist();

if ($is_admin && !$teacher_record) {
    // Администратор без профиля педагога — все учащиеся системы.
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename, u.email,
                s.class_number, s.category, s.difficulty_level,
                o.name AS org_name,
                NULL AS teacher_lastname, NULL AS teacher_firstname
         FROM {unics_students} s
         JOIN {user} u ON u.id = s.mdl_user_id
         JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE u.deleted = 0
         ORDER BY u.lastname, u.firstname"
    );
    $mode = 'admin';
} elseif ($is_methodist) {
    // Методист — все учащиеся его организации (организация берётся из unics_teachers).
    if ($teacher_record && $teacher_record->organization_id) {
        $students = $DB->get_records_sql(
            "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename, u.email,
                    s.class_number, s.category, s.difficulty_level,
                    o.name AS org_name,
                    NULL AS teacher_lastname, NULL AS teacher_firstname
             FROM {unics_students} s
             JOIN {user} u ON u.id = s.mdl_user_id
             JOIN {unics_organizations} o ON o.id = s.organization_id
             WHERE s.organization_id = :org_id AND u.deleted = 0
             ORDER BY u.lastname, u.firstname",
            ['org_id' => (int)$teacher_record->organization_id]
        );
    } else {
        $students = [];
    }
    $mode = 'methodist';
} elseif ($teacher_record) {
    // Педагог — только привязанные учащиеся.
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename, u.email,
                s.class_number, s.category, s.difficulty_level,
                o.name AS org_name,
                ts.id AS ts_id
         FROM {unics_teacher_student} ts
         JOIN {unics_students} s ON s.id = ts.student_id
         JOIN {user} u ON u.id = s.mdl_user_id
         JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE ts.teacher_id = :teacher_id AND u.deleted = 0
         ORDER BY u.lastname, u.firstname",
        ['teacher_id' => $teacher_record->id]
    );
    $mode = 'teacher';
} else {
    $students = [];
    $mode = 'noprofile';
}

$categories = [1 => 'ОВЗ', 2 => 'Семейное обучение', 3 => 'Длительное лечение', 4 => 'Одарённый'];
$levels      = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

echo $OUTPUT->header();
echo $OUTPUT->heading('Мои учащиеся');

if ($mode === 'noprofile') {
    echo $OUTPUT->notification(
        'Ваш профиль педагога не найден в системе УНИКС. Обратитесь к администратору.',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

if ($mode === 'admin') {
    echo $OUTPUT->notification('Вы вошли как администратор. Отображаются все учащиеся системы.', 'info');
} elseif ($mode === 'methodist') {
    if (!empty($teacher_record) && !empty($teacher_record->organization_id)) {
        $org_name = $DB->get_field('unics_organizations', 'name',
            ['id' => (int)$teacher_record->organization_id]);
        echo $OUTPUT->notification(
            'Вы вошли как методист. Отображаются все учащиеся вашей организации'
            . ($org_name ? ' «' . s($org_name) . '»' : '') . '.',
            'info'
        );
    } else {
        echo $OUTPUT->notification(
            'Ваш профиль методиста не привязан к организации. Обратитесь к администратору.',
            'warning'
        );
    }
}

if ($is_admin) {
    echo html_writer::tag('div',
        html_writer::link(
            new moodle_url('/local/unics/pages/assign.php'),
            'Назначения педагог-учащийся',
            ['class' => 'btn btn-outline-secondary btn-sm me-2']
        ) .
        html_writer::link(
            new moodle_url('/local/unics/pages/generate_umk.php'),
            'Генерация УМК (ИИ)',
            ['class' => 'btn btn-outline-primary btn-sm']
        ),
        ['class' => 'mb-3']
    );
}

if (empty($students)) {
    if ($mode === 'teacher') {
        echo $OUTPUT->notification(
            'У вас нет назначенных учащихся. Обратитесь к администратору для назначения.',
            'warning'
        );
    }
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = ['Учащийся', 'Класс', 'Категория', 'Уровень', 'Организация', 'Действия', 'Отчёт'];
$table->attributes['class'] = 'table table-sm table-bordered table-hover';

foreach ($students as $s) {
    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $cat = $categories[$s->category] ?? '—';
    $lvl = $levels[$s->difficulty_level] ?? '—';

    $actions = html_writer::link(
        new moodle_url('/local/unics/pages/generate_umk.php', ['student_id' => $s->student_id]),
        'Сгенерировать УМК',
        ['class' => 'btn btn-sm btn-outline-success']
    );

    $report_link = html_writer::link(
        new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $s->student_id]),
        'Отчёт',
        ['class' => 'btn btn-sm btn-outline-primary']
    );

    $table->data[] = [
        html_writer::tag('strong', htmlspecialchars($fio)),
        $s->class_number ? "{$s->class_number} кл." : '—',
        $cat,
        $lvl,
        htmlspecialchars($s->org_name),
        $actions,
        $report_link,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
