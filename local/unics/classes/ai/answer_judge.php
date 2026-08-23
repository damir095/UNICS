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

    /** Судья высказался, вердикты значимы. */
    public const STATUS_JUDGED = 'judged';

    /** Судья не ответил: сеть, отказ, неразобранный ответ. Вердиктов нет. */
    public const STATUS_FAILED = 'failed';

    /** Судья высказался, но себе противоречит: сработал предохранитель. Вердикты сняты. */
    public const STATUS_DISTRUST = 'distrust';

    /**
     * Доля расхождений, выше которой судье не верят вовсе.
     *
     * Судья, спорящий с генератором чаще чем в половине случаев, скорее сбился сам (потерял
     * нумерацию, ответил не на те вопросы), чем поймал массовый брак. Без порога одна порча
     * его ответа выкашивает весь комплект.
     */
    private const DISTRUST_SHARE = 0.5;

    /**
     * Со скольких высказываний судьи долевой порог включается.
     *
     * На одном-двух высказываниях доли нет: одно расхождение из одного - это сразу «сто
     * процентов», и порог глушил бы судью ВСЕГДА, обессмысливая ярус.
     */
    private const MIN_JUDGED_FOR_SHARE = 4;

    /**
     * Со скольких высказываний работает правило полного расхождения.
     *
     * Малые комплекты (один-четыре вопроса) - не редкость: пул заданий отдает воркеру ровно
     * столько мест, сколько осталось незабронированных (umk_processor), и долевой порог их не
     * прикрывает вовсе. Судья, разошедшийся со ВСЕМИ своими высказываниями, почти наверняка
     * сбился сам, и этого признака хватает без доли.
     */
    private const MIN_JUDGED_FOR_TOTAL = 2;

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
            $answers = array_values(array_filter((array)($item['answers'] ?? []), 'is_scalar'));
            shuffle($answers);
            $block = $n . '. ' . trim((string)($item['text'] ?? ''));
            foreach ($answers as $i => $a) {
                // По одному варианту в строке: так их видно и модели, и разбору.
                $block .= "\n" . ($i + 1) . ') ' . trim((string)$a);
            }
            $lines[] = $block;
        }
        $body = implode("\n\n", $lines);

        // Поле correct сюда не попадает и попасть не должно: слепота судьи - весь смысл яруса.
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
            // Нескалярный choice - не текст: без гейта (string) на массиве дает предупреждение
            // и литерал «Array», который потом сравнивается с вариантами. Прием донорский,
            // из question_tagger::parse.
            $choice = is_scalar($row['choice'] ?? null) ? trim((string)$row['choice']) : '';
            if ($i < 0 || $i >= $n || $choice === '') {
                continue;
            }
            if ($out[$i] !== null) {
                // Модель нередко дописывает к ответу образец формата из промта. Побеждает
                // ПЕРВЫЙ выбор: иначе эхо образца затирало бы настоящий ответ на вопрос.
                continue;
            }
            $out[$i] = $choice;
        }
        return $out;
    }

    /**
     * Сверка выборов судьи с ключами. Чистая: ни сети, ни базы.
     *
     * Ключи входного массива сохраняются: судью спрашивают только про то, что дожило до него,
     * и вызывающий вправе передать отфильтрованный массив с дырами в нумерации.
     *
     * @param array $questions [['text' => string, 'answers' => string[], 'correct' => int]]
     * @param array $picks выборы судьи по ПОРЯДКУ вопросов, индекс с нуля; null - молчание
     * @return array ['verdicts' => array<'ok'|'drop'>, 'status' => string,
     *                'judged' => int, 'disagreed' => int]
     */
    public static function verdicts(array $questions, array $picks): array {
        $keys = array_keys($questions);
        $list = array_values($questions);
        $verdicts = [];
        $disagreed = 0;
        $judged = 0;

        foreach ($list as $i => $q) {
            $pick = $picks[$i] ?? null;
            $verdicts[$i] = 'ok';
            if ($pick === null) {
                continue;
            }
            $answers = array_values(array_filter((array)($q['answers'] ?? []), 'is_scalar'));
            $norm = array_map([question_sanity::class, 'normalize'], $answers);
            $choice = question_sanity::normalize((string)$pick);
            $at = array_search($choice, $norm, true);
            if ($at === false) {
                // Судья назвал то, чего нет среди вариантов: это его сбой, а не брак задания.
                continue;
            }
            if (count(array_keys($norm, $choice, true)) > 1) {
                // Выбранный текст встречается дважды: какой из них имел в виду судья, неизвестно,
                // и «расхождение» тут было бы выдумкой. Дубли ловит question_sanity, но порядок
                // ярусов гарантируется вызывающим, а не этим классом.
                continue;
            }
            // Молчание и сбои судьи в знаменатель предохранителя не идут: считаем долю от тех
            // вопросов, по которым судья ДЕЙСТВИТЕЛЬНО высказался, иначе комплект с одним
            // расхождением и десятком молчаний выглядел бы благополучным.
            $judged++;
            if ((int)$at !== (int)($q['correct'] ?? -1)) {
                $verdicts[$i] = 'drop';
                $disagreed++;
            }
        }

        $total = $judged >= self::MIN_JUDGED_FOR_TOTAL && $disagreed === $judged;
        $share = $judged >= self::MIN_JUDGED_FOR_SHARE
            && $disagreed > $judged * self::DISTRUST_SHARE;
        if ($total || $share) {
            return [
                'verdicts' => array_combine($keys, array_fill(0, count($keys), 'ok')),
                'status' => self::STATUS_DISTRUST,
                'judged' => $judged,
                'disagreed' => $disagreed,
            ];
        }

        return [
            'verdicts' => array_combine($keys, $verdicts),
            'status' => self::STATUS_JUDGED,
            'judged' => $judged,
            'disagreed' => $disagreed,
        ];
    }

    /**
     * Спросить судью и вернуть вердикты. Отказ судьи не роняет комплект.
     *
     * Статус в ответе обязателен: без него вызывающий не отличит «судья согласился» от «судья
     * не запускался», и отказ сети выглядел бы чистым прогоном - ровно та ловушка, на которой
     * проект уже стоял с озвучкой ([[project_status_2026_08_10_tts_honest]]).
     *
     * @param array $questions [['text' => string, 'answers' => string[], 'correct' => int]]
     * @return array ['verdicts' => array<'ok'|'drop'>, 'status' => string,
     *                'judged' => int, 'disagreed' => int]
     */
    public function review(array $questions): array {
        if (!$questions) {
            return ['verdicts' => [], 'status' => self::STATUS_JUDGED,
                    'judged' => 0, 'disagreed' => 0];
        }
        try {
            $raw = $this->gen->generate_text(self::build_prompt($questions), 2048);
            $picks = self::parse($raw, count($questions));
        } catch (\moodle_exception $e) {
            // Ронять комплект из-за недоступности проверки нельзя: ребенок останется без теста
            // по причине, к его заданиям отношения не имеющей.
            return [
                'verdicts' => array_combine(array_keys($questions),
                    array_fill(0, count($questions), 'ok')),
                'status' => self::STATUS_FAILED,
                'judged' => 0,
                'disagreed' => 0,
            ];
        }
        $out = self::verdicts($questions, $picks);
        if ($out['judged'] === 0) {
            // Ответ разобрался, но ни одного пригодного выбора в нем нет - это тоже отказ.
            $out['status'] = self::STATUS_FAILED;
        }
        return $out;
    }
}
