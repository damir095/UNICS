<?php
namespace local_unics;

use local_unics\ai\codifier_proposer;

/**
 * Предложение структуры кодификатора моделью ([[codifier-ai-proposal-design]]).
 *
 * Сеть не трогаем: генератор подменяется анонимным классом.
 *
 * @package local_unics
 */
final class codifier_proposer_test extends \advanced_testcase {

    /** Ответ модели заданного размера. */
    private function reply(int $sections, int $topics): string {
        $secs = [];
        for ($i = 1; $i <= $sections; $i++) {
            $t = [];
            for ($j = 1; $j <= $topics; $j++) {
                $t[] = ['title' => "Тема $i.$j", 'description' => "умеет $i.$j"];
            }
            $secs[] = ['title' => "Раздел $i", 'description' => "про $i", 'topics' => $t];
        }
        return json_encode(['sections' => $secs], JSON_UNESCAPED_UNICODE);
    }

    public function test_parses_normal_reply(): void {
        $out = codifier_proposer::parse($this->reply(2, 3), 6, 5);
        $this->assertCount(2, $out);
        $this->assertSame('Раздел 1', $out[0]['title']);
        $this->assertSame('про 1', $out[0]['description']);
        $this->assertCount(3, $out[0]['topics']);
        $this->assertSame('Тема 1.2', $out[0]['topics'][1]['title']);
        $this->assertSame('умеет 1.2', $out[0]['topics'][1]['description']);
    }

    public function test_extra_sections_and_topics_are_cut(): void {
        $out = codifier_proposer::parse($this->reply(20, 20), 6, 5);
        $this->assertCount(6, $out, 'лишние разделы обязаны отсекаться');
        $this->assertCount(5, $out[0]['topics'], 'лишние темы обязаны отсекаться');
    }

    public function test_section_without_title_is_dropped(): void {
        $raw = '{"sections":[{"title":"","topics":[{"title":"Тема"}]},{"title":"Живой","topics":[{"title":"Тема"}]}]}';
        $out = codifier_proposer::parse($raw, 6, 5);
        $this->assertCount(1, $out);
        $this->assertSame('Живой', $out[0]['title']);
    }

    public function test_nonscalar_title_does_not_break_parse(): void {
        // Модель иногда отдает объект вместо строки; приведение к строке уронило бы разбор.
        $raw = '{"sections":[{"title":{"ru":"Объект"},"topics":[]},{"title":"Живой","topics":[]}]}';
        $out = codifier_proposer::parse($raw, 6, 5);
        $this->assertCount(1, $out);
        $this->assertSame('Живой', $out[0]['title']);
    }

    public function test_garbage_throws(): void {
        $this->expectException(\moodle_exception::class);
        codifier_proposer::parse('Извините, не могу помочь.', 6, 5);
    }
}
