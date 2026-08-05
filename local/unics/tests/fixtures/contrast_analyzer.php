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
}
