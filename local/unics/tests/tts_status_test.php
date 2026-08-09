<?php
namespace local_unics;

use local_unics\ai\tts_status;

/**
 * Память о недоступности озвучки ([[tts-honest-availability-design]]).
 *
 * Знание берется из реальной попытки синтеза, а не из зондов по расписанию,
 * поэтому класс - просто пара настроек плагина с внятным API.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(tts_status::class)]
final class tts_status_test extends \advanced_testcase {

    public function test_available_by_default(): void {
        $this->resetAfterTest();

        $this->assertFalse(tts_status::is_unavailable());
        $this->assertSame('', tts_status::reason());
    }

    public function test_mark_unavailable_stores_reason(): void {
        $this->resetAfterTest();

        tts_status::mark_unavailable('Payment Required');

        $this->assertTrue(tts_status::is_unavailable());
        $this->assertStringContainsString('Payment Required', tts_status::reason());
    }

    /** Оплатят пакет - удачный синтез снимет метку сам, без администратора. */
    public function test_mark_available_clears_the_flag(): void {
        $this->resetAfterTest();
        tts_status::mark_unavailable('Payment Required');

        tts_status::mark_available();

        $this->assertFalse(tts_status::is_unavailable());
        $this->assertSame('', tts_status::reason());
    }

    public function test_second_mark_overwrites_reason(): void {
        $this->resetAfterTest();

        tts_status::mark_unavailable('Payment Required');
        tts_status::mark_unavailable('Quota exceeded');

        $this->assertStringContainsString('Quota exceeded', tts_status::reason());
        $this->assertStringNotContainsString('Payment Required', tts_status::reason());
    }

    /** Время метки нужно администратору, чтобы понять, свежая она или прошлогодняя. */
    public function test_marks_timestamp(): void {
        $this->resetAfterTest();
        $before = time();

        tts_status::mark_unavailable('Payment Required');

        $at = (int)get_config('local_unics', 'tts_unavailable_at');
        $this->assertGreaterThanOrEqual($before, $at);
    }

    /** Пустая причина не считается недоступностью: это защита от случайной записи. */
    public function test_empty_reason_is_not_unavailable(): void {
        $this->resetAfterTest();

        tts_status::mark_unavailable('   ');

        $this->assertFalse(tts_status::is_unavailable());
    }
}
