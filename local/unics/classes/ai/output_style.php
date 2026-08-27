<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Нормализатор текста, пришедшего от модели ([[ai-output-style-design]]).
 *
 * Почему пост-обработка, а не указания в промте: промт УЖЕ содержит однозначное «используй
 * #### для заголовков», а живой прогон 2026-08-06 вернул пять «##» и ни одного «####».
 * Указание, которое уже есть, модель проигнорировала - значит нужен инвариант, а не просьба.
 *
 * Буква «ё» НЕ трогается сознательно: правило «без ё» действует на наши собственные строки, но
 * в учебном материале для детей с ЗПР и РАС «ё» снимает двусмысленность (все/всё, небо/нёбо).
 *
 * @package local_unics
 */
class output_style {

    /**
     * Эмодзи, вариационный селектор, ZWJ и keycap.
     *
     * Текстовые стрелки U+2190-U+21FF (->, <-) сюда НЕ входят: в учебном тексте стрелка
     * осмысленна. А вот дингбатные и «толстые» стрелки блоков U+2600-U+27BF и U+2B00-U+2BFF
     * (вместе со звездами вроде U+2B50) - декор, и они вырезаются.
     *
     * Блок U+1F000-U+1F2FF (закрытые буквы, флаги) и keycap U+20E3 добавлены после ревью
     * 2026-08-07: без них инвариант «эмодзи в выходе ИИ нет» не выполнялся.
     */
    private const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
                        . '\x{FE0F}\x{200D}\x{20E3}]/u';

    /** Тире: figure dash, en dash, em dash, horizontal bar. Дефис-минус не трогаем. */
    private const DASHES = '/[\x{2012}-\x{2015}]/u';

    /**
     * Убрать из текста модели то, что запрещено правилами проекта.
     */
    public static function clean(string $text): string {
        // Жесткий перенос markdown - это ДВА пробела в конце строки, и учебный текст ложится в
        // страницу как FORMAT_MARKDOWN, то есть перенос смысловой. Запоминаем такие строки ДО
        // вырезания эмодзи: после него «текст (эмодзи)» тоже кончается пробелом, но это след
        // вырезания, а не авторский перенос, и путать их нельзя.
        //
        // Замер 2026-08-27 на пяти уроках: 5 переносов на 219 строк, в двух уроках из пяти.
        // Съедали их обе чистки ниже - схлопывание раньше, обрезка следом.
        $hard = self::hard_break_lines($text);

        $text = preg_replace(self::EMOJI, '', $text) ?? $text;
        $text = preg_replace(self::DASHES, '-', $text) ?? $text;

        // Дыры от вырезанных эмодзи. Отступ в НАЧАЛЕ строки не трогаем - это markdown-вложенность
        // и блоки кода, поэтому схлопываем только пробелы после непробельного символа.
        $text = preg_replace('/(?<=\S)\h{2,}/u', ' ', $text) ?? $text;

        // Хвостовые пробелы в конце строк - тоже след вырезания.
        $text = preg_replace('/\h+$/mu', '', $text) ?? $text;

        // Перенос в самом конце текста бессмыслен и снимается общим trim ниже.
        return trim(self::restore_hard_breaks($text, $hard));
    }

    /**
     * Строки, кончающиеся жестким переносом markdown (два и более пробела после текста).
     *
     * Считать надо ДО любых чисток: «текст(пробел)(пробел)(эмодзи)» после вырезания эмодзи тоже
     * кончается двумя пробелами, но это дыра, а не авторский перенос.
     *
     * @return array<int,true> индексы в разбиении split_keeping_breaks()
     */
    private static function hard_break_lines(string $text): array {
        $hard = [];
        foreach (self::split_keeping_breaks($text) as $i => $part) {
            // Один пробел переносом не является: markdown требует двух.
            if ($i % 2 === 0 && preg_match('/\S\h{2,}$/u', $part)) {
                $hard[$i] = true;
            }
        }
        return $hard;
    }

    /**
     * Вернуть жесткие переносы, сбитые чисткой пробелов.
     *
     * Переводы строк собираются обратно ТЕМИ ЖЕ: подмена CRLF на LF была бы правкой, о которой
     * никто не просил.
     *
     * @param array<int,true> $hard результат hard_break_lines() на тексте ДО чистки
     */
    private static function restore_hard_breaks(string $text, array $hard): string {
        if (!$hard) {
            return $text;
        }
        $parts = self::split_keeping_breaks($text);
        foreach ($parts as $i => $part) {
            if (!empty($hard[$i]) && trim($part) !== '') {
                // Сперва СРЕЗАЕМ хвост, потом ставим ровно два. Обрезка пробелов в конце строки
                // ловит их только перед одиночным переводом строки: перед парой CR+LF они
                // оставались, и приписка дала бы три пробела вместо двух.
                $parts[$i] = rtrim($part, " 	") . '  ';
            }
        }
        return implode('', $parts);
    }

