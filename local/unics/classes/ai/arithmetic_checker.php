<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Проверка арифметики в сгенерированных заданиях ([[quiz-answer-verification-design]]).
 *
 * Зонд 2026-08-20: из десяти заданий по сложению дробей ШЕСТЬ имели неверный ключ - модель
 * складывает знаменатели («4/7 + 3/7 = 7/10»). Ребенок с верным ответом получал «неверно», а
 * калибровка IRT измеряла трудность по ошибочному ключу.
 *
 * Класс намеренно узкий: одно выражение «операнд знак операнд». Все шесть промахов зонда были
 * ровно такими, а неверный вердикт верификатора хуже отсутствующего.
 *
 * Числа - пары [числитель, знаменатель]. Сравнение кросс-умножением, без плавающей точки: иначе
 * 1/3 не совпал бы сам с собой после округления, а 2/8 не опознался бы как 1/4.
 *
 * @package local_unics
 */
class arithmetic_checker {

    /**
     * Знаки, которыми модель записывает операции.
     *
     * Косой черты здесь НЕТ намеренно: она уже занята записью дроби, и «5/6» неотличимо от
     * деления «5 разделить на 6». Из-за этого вопрос «какие дроби сложить, чтобы получить 5/6»
     * считался выражением. Деление записывается двоеточием или знаком деления - так его и пишут
     * в школьных задачах.
     */
    private const OPS = [
        '+' => '+',
        '-' => '-', "\u{2212}" => '-', "\u{2013}" => '-',
        '*' => '*', "\u{00D7}" => '*', "\u{00B7}" => '*',
        ':' => '/', "\u{00F7}" => '/',
    ];

