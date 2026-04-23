<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Добавляет пункты УНИКС в боковую навигацию для администраторов.
 */
function local_unics_extend_navigation(global_navigation $nav) {
    $ctx     = context_system::instance();
    $is_admin   = has_capability('local/unics:manage', $ctx);
    $is_teacher = has_capability('local/unics:viewstudents', $ctx);

    if (!$is_admin && !$is_teacher) {
        return;
    }

    $root_url = $is_admin
        ? new moodle_url('/local/unics/pages/users.php')
        : new moodle_url('/local/unics/pages/my_students.php');

    $branch = $nav->add(
        get_string('pluginname', 'local_unics'),
        $root_url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_root',
        new pix_icon('i/cohort', '')
    );

    // Страница «Мои учащиеся» — для всех (педагог видит только своих)
    $branch->add(
        'Мои учащиеся',
        new moodle_url('/local/unics/pages/my_students.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_my_students'
    );

    // Генерация УМК — для педагогов и администраторов
    $branch->add(
        'Генерация УМК (ИИ)',
        new moodle_url('/local/unics/pages/generate_umk.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_umk'
    );

    if ($is_admin) {
        $branch->add(
            get_string('users', 'local_unics'),
            new moodle_url('/local/unics/pages/users.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_users'
        );

        $branch->add(
            'Импорт из CSV',
            new moodle_url('/local/unics/pages/import_users.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_import'
        );

        $branch->add(
            get_string('organizations', 'local_unics'),
            new moodle_url('/local/unics/pages/organizations.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_orgs'
        );

        $branch->add(
            get_string('assignments', 'local_unics'),
            new moodle_url('/local/unics/pages/assign.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_assign'
        );

        $branch->add(
            'Запись учащихся на курс',
            new moodle_url('/local/unics/pages/enrol_students.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_enrol'
        );

        $branch->add(
            'Запись педагогов на курс',
            new moodle_url('/local/unics/pages/enrol_teachers.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_enrol_teachers'
        );

        $branch->add(
            'История генерации УМК',
            new moodle_url('/local/unics/pages/umk_status.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_umk_status'
        );

        $branch->add(
            'Настройка прав ролей',
            new moodle_url('/local/unics/pages/setup_roles.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_setup_roles'
        );
    }
}
