<?php
defined('MOODLE_INTERNAL') || die();

// УНИКС — тема на основе Boost
$THEME->name          = 'unics';
$THEME->parents       = ['boost'];
$THEME->sheets        = [];
$THEME->editor_sheets = [];
$THEME->enable_dock   = false;
$THEME->haveregions   = false;

// SCSS-компиляция
$THEME->scss = function ($theme) {
    return theme_unics_get_main_scss_content($theme);
};
$THEME->prescsscallback   = 'theme_unics_get_pre_scss';
$THEME->extrascsscallback = 'theme_unics_get_extra_scss';

// Унаследовано от Boost: иконки, блоки, рендерер
$THEME->yuicssmodules    = [];
$THEME->rendererfactory  = 'theme_overridden_renderer_factory';
$THEME->requiredblocks   = '';
$THEME->addblockposition = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->iconsystem       = '\\core\\output\\icon_system_fontawesome';
$THEME->haseditswitch    = true;

// Постобработка CSS: csstreepostprocessor убран — deprecated в Moodle 4.x,
// Bootstrap 5 уже имеет все vendor-префиксы в theme/boost/scss/moodle/prefixes.scss.
