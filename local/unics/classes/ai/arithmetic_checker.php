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
        $value = self::expression($text);
        if ($value === null && $solution !== '') {
            // Решение модели - цепочка вида «x = 1/2 + 1/4 = 2/4 + 1/4 = 3/4». Смотрим КАЖДУЮ
            // часть: слева от первого равенства часто стоит «x», а вычисление идет дальше.
            foreach (explode('=', $solution) as $part) {
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

    /**
     * Найти в тексте «операнд знак операнд» и вычислить.
     *
     * @return array|null пара [числитель, знаменатель] или null, если считать нечего
     */
    public static function expression(string $text): ?array {
        // Разделитель регулярки - тильда, а не косая: косая сама входит в список знаков
        // операций, и preg_quote экранировал бы ее, ломая шаблон.
        $ops = implode('', array_map(static function (string $o): string {
            return preg_quote($o, '~');
        }, array_keys(self::OPS)));
        $num = '-?\d+(?:/\d+|[.,]\d+)?';
        $re = '~(' . $num . ')\s*([' . $ops . '])\s*(' . $num . ')~u';
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
        if (preg_match('~^\s*[' . $ops . ']\s*' . $num . '~u', $tail)) {
            return null;
        }

        $left = self::rational($m[0][1]);
        $right = self::rational($m[0][3]);
        if (!$left || !$right) {
            return null;
        }
        switch (self::OPS[$m[0][2]]) {
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
