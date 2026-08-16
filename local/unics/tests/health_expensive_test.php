<?php
namespace local_unics;

use local_unics\health\check_result;
use local_unics\health\checks\gigachat;
use local_unics\health\checks\irt_service;

/**
 * Дорогие проверки: ходят по сети, поэтому в тестах зонд подставляется.
 * Конфигурационной проверки мало - оба реальных инцидента (перезаписанный ключ GigaChat и
 * неоплаченный SaluteSpeech) происходили при НЕПУСТОМ и внешне правильном ключе.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(gigachat::class)]
final class health_expensive_test extends \advanced_testcase {

    public function test_missing_key_is_alarm_without_network(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', '', 'local_unics');
        $check = new gigachat();
        // Зонд не подставлен: при пустом ключе он и не должен вызываться.
        $check->probe = function () {
            $this->fail('при пустом ключе в сеть ходить нельзя');
        };
        $r = $check->run();
        $this->assertSame(check_result::ALARM, $r->level);
    }

    public function test_key_present_and_service_answers_is_ok(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'ключ', 'local_unics');
        $check = new gigachat();
        $check->probe = fn() => ['ok' => true, 'message' => 'ответ получен'];
        $this->assertSame(check_result::OK, $check->run()->level);
    }

    public function test_key_present_but_service_refuses_is_alarm(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'протухший', 'local_unics');
        $check = new gigachat();
        $check->probe = fn() => ['ok' => false, 'message' => 'HTTP 401'];
        $r = $check->run();
        $this->assertSame(check_result::ALARM, $r->level);
        $this->assertStringContainsString('401', $r->summary . ' ' . implode(' ', $r->details));
    }

    public function test_irt_service_not_needed_is_ok_without_network(): void {
        $this->resetAfterTest();
        set_config('mastery_estimator', '', 'local_unics');
        set_config('adaptive_cat_enabled', 0, 'local_unics');
        $check = new irt_service();
        $check->probe = function () {
            $this->fail('сервис никому не нужен - проверять нечего');
        };
        $this->assertSame(check_result::OK, $check->run()->level);
    }

    /** Дорогих ровно две. Озвучка сюда НЕ входит: она читает метку tts_status, без сети. */
    public function test_only_two_checks_are_expensive(): void {
        $this->resetAfterTest();
        foreach ([new gigachat(), new irt_service()] as $c) {
            $this->assertFalse($c->is_cheap(), $c->name() . ' обязана быть дорогой');
        }
        $this->assertTrue((new \local_unics\health\checks\salute_speech())->is_cheap(),
            'озвучка читает готовую метку и в сеть не ходит');
    }
}
