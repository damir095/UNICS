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
        // Смешанное число «1 1/2» - это три вторых, а не «11/2». Раньше внутренние пробелы
        // удалялись, и неверный ключ признавался равным правильному ответу; потом пробелы
        // перестали удаляться вовсе, и годные ответы «3 / 8» и «1 1/2» стали нечитаемыми.
        if (preg_match('~^(-?\d+)\s+(\d+)\s*/\s*(\d+)$~u', $s, $m)) {
            $den = (int)$m[3];
            if ($den === 0) {
                return null;
            }
            $whole = (int)$m[1];
            $sign = $whole < 0 ? -1 : 1;
            return [$sign * (abs($whole) * $den + (int)$m[2]), $den];
        }
        // Пробелы вокруг косой не меняют дроби: «3 / 8» это те же три восьмых.
        $s = preg_replace('~\s*/\s*~u', '/', $s) ?? $s;
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
        // Словесное действие - последний и самый шаткий источник: слово в тексте может значить
        // что угодно («сложная задача»), поэтому явное решение модели идет вперед него.
        if ($value === null) {
            $value = self::worded($text);
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
        // Шаблоны, а не голые подстроки: «раздел» встречается в «в разделе учебника», «частно»
        // в «в частности», «слож» в «сложная задача». На всех трех верификатор сочинял значение
        // и портил верный ключ (найдено ревью 2026-08-21).
        '~произведени|перемнож|умнож(?:ить|им|ается|ением)~u' => '*',
        '~сумм[аеуы]|сложит|сложени|прибав~u' => '+',
        '~разност|вычест|вычти|вычтите|отним~u' => '-',
        '~частное|частного|раздели|делени~u' => '/',
    ];

    /** Слова, после которых вопрос спрашивает ОБРАТНОЕ: ключ там заведомо ложный. */
    private const NEGATIONS = ['неверн', 'не верн', 'ошибочн', 'ложн', 'не является',
        'не соответств', 'кроме', 'исключен'];

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
        $answers = array_values($answers);
        // «Наибольший общий делитель» содержит «больш», но сравнением не является: слово должно
        // стоять отдельно (ревью 2026-08-21).
        // «Насколько 5/6 больше 1/6» спрашивает РАЗНОСТЬ, а не какая дробь больше: без
        // этой проверки ключ уезжал с верного ответа на сам операнд (ревью 2026-08-21).
        $about_difference = (bool)preg_match('~на\s?сколько|насколько~u', $lower);
        $asks_more = !$about_difference && mb_strpos($lower, 'больше') !== false;
        $asks_less = !$about_difference && mb_strpos($lower, 'меньше') !== false;
        // Вопрос опознается и по ФОРМЕ ВАРИАНТОВ: живая генерация дала «Найдите верное
        // утверждение: 5/9 и 5/8», где слова-подсказки нет вовсе, зато варианты - неравенства.
        $inequalities = self::inequality_answers($answers);
        if (mb_strpos($lower, 'сравн') === false && !$asks_more && !$asks_less && $inequalities < 2) {
            return null;
        }
        foreach (self::NEGATIONS as $negation) {
            if (mb_strpos($lower, $negation) !== false) {
                return null; // вопрос спрашивает обратное, ключ там заведомо ложный
            }
        }

        if ($inequalities >= 2) {
            // Каждое неравенство проверяется САМО ПО СЕБЕ, а не по порядку чисел в вопросе:
            // «5/6 > 2/3» истинно независимо от того, как пара названа в тексте. Прежний способ
            // (знак из текста, поиск варианта по знаку) объявлял верным ложное утверждение.
            return self::pick($answers, $correct, static function (string $answer): bool {
                return self::inequality_is_true($answer);
            });
        }

        // Ответ - сама дробь: большая или меньшая из двух названных в тексте.
        if (!$asks_more && !$asks_less) {
            return null;
        }
        if (!preg_match_all('~' . self::NUM . '~u', $text, $m) || count($m[0]) !== 2) {
            return null;
        }
        $a = self::rational($m[0][0]);
        $b = self::rational($m[0][1]);
        if (!$a || !$b) {
            return null;
        }
        $diff = $a[0] * $b[1] - $b[0] * $a[1];
        if ($diff === 0) {
            return null; // значения равны, и защитимого ответа на «какая больше» нет
        }
        $want = $asks_more ? ($diff > 0 ? $a : $b) : ($diff > 0 ? $b : $a);
        return self::pick($answers, $correct, static function (string $answer) use ($want): bool {
            return self::equals(self::rational($answer), $want);
        });
    }

    /**
     * Выбор варианта с проверкой ключа модели ПЕРВЫМ и молчанием при неоднозначности.
     *
     * Общий гейт для распознавателей: без него каждый новый путь заново терял правило «верный
     * ключ не трогаем» и объявлял исправленным то, что и так сходилось (ревью 2026-08-21).
     *
     * @param callable $matches проверка одного варианта
     * @return array|null вердикт или null, если подходящего варианта нет либо их несколько
     */
    private static function pick(array $answers, int $correct, callable $matches): ?array {
        if (isset($answers[$correct]) && $matches((string)$answers[$correct])) {
            return ['verdict' => 'ok', 'correct' => $correct];
        }
        $found = null;
        foreach ($answers as $i => $answer) {
            if (!$matches((string)$answer)) {
                continue;
            }
            if ($found !== null) {
                return null; // подходит больше одного варианта - вопрос неоднозначен
            }
            $found = $i;
        }
        return $found === null ? null : ['verdict' => 'fixed', 'correct' => $found];
    }

    /** Истинно ли неравенство, записанное в варианте ответа («5/6 > 2/3»). */
    private static function inequality_is_true(string $answer): bool {
        if (!preg_match('~(' . self::NUM . ')\s*([<>=])\s*(' . self::NUM . ')~u', $answer, $m)) {
            return false;
        }
        $a = self::rational($m[1]);
        $b = self::rational($m[3]);
        if (!$a || !$b) {
            return false;
        }
        $diff = $a[0] * $b[1] - $b[0] * $a[1];
        switch ($m[2]) {
            case '>':
                return $diff > 0;
            case '<':
                return $diff < 0;
            default:
                return $diff === 0;
        }
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

        // Через общий гейт: ключ модели проверяется первым, а при нескольких годных кратных
        // («18» и «36» оба общие) верификатор молчит вместо бессмысленной правки.
        return self::pick(array_values($answers), $correct,
            static function (string $answer) use ($least, $lcm, $x, $y): bool {
                $n = self::rational($answer);
                if (!$n || $n[1] !== 1 || $n[0] <= 0) {
                    return false; // знаменатель - целое положительное число
                }
                return $least ? ($n[0] === $lcm) : ($n[0] % $x === 0 && $n[0] % $y === 0);
            });
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
        foreach (self::WORD_OPS as $pattern => $sign) {
            if (preg_match($pattern, $lower)) {
                if ($op !== null && $op !== $sign) {
                    return null; // два разных действия в одном тексте - не наш случай
                }
                $op = $sign;
            }
        }
        if ($op === null) {
            return null;
        }
        if (!preg_match_all('~' . self::NUM . '~u', $text, $m, PREG_OFFSET_CAPTURE)
                || count($m[0]) !== 2) {
            return null;
        }
        $first = self::rational($m[0][0][0]);
        $second = self::rational($m[0][1][0]);
        if (!$first || !$second) {
            return null;
        }
        // «Вычтите 1/4 из 3/4» и «отнимите 1/4 от 3/4»: уменьшаемое названо ВТОРЫМ, и без этой
        // проверки верификатор выдавал -1/2 вместо 1/2 (ревью 2026-08-21).
        // Смещения от preg_match БАЙТОВЫЕ, поэтому режем байтовой substr: mb_substr считает
        // символы и на кириллице давала кусок не с того места.
        $between = substr($text, $m[0][0][1] + strlen($m[0][0][0]),
            $m[0][1][1] - $m[0][0][1] - strlen($m[0][0][0]));
        $swapped = in_array($op, ['-', '/'], true)
            && preg_match('~(?:^|\s)(?:из|от)(?:\s|$)~u', mb_strtolower($between));
        $left = $swapped ? $second : $first;
        $right = $swapped ? $first : $second;
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
