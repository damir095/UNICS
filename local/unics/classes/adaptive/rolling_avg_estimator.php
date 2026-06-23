<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * PHP-фаза: владение навыком = экспоненциально-взвешенное скользящее среднее (EWMA),
 * свежая попытка весомее (ALPHA). Полосы (band) - пороговая производная score; пока
 * попыток меньше MIN_ATTEMPTS - полоса «недостаточно данных» (доверие не набрано).
 * weight связи в S1 не используется (зарезервирован в $ctx под per-question/ML).
 */
class rolling_avg_estimator implements mastery_estimator {

    /** Вес свежей попытки в EWMA (0..1). */
    const ALPHA = 0.5;
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

    public function estimate(?mastery_state $prior, array $ctx): mastery_state {
        $pct = (float)($ctx['pct'] ?? 0);
        $pct = max(0.0, min(100.0, $pct)); // клиппинг входа в 0..100

        if ($prior === null) {
            $score = $pct;
            $n = 1;
        } else {
            $score = self::ALPHA * $pct + (1 - self::ALPHA) * $prior->score;
            $n = $prior->attempts_n + 1;
        }
        $score = round($score, 2);

        return new mastery_state($score, self::band_for($score, $n), $n);
    }

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
}
