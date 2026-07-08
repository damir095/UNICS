<?php
namespace local_unics;

use local_unics\learning\mastery_manager;
use local_unics\adaptive\rolling_avg_estimator;

/**
 * Тесты подписи полос владения (этап 5.2 аудита). band_label - чистая функция,
 * единый источник подписей для отчёта и виджета; детский текст без кодов/жаргона.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mastery_manager::class)]
final class mastery_manager_test extends \basic_testcase {

    public function test_band_label_staff(): void {
        $this->assertSame(['освоено', 'success'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_MASTERED));
        $this->assertSame(['в процессе', 'warning'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_MID));
        $this->assertSame(['пробел', 'danger'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_GAP));
        $this->assertSame(['мало попыток', 'secondary'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_INSUFFICIENT));
    }

    public function test_band_label_child(): void {
        $this->assertSame(['отлично', 'success'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_MASTERED, true));
        $this->assertSame(['почти', 'warning'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_MID, true));
        $this->assertSame(['нужно повторить', 'danger'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_GAP, true));
        // «Мало попыток» одинаково для обеих аудиторий.
        $this->assertSame(['мало попыток', 'secondary'],
            mastery_manager::band_label(rolling_avg_estimator::BAND_INSUFFICIENT, true));
    }

    public function test_band_label_unknown_falls_back(): void {
        $this->assertSame(['мало попыток', 'secondary'], mastery_manager::band_label(99));
    }
}