    /**
     * Разбить текст на строки, СОХРАНИВ сами переводы строк отдельными элементами.
     *
     * Четные индексы - строки, нечетные - разделители. Обратная сборка через implode('') дает
     * ровно исходный текст, чем бы строки ни разделялись.
     *
     * @return string[]
     */
    private static function split_keeping_breaks(string $text): array {
        return preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    }

    /** Символы дробей Unicode в привычную ребенку запись. */
    private const VULGAR_FRACTIONS = [
        "\u{00BD}" => '1/2', "\u{2153}" => '1/3', "\u{2154}" => '2/3',
        "\u{00BC}" => '1/4', "\u{00BE}" => '3/4',
        "\u{2155}" => '1/5', "\u{2156}" => '2/5', "\u{2157}" => '3/5', "\u{2158}" => '4/5',
        "\u{2159}" => '1/6', "\u{215A}" => '5/6',
        "\u{2150}" => '1/7', "\u{215B}" => '1/8', "\u{215C}" => '3/8',
        "\u{215D}" => '5/8', "\u{215E}" => '7/8',
        "\u{2151}" => '1/9', "\u{2152}" => '1/10',
    ];

    /**
     * Убрать математическую разметку: промт ее запрещает, но модель ее шлет.
     *
     * Живой заход 2026-08-20: двенадцать заданий из пятнадцати содержали LaTeX, и ребенок видел
     * «$ \frac{4}{7} $» вместо дроби. Дробь превращаем в привычную запись «4/7», знаки операций -
     * в школьные, разделители формул убираем.
     */
    public static function strip_math_markup(string $text): string {
        // Жесткие переносы markdown сбивает и здешнее схлопывание пробелов - живой заход
        // 2026-08-27 показал, что после починки одного лишь clean() до страницы урока не дожил
        // НИ ОДИН перенос из трех. Правило одно, мест два, и знать о нем обязаны оба.
        $hard = self::hard_break_lines($text);

        // Блоки кода не трогаем: там обратный слэш законен, и «чистка» превратила бы пример на
        // Python в кашу ([[code-fence-and-math-design]]).
        $out = self::map_outside_code($text, static function (string $chunk): string {
            return self::clean_math($chunk);
        });

        return self::restore_hard_breaks($out, $hard);
    }

    /**
     * Знакомые команды LaTeX в школьные символы.
     *
     * Раньше словарь состоял из четырех записей, а все прочее оставалось в тексте урока: живая
     * генерация 2026-08-24 вернула «$$H_2O \rightarrow H_2O(пар)$$» - доллары снимались, команда
     * оставалась ([[code-fence-and-math-design]]).
     */
    private const MATH_COMMANDS = [
        '\\rightarrow' => '→', '\\to' => '→', '\\leftarrow' => '←', '\\Rightarrow' => '⇒',
        '\\leq' => '≤', '\\le' => '≤', '\\geq' => '≥', '\\ge' => '≥', '\\neq' => '≠',
        '\\approx' => '≈', '\\pm' => '±', '\\mp' => '∓', '\\infty' => '∞',
        '\\sqrt' => '√', '\\sum' => '∑', '\\degree' => '°', '\\circ' => '°',
        '\\alpha' => 'α', '\\beta' => 'β', '\\gamma' => 'γ', '\\pi' => 'π',
        '\\Delta' => 'Δ', '\\delta' => 'δ', '\\lambda' => 'λ', '\\mu' => 'μ', '\\omega' => 'ω',
        '\\cdot' => '×', '\\times' => '×', '\\div' => ':',
    ];

