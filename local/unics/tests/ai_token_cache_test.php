<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Кеш OAuth-токена GigaChat ([[ai-image-reliability-design]], раздел 2.3).
 *
 * До правки токен запрашивался на КАЖДЫЙ вызов ИИ, хотя живет 30 минут: комплект с
 * девятью картинками делал около 11 авторизаций вместо одной. Это и лишняя задержка,
 * и правдоподобная причина зависаний - одиннадцать OAuth подряд хороший повод для
 * тротлинга на той стороне.
 *
 * Подменяется сырой OAuth, поэтому сети тест не касается.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class ai_token_cache_test extends \advanced_testcase {

    /** Генератор со счетчиком авторизаций и управляемым сроком жизни токена. */
    private function generator(int $ttl_seconds): ai_generator {
        return new class($ttl_seconds) extends ai_generator {
            public int $auth_calls = 0;
            public function __construct(private int $ttl) {
                parent::__construct();
            }
            protected function fetch_gigachat_token(): array {
                $this->auth_calls++;
                return ['token' => 'tok-' . $this->auth_calls, 'expires_at' => time() + $this->ttl];
            }
            /** Обертка: get_gigachat_token() protected, а тесту нужен вызов. */
            public function public_token(): string {
                return $this->get_gigachat_token();
            }
        };
    }

    public function test_token_fetched_once_for_several_calls(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(1800);

        $gen->public_token();
        $gen->public_token();
        $gen->public_token();

        $this->assertSame(1, $gen->auth_calls);
    }

    public function test_same_token_returned_from_cache(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(1800);

        $this->assertSame($gen->public_token(), $gen->public_token());
    }

    /** Протухший токен перезапрашивается. */
    public function test_expired_token_is_refetched(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(-10); // уже истек

        $gen->public_token();
        $gen->public_token();

        $this->assertSame(2, $gen->auth_calls);
    }

    /**
     * Запас меньше минуты считается протухшим: иначе токен мог истечь между проверкой
     * и самим запросом к ИИ.
     */
    public function test_token_expiring_within_safety_margin_is_refetched(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(30); // живет меньше запаса в 60 с

        $gen->public_token();
        $gen->public_token();

        $this->assertSame(2, $gen->auth_calls);
    }

    /** Запас чуть больше минуты - токен еще годен. */
    public function test_token_beyond_safety_margin_is_reused(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY', 'local_unics');
        $gen = $this->generator(120);

        $gen->public_token();
        $gen->public_token();

        $this->assertSame(1, $gen->auth_calls);
    }
}
