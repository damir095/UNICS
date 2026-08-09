<?php
namespace local_unics;

use local_unics\ai\refusal_detector;

/**
 * Распознавание отказа модели ([[ai-refusal-detector-design]]).
 *
 * Болванки взяты дословно из живых замеров 2026-08-09: blacklist-ный вариант
 * воспроизводится надежно, мягкий попался один раз и больше не повторился.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(refusal_detector::class)]
final class refusal_detector_test extends \basic_testcase {

    /** Дословная болванка blacklist-отказа (336 символов в живом ответе). */
    private const BLACKLIST_TEXT = 'Как и любая языковая модель, GigaChat не обладает '
        . 'собственным мнением и не транслирует мнение своих разработчиков.';

    /** Мягкий отказ - тот самый, что лег в курс как учебный материал. */
    private const SOFT_TEXT = 'К сожалению, иногда генеративные языковые модели могут '
        . 'создавать некорректные ответы, основанные на открытых источниках. Во избежание '
        . 'неправильного толкования, ответы на вопросы, связанные с чувствительными темами, '
        . 'временно ограничены.';

    private const REAL_LECTURE = "#### Круговорот воды\n\nВода испаряется с поверхности "
        . 'океанов, поднимается в атмосферу и выпадает осадками. Этот процесс идет непрерывно.';

    public function test_blacklist_finish_reason_is_refusal(): void {
        $this->assertTrue(refusal_detector::is_refusal(self::BLACKLIST_TEXT,
            refusal_detector::BLACKLIST));
    }

    /** finish_reason решает сам по себе: даже правдоподобный текст при blacklist - отказ. */
    public function test_blacklist_wins_over_plausible_text(): void {
        $this->assertTrue(refusal_detector::is_refusal(self::REAL_LECTURE,
            refusal_detector::BLACKLIST));
    }

    /** Мягкий вариант ловится по фразе: его finish_reason неизвестен. */
    public function test_soft_refusal_caught_by_marker_with_stop(): void {
        $this->assertTrue(refusal_detector::is_refusal(self::SOFT_TEXT, 'stop'));
    }

    public function test_blacklist_boilerplate_caught_by_marker_too(): void {
        $this->assertTrue(refusal_detector::is_refusal(self::BLACKLIST_TEXT, 'stop'));
    }

    public function test_markers_are_case_insensitive(): void {
        $this->assertTrue(refusal_detector::is_refusal(
            'ВРЕМЕННО ОГРАНИЧЕНЫ ответы на такие вопросы.', 'stop'));
    }

    public function test_real_lecture_is_not_refusal(): void {
        $this->assertFalse(refusal_detector::is_refusal(self::REAL_LECTURE, 'stop'));
    }

    /** Путь тестов с подменой сетевого шва: поле не заполнено, текст нормальный. */
    public function test_empty_finish_reason_with_normal_text_is_not_refusal(): void {
        $this->assertFalse(refusal_detector::is_refusal(self::REAL_LECTURE, ''));
    }

    /** Пустой ответ - не наша забота: его ловит проверка длины в generate_text_gigachat(). */
    public function test_empty_text_is_not_refusal(): void {
        $this->assertFalse(refusal_detector::is_refusal('', 'stop'));
        $this->assertFalse(refusal_detector::is_refusal('   ', ''));
    }

    /**
     * Обрыв по лимиту токенов - НЕ отказ: текст настоящий, просто оборванный.
     * Валить из-за него генерацию было бы регрессом.
     */
    public function test_length_finish_reason_is_not_refusal(): void {
        $this->assertFalse(refusal_detector::is_refusal(self::REAL_LECTURE, 'length'));
    }
}
