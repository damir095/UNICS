<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/users.php'));
$PAGE->set_title(get_string('users', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

// Фильтры
$filter_role = optional_param('role', 0, PARAM_INT);
$filter_org  = optional_param('org', 0, PARAM_INT);

$users = unics_user_manager::get_users($filter_org, $filter_role);
$orgs  = unics_user_manager::get_organizations_menu();

$role_labels = [
    1 => 'Региональный администратор',
    2 => 'Муниципальный администратор',
    3 => 'Администратор организации',
    4 => 'Методист',
    5 => 'Педагог',
    6 => 'Тьютор',
    7 => 'Учащийся',
    8 => 'Родитель',
];

echo $OUTPUT->header();

// Навигационные кнопки
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/unics/pages/create_user.php'),
        get_string('create_user', 'local_unics'),
        ['class' => 'btn btn-primary mr-2']
    ) .
    html_writer::link(
        new moodle_url('/local/unics/pages/assign.php'),
        get_string('assignments', 'local_unics'),
        ['class' => 'btn btn-secondary mr-2']
    ) .
    html_writer::link(
        new moodle_url('/local/unics/pages/organizations.php'),
        get_string('organizations', 'local_unics'),
        ['class' => 'btn btn-outline-secondary']
    ),
    'mb-3'
);

// Форма фильтров
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);

// Фильтр по организации
echo html_writer::select(
    [0 => get_string('all_orgs', 'local_unics')] + $orgs,
    'org', $filter_org, false, ['class' => 'form-control mr-2']
);

// Фильтр по роли
echo html_writer::select(
    [0 => get_string('all_roles', 'local_unics')] + $role_labels,
    'role', $filter_role, false, ['class' => 'form-control mr-2']
);

echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

// Таблица пользователей
if (empty($users)) {
    echo $OUTPUT->notification(get_string('no_users', 'local_unics'), 'info');
} else {
    $table = new html_table();
    $table->head = ['ФИО', 'Email', 'Логин', 'Роль', 'Организация', 'Класс', get_string('actions', 'local_unics')];
    $table->attributes['class'] = 'table table-striped';

    foreach ($users as $user) {
        $fio = trim("{$user->lastname} {$user->firstname} {$user->middlename}");
        $role_label = $role_labels[$user->unics_role] ?? '—';

        // Класс: только для учащихся (роль 7)
        $class_cell = '—';
        if ((int)$user->unics_role === 7 && !empty($user->class_number)) {
            $class_cell = $user->class_number . ($user->class_letter ?? '');
        }

        $edit_url = new moodle_url('/local/unics/pages/edit_user.php', ['id' => $user->id]);

        $table->data[] = [
            $fio,
            $user->email,
            $user->username,
            $role_label,
            $user->org_name,
            $class_cell,
            html_writer::link($edit_url, get_string('edit', 'local_unics'), ['class' => 'btn btn-sm btn-outline-primary']),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
