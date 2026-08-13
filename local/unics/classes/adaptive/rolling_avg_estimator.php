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

    // Полосы переехали в mastery_bands (политика ядра, общая для всех оценщиков).
    // Константы и band_for остаются здесь ДЕЛЕГАТАМИ: их зовет irt_estimator, а также
    // существующие тесты. Убирать их нельзя - сломается IRT-оценщик.
    const MIN_ATTEMPTS       = mastery_bands::MIN_ATTEMPTS;
    const THRESHOLD_MASTERED = mastery_bands::THRESHOLD_MASTERED;
    const THRESHOLD_GAP      = mastery_bands::THRESHOLD_GAP;
    const BAND_INSUFFICIENT  = mastery_bands::BAND_INSUFFICIENT;
    const BAND_GAP           = mastery_bands::BAND_GAP;
    const BAND_MID           = mastery_bands::BAND_MID;
    const BAND_MASTERED      = mastery_bands::BAND_MASTERED;

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

        return new mastery_state($score, mastery_bands::band_for($score, $n), $n);
    }

    /** Полоса по score и числу попыток. Делегат к mastery_bands. */
    public static function band_for(float $score, int $attempts_n): int {
        return mastery_bands::band_for($score, $attempts_n);
    }
}
