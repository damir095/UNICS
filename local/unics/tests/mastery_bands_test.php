<?php
namespace local_unics;

use local_unics\adaptive\mastery_bands;
use local_unics\adaptive\rolling_avg_estimator;

/**
 * Полосы владения - политика ядра, общая для всех оценщиков. Раньше пороги лежали внутри
 * rolling_avg_estimator, и чужой оценщик был вынужден звать класс другого оценщика ради
 * констант. Тест держит и новый источник правды, и делегаты (их зовет irt_estimator).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mastery_bands::class)]
final class mastery_bands_test extends \basic_testcase {

    public function test_band_for_thresholds(): void {
        // Меньше MIN_ATTEMPTS - доверия нет независимо от score.
        $this->assertSame(mastery_bands::BAND_INSUFFICIENT, mastery_bands::band_for(100.0, 1));
        // Границы включительно/исключительно.
        $this->assertSame(mastery_bands::BAND_MASTERED, mastery_bands::band_for(85.0, 2));
        $this->assertSame(mastery_bands::BAND_MID, mastery_bands::band_for(84.99, 2));
        $this->assertSame(mastery_bands::BAND_MID, mastery_bands::band_for(50.0, 2));
        $this->assertSame(mastery_bands::BAND_GAP, mastery_bands::band_for(49.99, 2));
    }

    public function test_labels_for_staff_and_child(): void {
        $this->assertSame(['освоено', 'success'], mastery_bands::label(mastery_bands::BAND_MASTERED));
        $this->assertSame(['отлично', 'success'], mastery_bands::label(mastery_bands::BAND_MASTERED, true));
        $this->assertSame(['нужно повторить', 'danger'], mastery_bands::label(mastery_bands::BAND_GAP, true));
        $this->assertSame(['мало попыток', 'secondary'], mastery_bands::label(99));
    }

    /**
     * Делегаты обязаны жить: их зовет irt_estimator (файл в незакоммиченном WIP, править
     * его нельзя). Если делегат отвалится, IRT-оценщик сломается молча.
     */
    public function test_rolling_avg_still_delegates(): void {
        $this->assertSame(mastery_bands::BAND_GAP, rolling_avg_estimator::band_for(10.0, 5));
        $this->assertSame(mastery_bands::BAND_MASTERED, rolling_avg_estimator::BAND_MASTERED);
        $this->assertSame(mastery_bands::BAND_GAP, rolling_avg_estimator::BAND_GAP);
        $this->assertSame(mastery_bands::BAND_MID, rolling_avg_estimator::BAND_MID);
        $this->assertSame(mastery_bands::BAND_INSUFFICIENT, rolling_avg_estimator::BAND_INSUFFICIENT);
    }
}
