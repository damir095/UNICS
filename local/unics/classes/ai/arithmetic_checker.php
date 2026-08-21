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
        // Пробелы снимаются только по краям. Внутренние убирать нельзя: смешанное число
        // «1 2/63» склеивалось в «12/63» и признавалось равным правильному ответу, из-за чего
        // неверный ключ модели проходил как верный (найдено на задании зонда 2026-08-21).
        $s = trim(str_replace("\u{00A0}", ' ', $s));
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
        // Сравнение и общий знаменатель отвечают не числом, поэтому идут своим путем.
        $special = self::compare_verdict($text, $answers, $correct)
            ?? self::denominator_verdict($text, $answers, $correct);
        if ($special !== null) {
            return $special;
        }

        $value = self::sole_expression($text) ?? self::worded($text);
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
     * Словесные операции: как модель называет действие и что оно значит.
     *
     * Зонд 2026-08-21 показал, что модель почти не пишет выражений: «перемножить дроби 1/3 и
     * 1/5», «найдите произведение», «сравните дроби». Проверка молчала на девяти промахах из
     * девяти, потому что искала только «операнд знак операнд».
     */
    private const WORD_OPS = [
        'произведени' => '*', 'перемнож' => '*', 'умнож' => '*',
        'сумм' => '+', 'слож' => '+', 'прибав' => '+',
        'разност' => '-', 'вычест' => '-', 'вычт' => '-', 'отним' => '-',
        'частно' => '/', 'раздел' => '/', 'делен' => '/',
    ];

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
     * Вопрос на сравнение двух дробей. Ответ бывает двух видов, оба встречались в зонде:
     * неравенство целиком («2/3 < 5/6») и сама дробь на вопрос «какая больше».
     *
     * @return array|null вердикт или null, если это не сравнение
     */
    private static function compare_verdict(string $text, array $answers, int $correct): ?array {
        $lower = mb_strtolower($text);
        $asks_more = mb_strpos($lower, 'больше') !== false || mb_strpos($lower, 'больш') !== false;
        $asks_less = mb_strpos($lower, 'меньше') !== false || mb_strpos($lower, 'меньш') !== false;
        // Вопрос опознается и по ФОРМЕ ВАРИАНТОВ, а не только по словам: живая генерация
        // 2026-08-21 дала «Найдите верное утверждение: 5/9 и 5/8» - слова-подсказки нет вовсе,
        // зато все варианты являются неравенствами.
        $by_answers = self::inequality_answers(array_values($answers)) >= 2;
        if (mb_strpos($lower, 'сравн') === false && !$asks_more && !$asks_less && !$by_answers) {
            return null;
        }
        // Вопрос с отрицанием спрашивает ОБРАТНОЕ: «укажите неверное сравнение 2/3 и 3/4» -
        // ключом там стоит заведомо ложное утверждение, и наша правка испортила бы верный ключ.
        // Живая генерация 2026-08-21 такой вопрос выдала.
        foreach (['неверн', 'не верн', 'ошибочн', 'не является', 'кроме'] as $negation) {
            if (mb_strpos($lower, $negation) !== false) {
                return null;
            }
        }

        // Пару чисел берем из ВАРИАНТОВ, если в тексте их больше двух: модель порой вкладывает
        // варианты прямо в текст вопроса, и тогда счет чисел в нем ничего не значит.
        list($a, $b) = self::compared_pair($text, array_values($answers));
        if (!$a || !$b) {
            return null;
        }
        $diff = $a[0] * $b[1] - $b[0] * $a[1];
        $sign = $diff > 0 ? '>' : ($diff < 0 ? '<' : '=');

        $answers = array_values($answers);
        $want = null;
        if (($asks_more || $asks_less) && !$by_answers) {
            // Ответ - сама дробь: большая или меньшая из названных. Но если варианты записаны
            // неравенствами, спрашивают все равно про знак: «выберите большую» с вариантами
            // «2/3 > 3/4» и «2/3 < 3/4» - это сравнение, а не выбор дроби.
            $bigger = $diff > 0 ? $a : $b;
            $want = $asks_more ? $bigger : ($diff > 0 ? $b : $a);
        }
        $found = null;
        foreach ($answers as $i => $answer) {
            $ok = $want !== null
                ? self::equals(self::rational((string)$answer), $want)
                : self::inequality_sign((string)$answer) === $sign;
            if ($ok) {
                $found = $i;
                break;
            }
        }
        if ($found === null) {
            return null; // ни один вариант не опознан - молчим, а не отбраковываем
        }
        return ['verdict' => $found === $correct ? 'ok' : 'fixed', 'correct' => $found];
    }

    /**
     * Пара сравниваемых чисел: из текста, а если там их не ровно два - из вариантов-неравенств.
     *
     * Все варианты обязаны сравнивать ОДНУ И ТУ ЖЕ пару, иначе неизвестно, о чем вопрос.
     *
     * @return array{0: ?array, 1: ?array}
     */
    private static function compared_pair(string $text, array $answers): array {
        if (preg_match_all('~' . self::NUM . '~u', $text, $m) && count($m[0]) === 2) {
            return [self::rational($m[0][0]), self::rational($m[0][1])];
        }
        $pair = null;
        foreach ($answers as $answer) {
            if (!preg_match('~(' . self::NUM . ')\s*[<>=]\s*(' . self::NUM . ')~u', (string)$answer, $mm)) {
                continue;
            }
            $current = [self::rational($mm[1]), self::rational($mm[2])];
            if (!$current[0] || !$current[1]) {
                return [null, null];
            }
            if ($pair === null) {
                $pair = $current;
                continue;
            }
            if (!self::equals($pair[0], $current[0]) || !self::equals($pair[1], $current[1])) {
                return [null, null]; // варианты про разные пары - не наш случай
            }
        }
        return $pair ?? [null, null];
    }

    /** Сколько вариантов записаны неравенствами: по этому и опознается вопрос на сравнение. */
    private static function inequality_answers(array $answers): int {
        $n = 0;
        foreach ($answers as $answer) {
            if (self::inequality_sign((string)$answer) !== '') {
                $n++;
            }
        }
        return $n;
    }

    /** Знак неравенства в варианте ответа вида «2/3 < 5/6», или '' если его там нет. */
    private static function inequality_sign(string $answer): string {
        if (!preg_match('~(' . self::NUM . ')\s*([<>=])\s*(' . self::NUM . ')~u', $answer, $m)) {
            return '';
        }
        return $m[2];
    }

    /**
     * Вопрос про общий знаменатель. «Наименьший» требует ровно НОК, просто «общий» - любое
     * общее кратное. Промах зонда «общий знаменатель 2/3 и 4/5 = 10» ловится вторым правилом:
     * десять не кратно трем.
     *
     * @return array|null вердикт или null, если это не про знаменатель
     */
    private static function denominator_verdict(string $text, array $answers, int $correct): ?array {
        $lower = mb_strtolower($text);
        if (mb_strpos($lower, 'знаменател') === false || mb_strpos($lower, 'общ') === false) {
            return null;
        }
        if (!preg_match_all('~\d+/(\d+)~u', $text, $m) || count($m[1]) !== 2) {
            return null;
        }
        $x = (int)$m[1][0];
        $y = (int)$m[1][1];
        if ($x <= 0 || $y <= 0) {
            return null;
        }
        $lcm = intdiv($x, self::gcd($x, $y)) * $y;
        $least = mb_strpos($lower, 'наименьш') !== false;

        $found = null;
        foreach (array_values($answers) as $i => $answer) {
            $n = self::rational((string)$answer);
            if (!$n || $n[1] !== 1) {
                continue; // знаменатель - целое число
            }
            $fits = $least ? ($n[0] === $lcm) : ($n[0] > 0 && $n[0] % $x === 0 && $n[0] % $y === 0);
            if ($fits) {
                $found = $i;
                break;
            }
        }
        if ($found === null) {
            return null;
        }
        return ['verdict' => $found === $correct ? 'ok' : 'fixed', 'correct' => $found];
    }

    /** Наибольший общий делитель - для наименьшего общего кратного. */
    private static function gcd(int $a, int $b): int {
        while ($b !== 0) {
            list($a, $b) = [$b, $a % $b];
        }
        return abs($a);
    }

    /**
     * Действие, названное СЛОВОМ: «перемножить дроби 1/3 и 1/5», «произведение 3/4 и 5/6».
     *
     * Операнды берутся по порядку появления, и порядок несущий: «из 3/4 вычесть 1/4» это
     * 3/4 - 1/4, а не наоборот. Требуется ровно два числа в тексте - иначе неизвестно, какие
     * из них участвуют в действии.
     *
     * @return array|null пара [числитель, знаменатель] или null
     */
    private static function worded(string $text): ?array {
        $lower = mb_strtolower($text);
        $op = null;
        foreach (self::WORD_OPS as $word => $sign) {
            if (mb_strpos($lower, $word) !== false) {
                if ($op !== null && $op !== $sign) {
                    return null; // два разных действия в одном тексте - не наш случай
                }
                $op = $sign;
            }
        }
        if ($op === null) {
            return null;
        }
        if (!preg_match_all('~' . self::NUM . '~u', $text, $m) || count($m[0]) !== 2) {
            return null;
        }
        $left = self::rational($m[0][0]);
        $right = self::rational($m[0][1]);
        if (!$left || !$right) {
            return null;
        }
        switch ($op) {
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
