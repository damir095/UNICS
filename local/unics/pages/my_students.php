<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/identity/user_manager.php');

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
$PAGE->set_title('Мои учащиеся - УНИКС');
$PAGE->set_heading('Мои учащиеся');
$PAGE->set_pagelayout('standard');

// Фильтры (применяются в режимах admin/scoped).
$filter_org      = optional_param('f_org',    0, PARAM_INT);
$filter_class    = optional_param('f_class',  0, PARAM_INT);
$filter_level    = optional_param('f_level',  0, PARAM_INT);
$filter_district = optional_param('f_dist',   0, PARAM_INT);
$filter_letter   = optional_param('f_letter', '', PARAM_TEXT); // буква класса (кириллица)

$page    = optional_param('page', 0, PARAM_INT);
$perpage = 25;

// Определяем режим доступа.
// manageorg-роли (методист орг./района, региональн./районный админ) видят учащихся
// своего скоупа (регион/район/орг через unics_user_org). Проверку manageorg делаем
// ДО ветки педагога — у методиста тоже есть запись в unics_teachers.
$teacher_record = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
$is_manageorg   = has_capability('local/unics:manageorg', context_system::instance());

if ($is_admin && !$teacher_record) {
    $scope_where = '1=1';
    $scope_params = [];
    $mode = 'admin';
} elseif (!$is_admin && $is_manageorg) {
    // Скоуп берётся из unics_user_org: одна орг, весь район или весь регион.
    [$scope_where, $scope_params] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $mode = 'scoped';
} elseif ($teacher_record) {
    $mode = 'teacher';
} else {
    $mode = 'noprofile';
}

$students = [];
$total    = 0;
if ($mode === 'admin' || $mode === 'scoped') {
    $where  = $scope_where;
    $params = $scope_params;
    if ($filter_org > 0)      { $where .= ' AND s.organization_id = :forg';   $params['forg']   = $filter_org; }
    if ($filter_class > 0)    { $where .= ' AND s.class_number = :fclass';     $params['fclass'] = $filter_class; }
    if ($filter_level > 0)    { $where .= ' AND s.difficulty_level = :flvl';   $params['flvl']   = $filter_level; }
    if ($filter_district > 0) { $where .= ' AND o.district_id = :fdist';       $params['fdist']  = $filter_district; }
    if ($filter_letter !== '') { $where .= ' AND s.class_letter = :fletter';   $params['fletter'] = $filter_letter; }

    $total = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id)
           FROM {unics_students} s
           JOIN {user} u ON u.id = s.mdl_user_id
           JOIN {unics_organizations} o ON o.id = s.organization_id
          WHERE ({$where}) AND u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL",
        $params
    );
    // Категории/ОВЗ - из нормализованных таблиц с прежними алиасами (этап 2.6-B).
    [$catsql, $ovzsql] = \local_unics\identity\student_helper::taxonomy_select_sql('s');
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename, u.email,
                s.class_number, s.class_letter, {$catsql}, {$ovzsql}, s.difficulty_level,
                o.name AS org_name,
                NULL AS teacher_lastname, NULL AS teacher_firstname
         FROM {unics_students} s
         JOIN {user} u ON u.id = s.mdl_user_id
         JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE ({$where}) AND u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL
         ORDER BY u.lastname, u.firstname",
        $params, $page * $perpage, $perpage
    );
} elseif ($mode === 'teacher') {
    // Педагог - только привязанные учащиеся.
    $total = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id)
           FROM {unics_teacher_student} ts
           JOIN {unics_students} s ON s.id = ts.student_id
           JOIN {user} u ON u.id = s.mdl_user_id
           JOIN {unics_organizations} o ON o.id = s.organization_id
          WHERE ts.teacher_id = :teacher_id AND u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL",
        ['teacher_id' => $teacher_record->id]
    );
    // Категории/ОВЗ - из нормализованных таблиц с прежними алиасами (этап 2.6-B).
    [$catsql, $ovzsql] = \local_unics\identity\student_helper::taxonomy_select_sql('s');
    $students = $DB->get_records_sql(
        "SELECT s.id AS student_id, u.lastname, u.firstname, u.middlename, u.email,
                s.class_number, s.class_letter, {$catsql}, {$ovzsql}, s.difficulty_level,
                o.name AS org_name,
                ts.id AS ts_id
         FROM {unics_teacher_student} ts
         JOIN {unics_students} s ON s.id = ts.student_id
         JOIN {user} u ON u.id = s.mdl_user_id
         JOIN {unics_organizations} o ON o.id = s.organization_id
         WHERE ts.teacher_id = :teacher_id AND u.deleted = 0 AND s.graduated_at IS NULL AND s.archived_at IS NULL
         ORDER BY u.lastname, u.firstname",
        ['teacher_id' => $teacher_record->id], $page * $perpage, $perpage
    );
}

