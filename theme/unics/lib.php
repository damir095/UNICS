<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * УНИКС theme functions.
 *
 * @package   theme_unics
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Prepend SCSS variables before the preset is compiled.
 * Variables set here override !default values in the preset.
 *
 * @param theme_config $theme
 * @return string SCSS
 */
function theme_unics_get_pre_scss($theme): string {
    // УНИКС brand palette.
    $scss  = '$primary:  #F26545;' . "\n"; // Оранжево-красный акцент
    $scss .= '$body-bg:  #F5F6F9;' . "\n"; // Светло-серый фон
    $scss .= '$dark:     #292F3B;' . "\n"; // Тёмно-синий

    // Accessibility: larger base font (18px instead of 15px).
    $scss .= '$font-size-base: 1.125rem;' . "\n";

    // Better line spacing for children with visual impairments.
    $scss .= '$line-height-base: 1.7;' . "\n";

    // Larger touch targets.
    $scss .= '$input-btn-padding-y: 0.5rem;'  . "\n";
    $scss .= '$input-btn-padding-x: 1rem;'    . "\n";

    // Allow admin to override brand color from theme settings.
    if (!empty($theme->settings->brandcolor)) {
        $scss .= '$primary: ' . $theme->settings->brandcolor . ';' . "\n";
    }

    // Behat flag.
    if (defined('BEHAT_SITE_RUNNING')) {
        $scss .= '$behatsite: true;' . "\n";
    }

    // Custom pre-SCSS from theme settings.
    if (!empty($theme->settings->scsspre)) {
        $scss .= $theme->settings->scsspre;
    }

    return $scss;
}

/**
 * Inject additional SCSS after the preset has been compiled.
 *
 * @param theme_config $theme
 * @return string SCSS
 */
function theme_unics_get_extra_scss($theme): string {
    global $CFG;

    $content = file_get_contents($CFG->dirroot . '/theme/unics/scss/unics.scss');

    // Custom SCSS from theme settings (admin textarea).
    if (!empty($theme->settings->scss)) {
        $content .= "\n" . $theme->settings->scss;
    }

    return $content;
}

/**
 * Serve theme files (logo, background images).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context  $context
 * @param string   $filearea
 * @param array    $args
 * @param bool     $forcedownload
 * @param array    $options
 * @return bool
 */
function theme_unics_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    if ($context->contextlevel == CONTEXT_SYSTEM && in_array($filearea, ['logo', 'backgroundimage', 'loginbackgroundimage'])) {
        $theme = theme_config::load('unics');
        if (!array_key_exists('cacheability', $options)) {
            $options['cacheability'] = 'public';
        }
        return $theme->setting_file_serve($filearea, $args, $forcedownload, $options);
    }
    send_file_not_found();
}
