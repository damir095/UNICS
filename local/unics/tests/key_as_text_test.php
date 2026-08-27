<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Ключ приходит ТЕКСТОМ верного варианта, а индекс мы вычисляем сами ([[key-as-text-design]]).
 *
 * У номера нет способа проверки: сбитый на единицу, он остается совершенно законным числом. Обе
 * беды 27 августа выросли отсюда - `correct = 4` при четырех вариантах ([[one-based-key-design]])
 * и сбитый ключ ВНУТРИ диапазона, который виден только по расхождению со слепым судьей
 * ([[judge-key-shift-design]]).
 *
 * Текст сбить нельзя: он либо совпадает с вариантом, либо нет. Индекс при этом ВЫЧИСЛЯЕТСЯ - то
 * есть ключ ставит то, что считает, а не то, что мнит. Ровно эту границу проект и держит.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class key_as_text_test extends \advanced_testcase {

    /**
     * @param array $reply что модель кладет в вопрос помимо text и answers
     * @param array $answers варианты (по умолчанию четыре разных)
     */
    private function generator(array $reply, ?array $answers = null): ai_generator {
        $answers ??= ['Синий кит', 'Слон', 'Жираф', 'Морж'];
        return new class($reply, $answers) extends ai_generator {
            private array $reply;
            private array $answers;
            public function __construct(array $reply, array $answers) {
                $this->reply = $reply;
                $this->answers = $answers;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    // Судья согласен с чем угодно: этот тест про разбор, а не про третий ярус.
                    return '';
                }
                return json_encode(['questions' => [array_merge([
                    'text' => 'Какое животное самое крупное?',
                    'answers' => $this->answers,
                ], $this->reply)]], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    /** @return array{0:array,1:string} результат и след. */
    private function ask(ai_generator $gen): array {
        ob_start();
        try {
            $out = $gen->generate_quiz([], 'Животные', '', 1);
        } catch (\moodle_exception $e) {
            $out = [];
        } finally {
            $trace = ob_get_clean();
        }
        return [$out, $trace];
    }

    public function test_key_is_found_by_text(): void {
        $out = $this->ask($this->generator(['answer' => 'Жираф']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_text_wins_over_a_contradicting_index(): void {
        // Главное свойство: если модель прислала и текст, и номер, и они спорят - верим ТЕКСТУ.
        // Номер и есть тот канал, который ломается; текст мы проверяем сопоставлением.
        $out = $this->ask($this->generator(['answer' => 'Жираф', 'correct' => 0]))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct'], 'ключ ставит сопоставление, а не номер');
    }

    public function test_numbered_prefix_is_ignored(): void {
        // Модель нередко отвечает «3) Жираф» - номер тут ее собственный, из показанного списка.
        $out = $this->ask($this->generator(['answer' => '3) Жираф']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_case_and_spacing_do_not_matter(): void {
        $out = $this->ask($this->generator(['answer' => '  ЖИРАФ. ']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_index_is_the_fallback_when_text_is_missing(): void {
        // Обратная совместимость: модель может не прислать поле вовсе, и тогда путь прежний.
        [$out, $trace] = $this->ask($this->generator(['correct' => 1]));

        $this->assertCount(1, $out);
        $this->assertSame(1, (int)$out[0]['correct']);
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_index_is_the_fallback_when_text_does_not_match(): void {
        // Пересказ своими словами сопоставить нельзя - откатываемся на номер и говорим об этом.
        [$out, $trace] = $this->ask($this->generator([
            'answer' => 'самое крупное животное на планете', 'correct' => 0,
        ]));

        $this->assertCount(1, $out);
        $this->assertSame(0, (int)$out[0]['correct']);
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_duplicate_options_fall_back_to_the_index(): void {
        // Два одинаковых варианта делают сопоставление неоднозначным. Гадать нельзя: такой
        // вопрос все равно выбьет question_sanity, но ключ до нее должен остаться честным.
        [$out, $trace] = $this->ask($this->generator(
            ['answer' => 'Слон', 'correct' => 0],
            ['Слон', 'Слон', 'Жираф', 'Морж']
        ));

        $this->assertStringContainsString('Ключ взят номером', $trace);
        $this->assertSame([], $out, 'дубль вариантов выбивает вопрос признаками брака');
    }

    public function test_text_key_is_not_shifted_as_one_based(): void {
        // Сдвиг «счет с единицы» относится ТОЛЬКО к номеру. Ключ, найденный сопоставлением,
        // трогать нельзя: он и так указывает на нужный вариант, и сдвиг был бы порчей.
        [$out, $trace] = $this->ask($this->generator(['answer' => 'Морж', 'correct' => 4]));

        $this->assertCount(1, $out);
        $this->assertSame(3, (int)$out[0]['correct']);
        $this->assertStringNotContainsString('посчитанных моделью с единицы', $trace);
    }
}