$categories = [1 => 'ОВЗ', 2 => 'Семейное обучение', 3 => 'Длительное лечение', 4 => 'Одарённый'];
$levels      = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

echo $OUTPUT->header();
echo local_unics_dashboard_button();
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
} elseif ($mode === 'scoped') {
    $scope = \local_unics\identity\scope_checker::get_user_scope((int)$USER->id);
    $scope_name = '';
    if ($scope['organization_id']) {
        $scope_name = 'организация «' . (string)$DB->get_field('unics_organizations', 'name', ['id' => $scope['organization_id']]) . '»';
    } else if ($scope['district_id']) {
        $scope_name = 'муниципалитет ' . (string)$DB->get_field('unics_districts', 'name', ['id' => $scope['district_id']]);
    } else if ($scope['region_id']) {
        $scope_name = 'регион ' . (string)$DB->get_field('unics_regions', 'name', ['id' => $scope['region_id']]);
    }
    echo $OUTPUT->notification(
        $scope_name ? ('Показаны учащиеся: ' . s($scope_name) . '.') : 'Показаны ваши учащиеся.',
        'info'
    );
}

// --- Фильтры (организация / класс / уровень) для admin и scoped ---
if ($mode === 'admin' || $mode === 'scoped') {
    if ($is_admin) {
        $orgs_rows = $DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name');
    } else {
        [$ofw, $ofp] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
        $orgs_rows = $DB->get_records_sql(
            "SELECT o.id, o.name FROM {unics_organizations} o
              WHERE o.is_active = 1 AND ({$ofw}) ORDER BY o.name", $ofp);
    }
    $orgs_menu = [0 => 'Все организации'];
    foreach ($orgs_rows as $o) { $orgs_menu[$o->id] = s($o->name); }

    $class_menu = [0 => 'Все классы'];
    for ($i = 1; $i <= 11; $i++) { $class_menu[$i] = $i . ' класс'; }

    $level_menu = [0 => 'Все уровни'] + $levels;

    $letters_menu = ['' => 'Все буквы', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                     'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];

    // Меню районов — по скоупу. Показываем только если доступно больше одного
    // (у районного/орг-методиста район один и фиксирован скоуп-фильтром).
    $districts_menu = \local_unics\identity\scope_checker::accessible_districts_menu((int)$USER->id, (bool)$is_admin);

    echo html_writer::start_tag('form',
        ['method' => 'get', 'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3']);
    if (count($districts_menu) > 1) {
        echo html_writer::select([0 => 'Все муниципалитеты'] + $districts_menu,
            'f_dist', $filter_district, false, ['class' => 'form-control', 'aria-label' => 'Муниципалитет']);
    }
    echo html_writer::select($orgs_menu,    'f_org',    $filter_org,    false, ['class' => 'form-control', 'aria-label' => 'Организация']);
    echo html_writer::select($class_menu,   'f_class',  $filter_class,  false, ['class' => 'form-control', 'aria-label' => 'Класс']);
    echo html_writer::select($letters_menu, 'f_letter', $filter_letter, false, ['class' => 'form-control', 'aria-label' => 'Буква класса']);
    echo html_writer::select($level_menu,   'f_level',  $filter_level,  false, ['class' => 'form-control', 'aria-label' => 'Уровень']);
    echo html_writer::tag('button', 'Фильтр', ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');
}

if ($is_admin || $mode === 'scoped') {
    echo html_writer::tag('div',
        html_writer::link(
            new moodle_url('/local/unics/pages/assign.php'),
            'Назначения педагог-учащийся',
            ['class' => 'btn btn-outline-secondary btn-sm me-2']
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
    } else {
        echo $OUTPUT->notification('Учащихся по выбранным условиям не найдено.', 'info');
    }
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = ['Учащийся', 'Класс', 'Категория', 'Уровень', 'Организация', 'Действия', 'Отчёт'];
$table->attributes['class'] = local_unics_table_class(true);

foreach ($students as $s) {
    $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $cat = \local_unics\identity\student_helper::format_categories($s) ?: '-';
    $lvl = $levels[$s->difficulty_level] ?? '-';

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
    $report_link .= ' ' . html_writer::link(
        new moodle_url('/local/unics/pages/codifier_report.php', ['student_id' => $s->student_id]),
        'Элементы',
        ['class' => 'btn btn-sm btn-outline-secondary']
    );

    $table->data[] = [
        html_writer::tag('strong', htmlspecialchars($fio)),
        $s->class_number ? ($s->class_number . ($s->class_letter ?? '') . ' кл.') : '-',
        $cat,
        $lvl,
        htmlspecialchars($s->org_name),
        $actions,
        $report_link,
    ];
}

echo html_writer::table($table);

$baseurl = new moodle_url('/local/unics/pages/my_students.php', [
    'f_org'    => $filter_org,
    'f_class'  => $filter_class,
    'f_level'  => $filter_level,
    'f_dist'   => $filter_district,
    'f_letter' => $filter_letter,
]);
echo local_unics_render_paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
