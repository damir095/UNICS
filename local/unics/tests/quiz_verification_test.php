<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Сборка теста с проверкой ключей ([[quiz-answer-verification-design]]).
 *
 * @package local_unics
 */
final class quiz_verification_test extends \advanced_testcase {

    /** Генератор с заранее заданными ответами модели вместо сети. */
    private function generator(array $replies): ai_generator {
        return new class($replies) extends ai_generator {
            private array $queue;
            public int $calls = 0;
            // Родительский конструктор не зову намеренно: он читает ключ API из настроек.
            public function __construct(array $replies) {
                $this->queue = $replies;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return array_shift($this->queue) ?? '';
            }
        };
    }

    public function test_wrong_key_is_fixed(): void {
        $reply = json_encode(['questions' => [[
            'text' => 'Сколько будет 4/7 + 3/7?',
            'answers' => ['7/10', '7/49', '7/14', '7/7'],
            'correct' => 0,
        ]]], JSON_UNESCAPED_UNICODE);
        ob_start();
        $out = $this->generator([$reply])->generate_quiz([], 'Дроби', '', 1);
        $trace = ob_get_clean();
        $this->assertCount(1, $out);
        $this->assertSame(3, $out[0]['correct'], 'ключ обязан переехать на 7/7');
        $this->assertStringContainsString('исправлено ключей 1', $trace, 'след проверки обязателен');
    }

    public function test_question_without_right_answer_is_dropped(): void {
        $reply = json_encode(['questions' => [
            ['text' => 'Сколько будет 4/7 + 3/7?', 'answers' => ['7/10', '7/49'], 'correct' => 0],
            ['text' => 'Сколько будет 2/5 + 1/5?', 'answers' => ['3/5', '3/15'], 'correct' => 0],
        ]], JSON_UNESCAPED_UNICODE);
        ob_start();
        $out = $this->generator([$reply])->generate_quiz([], 'Дроби', '', 2);
        ob_end_clean();
        $this->assertCount(1, $out, 'вопрос без верного варианта в тест не идет');
        $this->assertStringContainsString('2/5', $out[0]['text']);
    }

    public function test_question_without_arithmetic_passes_untouched(): void {
        $reply = json_encode(['questions' => [[
            'text' => 'Что такое дробь?',
            'answers' => ['часть целого', 'сумма', 'разность'],
            'correct' => 1,
        ]]], JSON_UNESCAPED_UNICODE);
        $out = $this->generator([$reply])->generate_quiz([], 'Дроби', '', 1);
        $this->assertSame(1, $out[0]['correct'], 'непроверяемый вопрос не трогаем');
    }

    public function test_latex_is_stripped_from_text_and_answers(): void {
        $reply = '{"questions":[{"text":"Сложите $ \\\\frac{4}{7} $ и $ \\\\frac{3}{7} $",'
            . '"answers":["$ \\\\frac{7}{7} $","$ \\\\frac{7}{10} $"],"correct":0}]}';
        $out = $this->generator([$reply])->generate_quiz([], 'Дроби', '', 1);
        $this->assertStringNotContainsString('frac', $out[0]['text']);
        $this->assertStringNotContainsString('$', $out[0]['text']);
        $this->assertStringNotContainsString('frac', $out[0]['answers'][0]);
    }

    public function test_second_attempt_after_broken_reply(): void {
        $good = json_encode(['questions' => [[
            'text' => 'Что такое дробь?', 'answers' => ['часть целого', 'сумма'], 'correct' => 0,
        ]]], JSON_UNESCAPED_UNICODE);
        $gen = $this->generator(['мусор', $good]);
        ob_start();
        $out = $gen->generate_quiz([], 'Дроби', '', 1);
        $trace = ob_get_clean();
        $this->assertSame(2, $gen->calls, 'битый ответ обязан вызывать вторую попытку');
        $this->assertStringContainsString('попытка 1 из 2', $trace,
            'без следа удачный повтор неотличим от чистого прогона');
        $this->assertCount(1, $out);
    }

    public function test_all_questions_dropped_throws(): void {
        $reply = json_encode(['questions' => [[
            'text' => 'Сколько будет 4/7 + 3/7?', 'answers' => ['7/10', '7/49'], 'correct' => 0,
        ]]], JSON_UNESCAPED_UNICODE);
        // Комплект без теста лучше, чем комплект с мусорным тестом.
        ob_start();
        try {
            $this->generator([$reply, $reply])->generate_quiz([], 'Дроби', '', 1);
            ob_end_clean();
            $this->fail('после двух отбраковок обязано быть исключение');
        } catch (\moodle_exception $e) {
            ob_end_clean();
            $this->assertStringContainsString('некорректный формат теста', $e->getMessage());
        }
    }
}
