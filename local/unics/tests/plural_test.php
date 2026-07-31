<?php
namespace local_unics;

use local_unics\output\plural;

defined('MOODLE_INTERNAL') || die();

/**
 * Тест общего хелпера формы русского числительного ({@see plural::form}) -
 * используется и ученическим ({@see \local_unics\output\course_view}), и педагогским
 * ({@see \local_unics\output\course_staff_view}) видами страницы курса.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(plural::class)]
final class plural_test extends \advanced_testcase {

    public function test_russian_plural_forms(): void {
        $cases = [1 => 'one', 2 => 'few', 3 => 'few', 4 => 'few', 5 => 'many', 0 => 'many',
                  11 => 'many', 12 => 'many', 13 => 'many', 14 => 'many', 21 => 'one', 22 => 'few',
                  25 => 'many', 101 => 'one', 111 => 'many'];
        foreach ($cases as $n => $expected) {
            $this->assertSame($expected, plural::form($n), 'n = ' . $n);
        }
    }
}
