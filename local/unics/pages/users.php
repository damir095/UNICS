<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();
// Полный доступ — у системного админа (manage); районный/региональн. админ и методист
// (manageorg) видят и управляют пользователями в рамках своего скоупа.
local_unics_require_manage_or_manageorg();

global $USER, $DB;
$ctx           = context_system::instance();
$is_full_admin = has_capability('local/unics:manage', $ctx);

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/users.php'));
$PAGE->set_title(get_string('users', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

// Фильтры
$filter_role = optional_param('role', 0, PARAM_INT);
$filter_org  = optional_param('org', 0, PARAM_INT);

if ($is_full_admin) {
    $users = unics_user_manager::get_users($filter_org, $filter_role);
    $orgs  = unics_user_manager::get_organizations_menu();
} else {
    // Скоуп-фильтр: только пользователи района/региона/организации смотрящего.
    [$scope_where, $scope_params] =
        \local_unics\scope_checker::user_list_filter_sql((int)$USER->id, 'uo', 'o');
    $users = unics_user_manager::get_users($filter_org, $filter_role, $scope_where, $scope_params);

    // Выпадающий список организаций — тоже по скоупу.
    [$ofw, $ofp] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $orgs_rows = $DB->get_records_sql(
        "SELECT o.id, o.name FROM {unics_organizations} o
          WHERE o.is_active = 1 AND ({$ofw}) ORDER BY o.name", $ofp);
    $orgs = [];
    foreach ($orgs_rows as $o) { $orgs[$o->id] = $o->name; }
}

$role_labels = [
    1 => 'Региональный администратор',
    2 => 'Районный администратор',
    9 => 'Районный методист',
    3 => 'Администратор организации',       // legacy
    4 => 'Методист организации',
    5 => 'Педагог, создающий курсы',
    6 => 'Педагог',                          // non-editing
    7 => 'Учащийся',
    8 => 'Родитель',
];

echo $OUTPUT->header();

// Навигационные кнопки
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/unics/pages/create_user.php'),
        get_string('create_user', 'local_unics'),
        ['class' => 'btn btn-primary']
    ) .
    html_writer::link(
        new moodle_url('/local/unics/pages/assign.php'),
        get_string('assignments', 'local_unics'),
        ['class' => 'btn btn-secondary']
    ) .
    html_writer::link(
        new moodle_url('/local/unics/pages/organizations.php'),
        get_string('organizations', 'local_unics'),
        ['class' => 'btn btn-outline-secondary']
    ),
    'unics-btn-row'
);

// Форма фильтров
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3']);

// Фильтр по организации
echo html_writer::select(
    [0 => get_string('all_orgs', 'local_unics')] + $orgs,
    'org', $filter_org, false, ['class' => 'form-control']
);

// Фильтр по роли
echo html_writer::select(
    [0 => get_string('all_roles', 'local_unics')] + $role_labels,
    'role', $filter_role, false, ['class' => 'form-control']
);

echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

// Таблица пользователей
if (empty($users)) {
    echo $OUTPUT->notification(get_string('no_users', 'local_unics'), 'info');
} else {
    $table = new html_table();
    $table->head = ['ФИО', 'Email', 'Логин', 'Роль', 'Организация / территория', 'Класс', get_string('actions', 'local_unics')];
    $table->attributes['class'] = 'table table-striped';

    foreach ($users as $user) {
        $fio = trim("{$user->lastname} {$user->firstname} {$user->middlename}");
        $role_label = $role_labels[$user->unics_role] ?? '-';

        // Класс: только для учащихся (роль 7)
        $class_cell = '-';
        if ((int)$user->unics_role === 7 && !empty($user->class_number)) {
            $class_cell = $user->class_number . ($user->class_letter ?? '');
        }

        $edit_url = new moodle_url('/local/unics/pages/edit_user.php', ['id' => $user->id]);
        $moodle_edit_url = new moodle_url('/user/editadvanced.php', ['id' => $user->id, 'course' => SITEID]);
        $actions_cell = html_writer::link($edit_url, get_string('edit', 'local_unics'),
                ['class' => 'btn btn-sm btn-outline-primary'])
            . ' '
            . html_writer::link($moodle_edit_url, 'Moodle',
                ['class' => 'btn btn-sm btn-outline-secondary',
                 'title' => 'Редактировать аккаунт в Moodle (пароль, аватар, email)']);

        $table->data[] = [
            $fio,
            $user->email,
            $user->username,
            $role_label,
            $user->org_name,
            $class_cell,
            $actions_cell,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
