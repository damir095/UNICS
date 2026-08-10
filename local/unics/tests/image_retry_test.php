<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Повтор запроса за UUID картинки ([[ai-image-reliability-design]], раздел 2.2).
 *
 * Замер 2026-08-10: восемь генераций уложились в 13.8-14.1 с, разброс 0.3 секунды.
 * Медленных успехов не бывает - сервис либо отвечает примерно за 14 с, либо виснет
 * совсем. Поэтому таймаут снижен с 90 до 30 с, а зависание лечится повтором.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class image_retry_test extends \advanced_testcase {

    private const UUID = '11111111-2222-3333-4444-555555555555';

    public static function uuid(): string {
        return self::UUID;
    }

    /**
     * Генератор со счетчиком попыток. $fail_times - сколько первых попыток провалить.
     * Скачивание файла подменяется, чтобы тест не касался сети вовсе.
     */
    private function generator(int $fail_times): ai_generator {
        return new class($fail_times) extends ai_generator {
            public int $attempts = 0;
            public function __construct(private int $fail_times) {
                parent::__construct();
            }
            protected function fetch_image_uuid(string $prompt): string {
                $this->attempts++;
                if ($this->attempts <= $this->fail_times) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '',
                        'GigaChat image cURL ошибка: Operation timed out');
                }
                return image_retry_test::uuid();
            }
            protected function download_image(string $uuid): string {
                return 'FAKE-JPEG-' . $uuid;
            }
        };
    }

    /** Успех с первой попытки - лишнего запроса нет. */
    public function test_success_costs_single_attempt(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(0);

        $image = $gen->generate_image('нарисуй воду');

        $this->assertSame(1, $gen->attempts);
        $this->assertStringContainsString(self::UUID, $image);
    }

    /** Зависание на первой попытке лечится повтором. */
    public function test_retry_rescues_after_one_failure(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(1);

        // Ловим вывод, а не expectOutputRegex: нужна ТОЧНАЯ кратность следа, иначе снятие
        // гейта «if ($attempt === 1)» тест бы не уронило (найдено ревью).
        ob_start();
        $image = $gen->generate_image('нарисуй воду');
        $trace = (string)ob_get_clean();

        $this->assertSame(2, $gen->attempts);
        $this->assertStringContainsString(self::UUID, $image);
        $this->assertSame(1, substr_count($trace, 'повтор'));
    }

    /**
     * Пустое тело при HTTP 200 - это НЕ картинка.
     *
     * Найдено ревью: скачивание не проверяло размер, и пустой ответ возвращался как
     * успех. Воркер молча клал пустой файл, и в логе не было ни следа. У озвучки такая
     * проверка есть (`SaluteSpeech вернул некорректные аудиоданные`), у картинок не было.
     */
    public function test_empty_download_is_rejected(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');

        $gen = new class extends ai_generator {
            protected function fetch_image_uuid(string $prompt): string {
                return image_retry_test::uuid();
            }
            protected function raw_download_image(string $uuid): string {
                return ''; // HTTP 200, но тело пустое
            }
        };

        $this->expectException(\moodle_exception::class);
        $gen->generate_image('нарисуй воду');
    }

    /**
     * Сбой скачивания НЕ перегенерирует картинку заново.
     *
     * Найдено ревью: докблок обещал, что повтор не покрывает скачивание, а на деле
     * generate_image оборачивал всю цепочку - и сбой на скачивании выбрасывал уже
     * готовое изображение, чтобы заплатить за новое.
     */
    public function test_download_failure_does_not_regenerate(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');

        $gen = new class extends ai_generator {
            public int $uuid_calls = 0;
            protected function fetch_image_uuid(string $prompt): string {
                $this->uuid_calls++;
                return image_retry_test::uuid();
            }
            protected function raw_download_image(string $uuid): string {
                throw new \moodle_exception('generalexceptionmessage', 'error', '',
                    'GigaChat image download HTTP 500');
            }
        };

        try {
            $gen->generate_image('нарисуй воду');
            $this->fail('Ожидалось исключение при сбое скачивания');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('download', $e->getMessage());
        }

        $this->assertSame(1, $gen->uuid_calls);
    }

    /** Два сбоя подряд - исключение, третьей попытки нет. */
    public function test_two_failures_throw_without_third_attempt(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(2);

        ob_start();
        try {
            $gen->generate_image('нарисуй воду');
            $this->fail('Ожидалось исключение после двух сбоев');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        } finally {
            $trace = (string)ob_get_clean();
        }

        $this->assertSame(2, $gen->attempts);
        // След пишется только на первой попытке: вторая уже бросает наружу.
        $this->assertSame(1, substr_count($trace, 'повтор'));
    }
}
