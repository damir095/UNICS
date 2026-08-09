<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Отказ модели не должен доезжать до вызывающего как учебный текст
 * ([[ai-refusal-detector-design]], раздел 3.4).
 *
 * Подменяем самый нижний слой - HTTP к GigaChat, - поэтому проверяется именно
 * generate_text(), а не своя реализация. Сети тест не касается.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class generate_text_refusal_test extends \advanced_testcase {

    private const REFUSAL = 'Как и любая языковая модель, GigaChat не обладает собственным '
        . 'мнением и не транслирует мнение своих разработчиков.';

    private const GOOD = 'Круговорот воды в природе - это непрерывный процесс перехода воды '
        . 'между атмосферой, сушей и океанами.';

    public static function refusal_text(): string {
        return self::REFUSAL;
    }

    public static function good_text(): string {
        return self::GOOD;
    }

    /**
     * Отказ бывает транзиентным: замерено 2026-08-09, что тот же промт на следующий день
     * дал нормальные 3180 символов. Поэтому один повтор, а не сразу ошибка.
     */
    public function test_retries_once_and_returns_good_text(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');

        $gen = new class extends ai_generator {
            public int $calls = 0;
            protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return $this->calls === 1
                    ? generate_text_refusal_test::refusal_text()
                    : generate_text_refusal_test::good_text();
            }
        };

        $out = $gen->generate_text('любой промт');

        $this->assertSame(2, $gen->calls);
        $this->assertStringContainsString('Круговорот воды', $out);
    }

    /** Два отказа подряд - исключение с внятным текстом, третьей попытки нет. */
    public function test_two_refusals_throw_without_third_attempt(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');

        $gen = new class extends ai_generator {
            public int $calls = 0;
            protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return generate_text_refusal_test::refusal_text();
            }
        };

        try {
            $gen->generate_text('любой промт');
            $this->fail('Ожидалось исключение при двойном отказе');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('фильтр модели', $e->getMessage());
        }

        $this->assertSame(2, $gen->calls);
    }

    /** Нормальный ответ - ровно один запрос, лишнего обращения к ИИ нет. */
    public function test_good_text_costs_single_call(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');

        $gen = new class extends ai_generator {
            public int $calls = 0;
            protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return generate_text_refusal_test::good_text();
            }
        };

        $out = $gen->generate_text('любой промт');

        $this->assertSame(1, $gen->calls);
        $this->assertStringContainsString('Круговорот воды', $out);
    }
}
