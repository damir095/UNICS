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
 * Concatenates modular partials from scss/ in a defined order. The order matters:
 *   _variables  → CSS custom properties used by everything below
 *   _navbar     → navbar, breadcrumb (top of page)
 *   _buttons    → button system (incl. .unics-cta)
 *   _forms      → form-control, focus rings
 *   _cards      → card / block surfaces
 *   _layout     → typography, links, sidebar, body
 *   _login      → login page only
 *   _unics-pages → plugin-specific (local_unics) components
 *   _tables     → единая система таблиц «Мягкие карточки» (.generaltable/.unics-table)
 *   _course-student → ученический вид страницы курса (.unics-child-course)
 *   _course-staff   → педагогский вид страницы курса (.unics-staff-course)
 *
 * @param theme_config $theme
 * @return string SCSS
 */
function theme_unics_get_extra_scss($theme): string {
    global $CFG;

    $partials = [
        '_variables',
        '_navbar',
        '_buttons',
        '_forms',
        '_cards',
        '_layout',
        '_login',
        '_unics-pages',
        '_tables',        // единая система таблиц (после наших страниц, до core и темной темы)
        '_course-student', // ученический вид страницы курса (.unics-child-course, весь под гейтом)
        '_course-staff',   // педагогский вид страницы курса (.unics-staff-course, весь под гейтом)
        // Оболочка под роль (G3): боковой рельс + центр уведомлений (toast - позже).
        // После _unics-pages (наши страницы — эталон), перед core/accessibility.
        '_shell-rail',
        '_shell-notifications', // фирменная подача ядрового колокола уведомлений

        // Core-страницы Moodle (G2): единый стиль курса, активностей, теста, журнала.
        // Идут ПОСЛЕ _unics-pages (наши страницы — эталон) и ПЕРЕД _accessibility
        // (per-user A1-темы перекрывают эти правила последними по cascade order).
        '_core-shared',     // сквозные примитивы ядра: page-header, generaltable, mform, alert, secondary-nav
        '_core-course',     // страница курса: секции-карточки, строка активности, course index
        '_core-mod',        // mod-страницы: заголовок, readable-width, intro, assign/forum
        '_core-quiz',       // прохождение/просмотр теста: блок вопроса, варианты, навигация теста
        '_core-gradebook',  // журнал/оценки: staff-таблицы, липкий заголовок, бейджи
        '_accessibility',  // per-user темы (dark/contrast/accent) — должен идти ПОСЛЕ остальных,
                           // чтобы перекрывать базовые правила по cascade order.
    ];

    $scssdir = $CFG->dirroot . '/theme/unics/scss/';

    // Маркер начала НАШЕГО SCSS. Нужен инструменту аудита контраста: он судит только наши
    // правила, а определять принадлежность по имени селектора нельзя - партиалы _core-*.scss
    // намеренно красят ядровые селекторы, где слова unics нет, и наш дефект на таком селекторе
    // страж не видел вовсе ([[contrast-audit-design]]).
    //
    // Свойство ОБЫЧНОЕ, а не кастомное: после блока с `--foo: 1` компилятор scssphp сбивается
    // на следующем построчном комментарии, если в нем есть `<html>` и открывающая скобка -
    // ровно такой комментарий стоит в шапке `_accessibility.scss`. Сборка падала целиком, а
    // Moodle ПОДМЕНЯЛ ее запасным CSS молча, без единого следа на странице.
    // Именно ПРАВИЛО, а не комментарий: комментарии-разделители ниже компилятор в сжатом режиме
    // вырезает, и в собранном бандле их ноль (проверено). Правило переживает сжатие, и все, что
    // идет за ним, - наше.
    $content = ".unics-scss-boundary{outline-color:transparent}\n";

    foreach ($partials as $partial) {
        $path = $scssdir . $partial . '.scss';
        if (is_readable($path)) {
            $content .= "\n/* ===== {$partial}.scss ===== */\n";
            $content .= file_get_contents($path);
        }
    }

    // Custom SCSS from theme settings (admin textarea).
    if (!empty($theme->settings->scss)) {
        $content .= "\n/* ===== admin custom SCSS ===== */\n";
        $content .= $theme->settings->scss;
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

/**
 * Боковой рельс под роль (G3 per-role-shell, Срез "Рельс v1").
 *
 * Эмитит постоянную левую навигацию в начале <body> из структуры
 * {@see local_unics_get_shell_nav()} (единый источник пунктов с
 * local_unics_extend_navigation). Сдвиг #page и позиционирование - в
 * scss/_shell-rail.scss (через body:has(.unics-shell-rail), без форка layout).
 *
 * Охват v1: только страницы /local/unics/* (страница курса + сосуществование с
 * course-index Boost - следующий срез).
 *
 * @return string HTML рельса (пустая строка, если рельс не нужен).
 */
function theme_unics_before_standard_top_of_body_html(): string {
    global $PAGE, $OUTPUT, $CFG;

    if (during_initial_install() || !isloggedin() || isguestuser()) {
        return '';
    }

    // Только наши страницы (охват курса - позже, после решения по course-index).
    $onunics = $PAGE->has_set_url()
        && strpos($PAGE->url->out_omit_querystring(), '/local/unics/') !== false;
    if (!$onunics) {
        return '';
    }

    if (!function_exists('local_unics_get_shell_nav')) {
        $lib = $CFG->dirroot . '/local/unics/lib.php';
        if (!file_exists($lib)) {
            return '';
        }
        require_once($lib);
    }

    $nav = local_unics_get_shell_nav();
    if (empty($nav['groups'])) {
        return '';
    }

    $groupshtml = '';
    foreach ($nav['groups'] as $group) {
        $itemshtml = '';
        foreach ($group['items'] as $it) {
            $icon = !empty($it['icon'])
                ? $OUTPUT->pix_icon($it['icon'], '', 'moodle', ['class' => 'icon unics-shell-rail__icon'])
                : '';
            $text = html_writer::span(s($it['label']), 'unics-shell-rail__text');
            // title - подсказка при наведении в свёрнутом режиме (текст визуально скрыт,
            // но остаётся в дереве доступности; title даёт hover-tooltip).
            $linkattrs = [
                'class' => 'unics-shell-rail__item' . (!empty($it['active']) ? ' is-active' : ''),
                'title' => $it['label'],
            ];
            if (!empty($it['active'])) {
                $linkattrs['aria-current'] = 'page';
            }
            $itemshtml .= html_writer::tag('li',
                html_writer::link($it['url'], $icon . $text, $linkattrs),
                ['class' => 'unics-shell-rail__li']);
        }

        $labelhtml = !empty($group['label'])
            ? html_writer::div(s($group['label']), 'unics-shell-rail__label')
            : '';

        $groupshtml .= html_writer::tag('div',
            $labelhtml . html_writer::tag('ul', $itemshtml, ['class' => 'unics-shell-rail__group-list']),
            ['class' => 'unics-shell-rail__group']);
    }

    // Свёрнутый режим - per-user предпочтение (local_unics_user_preferences),
    // серверный рендер = источник истины (без мигания); AMD-тоггл сохраняет его
    // через core_user/repository + localStorage для мгновенности.
    $collapsed   = (int) get_user_preferences('local_unics_shell_rail_collapsed', 0);
    $togglelabel = $collapsed ? 'Развернуть меню' : 'Свернуть меню';

    $toggle = html_writer::tag('button',
        $OUTPUT->pix_icon('i/arrow-left', '', 'moodle', ['class' => 'icon unics-shell-rail__toggle-icon']) .
        html_writer::span('Свернуть', 'unics-shell-rail__toggle-text'),
        [
            'type'          => 'button',
            'class'         => 'unics-shell-rail__toggle',
            'aria-expanded' => $collapsed ? 'false' : 'true',
            'aria-controls' => 'unics-shell-rail-groups',
            'aria-label'    => $togglelabel,
            'title'         => $togglelabel,
        ]);

    $groupswrap = html_writer::div($groupshtml, 'unics-shell-rail__groups', ['id' => 'unics-shell-rail-groups']);

    // Подключаем тоггл-логику только когда рельс реально отрисован.
    $PAGE->requires->js_call_amd('local_unics/shell_rail', 'init');

    $railhtml = html_writer::tag('nav', $toggle . $groupswrap, [
        'class'      => 'unics-shell-rail' . ($collapsed ? ' unics-shell-rail--collapsed' : ''),
        'id'         => 'unics-shell-rail',
        'aria-label' => 'Основная навигация УНИКС',
    ]);

    // Подложка для мобильного off-canvas (на десктопе скрыта CSS). Скрыта по
    // умолчанию; AMD shell_rail показывает её при открытии рельса гамбургером.
    $railhtml .= html_writer::div('', 'unics-shell-rail__backdrop', [
        'hidden'      => 'hidden',
        'data-region' => 'unics-shell-rail-backdrop',
    ]);

    return $railhtml;
}
