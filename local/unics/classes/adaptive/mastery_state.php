<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Снимок владения навыком: score (0-100), band (полоса), attempts_n (число попыток).
 * Иммутабельное value-object - результат оценщика. Хранилищем не владеет.
 */
class mastery_state {
    public float $score;
    public int $band;
    public int $attempts_n;
    public ?float $theta;
    public ?float $theta_se;

    public function __construct(float $score, int $band, int $attempts_n,
                                ?float $theta = null, ?float $theta_se = null) {
        $this->score = $score;
        $this->band = $band;
        $this->attempts_n = $attempts_n;
        $this->theta = $theta;
        $this->theta_se = $theta_se;
    }
}
