<?php
namespace local_unics;

use local_unics\ai\arithmetic_checker;

/**
 * Разбор чисел и вычисление выражений ([[quiz-answer-verification-design]]).
 *
 * @package local_unics
 */
final class arithmetic_checker_test extends \advanced_testcase {

    public function test_rational_reads_fraction_and_integer(): void {
        $this->assertSame([7, 7], arithmetic_checker::rational('7/7'));
        $this->assertSame([12, 1], arithmetic_checker::rational('12'));
        $this->assertSame([3, 8], arithmetic_checker::rational(' 3/8 '));
    }

    public function test_rational_reads_decimal_with_comma_and_dot(): void {
        // Модель отвечает и «0,5», и «0.5»: обе записи законны для ребенка.
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::rational('0,5'), [1, 2]));
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::rational('0.5'), [1, 2]));
    }

    public function test_rational_rejects_words_and_zero_denominator(): void {
        $this->assertNull(arithmetic_checker::rational('не изменится'));
        $this->assertNull(arithmetic_checker::rational('5/0'));
        $this->assertNull(arithmetic_checker::rational(''));
    }

    public function test_equals_compares_by_cross_multiplication(): void {
        // Сокращение не нужно: кросс-умножение и так признает эти пары равными.
        $this->assertTrue(arithmetic_checker::equals([2, 8], [1, 4]));
        $this->assertTrue(arithmetic_checker::equals([3, 3], [1, 1]));
        $this->assertFalse(arithmetic_checker::equals([7, 10], [7, 7]));
    }

    public function test_expression_computes_all_operations(): void {
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::expression('Сколько будет 4/7 + 3/7?'), [1, 1]));
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::expression('Найдите разность 3/5 - 1/5.'), [2, 5]));
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::expression('Вычислите 2/3 × 3/4'), [1, 2]));
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::expression('Чему равно 1/2 : 1/4'), [2, 1]));
    }

    public function test_expression_understands_minus_sign_and_middots(): void {
        // Модель пишет и обычный дефис, и знак минуса, и точку умножения.
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::expression('3/10 − 1/10'), [2, 10]));
        $this->assertTrue(arithmetic_checker::equals(
            arithmetic_checker::expression('2 · 3'), [6, 1]));
    }

    public function test_expression_returns_null_without_arithmetic(): void {
        $this->assertNull(arithmetic_checker::expression(
            'Какие дроби нужно сложить, чтобы получить 5/6?'));
        $this->assertNull(arithmetic_checker::expression('Что такое дробь?'));
    }

    public function test_expression_returns_null_on_chain_of_two_operators(): void {
        // Цепочку не считаем: приоритет операций и скобки - отдельная задача, а неверный
        // ответ верификатора хуже отсутствующего.
        $this->assertNull(arithmetic_checker::expression('1/2 + 1/4 + 1/8'));
    }

    public function test_expression_returns_null_on_two_separate_expressions(): void {
        // Два выражения в одном тексте: неизвестно, ответ на какое из них считать ключом.
        // Отдельно от цепочки «1/2 + 1/4 + 1/8»: там срабатывает проверка хвоста, а тут -
        // счетчик совпадений.
        $this->assertNull(arithmetic_checker::expression(
            'Верно ли, что 2 + 3 больше, чем 4 × 5?'));
    }

    public function test_expression_returns_null_on_division_by_zero(): void {
        $this->assertNull(arithmetic_checker::expression('5 : 0'));
    }

    // -----------------------------------------------------------------
    // Вердикт по заданию
    // -----------------------------------------------------------------

    /**
     * Шесть настоящих заданий зонда 2026-08-20: ключ модели неверен во всех.
     *
     * @return array список [текст, варианты, ключ модели, наш ожидаемый индекс]
     */
    private function probe_items(): array {
        return [
            ['4/7 + 3/7 равно?', ['7/10', '7/49', '7/14', '7/7'], 0, 3],
            ['Сколько будет 3/10 - 1/10?', ['2/20', '2/10', '4/10', '3/10'], 0, 1],
            ['Найдите значение 2/5 + 1/5.', ['3/15', '3/5', '2/10', '1/5'], 0, 1],
            ['Найдите сумму дробей 1/4 + 1/8.', ['3/4', '2/12', '3/8', '1/12'], 0, 2],
            ['Найдите разность дробей 3/5 - 1/5.', ['1/5', '2/5', '4/5', '2/10'], 0, 1],
            ['Чему равно 1/2 + 1/4?', ['5/8', '2/6', '3/4', '1/8'], 0, 2],
        ];
    }

    public function test_verdict_ok_when_key_is_right(): void {
        $out = arithmetic_checker::verdict('2/3 + 1/3 равно?', ['3/3', '3/6', '2/6', '1/3'], 0);
        $this->assertSame('ok', $out['verdict']);
        $this->assertSame(0, $out['correct']);
    }

    public function test_verdict_fixes_wrong_key_on_real_probe_items(): void {
        foreach ($this->probe_items() as $i => list($text, $answers, $correct, $expected)) {
            $out = arithmetic_checker::verdict($text, $answers, $correct);
            $this->assertSame('fixed', $out['verdict'], 'задание зонда #' . $i . ': ' . $text);
            $this->assertSame($expected, $out['correct'], 'задание зонда #' . $i);
        }
    }

    public function test_verdict_keeps_model_key_when_it_is_right_among_equals(): void {
        // Живой ответ 2026-08-21: «7/8 - 3/8» с вариантами «4/8» и «1/2» - оба верны. Ключ
        // модели указывал на «1/2», и переставлять его на первый совпавший незачем.
        $out = arithmetic_checker::verdict('Вычислите разность: 7/8 - 3/8',
            ['4/8', '1/2', '4/5', '1/4'], 1);
        $this->assertSame('ok', $out['verdict']);
        $this->assertSame(1, $out['correct'], 'верный ключ модели не трогаем');
    }

    public function test_verdict_reads_expression_from_any_part_of_solution(): void {
        // «x = 1/2 + 1/4 = 2/4 + 1/4 = 3/4»: слева от первого равенства стоит «x», а
        // вычисление идет дальше. Живой ответ 2026-08-21 с неверным ключом ловится именно так.
        $out = arithmetic_checker::verdict('Решите уравнение: x - 1/4 = 1/2',
            ['3/4', '5/4', '1/4', '3/8'], 1, 'x = 1/2 + 1/4 = 2/4 + 1/4 = 3/4');
        $this->assertSame('fixed', $out['verdict']);
        $this->assertSame(0, $out['correct']);
    }

    // -----------------------------------------------------------------
    // Где верификатор обязан молчать (ревью 2026-08-21)
    // -----------------------------------------------------------------

    public function test_verdict_keeps_silent_on_word_problem(): void {
        // «3 + 2» тут не ответ, а условие: после выражения в тексте есть еще число. Раньше ключ
        // переезжал с верного «4» на «5» - верификатор сам сочинял неверный ключ.
        $out = arithmetic_checker::verdict('У Маши было 3 + 2 конфеты, она съела 1. Сколько осталось?',
            ['4', '5', '6', '3'], 0);
        $this->assertSame('unverifiable', $out['verdict']);
        $this->assertSame(0, $out['correct']);
    }

    public function test_verdict_keeps_silent_on_year_range(): void {
        // Дефис в «1941-1945» - диапазон, а не вычитание: годный вопрос по истории удалялся.
        $out = arithmetic_checker::verdict('Сколько лет длилась война 1941-1945 годов?',
            ['4 года', '5 лет', '3 года', '6 лет'], 0);
        $this->assertSame('unverifiable', $out['verdict']);
    }

    public function test_verdict_keeps_silent_on_time_and_ratio(): void {
        // Двоеточие в «10:30» и «2:3» - время и отношение, а не деление.
        $this->assertSame('unverifiable', arithmetic_checker::verdict(
            'Урок начался в 10:30. Сколько это минут?', ['630', '1030', '600', '30'], 0)['verdict']);
        $this->assertSame('unverifiable', arithmetic_checker::verdict(
            'Мальчиков и девочек 2:3. Сколько девочек, если мальчиков 12?',
            ['18', '12', '6', '24'], 0)['verdict']);
    }

    public function test_verdict_keeps_silent_when_no_answer_is_a_number(): void {
        // Ни один вариант не число - значит найденное «выражение» скорее всего не про ответ.
        // Молчание тут безвредно, а отбраковка стоила бы ребенку вопроса.
        $out = arithmetic_checker::verdict('Сколько будет 2 + 3 яблок?',
            ['5 яблок', '6 яблок', '4 яблока', '7 яблок'], 0);
        $this->assertSame('unverifiable', $out['verdict']);
    }

    public function test_verdict_takes_last_step_of_multistep_solution(): void {
        // «3 + 4 = 7, периметр 7 × 2 = 14»: ответ - ПОСЛЕДНИЙ шаг. Раньше брался первый, и
        // ключ переезжал с верного «14» на промежуточное «7».
        $out = arithmetic_checker::verdict('Найди периметр прямоугольника со сторонами 3 и 4.',
            ['14', '7', '12', '10'], 0, 'Сумма сторон 3 + 4 = 7, периметр 7 × 2 = 14');
        $this->assertSame('ok', $out['verdict']);
        $this->assertSame(0, $out['correct']);
    }

    public function test_verdict_drops_when_no_answer_is_right(): void {
        $out = arithmetic_checker::verdict('4/7 + 3/7 равно?', ['7/10', '7/49', '7/14'], 0);
        $this->assertSame('drop', $out['verdict']);
    }

    public function test_verdict_unverifiable_without_expression(): void {
        $out = arithmetic_checker::verdict('Что такое дробь?', ['часть целого', 'число'], 0);
        $this->assertSame('unverifiable', $out['verdict']);
        $this->assertSame(0, $out['correct'], 'ключ не трогаем');
    }

    public function test_verdict_falls_back_to_solution_field(): void {
        // Выражения в самом вопросе нет, но модель показала вычисление - считаем по нему.
        $out = arithmetic_checker::verdict(
            'Какие дроби нужно сложить, чтобы получить сумму?',
            ['3/5', '3/15', '1/5'], 1, '2/5 + 1/5 = 3/5');
        $this->assertSame('fixed', $out['verdict']);
        $this->assertSame(0, $out['correct']);
    }
}
