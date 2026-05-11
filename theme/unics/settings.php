<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin settings for theme_unics.
 *
 * @package   theme_unics
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings = new theme_boost_admin_settingspage_tabs('themesettingunics', get_string('configtitle', 'theme_unics'));
    $page = new admin_settingpage('theme_unics_general', get_string('generalsettings', 'theme_boost'));

    // Brand colour.
    $name        = 'theme_unics/brandcolor';
    $title       = get_string('brandcolor', 'theme_unics');
    $description = get_string('brandcolordesc', 'theme_unics');
    $default     = '#F26545';
    $setting     = new admin_setting_configcolourpicker($name, $title, $description, $default, null, false);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Custom SCSS variables (prepended before the preset).
    $name        = 'theme_unics/scsspre';
    $title       = get_string('rawscsspre', 'theme_unics');
    $description = get_string('rawscsspredesc', 'theme_unics');
    $default     = '';
    $setting     = new admin_setting_scsscode($name, $title, $description, $default, PARAM_RAW);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Custom SCSS overrides (appended at the end).
    $name        = 'theme_unics/scss';
    $title       = get_string('rawscss', 'theme_unics');
    $description = get_string('rawscssdesc', 'theme_unics');
    $default     = '';
    $setting     = new admin_setting_scsscode($name, $title, $description, $default, PARAM_RAW);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $settings->add($page);
}
