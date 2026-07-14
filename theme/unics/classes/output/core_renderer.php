<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace theme_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Core renderer для темы УНИКС.
 *
 * Отдает логотип из pix/ темы вместо core-настроек (core_admin logo/logocompact
 * остаются пустыми): логотип версионируется в git и переживает переустановку.
 * Покрывает страницу входа (loginform.mustache -> logourl), навбар и мобильный
 * drawer (navbar.mustache / primary-drawer-mobile.mustache -> get_compact_logo_url).
 * Белая версия для темных поверхностей подменяется в SCSS через content:url
 * (см. _navbar.scss и _accessibility.scss). Дизайн: [[logo-favicon-design]] в LLM-вики.
 */
class core_renderer extends \theme_boost\output\core_renderer {

    /**
     * Логотип страницы входа: связка «знак У-росток + УНИКС», темный текст.
     *
     * @param int|null $maxwidth не используется (SVG, размер задает верстка)
     * @param int $maxheight не используется
     * @return \moodle_url
     */
    public function get_logo_url($maxwidth = null, $maxheight = 200) {
        return $this->image_url('unics-logo', 'theme');
    }

    /**
     * Компактный логотип навбара/drawer'а: тот же файл, что и на входе.
     *
     * @param int $maxwidth не используется
     * @param int $maxheight не используется
     * @return \moodle_url
     */
    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        return $this->image_url('unics-logo', 'theme');
    }
}
