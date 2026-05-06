<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // ---- Логотип ----
    $setting = new admin_setting_configstoredfile(
        'theme_unics/logo',
        get_string('logo', 'theme_unics'),
        get_string('logo_desc', 'theme_unics'),
        'logo',
        0,
        ['accepted_types' => ['.png', '.jpg', '.svg', '.gif']]
    );
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    // ---- Цвет Primary (опционально: ввод вручную) ----
    $setting = new admin_setting_configcolourpicker(
        'theme_unics/primarycolour',
        get_string('primarycolour', 'theme_unics'),
        get_string('primarycolour_desc', 'theme_unics'),
        '#e77031'
    );
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    // ---- Дополнительный SCSS ----
    $setting = new admin_setting_configtextarea(
        'theme_unics/customscss',
        get_string('customscss', 'theme_unics'),
        get_string('customscss_desc', 'theme_unics'),
        '',
        PARAM_RAW
    );
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);
}
