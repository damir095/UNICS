<?php
namespace unicsest_irt;

use local_unics\adaptive\item_response_consumer;
use local_unics\adaptive\mastery_bands;
use local_unics\adaptive\mastery_estimator;
use local_unics\adaptive\mastery_state;
use local_unics\adaptive\rolling_avg_estimator;
use local_unics\adaptive\irt_client;
use local_unics\adaptive\theta_scale;

defined('MOODLE_INTERNAL') || die();

/**
 * Оценщик владения через Python-сервис (модель Раша / 2PL). Первый подплагин типа
 * `unicsest` - он же доказательство, что точка расширения рабочая, а не декларация.
 *
 * Реализует `item_response_consumer`: ядру этот маркер говорит, что оценщику нужны
 * ответы по отдельным заданиям с параметрами, и сборку такого контекста стоит делать.
 *
 * Откат: нет ответов с параметрами, сервис недоступен, ошибка - считаем встроенным
 * `rolling_avg_estimator`. Адаптивный цикл не должен ломаться из-за внешнего сервиса.
 * Полосы берутся из `mastery_bands` - те же, что у встроенного оценщика, иначе
 * значения в `unics_skill_mastery` были бы несопоставимы между режимами.
 */
class estimator implements mastery_estimator, item_response_consumer {

    public function estimate(?mastery_state $prior, array $ctx): mastery_state {
        $responses = $ctx['responses'] ?? [];
        $fallback = new rolling_avg_estimator();
        if (empty($responses)) {
            return $fallback->estimate($prior, $ctx);
        }
        $prior_theta = $prior !== null ? $prior->theta : null;
        $prior_se = $prior !== null ? $prior->theta_se : null;
        // Привести ответы {a,b,correct} к контракту клиента {discrimination,difficulty,correct}.
        $payload = array_map(
            fn($r) => ['discrimination' => (float)($r['a'] ?? 1.0), 'difficulty' => (float)$r['b'],
                'correct' => (int)$r['correct']],
            $responses);
        $res = irt_client::estimate($payload, $prior_theta, $prior_se);
        if ($res === null) {
            return $fallback->estimate($prior, $ctx);
        }
        $theta = (float)$res['theta'];
        $se = (float)$res['se'];
        $score = theta_scale::project($theta);
        $n = ($prior !== null ? $prior->attempts_n : 0) + 1;
        $band = mastery_bands::band_for($score, $n);
        return new mastery_state($score, $band, $n, $theta, $se);
    }
}
