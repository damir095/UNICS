<?php
namespace local_unics;

use local_unics\identity\name_validator;

/**
 * Тесты валидатора ФИО (запрет разметки на входе, follow-up 4.4).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(name_validator::class)]
final class name_validator_test extends \basic_testcase {

    public function test_has_markup_detects_angle_brackets(): void {
        $this->assertTrue(name_validator::has_markup('<script>alert(1)</script>'));
        $this->assertTrue(name_validator::has_markup('<b>'));
        $this->assertTrue(name_validator::has_markup('a>b'));
        $this->assertTrue(name_validator::has_markup(' Игрек <'));
    }

    public function test_has_markup_allows_legit_names(): void {
        $this->assertFalse(name_validator::has_markup('Иванов'));
        $this->assertFalse(name_validator::has_markup("О'Брайен"));
        $this->assertFalse(name_validator::has_markup('Мамин-Сибиряк'));
        $this->assertFalse(name_validator::has_markup('Анна Мария'));
        $this->assertFalse(name_validator::has_markup('Ким Чен'));
        $this->assertFalse(name_validator::has_markup(''));
        $this->assertFalse(name_validator::has_markup(null));
    }
}
