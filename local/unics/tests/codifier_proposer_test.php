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

    /** Ответ модели заданного размера: плоский список «раздел, тема, описание». */
    private function reply(int $sections, int $topics): string {
        $items = [];
        for ($i = 1; $i <= $sections; $i++) {
            for ($j = 1; $j <= $topics; $j++) {
                $items[] = ['section' => "Раздел $i", 'topic' => "Тема $i.$j",
                            'description' => "умеет $i.$j"];
            }
        }
        return json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    }

    public function test_parses_normal_reply(): void {
        $out = codifier_proposer::parse($this->reply(2, 3), 6, 5);
        $this->assertCount(2, $out);
        $this->assertSame('Раздел 1', $out[0]['title']);
        $this->assertCount(3, $out[0]['topics']);
        $this->assertSame('Тема 1.2', $out[0]['topics'][1]['title']);
        $this->assertSame('умеет 1.2', $out[0]['topics'][1]['description']);
    }

    public function test_sections_keep_the_order_of_first_mention(): void {
        // Иерархию собираем мы группировкой, поэтому порядок разделов обязан быть порядком
        // изучения, а не алфавитом или порядком словаря.
        $raw = '{"items":[{"section":"Ясли","topic":"а"},{"section":"Абвгд","topic":"б"},'
            . '{"section":"Ясли","topic":"в"}]}';
        $out = codifier_proposer::parse($raw, 6, 5);
        $this->assertSame(['Ясли', 'Абвгд'], [$out[0]['title'], $out[1]['title']]);
        $this->assertCount(2, $out[0]['topics'], 'строки одного раздела обязаны слиться');
    }

    public function test_topics_of_extra_section_do_not_leak_into_taken_ones(): void {
        // Разделов уже достаточно - темы лишнего раздела не должны прилипать к последнему взятому.
        $raw = '{"items":[{"section":"А","topic":"а1"},{"section":"Б","topic":"б1"},'
            . '{"section":"В","topic":"в1"}]}';
        $out = codifier_proposer::parse($raw, 2, 5);
        $this->assertCount(2, $out);
        $this->assertSame(['а1'], array_column($out[0]['topics'], 'title'));
        $this->assertSame(['б1'], array_column($out[1]['topics'], 'title'));
    }

    public function test_extra_sections_and_topics_are_cut(): void {
        $out = codifier_proposer::parse($this->reply(20, 20), 6, 5);
        $this->assertCount(6, $out, 'лишние разделы обязаны отсекаться');
        $this->assertCount(5, $out[0]['topics'], 'лишние темы обязаны отсекаться');
    }

    public function test_row_without_section_or_topic_is_dropped(): void {
        $raw = '{"items":[{"section":"","topic":"Сирота"},{"section":"Живой","topic":""},'
            . '{"section":"Живой","topic":"Тема"}]}';
        $out = codifier_proposer::parse($raw, 6, 5);
        $this->assertCount(1, $out);
        $this->assertSame('Живой', $out[0]['title']);
        $this->assertCount(1, $out[0]['topics']);
    }

    public function test_nonscalar_title_does_not_break_parse(): void {
        // Модель иногда отдает объект вместо строки; приведение к строке уронило бы разбор.
        $raw = '{"items":[{"section":{"ru":"Объект"},"topic":"Тема"},{"section":"Живой","topic":"Тема"}]}';
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

    // -----------------------------------------------------------------
    // Промт и вызов модели
    // -----------------------------------------------------------------

    public function test_prompt_carries_everything_the_methodist_asked(): void {
        $p = (new codifier_proposer())->build_prompt('Математика', 6, 7, 4,
            'по учебнику Мерзляка', ['Нефть и нефтепродукты', 'Части света']);
        $this->assertStringContainsString('Математика', $p);
        $this->assertStringContainsString('6 класса', $p);
        $this->assertStringContainsString('7 разделов', $p);
        $this->assertStringContainsString('4 тем', $p);
        $this->assertStringContainsString('всего 28 строк', $p, 'модели легче считать строки, чем вложенность');
        $this->assertStringContainsString('по учебнику Мерзляка', $p);
        $this->assertStringContainsString('Нефть и нефтепродукты', $p);
        $this->assertStringContainsString('"items"', $p, 'формат ответа обязан быть в промте');
    }

    public function test_prompt_without_existing_elements_has_no_empty_section(): void {
        $p = (new codifier_proposer())->build_prompt('Математика', 6, 6, 5, '', []);
        $this->assertStringNotContainsString('НЕ повторяй', $p);
    }

    public function test_propose_parses_generator_reply(): void {
        $gen = new class($this->reply(2, 2)) extends \local_unics\ai\ai_generator {
            private string $canned;
            // Родительский конструктор не зову намеренно: он читает ключ API из настроек.
            public function __construct(string $canned) {
                $this->canned = $canned;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                return $this->canned;
            }
        };
        $out = (new codifier_proposer($gen))->propose('Математика', 6, 6, 5);
        $this->assertCount(2, $out);
        $this->assertSame('Раздел 1', $out[0]['title']);
    }

    public function test_propose_retries_once_after_broken_reply(): void {
        // Порча разметки у модели случайна: 2026-08-20 из трех живых ответов два были битыми,
        // а тот же промт из CLI разобрался с первого раза.
        $gen = new class('мусор', $this->reply(2, 2)) extends \local_unics\ai\ai_generator {
            private array $queue;
            public int $calls = 0;
            public function __construct(string $first, string $second) {
                $this->queue = [$first, $second];
            }
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return array_shift($this->queue) ?? '';
            }
        };
        $out = (new codifier_proposer($gen))->propose('Математика', 6, 6, 5);
        $this->assertSame(2, $gen->calls, 'битый ответ обязан вызывать вторую попытку');
        $this->assertCount(2, $out);
    }

    public function test_propose_gives_up_after_second_broken_reply(): void {
        $gen = new class extends \local_unics\ai\ai_generator {
            public int $calls = 0;
            public function __construct() {
            }
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return 'мусор';
            }
        };
        try {
            (new codifier_proposer($gen))->propose('Математика', 6, 6, 5);
            $this->fail('после двух битых ответов обязано быть исключение');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('некорректную структуру', $e->getMessage());
        }
        $this->assertSame(codifier_proposer::PARSE_ATTEMPTS, $gen->calls,
            'третьей попытки быть не должно: устойчивую порчу повтором не вылечить');
    }
}
