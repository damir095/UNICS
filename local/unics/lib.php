<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Добавляет пункты УНИКС в боковую навигацию для администраторов.
 */
/**
 * Редиректит учащегося на его дашборд, если он пытается открыть педагогическую страницу.
 * Вызывать в начале каждой страницы, доступной педагогам/администраторам.
 */
function local_unics_require_not_student(): void {
    global $DB, $USER;
    if ($DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
        redirect(new moodle_url('/local/unics/pages/dashboard.php'));
    }
}

/**
 * Убирает пункты редактирования профиля из настроек навигации для учащихся.
 * Также редиректит учащегося со страниц редактирования профиля Moodle.
 */
function local_unics_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $DB, $USER, $PAGE;

    if (!$DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
        return;
    }

    // Редиректим со страниц редактирования профиля (совместимо с PHP 7.x)
    $path = $PAGE->url->get_path();
    if (strpos($path, '/user/edit.php') !== false || strpos($path, '/user/editadvanced.php') !== false) {
        redirect(new moodle_url('/local/unics/pages/dashboard.php'));
    }

    // Скрываем настройки профиля в навигации (null = искать по ключу без ограничения по типу)
    $usersettings = $settingsnav->find('usersettings', null);
    if ($usersettings) {
        foreach (['editprofile', 'useraccount', 'usermessaging', 'userpreferences',
                  'security', 'contactable', 'blog', 'mnet_loginas', 'myprofile'] as $key) {
            $node = $usersettings->find($key, null);
            if ($node) {
                $node->remove();
            }
        }
    }
}

function local_unics_extend_navigation(global_navigation $nav) {
    global $DB, $USER, $PAGE;

    // Учащийся — проверяем по БД в первую очередь, до любых проверок возможностей.
    // Это гарантирует, что неправильно назначенная Moodle-роль не откроет педагогическое меню.
    $student_rec = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
    if ($student_rec) {
        $branch = $nav->add(
            'УНИКС — Мой портал',
            new moodle_url('/local/unics/pages/dashboard.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_student_root',
            new pix_icon('i/cohort', '')
        );
        $branch->add(
            'Мои результаты',
            new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_rec->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_my_report'
        );
        $branch->add(
            'Мои достижения',
            new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student_rec->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_achievements'
        );

        // Ссылки «Заметки педагога» для активного курса
        if ($PAGE->context instanceof context_course) {
            $courseid  = $PAGE->context->instanceid;
            $has_notes = $DB->record_exists_sql(
                "SELECT 1
                   FROM {unics_comments} c
                   JOIN {course_modules} cm ON cm.id = c.cmid
                  WHERE c.student_id = :sid AND cm.course = :cid",
                ['sid' => $student_rec->id, 'cid' => $courseid]
            );
            if ($has_notes) {
                $branch->add(
                    'Заметки педагога (этот курс)',
                    new moodle_url('/local/unics/pages/course_notes.php', [
                        'student_id' => $student_rec->id,
                        'courseid'   => $courseid,
                    ]),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'local_unics_course_notes'
                );
            }
        }
        return;
    }

    // Родитель
    if ($DB->record_exists('unics_parent_student', ['parent_mdl_user_id' => $USER->id])) {
        $nav->add(
            'УНИКС — Мои дети',
            new moodle_url('/local/unics/pages/dashboard.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_parent_root',
            new pix_icon('i/cohort', '')
        );
        return;
    }

    // Педагог / администратор
    $ctx        = context_system::instance();
    $is_admin   = has_capability('local/unics:manage', $ctx);
    $is_teacher = has_capability('local/unics:viewstudents', $ctx);

    if (!$is_admin && !$is_teacher) {
        return;
    }

    // Педагог должен быть реальным педагогом УНИКС, а не просто иметь роль Moodle.
    if ($is_teacher && !$is_admin && !$DB->record_exists('unics_teachers', ['mdl_user_id' => $USER->id])) {
        return;
    }

    $root_url = new moodle_url('/local/unics/pages/dashboard.php');

    $branch = $nav->add(
        'УНИКС — Портал',
        $root_url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_root',
        new pix_icon('i/cohort', '')
    );

    // Дашборд — для педагогов и администраторов
    $branch->add(
        'Портал (дашборд)',
        new moodle_url('/local/unics/pages/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_dashboard'
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

        // Подраздел «Отчёты»
        $reports = $branch->add(
            'Отчёты',
            new moodle_url('/local/unics/pages/org_report.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_reports'
        );
        $reports->add(
            'Отчёт по организации',
            new moodle_url('/local/unics/pages/org_report.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_org_report'
        );

    }
}
