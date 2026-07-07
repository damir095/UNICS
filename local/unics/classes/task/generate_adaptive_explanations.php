<?php
namespace local_unics\task;

defined('MOODLE_INTERNAL') || die();

/**
 * S3: фоновая генерация ИИ-обоснований «почему этот шаг» для открытых адаптивных
 * предложений [[adaptive-ai-design]]. Гейт: работает ТОЛЬКО при включённом флаге
 * local_unics/adaptive_ai_explanations (по умолчанию OFF - заморозка ИИ). Для каждого
 * открытого предложения без rationale собирает факты (тип шага, навык, целевой уровень,
 * последний балл по навыку) и просит GigaChat короткое пояснение. Graceful: нет ключа /
 * сбой -> rationale остаётся пустым (повтор в следующий прогон). Решений LLM не принимает.
 */
class generate_adaptive_explanations extends \core\task\scheduled_task {

    /** Сколько предложений обрабатывать за один прогон (бережём внешний API). */
    const BATCH = 20;

    public function get_name(): string {
        return 'УНИКС: ИИ-обоснования адаптивных предложений';
    }

    public function execute(): void {
        global $DB, $CFG;

        // Гейт флагом - живые вызовы выключены по умолчанию (заморозка).
        if ((int)get_config('local_unics', 'adaptive_ai_explanations') !== 1) {
            mtrace('УНИКС ИИ-обоснования: флаг выключен - пропуск.');
            return;
        }

        require_once($CFG->dirroot . '/local/unics/classes/ai/ai_generator.php');
        require_once($CFG->dirroot . '/local/unics/classes/suggestion_service.php');

        $rows = $DB->get_records_select('unics_adaptive_suggestion',
            'status = :st AND (rationale IS NULL OR rationale = :empty)',
            ['st' => \local_unics\suggestion_service::STATUS_PENDING, 'empty' => ''],
            'created_at ASC', '*', 0, self::BATCH);
        if (!$rows) {
            mtrace('УНИКС ИИ-обоснования: нечего генерировать.');
            return;
        }

        $levels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        $gen = new \local_unics\ai\ai_generator();
        $done = 0;

        foreach ($rows as $s) {
            $payload = json_decode((string)$s->payload, true) ?: [];

            $skill_title = '';
            if (!empty($s->element_id)) {
                $skill_title = (string)$DB->get_field('unics_codifier_element', 'title', ['id' => (int)$s->element_id]);
            }
            $last_score = null;
            if (!empty($s->element_id)) {
                $ls = $DB->get_field('unics_skill_mastery', 'last_score',
                    ['student_id' => (int)$s->student_id, 'element_id' => (int)$s->element_id]);
                if ($ls !== false && $ls !== null) {
                    $last_score = (float)$ls;
                }
            }
            $tl = isset($payload['target_level']) ? (int)$payload['target_level'] : 0;

            $text = $gen->generate_rationale([
                'kind_label'         => \local_unics\suggestion_service::kind_label((int)$s->kind),
                'skill_title'        => trim($skill_title),
                'target_level_label' => $levels[$tl] ?? '',
                'last_score'         => $last_score,
            ]);

            if ($text !== null && $text !== '') {
                $DB->set_field('unics_adaptive_suggestion', 'rationale', $text, ['id' => (int)$s->id]);
                $done++;
            }
        }
        mtrace("УНИКС ИИ-обоснования: заполнено {$done} из " . count($rows) . '.');
    }
}
