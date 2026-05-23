<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/ai_generator.php');
require_once(dirname(__FILE__) . '/grade_scale.php');

/**
 * Автоматическая предварительная проверка развёрнутых текстовых ответов
 * (сочинения, эссе, ответы на открытые вопросы) средствами ИИ.
 *
 * Реализация задачи 5/8 документа руководителя ([[guide-alignment-plan]] D2.1).
 * Принцип: «педагог в контуре» — ИИ выдаёт ПОДСКАЗКУ (балл + комментарий),
 * педагог принимает решение. Автоматическое выставление оценки НЕ делается.
 */
class essay_checker {

    /**
     * Оценить один развёрнутый ответ.
     *
     * Балл возвращается в ЕДИНОЙ шкале УНИКС ([[grade_scale]], сейчас 0..5),
     * чтобы тесты и развёрнутые задания были сопоставимы.
     *
     * @param string $question   Текст задания/вопроса.
     * @param string $answer     Ответ учащегося (plain text).
     * @param string $criteria   Необязательные критерии оценивания.
     * @return array{score: float, feedback: string, raw: string}
     */
    public static function evaluate(string $question, string $answer,
            string $criteria = ''): array {

        $answer = trim($answer);
        if ($answer === '') {
            return ['score' => 0.0, 'feedback' => 'Ответ пуст — оценивать нечего.', 'raw' => ''];
        }

        $scale_max = grade_scale::MAX;

        $crit_block = $criteria !== ''
            ? "Критерии оценивания от педагога:\n{$criteria}\n"
            : "Критерии: полнота раскрытия темы, фактическая корректность, "
            . "логичность и связность изложения, грамотность.\n";

        $prompt =
            "Ты — помощник педагога, проверяющий развёрнутый письменный ответ ученика. "
            . "Оцени ответ строго и объективно.\n\n"
            . "ЗАДАНИЕ:\n{$question}\n\n"
            . "ОТВЕТ УЧЕНИКА:\n{$answer}\n\n"
            . $crit_block
            . "Шкала оценивания: от 0 до {$scale_max} баллов (единая шкала, "
            . "{$scale_max} — отлично, 0 — ответ отсутствует или неверен).\n\n"
            . "Верни СТРОГО один JSON-объект без markdown и пояснений вокруг, в формате:\n"
            . '{"score": <число от 0 до ' . $scale_max . '>, '
            . '"feedback": "<краткий разбор: что хорошо, что улучшить, 2-4 предложения, по-русски>"}';

        $generator = new ai_generator();
        $raw = $generator->generate_text($prompt, 700);

        $parsed = self::parse_json($raw);

        $score = isset($parsed['score']) ? (float)$parsed['score'] : 0.0;
        // Нормализуем в диапазон единой шкалы [0; grade_scale::MAX].
        if ($score < 0) {
            $score = 0.0;
        }
        if ($score > $scale_max) {
            $score = (float)$scale_max;
        }
        $score = round($score, 1);

        $feedback = isset($parsed['feedback']) && is_string($parsed['feedback'])
            ? trim($parsed['feedback'])
            : '';
        if ($feedback === '') {
            // Если модель не вернула валидный JSON — отдаём сырой текст как комментарий.
            $feedback = trim($raw) !== ''
                ? 'Не удалось разобрать структурированный ответ ИИ. Текст ответа модели:' . "\n" . trim($raw)
                : 'ИИ не вернул ответ. Повторите попытку позже.';
        }

        return ['score' => $score, 'feedback' => $feedback, 'raw' => $raw];
    }

    /**
     * Достаёт первый JSON-объект из ответа модели (модель иногда оборачивает
     * в ```json ... ``` или добавляет текст вокруг).
     */
    private static function parse_json(string $raw): array {
        $raw = trim($raw);

        // Снять обёртку ```json ... ```
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw);

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Попытка вырезать первый {...} блок.
        if (preg_match('/\{.*\}/su', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
