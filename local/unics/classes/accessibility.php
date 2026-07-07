<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * A1. Доступность: per-user темы/контраст/шрифт/акцент + голосовой ввод
 * ([[accessibility-features-design]]; этап 2.1 разгрузки lib.php).
 *
 * Тела перенесены из lib.php; там остались тонкие обёртки
 * (`local_unics_a11y_get_prefs()` и т.д.) и колбек `local_unics_before_standard_html_head`.
 */
class accessibility {

    /**
     * Допустимые значения предпочтений доступности.
     * Используется и при чтении (валидация), и формой accessibility.php (рендер опций).
     *
     * @return array<string, string[]>
     */
    public static function allowed_values(): array {
        return [
            'theme'    => ['light', 'dark'],
            'contrast' => ['0', '1'],
            'font'     => ['normal', 'large', 'xlarge'],
            'accent'   => ['default', 'blue', 'green', 'purple'],
        ];
    }

    /**
     * Читает предпочтения доступности текущего (или указанного) пользователя
     * из Moodle user preferences. Невалидные/отсутствующие значения -> дефолт.
     *
     * @param int|null $userid id пользователя; null = текущий
     * @return array{theme:string, contrast:string, font:string, accent:string}
     */
    public static function get_prefs(?int $userid = null): array {
        $allowed = self::allowed_values();
        $defaults = ['theme' => 'light', 'contrast' => '0', 'font' => 'normal', 'accent' => 'default'];
        $prefs = [];
        foreach ($defaults as $key => $def) {
            $val = get_user_preferences('local_unics_a11y_' . $key, $def, $userid);
            $prefs[$key] = in_array($val, $allowed[$key], true) ? $val : $def;
        }
        return $prefs;
    }

    /**
     * Тело колбека ядра `local_unics_before_standard_html_head` (строится <head> каждой страницы).
     *
     * Делает две вещи:
     *  1. Доступность - по предпочтениям пользователя вешает классы на <body>
     *     (тёмная схема / контраст / акцент) и возвращает инлайн-стиль масштаба шрифта
     *     (на <html>, т.к. rem считается от корня, а классы мы вешаем на body).
     *  2. Голосовой ввод - если включён админом и страница это assign/quiz, подключает
     *     AMD-модуль, навешивающий кнопку «Диктовать» на текстовые поля ответа.
     *
     * @return string HTML/стиль для вставки в <head>
     */
    public static function before_standard_html_head(): string {
        global $PAGE;

        if (!isloggedin() || isguestuser()) {
            return '';
        }

        $out = '';

        // --- 1. Доступность: классы на <html> + масштаб шрифта. ---
        // Классы вешаем на <html> инлайн-скриптом в <head>, а НЕ через
        // $PAGE->add_body_class(): к моменту вызова этого callback'а страница уже в
        // состоянии PRINTING_HEADER, и add_body_class бросает coding_exception.
        // Скрипт в <head> отрабатывает синхронно до отрисовки body - без мигания.
        $prefs = self::get_prefs();
        $classes = [];
        if ($prefs['theme'] === 'dark') {
            $classes[] = 'unics-a11y-dark';
        }
        if ($prefs['contrast'] === '1') {
            $classes[] = 'unics-a11y-contrast';
        }
        if ($prefs['accent'] !== 'default') {
            $classes[] = 'unics-a11y-accent-' . $prefs['accent'];
        }
        if ($classes) {
            $json = json_encode($classes);
            $out .= '<script>(function(c){var e=document.documentElement;'
                  . 'c.forEach(function(x){e.classList.add(x);});})(' . $json . ');</script>' . "\n";
        }
        if ($prefs['font'] !== 'normal') {
            $scale = $prefs['font'] === 'xlarge' ? '125%' : '112.5%';
            $out .= '<style id="unics-a11y-font">html{font-size:' . $scale . ';}</style>' . "\n";
        }

        // --- 2. Голосовой ввод (Web Speech API). ---
        if (get_config('local_unics', 'voice_input_enabled')) {
            $pagetype = (string)$PAGE->pagetype;
            // Страницы ответа: онлайн-текст задания и попытка теста.
            $voicepages = ['mod-assign-view', 'mod-quiz-attempt'];
            if (in_array($pagetype, $voicepages, true)) {
                try {
                    $PAGE->requires->js_call_amd('local_unics/voice_input', 'init');
                } catch (\Throwable $e) {
                    // Нефатально - кнопка просто не появится.
                    debugging('local_unics: подавленное исключение: ' . $e->getMessage()
                        . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
                }
            }
        }

        return $out;
    }
}
