<?php
namespace local_unics;

/**
 * Анализатор контраста: читает скомпилированный CSS темы, строит таблицу токенов
 * для каждой комбинации схем доступности и проверяет два правила ([[contrast-audit-design]]).
 *
 * Инструмент разработки, не production-код. Лежит в tests/fixtures, потому что
 * phpunit.xml исключает этот каталог из автосбора тест-кейсов.
 *
 * @package local_unics
 */
final class contrast_analyzer {

    /**
     * Поверхности, против которых проверяется цвет текста без собственного фона
     * (правило 2). Токен -> человекочитаемое имя для сообщения об ошибке.
     */
    public const SURFACES = [
        '--unics-bg'                   => 'фон страницы',
        '--unics-surface'              => 'карточка',
        '--unics-header-bg'            => 'баннер',
        '--unics-table-head-bg'        => 'шапка таблицы',
        '--unics-rail-item-active-bg'  => 'активный пункт рельса',
    ];

    /** Разбирает плоский CSS на декларации. Комментарии и @-правила игнорируются. */
    public static function declarations(string $css): array {
        // Убрать комментарии, иначе их содержимое попадет в значения.
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        $out = [];
        $ord = 0;
        // Селектор + тело блока. Вложенные @-правила (@media, @supports, @keyframes)
        // игнорируются: их оболочка удаляется и вложенные правила добавляются на
        // верхний уровень как независимые блоки.
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $b) {
                $sel = trim($b[1]);
                if ($sel === '' || $sel[0] === '@') {
                    continue;
                }
                $declarations = self::split_declarations($b[2]);
                foreach ($declarations as $d) {
                    $pos = self::find_colon_outside_context($d);
                    if ($pos === false) {
                        continue;
                    }
                    $prop = trim(substr($d, 0, $pos));
                    $val = trim(substr($d, $pos + 1));
                    if ($prop === '' || $val === '') {
                        continue;
                    }
                    $out[] = ['sel' => $sel, 'prop' => $prop, 'val' => $val, 'ord' => $ord++];
                }
            }
        }
        return $out;
    }

    /**
     * Разбивает тело блока на отдельные декларации, учитывая парные скобки и кавычки.
     * Точка с запятой внутри url(...) или кавычек не считается границей.
     */
    private static function split_declarations(string $body): array {
        $out = [];
        $current = '';
        $paren_depth = 0;
        $quote_char = null;
        $i = 0;
        $len = strlen($body);

        while ($i < $len) {
            $ch = $body[$i];

            // Обработка кавычек.
            if (($ch === '"' || $ch === "'") && ($i === 0 || $body[$i - 1] !== '\\')) {
                if ($quote_char === $ch) {
                    $quote_char = null;
                } elseif ($quote_char === null) {
                    $quote_char = $ch;
                }
            }

            // Обработка скобок (только вне кавычек).
            if ($quote_char === null) {
                if ($ch === '(') {
                    $paren_depth++;
                } elseif ($ch === ')') {
                    $paren_depth--;
                }
            }

            // Проверка границы декларации (вне скобок и кавычек).
            if ($ch === ';' && $paren_depth === 0 && $quote_char === null) {
                if ($current !== '') {
                    $out[] = $current;
                    $current = '';
                }
            } else {
                $current .= $ch;
            }

            $i++;
        }

        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    /**
     * Находит первый символ ':' снаружи парных скобок и кавычек.
     * Возвращает позицию или false если не найден.
     */
    private static function find_colon_outside_context(string $str): int|false {
        $paren_depth = 0;
        $quote_char = null;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];

            // Обработка кавычек.
            if (($ch === '"' || $ch === "'") && ($i === 0 || $str[$i - 1] !== '\\')) {
                if ($quote_char === $ch) {
                    $quote_char = null;
                } elseif ($quote_char === null) {
                    $quote_char = $ch;
                }
            }

            // Обработка скобок (только вне кавычек).
            if ($quote_char === null) {
                if ($ch === '(') {
                    $paren_depth++;
                } elseif ($ch === ')') {
                    $paren_depth--;
                }
            }

            // Если нашли ':' вне контекста - вернуть позицию.
            if ($ch === ':' && $paren_depth === 0 && $quote_char === null) {
                return $i;
            }
        }

        return false;
    }

    /** 16 комбинаций: theme(2) x contrast(2) x accent(4). См. accessibility.php:23-26. */
    public static function combos(): array {
        $out = [];
        foreach (['light', 'dark'] as $theme) {
            foreach (['0', '1'] as $contrast) {
                foreach (['default', 'blue', 'green', 'purple'] as $accent) {
                    $classes = [];
                    if ($theme === 'dark') {
                        $classes[] = 'unics-a11y-dark';
                    }
                    if ($contrast === '1') {
                        $classes[] = 'unics-a11y-contrast';
                    }
                    if ($accent !== 'default') {
                        $classes[] = 'unics-a11y-accent-' . $accent;
                    }
                    $out[] = $classes;
                }
            }
        }
        return $out;
    }

    /** Относительная яркость по WCAG 2.x. $hex - 6 знаков без '#'. */
    private static function luminance(string $hex): float {
        $ch = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $ch[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }

    /** Коэффициент контраста 1.0..21.0. Порядок аргументов не важен. */
    public static function ratio(string $hex1, string $hex2): float {
        $l1 = self::luminance($hex1);
        $l2 = self::luminance($hex2);
        $hi = max($l1, $l2);
        $lo = min($l1, $l2);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * Применим ли селектор к html с данным набором a11y-классов.
     * Интересуют только `:root` и `html.unics-a11y-*` - остальные блоки токенов не объявляют.
     * Возврат: [применим, специфичность] или [false, 0].
     */
    private static function scheme_match(string $sel, array $classes): array {
        $sel = trim($sel);
        if ($sel === ':root') {
            return [true, 0];
        }
        if (!preg_match('/^html((?:\.unics-a11y-[a-z-]+)+)$/', $sel, $m)) {
            return [false, 0];
        }
        $needed = array_filter(explode('.', $m[1]));
        foreach ($needed as $c) {
            if (!in_array($c, $classes, true)) {
                return [false, 0];
            }
        }
        // Специфичность = число классов в селекторе.
        return [true, count($needed)];
    }

    /** Таблица токенов для комбинации. Ничьи по специфичности решает порядок ($ord). */
    public static function tokens(array $decls, array $classes): array {
        $best = [];   // токен -> [специфичность, ord]
        $raw = [];    // токен -> сырое значение
        foreach ($decls as $d) {
            if (strncmp($d['prop'], '--unics-', 8) !== 0) {
                continue;
            }
            [$ok, $spec] = self::scheme_match($d['sel'], $classes);
            if (!$ok) {
                continue;
            }
            $prev = $best[$d['prop']] ?? null;
            if ($prev === null || $spec > $prev[0] || ($spec === $prev[0] && $d['ord'] > $prev[1])) {
                $best[$d['prop']] = [$spec, $d['ord']];
                $raw[$d['prop']] = $d['val'];
            }
        }
        // Резолвим цепочки var() внутри самой таблицы: --unics-section-bg: var(--unics-surface).
        $out = [];
        foreach ($raw as $token => $val) {
            $hex = self::resolve_raw($val, $raw, 0);
            if ($hex !== null) {
                $out[$token] = $hex;
            }
        }
        return $out;
    }

    /** Разворачивает значение в hex, идя по цепочке var() внутри карты сырых значений. */
    private static function resolve_raw(string $val, array $raw, int $depth): ?string {
        if ($depth > 10) {
            return null;   // цикл в токенах
        }
        $val = trim($val);
        $val = preg_replace('/\s*!important$/', '', $val);

        if (preg_match('/^#([0-9a-f]{6})$/i', $val, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match('/^#([0-9a-f]{3})$/i', $val, $m)) {
            $s = strtolower($m[1]);
            return $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
        }
        // Шестизначный hex без #: встречается в таблице токенов.
        if (preg_match('/^([0-9a-f]{6})$/i', $val, $m)) {
            return strtolower($m[1]);
        }
        // Трехзначный hex без #.
        if (preg_match('/^([0-9a-f]{3})$/i', $val, $m)) {
            $s = strtolower($m[1]);
            return $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
        }
        if (preg_match('/^rgb\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)\s*\)$/i', $val, $m)) {
            return sprintf('%02x%02x%02x', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        // Именованные цвета, встречающиеся в бандле как непрозрачные.
        $named = ['white' => 'ffffff', 'black' => '000000', 'red' => 'ff0000'];
        if (isset($named[strtolower($val)])) {
            return $named[strtolower($val)];
        }
        // var(--token) или var(--token, fallback).
        if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*(?:,\s*(.+))?\)$/i', $val, $m)) {
            $token = $m[1];
            if (isset($raw[$token])) {
                return self::resolve_raw($raw[$token], $raw, $depth + 1);
            }
            return isset($m[2]) ? self::resolve_raw($m[2], $raw, $depth + 1) : null;
        }
        // transparent, currentColor, градиенты, rgba с альфой - непроверяемо.
        return null;
    }

    /** Публичный резолв значения против ГОТОВОЙ таблицы токенов. */
    public static function resolve(string $value, array $tokens): ?string {
        return self::resolve_raw($value, $tokens, 0);
    }

    /**
     * Скомпилированный CSS темы unics - весь бандл, включая плагинные и ядровые правила.
     *
     * В Moodle 5.0 класс лежит в namespace core\output (lib/classes/output/theme_config.php),
     * а НЕ в глобальном пространстве: обращаться только по полному имени.
     * Метод get_css_content() аргументов не принимает - это ровно то, что
     * theme/styles.php отдает браузеру.
     */
    public static function css(): string {
        $theme = \core\output\theme_config::load('unics');
        return $theme->get_css_content();
    }

    /**
     * Ядровые селекторы, цвет которых подставляем МЫ (через theme_unics_get_pre_scss),
     * и потому по решению 3 спеки считаются нашими.
     *
     * `.btn.add-section` объявлен в boost/scss/moodle/course.scss как `color: $primary`,
     * а `$primary` = #F26545 приходит из нашего get_pre_scss.
     */
    public const CORE_OWNED = ['.btn.add-section'];

    /**
     * Наш ли селектор. Нужен, чтобы отделить дефекты, за которые мы отвечаем, от
     * ядровых: бандл содержит правила Moodle, написанные под светлую схему, а наши
     * темные переопределения живут на ДРУГИХ селекторах (html.unics-a11y-dark a:not(.btn)),
     * поэтому слияние по имени селектора ядровую пару «починенной» не видит и дает
     * тысячи ложных срабатываний. Полноценный каскад между разными селекторами - это
     * CSS-движок, что вне задачи. Ядровые находки остаются в отчете как справка.
     */
    /**
     * ИЗВЕСТНОЕ ОГРАНИЧЕНИЕ ОБЛАСТИ (не читать зеленого стража как «тема чиста»).
     *
     * Признак принадлежности - подстрока `unics` в СЕЛЕКТОРЕ. Но theme_unics намеренно
     * стилизует и ядровые селекторы: `_navbar.scss`, `_buttons.scss`, `_forms.scss`,
     * `_cards.scss`, `_core-*.scss` красят `.navbar`, `.btn`, `.generaltable` и прочее,
     * где слова `unics` нет. Наш дефект на таком селекторе попадает в ядровую корзину
     * и стражем НЕ судится. Пример, найденный ревью: `.generaltable thead th a:hover` -
     * 4.37:1 в зеленом акценте, где НАШИ обе стороны (фон из --unics-table-head-bg,
     * цвет из accent-миксина), но `is_ours()` возвращает false.
     *
     * Правильное решение - определять принадлежность по ФАЙЛУ-источнику, а не по
     * селектору. Прямой путь через маркеры партиалов НЕ РАБОТАЕТ: `theme_unics_get_extra_scss`
     * пишет их (`lib.php:116`) обычными CSS-комментариями, а компилятор в сжатом режиме
     * комментарии вырезает - в собранном бандле их ноль (проверено). Понадобится либо
     * компиляция партиалов по отдельности, либо маркер, переживающий сжатие. Отдельная задача.
     */
    public static function is_ours(string $sel): bool {
        if (stripos($sel, 'unics') !== false) {
            return true;
        }
        // По ЧАСТЯМ группы: находка рождается на одной части, а сообщается с групповым селектором
        // того правила, что победило в слиянии, - и группа эта бывает ядровой. Сверка строки
        // целиком тогда объявляла находку чужой и молча ее теряла. Так пропал дефект условия
        // вопроса, пойманный мутацией уже после починки (2026-08-28).
        $ours = self::ours_selectors();
        foreach (explode(',', $sel) as $part) {
            if (isset($ours[trim($part)])) {
                return true;
            }
        }
        foreach (self::CORE_OWNED as $core) {
            if (strpos($sel, $core) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Селекторы, объявленные ПОСЛЕ маркера границы, то есть нашим SCSS. */
    private static ?array $ourselectors = null;

    /**
     * Селекторы нашего SCSS - по положению в бандле, а не по имени.
     *
     * `theme_unics_get_extra_scss` начинается правилом `.unics-scss-boundary`, и все, что идет
     * за ним, написано нами. Именно правилом, а не комментарием: комментарии-разделители
     * компилятор в сжатом режиме вырезает.
     *
     * Селектор, встречающийся и до, и после границы, считается нашим - и это верно: раз мы его
     * красим, за его контраст отвечаем мы.
     *
     * @return array<string,true>
     */
    public static function ours_selectors(): array {
        if (self::$ourselectors !== null) {
            return self::$ourselectors;
        }
        $decls = self::declarations(self::css());
        $boundary = null;
        foreach ($decls as $d) {
            if ($d['sel'] === '.unics-scss-boundary') {
                $boundary = $d['ord'];
                break;
            }
        }
        self::$ourselectors = [];
        if ($boundary === null) {
            // Маркера нет - тема собрана старой версией lib.php. Молча судить по одному имени
            // селектора нельзя: страж выглядел бы работающим, теряя целый класс дефектов.
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'В собранном CSS нет маркера .unics-scss-boundary: очистите кеши темы');
        }
        foreach ($decls as $d) {
            if ($d['ord'] <= $boundary) {
                continue;
            }
            // Индексируем и группу целиком, и каждую ее часть.
            self::$ourselectors[$d['sel']] = true;
            foreach (explode(',', $d['sel']) as $part) {
                self::$ourselectors[trim($part)] = true;
            }
        }
        return self::$ourselectors;
    }

    /** Человекочитаемая метка комбинации: light / dark+contrast+accent-blue и т.п. */
    private static function label(array $classes): string {
        if ($classes === []) {
            return 'light';
        }
        $parts = [];
        foreach ($classes as $c) {
            $parts[] = str_replace('unics-a11y-', '', $c);
        }
        return implode('+', $parts);
    }

    /**
     * Два правила ([[contrast-audit-design]], раздел 6).
     *
     * Правило 1 - пара color+background объявлена в одном блоке, контраст считается напрямую.
     * Правило 2 - color без фона: проверяется против ВСЕХ поверхностей схемы, и дефектом
     * считается только провал на каждой из них. Цвет, проходящий хотя бы на одной
     * поверхности, законен - «применен не на ту поверхность» статике недоступно,
     * это работа рантайм-проверки.
     */
    /**
     * Классы схемы, которых селектор ТРЕБУЕТ, и селектор без них.
     *
     * `html.unics-a11y-dark .unics-teacher-note` требует [dark] и нормализуется в
     * `.unics-teacher-note`. Нормализованная форма нужна, чтобы в темной комбинации
     * темный вариант правила перекрыл базовый: это разные строки селектора, и слияние
     * по имени их не объединяет.
     *
     * @return array{0: string[], 1: string} [требуемые классы, нормализованный селектор]
     */
    private static function scheme_scope(string $sel): array {
        $required = [];
        if (preg_match_all('/\.(unics-a11y-[a-z-]+)/', $sel, $m)) {
            $required = array_values(array_unique($m[1]));
        }
        // Убрать префикс схемы: `html.unics-a11y-dark.unics-a11y-contrast ` в начале
        // каждой запятой-части. Остаток и есть то, что правило красит.
        $parts = [];
        foreach (explode(',', $sel) as $part) {
            $parts[] = trim(preg_replace('/^html(?:\.unics-a11y-[a-z-]+)+\s*/', '', trim($part)));
        }
        return [$required, implode(',', $parts)];
    }

    /**
     * Фон, приходящий от ПРЕДКА селектора (правило 3).
     *
     * Берем первую запятую-часть селектора, отрезаем от нее хвостовые компоненты
     * по одному и ищем блок-предок с разрешимым фоном - от самого длинного префикса
     * к самому короткому, то есть от ближайшего предка к дальнему. Это не каскадный
     * движок: он не знает ни специфичности между разными цепочками, ни реального DOM.
     * Но он закрывает господствующую у нас форму дефекта - тонированная карточка плюс
     * приглушенный текст в потомке.
     *
     * @param array $winners блоки, применимые в текущей комбинации, по нормализованному селектору
     * @return string|null hex фона предка либо null, если ни у одного предка фона нет
     */
    private static function ancestor_background(string $sel, array $winners, array $tokens): ?string {
        $first = trim(explode(',', $sel)[0]);
        // Схемный префикс уже учтен в нормализации, работаем с ней же.
        [, $normal] = self::scheme_scope($first);
        $parts = preg_split('/\s+/', trim($normal));
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $prefix = implode(' ', array_slice($parts, 0, $i));
            if (!isset($winners[$prefix])) {
                continue;
            }
            $props = $winners[$prefix]['props'];
            $raw = $props['background-color'] ?? ($props['background'] ?? null);
            if ($raw === null) {
                continue;
            }
            $hex = self::resolve($raw, $tokens);
            if ($hex !== null) {
                return $hex;
            }
        }
        return null;
    }

    public static function audit(array $decls, array $combos, float $threshold = 4.5): array {
        // Сгруппировать декларации по селектору, чтобы видеть пары.
        $blocks = [];
        foreach ($decls as $d) {
            if (strncmp($d['prop'], '--', 2) === 0) {
                continue;
            }
            $blocks[$d['sel']][$d['prop']] = $d['val'];
        }

        // Разметить каждый блок его схемным скоупом один раз, а не на каждой комбинации.
        $scoped = [];
        foreach ($blocks as $sel => $props) {
            // Групповой селектор разбираем НА ЧАСТИ: правило `html.unics-a11y-dark .btn-outline-primary,
            // html.unics-a11y-dark .btn-outline-secondary, ...` относится к каждой из них, а слияние
            // по строке целиком его с одиночным `.btn-outline-primary` не сводило. Отсюда рождались
            // ложные срабатывания: базовое светлое правило выглядело неперекрытым в темной схеме,
            // хотя перекрыто оно правилом с БОЛЬШЕЙ специфичностью (найдено 2026-08-28).
            foreach (explode(',', $sel) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                [$required, $normal] = self::scheme_scope($part);
                $scoped[] = ['sel' => $sel, 'props' => $props,
                             'required' => $required, 'normal' => $normal];
            }
        }

        $found = [];
        foreach ($combos as $classes) {
            $tokens = self::tokens($decls, $classes);
            $combo = self::label($classes);

            // Поверхности этой схемы.
            $surfaces = [];
            foreach (array_keys(self::SURFACES) as $token) {
                if (isset($tokens[$token])) {
                    $surfaces[$token] = $tokens[$token];
                }
            }

            // Отобрать блоки, применимые в ЭТОЙ комбинации, и НАЛОЖИТЬ их друг на друга
            // по нормализованной форме: сначала базовое правило, затем схемные варианты.
            // Именно наложение, а не замена: схемный вариант обычно переопределяет лишь
            // часть свойств (например только color), а фон остается от базового правила.
            // Замена теряла бы такой фон и давала ложные срабатывания.
            $applicable = [];
            foreach ($scoped as $b) {
                $applies = true;
                foreach ($b['required'] as $c) {
                    if (!in_array($c, $classes, true)) {
                        $applies = false;
                        break;
                    }
                }
                if ($applies) {
                    $applicable[] = $b;
                }
            }
            // Стабильная сортировка по квалификации: чем больше требуемых классов схемы,
            // тем позже накладывается.
            usort($applicable, fn($x, $y) => count($x['required']) <=> count($y['required']));

            $winners = [];
            foreach ($applicable as $b) {
                $key = $b['normal'];
                if (!isset($winners[$key])) {
                    $winners[$key] = ['sel' => $b['sel'], 'props' => $b['props']];
                } else {
                    // Более квалифицированный вариант переопределяет свойства поштучно.
                    $winners[$key]['props'] = array_merge($winners[$key]['props'], $b['props']);
                    if ($b['required'] !== []) {
                        $winners[$key]['sel'] = $b['sel'];
                    }
                }
            }

            foreach ($winners as $key => $b) {
                // $key - НОРМАЛИЗОВАННАЯ часть селектора, $b['sel'] - исходная группа целиком.
                // Для поиска фона предка нужна именно часть: у группы
                // `.card .card-header.bg-primary, .card .card-header.bg-primary *` первая часть
                // ведет к белому фону `.card`, а вторая - к брендовой заливке самого заголовка.
                // Разбор по группе давал белое на белом, 1.00:1, там где на деле 4.6:1.
                $sel = $b['sel'];
                $props = $b['props'];
                $fgraw = $props['color'] ?? null;
                if ($fgraw === null) {
                    continue;
                }
                $fg = self::resolve($fgraw, $tokens);
                if ($fg === null) {
                    continue;
                }
                $bgraw = $props['background-color'] ?? ($props['background'] ?? null);
                $bg = $bgraw === null ? null : self::resolve($bgraw, $tokens);

                if ($bg !== null) {
                    // Правило 1: пара объявлена рядом.
                    $r = self::ratio($fg, $bg);
                    if ($r < $threshold) {
                        $found[] = ['sel' => $sel, 'combo' => $combo, 'fg' => $fg,
                                    'bg' => $bg, 'ratio' => round($r, 2), 'rule' => 1];
                    }
                    continue;
                }

                // Фона в самом блоке нет. Сначала ищем его у ПРЕДКА: подавляющее
                // большинство наших компонентов - карточка с тонированным фоном и
                // приглушенным текстом в потомке. Без этого прохода такая пара
                // сверяется с белым (самой щадящей из поверхностей) и молча проходит:
                // так проскочили .note-date 4.33:1 на тинте и ховер навбара 2.16:1
                // на всегда-темной подложке.
                $ancestorbg = self::ancestor_background($key, $winners, $tokens);
                if ($ancestorbg !== null) {
                    $r = self::ratio($fg, $ancestorbg);
                    if ($r < $threshold) {
                        $found[] = ['sel' => $sel, 'combo' => $combo, 'fg' => $fg,
                                    'bg' => $ancestorbg, 'ratio' => round($r, 2), 'rule' => 3];
                    }
                    continue;
                }

                // Правило 2: фона нет и у предков - проверяем против поверхностей схемы.
                if ($surfaces === []) {
                    continue;
                }
                $bestratio = 0.0;
                $bestbg = '';
                foreach ($surfaces as $sbg) {
                    $r = self::ratio($fg, $sbg);
                    if ($r > $bestratio) {
                        $bestratio = $r;
                        $bestbg = $sbg;
                    }
                }
                if ($bestratio < $threshold) {
                    $found[] = ['sel' => $sel, 'combo' => $combo, 'fg' => $fg,
                                'bg' => $bestbg, 'ratio' => round($bestratio, 2), 'rule' => 2];
                }
            }
        }
        return $found;
    }
}