    /**
     * Число из строки: дробь «3/8», целое «12», десятичное «0,5».
     *
     * @return array|null пара [числитель, знаменатель] или null
     */
    public static function rational(string $s): ?array {
        $s = trim(str_replace(["\u{00A0}", ' '], '', $s));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^-?\d+\/\d+$/', $s)) {
            list($n, $d) = explode('/', $s);
            return (int)$d === 0 ? null : [(int)$n, (int)$d];
        }
        if (preg_match('/^-?\d+$/', $s)) {
            return [(int)$s, 1];
        }
        if (preg_match('/^-?\d+[.,]\d+$/', $s)) {
            $parts = preg_split('/[.,]/', $s);
            $scale = 10 ** strlen($parts[1]);
            $sign = strpos($s, '-') === 0 ? -1 : 1;
            return [$sign * (abs((int)$parts[0]) * $scale + (int)$parts[1]), $scale];
        }
        return null;
    }

    /** Равенство рациональных чисел кросс-умножением. */
    public static function equals(?array $a, ?array $b): bool {
        if (!$a || !$b) {
            return false;
        }
        return $a[0] * $b[1] === $b[0] * $a[1];
    }

    /**
     * Вердикт по заданию: сошелся ли ключ модели с нашим расчетом.
     *
     * @param string $text текст вопроса
     * @param array $answers варианты ответа (строки)
     * @param int $correct индекс варианта, объявленного моделью верным
     * @param string $solution поле решения от модели - запасной источник выражения
     * @return array{verdict: string, correct: int} verdict: ok, fixed, unverifiable или drop
     */
    public static function verdict(string $text, array $answers, int $correct,
                                   string $solution = ''): array {
        $value = self::sole_expression($text);
        if ($value === null && $solution !== '') {
            // Решение модели - цепочка вида «3 + 4 = 7, периметр 7 × 2 = 14». Ответ дает
            // ПОСЛЕДНИЙ вычислимый шаг: на первом стоит промежуточный результат, и ключ уезжал
            // с верного «14» на «7» (найдено ревью 2026-08-21).
            foreach (array_reverse(explode('=', $solution)) as $part) {
                $value = self::expression($part);
                if ($value !== null) {
                    break;
                }
            }
        }
        if ($value === null) {
            return ['verdict' => 'unverifiable', 'correct' => $correct];
        }
        $answers = array_values($answers);
        // Ни один вариант не число - значит найденное «выражение» скорее всего не про ответ
        // («Сколько будет 2 + 3 яблок?» с вариантами «5 яблок»). Молчание тут безвредно, а
        // отбраковка стоила бы ребенку годного вопроса.
        $numeric = false;
        foreach ($answers as $answer) {
            if (self::rational((string)$answer) !== null) {
                $numeric = true;
                break;
            }
        }
        if (!$numeric) {
            return ['verdict' => 'unverifiable', 'correct' => $correct];
        }
        // Ключ модели проверяем ПЕРВЫМ. Иначе задание с двумя равными вариантами («4/8» и
        // «1/2») объявлялось бы исправленным, и ключ переезжал бы с верного варианта на
        // первый совпавший - бессмысленная правка вместо честного «сошлось».
        if (isset($answers[$correct]) && self::equals(self::rational((string)$answers[$correct]), $value)) {
            return ['verdict' => 'ok', 'correct' => $correct];
        }
        foreach ($answers as $i => $answer) {
            if (self::equals(self::rational((string)$answer), $value)) {
                return ['verdict' => 'fixed', 'correct' => $i];
            }
        }
        return ['verdict' => 'drop', 'correct' => $correct];
    }

    /** Шаблон операнда: дробь, целое, десятичное. */
    private const NUM = '-?\d+(?:/\d+|[.,]\d+)?';

    /**
     * Класс знаков, которые можно писать слитно с числами: «1/3+1/6» модель пишет постоянно.
     *
     * Разделитель регулярок в классе - тильда: косая входит в запись дроби, и preg_quote
     * экранировал бы ее, ломая шаблон с разделителем «косая».
     */
    private static function ops_class(): string {
        return implode('', array_map(static function (string $o): string {
            return preg_quote($o, '~');
        }, ['+', '*', "\u{00D7}", "\u{00B7}", "\u{00F7}"]));
    }

    /**
     * Шаблон выражения «операнд знак операнд».
     *
     * Минус и двоеточие требуют пробелов с ОБЕИХ сторон, потому что слитно они означают совсем
     * другое: «1941-1945» - диапазон лет, «10:30» - время, «2:3» - отношение. Раньше такие
     * вопросы объявлялись вычислительными и удалялись из теста (найдено ревью 2026-08-21).
     */
    private static function expression_regex(): string {
        $num = self::NUM;
        $tight = '[' . self::ops_class() . ']';
        $spaced = '(?:[-' . preg_quote("\u{2212}\u{2013}", '~') . ':]|' . $tight . ')';
        return '~(' . $num . ')(?:\s*(' . $tight . ')\s*|\s+(' . $spaced . ')\s+)(' . $num . ')~u';
    }

    /**
     * Выражение вопроса - но только если оно ЕДИНСТВЕННОЕ, что есть в тексте из чисел.
     *
     * «У Маши было 3 + 2 конфеты, она съела 1» - тут «3 + 2» не ответ, а условие: после
     * выражения осталось число 1, и вопрос спрашивает про другое. Раньше верификатор считал
     * такой текст вычислительным и переставлял ключ с верного «4» на «5», то есть САМ сочинял
     * неверный ключ (найдено ревью 2026-08-21).
     *
     * @return array|null пара [числитель, знаменатель] или null
     */
    private static function sole_expression(string $text): ?array {
        $value = self::expression($text);
        if ($value === null) {
            return null;
        }
        $rest = self::without_first_expression($text);
        return preg_match('~\d~u', $rest) ? null : $value;
    }

    /** Текст без первого найденного выражения - для проверки, что других чисел не осталось. */
    private static function without_first_expression(string $text): string {
        $re = self::expression_regex();
        return preg_match($re, $text, $m) ? str_replace($m[0], ' ', $text) : $text;
    }

    /**
     * Найти в тексте «операнд знак операнд» и вычислить.
     *
     * @return array|null пара [числитель, знаменатель] или null, если считать нечего
     */
    public static function expression(string $text): ?array {
        $ops = self::ops_class();
        $num = self::NUM;
        $re = self::expression_regex();
        if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        if (count($m) > 1) {
            return null; // цепочка операций: приоритет и скобки вне охвата
        }
        // Хвост вида «+ 1/8» сразу за найденным выражением тоже делает его цепочкой: второе
        // совпадение регулярка не находит, потому что операнд уже съеден первым.
        $pos = strpos($text, $m[0][0]);
        $tail = $pos === false ? '' : substr($text, $pos + strlen($m[0][0]));
        if (preg_match('~^\s*(?:[' . $ops . ']|\s[-:]\s)\s*' . $num . '~u', $tail)) {
            return null;
        }

        // Знак приходит либо слитной группой, либо группой «с пробелами» - смотрим непустую.
        $sign = $m[0][2] !== '' ? $m[0][2] : ($m[0][3] ?? '');
        $left = self::rational($m[0][1]);
        $right = self::rational($m[0][4] ?? '');
        if (!$left || !$right || !isset(self::OPS[$sign])) {
            return null;
        }
        switch (self::OPS[$sign]) {
            case '+':
                return [$left[0] * $right[1] + $right[0] * $left[1], $left[1] * $right[1]];
            case '-':
                return [$left[0] * $right[1] - $right[0] * $left[1], $left[1] * $right[1]];
            case '*':
                return [$left[0] * $right[0], $left[1] * $right[1]];
            case '/':
                return $right[0] === 0 ? null : [$left[0] * $right[1], $left[1] * $right[0]];
        }
        return null;
    }
}
