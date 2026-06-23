<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * ML-реализация шва mastery_estimator: способность theta по навыку через Python-сервис (Rasch).
 * Нет ответов с параметрами / сервис недоступен / ошибка -> graceful fallback на
 * rolling_avg_estimator (цикл не ломается). score = проекция theta; полоса band - теми же порогами
 * rolling_avg_estimator::band_for (оценщики сопоставимы).
 */
class irt_estimator implements mastery_estimator {

    public function estimate(?mastery_state $prior, array $ctx): mastery_state {
        $responses = $ctx['responses'] ?? [];
        $fallback = new rolling_avg_estimator();
        if (empty($responses)) {
            return $fallback->estimate($prior, $ctx);
        }
        $prior_theta = $prior !== null ? $prior->theta : null;
        $prior_se = $prior !== null ? $prior->theta_se : null;
        // Привести ответы {b,correct} к контракту клиента {difficulty,correct}.
        $payload = array_map(
            fn($r) => ['difficulty' => (float)$r['b'], 'correct' => (int)$r['correct']],
            $responses);
        $res = irt_client::estimate($payload, $prior_theta, $prior_se);
        if ($res === null) {
            return $fallback->estimate($prior, $ctx);
        }
        $theta = (float)$res['theta'];
        $se = (float)$res['se'];
        $score = self::project($theta);
        $n = ($prior !== null ? $prior->attempts_n : 0) + 1;
        $band = rolling_avg_estimator::band_for($score, $n);
        return new mastery_state($score, $band, $n, $theta, $se);
    }

    /** Проекция theta -> score 0..100 (логистическая шкала; theta=0 -> 50). */
    public static function project(float $theta): float {
        return round(100.0 / (1.0 + exp(-$theta)), 2);
    }
}
