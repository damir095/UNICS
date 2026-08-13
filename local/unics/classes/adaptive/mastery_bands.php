<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Полосы владения навыком - политика УНИКС, а не дело оценщика. Все оценщики обязаны
 * ложиться на одну шкалу, иначе их результаты несопоставимы (об этом прямо сказано в
 * комментарии irt_estimator). Раньше пороги лежали внутри rolling_avg_estimator, и любой
 * новый оценщик был вынужден звать класс другого оценщика ради констант - это и мешало
 * объявить точку расширения. [[estimator-subplugin-design]]
 */
class mastery_bands {

    /** Меньше этого числа попыток - полоса «недостаточно данных». */
    const MIN_ATTEMPTS = 2;
    /** score >= этого - навык освоен. */
    const THRESHOLD_MASTERED = 85;
    /** score < этого - пробел. */
    const THRESHOLD_GAP = 50;

    const BAND_INSUFFICIENT = 0;
    const BAND_GAP          = 1;
    const BAND_MID          = 2;
    const BAND_MASTERED     = 3;

    /** Полоса по score и числу попыток. */
    public static function band_for(float $score, int $attempts_n): int {
        if ($attempts_n < self::MIN_ATTEMPTS) {
            return self::BAND_INSUFFICIENT;
        }
        if ($score >= self::THRESHOLD_MASTERED) {
            return self::BAND_MASTERED;
        }
        if ($score < self::THRESHOLD_GAP) {
            return self::BAND_GAP;
        }
        return self::BAND_MID;
    }

    /**
     * Подпись полосы: [текст, bootstrap-класс]. Детский вариант мягче по формулировке -
     * «нужно повторить» вместо «пробел».
     *
     * @return array{0:string,1:string}
     */
    public static function label(int $band, bool $child = false): array {
        switch ($band) {
            case self::BAND_MASTERED:
                return [$child ? 'отлично' : 'освоено', 'success'];
            case self::BAND_MID:
                return [$child ? 'почти' : 'в процессе', 'warning'];
            case self::BAND_GAP:
                return [$child ? 'нужно повторить' : 'пробел', 'danger'];
            default: // BAND_INSUFFICIENT
                return ['мало попыток', 'secondary'];
        }
    }
}
