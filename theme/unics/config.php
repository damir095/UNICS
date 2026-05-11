<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * УНИКС theme configuration.
 *
 * @package   theme_unics
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

$THEME->name = 'unics';

$THEME->sheets = [];
$THEME->editor_sheets = [];
$THEME->editor_scss = ['editor'];
$THEME->usefallback = true;

// SCSS pipeline: pre → preset (boost default) → extra.
$THEME->scss = function($theme) {
    return theme_boost_get_main_scss_content($theme);
};
$THEME->prescsscallback    = 'theme_unics_get_pre_scss';
$THEME->extrascsscallback  = 'theme_unics_get_extra_scss';

// Inherit all layout files from boost (drawers.php, login.php, etc.).
$THEME->layouts = [
    'base' => [
        'file'    => 'drawers.php',
        'regions' => [],
    ],
    'standard' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'course' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options'       => ['langmenu' => true],
    ],
    'coursecategory' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'incourse' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'frontpage' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options'       => ['nonavbar' => true],
    ],
    'admin' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'mycourses' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options'       => ['nonavbar' => true],
    ],
    'mydashboard' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options'       => ['nonavbar' => true, 'langmenu' => true],
    ],
    'mypublic' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'login' => [
        'file'    => 'login.php',
        'regions' => [],
        'options' => ['langmenu' => true],
    ],
    'popup' => [
        'file'    => 'columns1.php',
        'regions' => [],
        'options' => [
            'nofooter'    => true,
            'nonavbar'    => true,
            'activityheader' => [
                'notitle'       => true,
                'nocompletion'  => true,
                'nodescription' => true,
            ],
        ],
    ],
    'frametop' => [
        'file'    => 'columns1.php',
        'regions' => [],
        'options' => [
            'nofooter'       => true,
            'nocoursefooter' => true,
            'activityheader' => ['nocompletion' => true],
        ],
    ],
    'embedded' => [
        'file'    => 'embedded.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'maintenance' => [
        'file'    => 'maintenance.php',
        'regions' => [],
    ],
    'print' => [
        'file'    => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => false, 'noactivityheader' => true],
    ],
    'redirect' => [
        'file'    => 'embedded.php',
        'regions' => [],
    ],
    'report' => [
        'file'          => 'drawers.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'secure' => [
        'file'          => 'secure.php',
        'regions'       => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options'       => ['activityheader' => ['notitle' => false]],
    ],
];

$THEME->parents           = ['boost'];
$THEME->enable_dock       = false;
$THEME->yuicssmodules     = [];
$THEME->rendererfactory   = 'theme_overridden_renderer_factory';
$THEME->requiredblocks    = '';
$THEME->addblockposition  = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->iconsystem        = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch     = true;
$THEME->usescourseindex   = true;
$THEME->activityheaderconfig = ['notitle' => true];
