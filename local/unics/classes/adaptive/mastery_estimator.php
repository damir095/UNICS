<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Шов оценки владения навыком (ради безболезненной ML-миграции). Потребитель -
 * mastery_manager - сам читает/пишет хранилище; реализация только считает.
 * PHP-реализация: rolling_avg_estimator. ML-реализация позже: irt_estimator
 * (подключается без изменения потребителей; $ctx понесет ответы/параметры заданий).
 */
interface mastery_estimator {

    /**
     * Пересчитать владение из прежнего состояния + одной оцененной попытки. Чистая функция.
     *
     * @param mastery_state|null $prior null = первая попытка по навыку
     * @param array $ctx ['pct' => float 0..100, 'weight' => int|null, 'cmid' => int]
     * @return mastery_state новое состояние
     */
    public function estimate(?mastery_state $prior, array $ctx): mastery_state;
}
