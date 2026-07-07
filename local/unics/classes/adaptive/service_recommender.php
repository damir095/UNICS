<?php
namespace local_unics\adaptive;

defined('MOODLE_INTERNAL') || die();

/**
 * ML-реализация шва recommender: ранжирует навыки через Python-сервис (content_v1) и отдает
 * кандидатов только для топ-N приоритетных. При сбое/пустом ответе - graceful fallback на
 * rule_recommender. Формат кандидатов - как у rule_recommender (generate_suggestions не меняется).
 */
class service_recommender implements recommender {

    /** Сколько навыков предлагать за раз (фокус, не заваливать педагога). */
    const TOP_N = 3;

    public function recommend(int $student_id): array {
        global $DB;
        $map = \local_unics\learning\mastery_manager::get_student_mastery_map($student_id);
        if (!$map) {
            return [];
        }
        // Глубина из материализованного path ("/.../id/" -> число '/' минус 2).
        $paths = $DB->get_records_list('unics_codifier_element', 'id', array_keys($map), '', 'id, path');
        $skills = [];
        foreach ($map as $eid => $m) {
            $path = isset($paths[$eid]) ? (string)$paths[$eid]->path : '';
            $depth = $path !== '' ? max(0, substr_count($path, '/') - 2) : 0;
            $skills[] = [
                'element_id' => (int)$eid,
                'depth'      => $depth,
                'score'      => (float)$m->score,
                'band'       => (int)$m->band,
                'theta'      => $m->theta,
                'theta_se'   => $m->theta_se,
                'attempts_n' => (int)$m->attempts_n,
            ];
        }
        $recs = irt_client::recommend($skills, self::TOP_N);
        if ($recs === null || !$recs) {
            return (new rule_recommender())->recommend($student_id); // graceful fallback
        }
        $level = (int)$DB->get_field('unics_students', 'difficulty_level', ['id' => $student_id]);
        if ($level < 1) {
            $level = 1;
        }
        $out = [];
        foreach ($recs as $r) {
            $eid = (int)($r['element_id'] ?? 0);
            $kind = (string)($r['kind'] ?? '');
            $score = isset($map[$eid]) ? (int)round((float)$map[$eid]->score) : 0;
            $reason = self::reason_text((string)($r['reason_code'] ?? ''), $score);
            if ($kind === 'remediation') {
                $out[] = ['kind' => \local_unics\learning\suggestion_service::KIND_REMEDIATION,
                    'element_id' => $eid, 'target_level' => $level, 'reason' => $reason];
            } else if ($kind === 'advancement') {
                $out[] = ['kind' => \local_unics\learning\suggestion_service::KIND_ADVANCEMENT,
                    'element_id' => $eid, 'target_level' => min(3, $level + 1), 'reason' => $reason];
            }
        }
        return $out;
    }

    /** Русский текст причины из reason_code сервиса (+ балл). */
    private static function reason_text(string $code, int $score): string {
        switch ($code) {
            case 'gap_foundational':
                return 'Базовый навык с пробелом (балл ' . $score . '%)';
            case 'gap_severe':
                return 'Существенный пробел (балл ' . $score . '%)';
            case 'mastered_advance':
                return 'Навык освоен, можно продвигаться (балл ' . $score . '%)';
            default:
                return 'Пробел по навыку (балл ' . $score . '%)';
        }
    }
}
