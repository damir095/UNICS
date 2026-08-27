<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Модель считает варианты от единицы ([[one-based-key-design]]).
 *
 * Проба 2026-08-27 на сорока двух вопросах: пять индексов оказались вне диапазона, и ВСЕ пять
 * одного вида - `correct = 4` при четырёх вариантах. То есть модель нумерует варианты с единицы, а
 * не с нуля, и мы выбрасывали каждый восьмой годный вопрос.
 *
 * Сдвигается ровно этот однозначный случай: индекс, равный ЧИСЛУ вариантов. Всё, что выходит за
 * диапазон сильнее, по-прежнему отбраковывается - это не возврат к августовскому зажиму, который
 * прятал ошибку («correct = 7 при четырёх вариантах молча объявлял верным последний»). Вдобавок
 * сдвинутый вопрос всё равно уходит к слепому судье.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class one_based_key_test extends \advanced_testcase {

    /** Генератор с одним вопросом и заданным значением correct. */
    private function generator(int $correct, int $variants = 4): ai_generator {
        return new class($correct, $variants) extends ai_generator {
            private int $correct;
            private int $variants;
            public function __construct(int $correct, int $variants) {
                $this->correct = $correct;
                $this->variants = $variants;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    return '';
                }
                $answers = [];
                for ($i = 1; $i <= $this->variants; $i++) {
                    $answers[] = 'Вариант номер ' . $i;
                }
                return json_encode(['questions' => [[
                    'text' => 'Какой вариант верный?',
                    'answers' => $answers,
                    'correct' => $this->correct,
                ]]], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    private function ask(ai_generator $gen): array {
        ob_start();
        try {
            return $gen->generate_quiz([], 'Дроби', '', 1);
        } finally {
            ob_end_clean();
        }
    }

    private function ask_traced(ai_generator $gen): array {
        ob_start();
        try {
            $out = $gen->generate_quiz([], 'Дроби', '', 1);
        } finally {
            $trace = ob_get_clean();
        }
        return [$out, $trace];
    }

    public function test_index_equal_to_count_is_shifted(): void {
        // Главный случай пробы: correct = 4 при четырёх вариантах означает «четвёртый вариант».
        $out = $this->ask($this->generator(4, 4));

        $this->assertCount(1, $out, 'вопрос обязан уцелеть, а не выбыть');
        $this->assertSame(3, (int)$out[0]['correct'], 'ключ переезжает на последний вариант');
        $this->assertSame('Вариант номер 4', $out[0]['answers'][3]);
    }

    public function test_shift_works_for_three_variants_too(): void {
        // Правило про счёт с единицы, а не про число четыре.
        $out = $this->ask($this->generator(3, 3));

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_index_far_out_of_range_is_still_dropped(): void {
        // Августовский урок: correct = 7 при четырёх вариантах означает, что модель ПОТЕРЯЛА
        // соответствие ключа вариантам. Зажимать такое нельзя - ребёнок получил бы «неверно»
        // за верный ответ.
        $this->expectException(\moodle_exception::class);
        $this->ask($this->generator(7, 4));
    }

    public function test_normal_index_is_untouched(): void {
        $out = $this->ask($this->generator(2, 4));

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_zero_index_is_untouched(): void {
        // Ноль - законный первый вариант, и сдвигать его было бы порчей.
        $out = $this->ask($this->generator(0, 4));

        $this->assertCount(1, $out);
        $this->assertSame(0, (int)$out[0]['correct']);
    }

    public function test_negative_index_is_dropped(): void {
        $this->expectException(\moodle_exception::class);
        $this->ask($this->generator(-1, 4));
    }

    public function test_shift_is_visible_in_the_trace(): void {
        // Молчаливая правка ключа - это то, чего проект избегает: педагог должен видеть, что
        // задание пришло с ошибкой и было исправлено.
        [$out, $trace] = $this->ask_traced($this->generator(4, 4));

        $this->assertCount(1, $out);
        $this->assertStringContainsString('исправлено ключей 1', $trace);
    }
}
