<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * Правило-рекомендатель (S2): из текущего владения навыками строит кандидатов-предложения.
 * Пробел (band=BAND_GAP) -> ремедиация навыка; стабильно освоено (band=BAND_MASTERED) ->
 * продвижение. Дедуп открытых предложений делает suggestion_service при создании, поэтому
 * здесь просто перечисляем кандидатов по текущему состоянию. ML-замена (lightfm_recommender)
 * подключается за тем же швом `recommender`.
 */
class rule_recommender implements recommender {

    public function recommend(int $student_id): array {
        global $DB;
        $rows = $DB->get_records('unics_skill_mastery', ['student_id' => $student_id]);
        if (!$rows) {
            return [];
        }
        $level = (int)$DB->get_field('unics_students', 'difficulty_level', ['id' => $student_id]);
        if ($level < 1) {
            $level = 1;
        }

        $out = [];
        foreach ($rows as $r) {
            $band = (int)$r->band;
            if ($band === rolling_avg_estimator::BAND_GAP) {
                $out[] = [
                    'kind'         => \local_unics\learning\suggestion_service::KIND_REMEDIATION,
                    'element_id'   => (int)$r->element_id,
                    'target_level' => $level,
                    'reason'       => 'Пробел по навыку (балл ' . round((float)$r->score) . '%)',
                ];
            } else if ($band === rolling_avg_estimator::BAND_MASTERED) {
                $out[] = [
                    'kind'         => \local_unics\learning\suggestion_service::KIND_ADVANCEMENT,
                    'element_id'   => (int)$r->element_id,
                    'target_level' => min(3, $level + 1),
                    'reason'       => 'Навык освоен (балл ' . round((float)$r->score) . '%)',
                ];
            }
        }
        return $out;
    }
}
