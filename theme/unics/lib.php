<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Возвращает основной SCSS-контент — используем дефолтный пресет Boost.
 */
function theme_unics_get_main_scss_content($theme) {
    global $CFG;
    return file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');
}

/**
 * Возвращает SCSS, который PREPEND-ится перед основным (переменные Bootstrap).
 * Благодаря флагу !default в Bootstrap, наши значения имеют приоритет.
 */
function theme_unics_get_pre_scss($theme) {
    // ---- Цветовая схема УНИКС (оранжевая) ----
    $primary = !empty($theme->settings->primarycolour) ? $theme->settings->primarycolour : '#E8621E';

    $scss  = '// УНИКС — Bootstrap variable overrides' . "\n";
    $scss .= '$primary:           ' . $primary . ';' . "\n"; // чистый тёплый оранжевый
    $scss .= '$secondary:         #6B4C3B;' . "\n"; // нейтральный тёмно-коричневый
    $scss .= '$success:           #2E7D32;' . "\n"; // тёмно-зелёный
    $scss .= '$warning:           #FF8F00;' . "\n"; // янтарный
    $scss .= '$info:              #0277BD;' . "\n"; // синий — контраст к терракоте
    $scss .= '$danger:            #C62828;' . "\n"; // тёмно-красный
    $scss .= '$body-bg:           #FAF6F2;' . "\n"; // тёплый нейтральный фон
    $scss .= '$body-color:        #263238;' . "\n"; // почти чёрный текст
    $scss .= '$link-color:        #0f6cbf;' . "\n"; // классический синий Moodle
    $scss .= '$link-hover-color:  #0a4e92;' . "\n";

    // ---- Скругления ----
    $scss .= '$border-radius:     .5rem;'  . "\n";
    $scss .= '$border-radius-sm:  .35rem;' . "\n";
    $scss .= '$border-radius-lg:  .75rem;' . "\n";
    $scss .= '$border-radius-xl:  1rem;'   . "\n";

    // ---- Карточки ----
    $scss .= '$card-border-width: 0;'           . "\n";
    $scss .= '$card-box-shadow:   0 2px 10px rgba(21, 101, 192, .09);' . "\n";

    // ---- Шрифты (системный стек с поддержкой кириллицы) ----
    $scss .= '$bodyfonts: -apple-system, BlinkMacSystemFont, "Segoe UI", "PT Sans", Arial, sans-serif;' . "\n";
    $scss .= '$headingsfont: $bodyfonts;' . "\n";

    // ---- Дополнительный SCSS из поля настроек темы ----
    if (!empty($theme->settings->customscss)) {
        $scss .= "\n" . $theme->settings->customscss . "\n";
    }

    return $scss;
}

/**
 * Возвращает SCSS, который APPEND-ится после основного (кастомные стили).
 */
function theme_unics_get_extra_scss($theme) {
    global $CFG;
    $scss = '';

    // Логотип из настроек темы
    $logoimageurl = $theme->setting_file_url('logo', 'logo');
    if ($logoimageurl) {
        $scss .= '.navbar-brand { background-image: url("' . $logoimageurl . '"); '
               . 'background-repeat: no-repeat; background-size: contain; '
               . 'width: 120px; height: 40px; display: inline-block; }' . "\n";
        $scss .= '.navbar-brand .site-name { display: none; }' . "\n";
    }

    // Кастомные стили УНИКС
    $scss .= file_get_contents($CFG->dirroot . '/theme/unics/scss/unics.scss');

    return $scss;
}
