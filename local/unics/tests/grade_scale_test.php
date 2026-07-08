<?php
namespace local_unics;

use local_unics\learning\grade_scale;

/**
 * Тесты единой шкалы оценивания УНИКС (этап 5.2 аудита - страховка рефакторинга).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grade_scale::class)]
final class grade_scale_test extends \basic_testcase {

    public function test_from_percent_maps_and_clamps(): void {
        $this->assertSame(0.0, grade_scale::from_percent(0));
        $this->assertSame(5.0, grade_scale::from_percent(100));
        $this->assertSame(2.5, grade_scale::from_percent(50));
        $this->assertSame(4.5, grade_scale::from_percent(90));
        $this->assertSame(1.0, grade_scale::from_percent(20));
        // Клампы за пределами 0..100.
        $this->assertSame(0.0, grade_scale::from_percent(-15));
        $this->assertSame(5.0, grade_scale::from_percent(150));
    }

    public function test_from_raw(): void {
        $this->assertSame(4.0, grade_scale::from_raw(4, 5));
        $this->assertSame(2.5, grade_scale::from_raw(50, 100));
        // Нулевой/отрицательный максимум не делит на ноль.
        $this->assertSame(0.0, grade_scale::from_raw(3, 0));
        $this->assertSame(0.0, grade_scale::from_raw(3, -1));
    }

    public function test_badge_class_bands(): void {
        // >= 85% -> success, >= 50% -> warning, ниже -> danger.
        $this->assertSame('success', grade_scale::badge_class(5.0));
        $this->assertSame('success', grade_scale::badge_class(4.25)); // ровно 85%
        $this->assertSame('warning', grade_scale::badge_class(4.2));
        $this->assertSame('warning', grade_scale::badge_class(2.5));  // ровно 50%
        $this->assertSame('danger',  grade_scale::badge_class(2.4));
        $this->assertSame('danger',  grade_scale::badge_class(0.0));
    }

    public function test_format_and_label(): void {
        $this->assertSame('4/5', grade_scale::format(4));
        $this->assertSame('2.5/5', grade_scale::format(2.5));
        $this->assertSame('из 5', grade_scale::label());
    }
}
