<?php
namespace local_unics;

/**
 * Русское склонение существительного при числе.
 *
 * Заведено ради строки индикатора готовности: фиксированная форма давала «еще 1 ответов» ровно
 * там, где элемент ближе всего к готовности ([[cat-honest-precision]]).
 *
 * @package local_unics
 */
final class plural_test extends \advanced_testcase {

    private function ответов(int $n): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/lib.php');
        return local_unics_plural($n, 'ответ', 'ответа', 'ответов');
    }

    public function test_one(): void {
        $this->assertSame('ответ', $this->ответов(1));
        $this->assertSame('ответ', $this->ответов(21));
        $this->assertSame('ответ', $this->ответов(101));
    }

    public function test_few(): void {
        $this->assertSame('ответа', $this->ответов(2));
        $this->assertSame('ответа', $this->ответов(4));
        $this->assertSame('ответа', $this->ответов(23));
    }

    public function test_many(): void {
        $this->assertSame('ответов', $this->ответов(5));
        $this->assertSame('ответов', $this->ответов(20));
        $this->assertSame('ответов', $this->ответов(0));
    }

    public function test_teens_are_many(): void {
        // Одиннадцать-четырнадцать - исключение: последняя цифра обманывает.
        $this->assertSame('ответов', $this->ответов(11));
        $this->assertSame('ответов', $this->ответов(12));
        $this->assertSame('ответов', $this->ответов(14));
        $this->assertSame('ответов', $this->ответов(111));
    }
}
