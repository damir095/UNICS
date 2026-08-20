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
}