    private static function clean_math(string $text): string {
        // Символы дробей - обход запрета на LaTeX, а не украшение: живая генерация 2026-08-21
        // вернула «⅓ + ⅙ = ?» с вариантами «½», «⅔». Верификатор такие числа не понимал, и
        // ВЕРНЫЕ задания отбрасывались как безответные.
        // Смешанное число «1½» без разделителя слиплось бы в «11/2» - вдесятеро больше. Разводим
        // ПРИ подстановке, по самому символу дроби.
        //
        // Раньше это делалось общим правилом «цифра + дробь» ПОСЛЕ подстановки, и оно ломало
        // обычные дроби: «11/15» превращалось в «1 1/15», «25/100» - в «2 5/100». То есть в уроке
        // про дроби ребенок читал неверный ответ. Найдено 2026-08-25 проверкой чистки на реальных
        // страницах стенда: из 27 страниц изменились 13, и половина изменений была порчей
        // ([[code-fence-and-math-design]]).
        $out = $text;
        foreach (self::VULGAR_FRACTIONS as $glyph => $plain) {
            $out = preg_replace('~(\d)\s*' . preg_quote($glyph, '~') . '~u', '$1 ' . $plain, $out)
                ?? $out;
        }
        $out = strtr($out, self::VULGAR_FRACTIONS);
        $out = preg_replace('/\\\\[dt]?frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/u', '$1/$2', $out) ?? $out;

        // Длинные имена вперед коротких: иначе «\leq» съелся бы правилом «\le» и оставил хвост
        // «q». strtr берет самое длинное совпадение сам, но порядок в массиве держим явным.
        $out = strtr($out, self::MATH_COMMANDS);

        $out = str_replace(['\\(', '\\)', '\\[', '\\]', '$'], '', $out);
        // Все, что осталось от LaTeX, - незнакомые команды. Оставлять их нельзя: «rightarrow»
        // без слэша такой же мусор, как со слэшем, а ребенок читает это в тексте урока.
        $out = preg_replace('/\\\\[a-zA-Z]+\s*/u', '', $out) ?? $out;
        // Схлопываем пробелы, оставшиеся от снятых разделителей.
        $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
        return trim($out);
    }

    /**
     * Применить преобразование ко всему тексту, КРОМЕ блоков кода в ограждениях ```.
     *
     * Тот же обход, что в shift_headings(), но пригодный для повторного использования: решетка и
     * обратный слэш внутри блока кода - часть материала, а не разметка модели.
     */
    public static function map_outside_code(string $text, callable $fn): string {
        $lines = preg_split('/\R/u', $text);
        if ($lines === false) {
            return $fn($text);
        }

        $out = [];
        $buffer = [];
        $in_fence = false;
        $flush = static function () use (&$buffer, &$out, $fn): void {
            if ($buffer) {
                $out[] = $fn(implode("\n", $buffer));
                $buffer = [];
            }
        };
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/u', $line)) {
                $flush();
                $out[] = $line;
                $in_fence = !$in_fence;
                continue;
            }
            if ($in_fence) {
                $out[] = $line;
                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        return implode("\n", $out);
    }

    /**
     * Номера строк markdown, лежащих ВНЕ блоков кода.
     *
     * @return array<int, bool> номер строки => true, если строка вне ограждения
     */
    public static function lines_outside_code(string $text): array {
        $lines = preg_split('/\R/u', $text);
        $map = [];
        $in_fence = false;
        foreach ((array)$lines as $i => $line) {
            if (preg_match('/^\s*```/u', (string)$line)) {
                $in_fence = !$in_fence;
                $map[$i] = false;
                continue;
            }
            $map[$i] = !$in_fence;
        }
        return $map;
    }

    /**
     * Сдвинуть уровни заголовков так, чтобы минимальный стал «####».
     *
     * Именно сдвиг, а не выравнивание всех заголовков в один уровень: относительная структура
     * разделов сохраняется, иначе программа экранного доступа потеряет вложенность вместо того,
     * чтобы ее обрести.
     */
    public static function shift_headings(string $text): string {
        $lines = preg_split('/\R/u', $text);
        if ($lines === false) {
            return $text;
        }

        // Решетка внутри блока кода - комментарий, а не заголовок. Без этой проверки одна
        // строка «# считаем сумму» в примере на Python задирала минимум до первого уровня,
        // перекашивала весь сдвиг и портила сам код (найдено ревью 2026-08-07).
        $min      = 7;
        $in_fence = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/u', $line)) {
                $in_fence = !$in_fence;
                continue;
            }
            if (!$in_fence && preg_match('/^(#{1,6})\h+/u', $line, $m)) {
                $min = min($min, strlen($m[1]));
            }
        }

        $delta = 4 - $min;
        if ($min === 7 || $delta === 0) {
            return $text;
        }

        $in_fence = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*```/u', $line)) {
                $in_fence = !$in_fence;
                continue;
            }
            if ($in_fence) {
                continue;
            }
            $lines[$i] = preg_replace_callback('/^(#{1,6})(\h+)/u',
                static function (array $m) use ($delta): string {
                    $level = min(6, max(1, strlen($m[1]) + $delta));
                    return str_repeat('#', $level) . $m[2];
                }, $line) ?? $line;
        }

        return implode("\n", $lines);
    }
}
