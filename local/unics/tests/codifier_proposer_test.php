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

    // -----------------------------------------------------------------
    // Разводка кодов
    // -----------------------------------------------------------------

    public function test_plan_numbers_from_scratch(): void {
        $parsed = codifier_proposer::parse($this->reply(2, 2), 6, 5);
        $plan = codifier_proposer::plan([], $parsed);
        $this->assertSame('1', $plan[0]['code']);
        $this->assertSame('2', $plan[1]['code']);
        $this->assertSame('1.1', $plan[0]['topics'][0]['code']);
        $this->assertSame('1.2', $plan[0]['topics'][1]['code']);
        $this->assertFalse($plan[0]['shifted']);
    }

    public function test_plan_walks_around_taken_codes(): void {
        // На стенде заняты ровно эти коды остатками демонстрационных прогонов.
        $parsed = codifier_proposer::parse($this->reply(2, 2), 6, 5);
        $plan = codifier_proposer::plan(['1', '1.1', '2', '2.1'], $parsed);
        $this->assertSame('3', $plan[0]['code'], 'коды 1 и 2 заняты');
        $this->assertSame('4', $plan[1]['code']);
        $this->assertSame('3.1', $plan[0]['topics'][0]['code']);
        $this->assertTrue($plan[0]['shifted'], 'сдвиг обязан быть виден методисту');
        $this->assertSame('1', $plan[0]['natural']);
    }

    public function test_plan_walks_around_taken_topic_code(): void {
        $parsed = codifier_proposer::parse($this->reply(1, 2), 6, 5);
        $plan = codifier_proposer::plan(['1.1'], $parsed);
        $this->assertSame('1', $plan[0]['code'], 'сам код 1 свободен');
        $this->assertSame('1.2', $plan[0]['topics'][0]['code'], 'код 1.1 занят');
        $this->assertSame('1.3', $plan[0]['topics'][1]['code']);
    }

    public function test_plan_does_not_reuse_code_inside_one_batch(): void {
        $parsed = codifier_proposer::parse($this->reply(3, 1), 6, 5);
        $plan = codifier_proposer::plan([], $parsed);
        $codes = [$plan[0]['code'], $plan[1]['code'], $plan[2]['code']];
        $this->assertSame($codes, array_unique($codes));
    }
}
