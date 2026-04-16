<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_unics_cat',
        get_string('pluginname', 'local_unics')
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_users',
        get_string('users', 'local_unics'),
        new moodle_url('/local/unics/pages/users.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_orgs',
        get_string('organizations', 'local_unics'),
        new moodle_url('/local/unics/pages/organizations.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_assign',
        get_string('assignments', 'local_unics'),
        new moodle_url('/local/unics/pages/assign.php'),
        'local/unics:manage'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_my_students',
        'Мои учащиеся',
        new moodle_url('/local/unics/pages/my_students.php'),
        'local/unics:viewstudents'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_umk',
        'Генерация УМК (ИИ)',
        new moodle_url('/local/unics/pages/generate_umk.php'),
        'local/unics:viewstudents'
    ));

    $ADMIN->add('local_unics_cat', new admin_externalpage(
        'local_unics_umk_status',
        'История генерации УМК',
        new moodle_url('/local/unics/pages/umk_status.php'),
        'local/unics:manage'
    ));

    // Настройки ИИ-генерации
    $settings = new admin_settingpage('local_unics_ai', 'УНИКС: Настройки ИИ');
    $ADMIN->add('local_unics_cat', $settings);

    $settings->add(new admin_setting_heading(
        'local_unics/groq_heading', 'Groq API (генерация текста, бесплатно)', ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_unics/groq_api_key',
        'Groq API Key',
        'Получить бесплатно без карты: console.groq.com → API Keys',
        '', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_unics/groq_model',
        'Модель Groq',
        'По умолчанию: llama-3.1-8b-instant. Также доступны: llama-3.3-70b-versatile, mixtral-8x7b-32768',
        'llama-3.1-8b-instant', PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_unics/voicerss_heading', 'VoiceRSS API (синтез речи)', ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_unics/voicerss_api_key',
        'VoiceRSS API Key',
        'Получить бесплатно (350 запросов/день): voicerss.org',
        '', PARAM_TEXT
    ));
}
