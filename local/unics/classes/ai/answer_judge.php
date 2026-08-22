<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Слепой судья: повторный ответ модели, не видящей ключа ([[answer-judge-design]], раздел 2.2).
 *
 * Второй из двух ярусов, работающих там, где арифметическому верификатору нечего посчитать.
 * Судья получает вопрос и варианты БЕЗ указания ключа и просто выбирает ответ; расхождение с
 * ключом отбрасывает вопрос.
 *
 * Почему отдельный вызов, а не поле в том же ответе: зонд 2026-08-22
 * ([[probe-full-cycle-2026-08-22]]) поймал модель на том, что она ошиблась в приложенном решении
 * ровно так же, как в ключе, и подтвердила собственную ошибку. Самоотчет в одном ответе - не
 * свидетельство: свидетель один.
 *
 * Почему варианты перемешиваются: позиционная привычка модели при ответе иначе совпала бы с ее
 * же привычкой при генерации, и согласие снова оказалось бы согласием одного.
 *
 * Ключ по мнению судьи НЕ переносится: вне математики он не авторитет, его расхождение - сигнал
 * спорности задания, а не установление истины.
 *
 * @package local_unics
 */
class answer_judge {

    /**
     * Доля расхождений, выше которой судье не верят вовсе.
     *
     * Судья, спорящий с генератором чаще чем в половине случаев, скорее сбился сам (потерял
     * нумерацию, ответил не на те вопросы), чем поймал массовый брак. Без порога одна порча
     * его ответа выкашивает весь комплект.
     */
    private const DISTRUST_SHARE = 0.5;

    /**
     * Со скольких высказываний судьи предохранитель вообще включается.
     *
     * На одном-двух высказываниях доли нет: одно расхождение из одного - это сразу «сто
     * процентов», и предохранитель глушил бы судью ВСЕГДА, обессмысливая ярус. Порог имеет
     * смысл лишь тогда, когда судья высказался по комплекту целиком (типовой комплект - пять
     * вопросов).
     */
    private const MIN_JUDGED_FOR_DISTRUST = 4;

    /** @var ai_generator генератор; внедряется конструктором - шов для тестов. */
    private ai_generator $gen;

    public function __construct(?ai_generator $gen = null) {
        $this->gen = $gen ?? new ai_generator();
    }

    /**
     * Промт судьи: вопросы с перемешанными вариантами, без ключа.
     *
     * @param array $items [['text' => string, 'answers' => string[], 'correct' => int]]
     */
    public static function build_prompt(array $items): string {
        $lines = [];
        $n = 0;
        foreach ($items as $item) {
            $n++;
            $answers = array_values((array)($item['answers'] ?? []));
            shuffle($answers);
            $block = $n . '. ' . trim((string)($item['text'] ?? ''));
            foreach ($answers as $i => $a) {
                // По одному варианту в строке: так их видно и модели, и разбору.
                $block .= "\n" . ($i + 1) . ') ' . trim((string)$a);
            }
            $lines[] = $block;
        }
        $body = implode("\n\n", $lines);

        return "Ты - учитель, который решает тестовые задания.

Для каждого вопроса выбери ОДИН верный ответ. В поле choice впиши текст выбранного варианта
дословно, как он записан в списке. Если уверенного ответа нет, оставь choice пустым.

{$body}

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов:
{\"answers\":[{\"n\":1,\"choice\":\"дословный текст варианта\"}]}";
    }

    /**
     * Разбор ответа судьи в список выборов по номерам вопросов.
     *
     * @return array<int,?string> индекс с нуля; null - судья промолчал или не уверен
     */
    public static function parse(string $raw, int $n): array {
        $out = array_fill(0, $n, null);
        $data = json_reply::decode($raw, 'answers');
        foreach ((array)($data['answers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Нумерация судьи - от единицы; пропущенный вопрос НЕ должен смещать остальные.
            $i = (int)($row['n'] ?? 0) - 1;
            $choice = trim((string)($row['choice'] ?? ''));
            if ($i < 0 || $i >= $n || $choice === '') {
                continue;
            }
            $out[$i] = $choice;
        }
        return $out;
    }

    /**
     * Сверка выборов судьи с ключами. Чистая: ни сети, ни базы.
     *
     * @param array $questions [['text' => string, 'answers' => string[], 'correct' => int]]
     * @param array $picks выборы судьи, индекс с нуля, null - молчание
     * @return string[] 'ok'|'drop' по каждому вопросу
     */
    public static function verdicts(array $questions, array $picks): array {
        $questions = array_values($questions);
        $verdicts = [];
        $disagreed = 0;
        $judged = 0;

        foreach ($questions as $i => $q) {
            $pick = $picks[$i] ?? null;
            $verdicts[$i] = 'ok';
            if ($pick === null) {
                continue;
            }
            $norm = array_map([question_sanity::class, 'normalize'],
                array_values((array)($q['answers'] ?? [])));
            $choice = question_sanity::normalize((string)$pick);
            $at = array_search($choice, $norm, true);
            if ($at === false) {
                // Судья назвал то, чего нет среди вариантов: это его сбой, а не брак задания.
                continue;
            }
            // Молчание и сбои судьи в знаменатель предохранителя не идут: иначе три молчания
            // и одно расхождение выглядели бы как «сбился на большинстве».
            $judged++;
            if ((int)$at !== (int)($q['correct'] ?? -1)) {
                $verdicts[$i] = 'drop';
                $disagreed++;
            }
        }

        if ($judged >= self::MIN_JUDGED_FOR_DISTRUST
                && $disagreed > $judged * self::DISTRUST_SHARE) {
            return array_fill(0, count($questions), 'ok');
        }
        return $verdicts;
    }

    /**
     * Спросить судью и вернуть вердикты. Отказ судьи не роняет комплект.
     *
     * @param array $questions [['text' => string, 'answers' => string[], 'correct' => int]]
     * @return string[] 'ok'|'drop' по каждому вопросу
     */
    public function review(array $questions): array {
        $questions = array_values($questions);
        if (!$questions) {
            return [];
        }
        try {
            $raw = $this->gen->generate_text(self::build_prompt($questions), 2048);
            $picks = self::parse($raw, count($questions));
        } catch (\moodle_exception $e) {
            // Ронять комплект из-за недоступности проверки нельзя: ребенок останется без теста
            // по причине, к его заданиям отношения не имеющей. След пишет вызывающий.
            return array_fill(0, count($questions), 'ok');
        }
        return self::verdicts($questions, $picks);
    }
}
