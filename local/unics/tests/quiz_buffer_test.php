<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Комплект не усыхает: у модели просим с запасом ([[quiz-buffer-design]]).
 *
 * Живая генерация 2026-08-26: из двух заказов по пять вопросов вышло четыре и три - один вопрос
 * снял судья, два не прошли по битому индексу ключа. У модели просили РОВНО нужное количество, а
 * ярусов отбраковки уже четыре (арифметика, признаки брака, судья, длина ключа), и каждый новый
 * ярус укорачивает тест.
 *
 * Запас просится ВНУТРИ generate_quiz: обрезка до нужного числа в конце разбора уже стоит, и
 * вызывающие (umk_processor, бронь пула) не меняются - наружу по-прежнему уходит не больше $num.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class quiz_buffer_test extends \advanced_testcase {

    /** Генератор с заданными ответами модели; запоминает промты и потолки токенов. */
    private function generator(array $replies): ai_generator {
        return new class($replies) extends ai_generator {
            private array $queue;
            public array $prompts = [];
            public array $limits = [];
            public function __construct(array $replies) {
                $this->queue = $replies;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    return '';
                }
                $this->prompts[] = $prompt;
                $this->limits[] = $max_tokens;
                return array_shift($this->queue) ?? '';
            }
        };
    }

    /** Ответ модели с N годными вопросами. */
    private function reply(int $n, int $badkeys = 0): string {
        $questions = [];
        for ($i = 0; $i < $n; $i++) {
            $questions[] = [
                'text' => "Вопрос номер {$i}?",
                'answers' => ["Верный {$i}", "Первый неверный {$i}",
                              "Второй неверный {$i}", "Третий неверный {$i}"],
                // Битый индекс ключа - ровно то, чем модель резала комплекты в живой генерации.
                'correct' => $i < $badkeys ? 99 : 0,
            ];
        }
        return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
    }

    private function ask(ai_generator $gen, int $num): array {
        ob_start();
        try {
            return $gen->generate_quiz([], 'Дроби', '', $num);
        } finally {
            ob_end_clean();
        }
    }

    public function test_prompt_asks_for_more_than_needed(): void {
        // Главная проверка: в промт уходит число С ЗАПАСОМ, а не заказанное.
        $gen = $this->generator([$this->reply(7)]);

        $this->ask($gen, 5);

        $this->assertStringContainsString('ровно 7 вопросов', $gen->prompts[0],
            'у модели просим с запасом');
        $this->assertStringNotContainsString('ровно 5 вопросов', $gen->prompts[0]);
    }

    public function test_caller_still_gets_exactly_what_it_asked(): void {
        // Запас наружу не протекает: вызывающий рассчитывает места в пуле по своему числу.
        $gen = $this->generator([$this->reply(7)]);

        $out = $this->ask($gen, 5);

        $this->assertCount(5, $out);
    }

    public function test_buffer_absorbs_rejections(): void {
        // Ради чего задача и делается: два вопроса выбывают, а комплект остаётся полным.
        $gen = $this->generator([$this->reply(7, 2)]);

        $out = $this->ask($gen, 5);

        $this->assertCount(5, $out, 'запас обязан покрыть отбраковку двух вопросов');
    }

    public function test_short_reply_is_not_padded(): void {
        // Модель прислала меньше запрошенного - выдаём сколько есть, выдумывать нечего.
        $gen = $this->generator([$this->reply(3)]);

        $out = $this->ask($gen, 5);

        $this->assertCount(3, $out);
    }

    public function test_token_limit_grows_with_the_order(): void {
        // Потолок был жёстким на любое число вопросов. При семи ответ длиннее, и обрезка
        // привела бы ровно к тому, от чего лечимся.
        $small = $this->generator([$this->reply(3)]);
        $this->ask($small, 1);

        $big = $this->generator([$this->reply(12)]);
        $this->ask($big, 10);

        $this->assertGreaterThan($small->limits[0], $big->limits[0],
            'заказ больше - потолок токенов выше');
    }

    public function test_all_rejected_still_throws(): void {
        // Порог «все отбракованы» считает по факту, а не по заказу: запас его не отменяет.
        $gen = $this->generator([$this->reply(7, 7), $this->reply(7, 7)]);

        $this->expectException(\moodle_exception::class);
        $this->ask($gen, 5);
    }
}
