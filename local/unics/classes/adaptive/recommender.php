<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Шов рекомендателя следующего шага. PHP-реализация: rule_recommender (пробел ->
 * ремедиация, освоено -> продвижение) - НАПОЛНЯЕТСЯ в S2. ML-реализация позже:
 * lightfm_recommender / content-based. В S1 шов существует пустым - чтобы потребители
 * (S2-гейт) уже могли на него ссылаться без изменений при подмене реализации.
 */
interface recommender {

    /**
     * Предложения для ученика на основе текущего владения навыками.
     *
     * @return array список предложений (структура определяется в S2); S1 - пусто
     */
    public function recommend(int $student_id): array;
}
