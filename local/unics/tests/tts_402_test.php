<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\tts_status;

/**
 * Метку недоступности ставит ИМЕННО 402 ([[tts-honest-availability-design]], раздел 3.2).
 *
 * Пятисотка и таймаут - временные неурядицы: выключать из-за них озвучку значило бы
 * гасить рабочую функцию из-за сетевого сбоя.
 *
 * Подменяется самый нижний слой - HTTP к SmartSpeech, - поэтому сети тест не касается.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class tts_402_test extends \advanced_testcase {

    /** Генератор с подменой сетевого слоя синтеза. */
    private function generator(int $http_code, string $body): ai_generator {
        return new class($http_code, $body) extends ai_generator {
            public function __construct(private int $code, private string $body) {
                parent::__construct();
            }
            protected function salute_synthesize(string $text, string $voice): array {
                return [$this->code, $this->body];
            }
            protected function salute_token(): string {
                return 'fake-token';
            }
        };
    }

    public function test_402_marks_tts_unavailable(): void {
        $this->resetAfterTest();
        set_config('salute_speech_api_key', 'FAKE_KEY', 'local_unics');

        try {
            $this->generator(402, '{"status":402,"message":"Payment Required"}')
                ->generate_audio('Текст урока.');
            $this->fail('Ожидалось исключение при 402');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('402', $e->getMessage());
        }

        $this->assertTrue(tts_status::is_unavailable());
        $this->assertStringContainsString('Payment Required', tts_status::reason());
    }

    /** Пятисотка метку НЕ ставит: это временный сбой, а не состояние оплаты. */
    public function test_500_does_not_mark_unavailable(): void {
        $this->resetAfterTest();
        set_config('salute_speech_api_key', 'FAKE_KEY', 'local_unics');

        try {
            $this->generator(500, 'Internal Server Error')->generate_audio('Текст урока.');
            $this->fail('Ожидалось исключение при 500');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $this->assertFalse(tts_status::is_unavailable());
    }

    /** Удачный синтез снимает метку - путь «пакет оплатили». */
    public function test_success_clears_the_mark(): void {
        $this->resetAfterTest();
        set_config('salute_speech_api_key', 'FAKE_KEY', 'local_unics');
        tts_status::mark_unavailable('Payment Required');

        $wav = 'RIFF' . str_repeat("\x00", 2000);
        $audio = $this->generator(200, $wav)->generate_audio('Текст урока.');

        $this->assertSame($wav, $audio);
        $this->assertFalse(tts_status::is_unavailable());
    }
}
