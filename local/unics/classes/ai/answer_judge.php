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
 * спорности задания, а не установление истины. Ключ правит то, что ВЫЧИСЛЯЕТ ответ
 * (arithmetic_checker), а не то, что его мнит.
 *
 * Правило проверено на прочность 2026-08-27: расхождения судьи заметно чаще ожидаемого ложатся на
 * вариант ПЕРЕД ключом, то есть ключ бывает сбит на единицу. Соблазн переставить его по выбору
 * судьи был реализован и откачен - выигрыш вышел два вопроса из сорока при полных комплектах, а
 * цена ошибки прежняя: «неверно» за верный ответ и порча калибровки IRT через общий пул
 * ([[judge-key-shift-design]]). Поле `at` осталось - но только чтобы такие случаи СЧИТАТЬ.
 *
 * @package local_unics
 */
class answer_judge {

    /**
     * По этим словам заглушки тестов отличают промт судьи от промта генерации.
     *
     * Константа, а не литерал в каждом тесте: перефразировка промта - обычная правка, и три
     * независимые копии строки молча превратили бы вызов судьи в вызов генерации.
     */
    public const PROMPT_MARKER = 'решает тестовые задания';

    /** Судья высказался, вердикты значимы. */
    public const STATUS_JUDGED = 'judged';

    /** Судья ответил, но ни один выбор не пригодился: формат ответа разошелся с просьбой. */
    public const STATUS_UNUSABLE = 'unusable';

    /** Судья не ответил: сеть, отказ, неразобранный ответ. Вердиктов нет. */
    public const STATUS_FAILED = 'failed';

    /** Судья спорит с большинством ключей: нужен переспрос, вердикты еще не решены. */
    public const STATUS_SUSPECT = 'suspect';

    /** Переспрос не подтвердил первый ответ: судья сбивается, вердикты сняты. */
    public const STATUS_DISTRUST = 'distrust';

    /** Переспрос подтвердил первый ответ: массовое расхождение настоящее, вердикты в силе. */
    public const STATUS_CONFIRMED = 'confirmed';

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
        return "Ты - учитель, который " . self::PROMPT_MARKER . ".

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
     * Нормализованный текст выбора судьи со снятой ведущей нумерацией.
     *
     * Живой заход 2026-08-23: судья отвечает то «Полярный», то «4) Полярный» - формат гуляет
     * между запросами. Пока номер не снимался, второй вид ответа не совпадал ни с одним
     * вариантом, ярус молча принимал весь комплект, и мимо прошли неверные ключи, на которые
     * судья ответил верно. Снимается ТОЛЬКО ведущая нумерация: ответ «1861» остается собой.
     */
    private static function choice_text(string $pick): string {
        // Правило одно на весь плагин: тем же приемом генератор ищет ключ, когда просит у модели
        // ТЕКСТ верного ответа. Разъехавшись, две копии дали бы разный ключ у разных ярусов.
        return question_sanity::option_text($pick);
    }

    /**
     * Сверка выборов судьи с ключами. Чистая: ни сети, ни базы.
     *
     * Ключи входного массива сохраняются: судью спрашивают только про то, что дожило до него,
     * и вызывающий вправе передать отфильтрованный массив с дырами в нумерации.
     *
     * @param array $questions [['text' => string, 'answers' => string[], 'correct' => int]]
     * @param array $picks выборы судьи по ПОРЯДКУ вопросов, индекс с нуля; null - молчание
     * @return array ['verdicts' => array<'ok'|'drop'>,
     *                'at' => array<int,?int> куда лег выбор судьи в НАШЕМ порядке вариантов
     *                        (null - судья промолчал или его выбор не нашелся),
     *                'status' => string, 'judged' => int, 'disagreed' => int]
     */
    public static function verdicts(array $questions, array $picks): array {
        $keys = array_keys($questions);
        $list = array_values($questions);
        $verdicts = [];
        $at_list = [];
        $disagreed = 0;
        $judged = 0;

        foreach ($list as $i => $q) {
            $pick = $picks[$i] ?? null;
            $verdicts[$i] = 'ok';
            // Куда лег выбор судьи в НАШЕМ порядке вариантов. Вызывающему этого не вычислить:
            // судье варианты перемешиваются, и отвечает он текстом, а не номером.
            $at_list[$i] = null;
            if ($pick === null) {
                continue;
            }
            $answers = array_values(array_filter((array)($q['answers'] ?? []), 'is_scalar'));
            $norm = array_map([question_sanity::class, 'normalize'], $answers);
            $choice = self::choice_text((string)$pick);
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
            $at_list[$i] = (int)$at;
            if ((int)$at !== (int)($q['correct'] ?? -1)) {
                $verdicts[$i] = 'drop';
                $disagreed++;
            }
        }

        // Массовое расхождение НЕ снимает вердикты само по себе: живой заход 2026-08-23 показал,
        // что оно бывает настоящим. Модель выдала четыре вопроса по истории Петра I, из них три
        // с заведомо неверными ключами (Казань вместо Петербурга, «запретил обучение грамоте»,
        // Северная война с Финляндией) - судья ответил верно на все три, а прежний порог его
        // вердикты снял и отправил негодные вопросы детям. Здесь только помечаем комплект
        // подозрительным; решает переспрос в review().
        $total = $judged >= self::MIN_JUDGED_FOR_TOTAL && $disagreed === $judged;
        $share = $judged >= self::MIN_JUDGED_FOR_SHARE
            && $disagreed > $judged * self::DISTRUST_SHARE;

        return [
            'verdicts' => array_combine($keys, $verdicts),
            'at' => array_combine($keys, $at_list),
            'status' => ($total || $share) ? self::STATUS_SUSPECT : self::STATUS_JUDGED,
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
     * @return array ['verdicts' => array<'ok'|'drop'>, 'at' => array<int,?int>,
     *                'status' => string, 'judged' => int, 'disagreed' => int]
     */
    public function review(array $questions): array {
        if (!$questions) {
            return ['verdicts' => [], 'at' => [], 'status' => self::STATUS_JUDGED,
                    'judged' => 0, 'disagreed' => 0];
        }
        try {
            $picks = $this->ask($questions);
        } catch (\Throwable $e) {
            // Ронять комплект из-за недоступности проверки нельзя: ребенок останется без теста
            // по причине, к его заданиям отношения не имеющей. Ловим Throwable, а не только
            // moodle_exception: TypeError из сетевого слоя (curl_init вернул false) прошел бы
            // насквозь и убил бы комплект, уже прошедший все три яруса.
            return [
                'verdicts' => array_combine(array_keys($questions),
                    array_fill(0, count($questions), 'ok')),
                'at' => array_combine(array_keys($questions),
                    array_fill(0, count($questions), null)),
                'status' => self::STATUS_FAILED,
                'judged' => 0,
                'disagreed' => 0,
            ];
        }
        $out = self::verdicts($questions, $picks);
        if ($out['judged'] === 0) {
            // Ответ разобрался, но ни один выбор не пригодился - модель ответила номерами
            // вместо текста или назвала свои варианты. Это НЕ отказ сети: путать их нельзя,
            // иначе устойчивое расхождение форматов читалось бы как перебои связи.
            $out['status'] = self::STATUS_UNUSABLE;
            return $out;
        }
        if ($out['status'] !== self::STATUS_SUSPECT) {
            return $out;
        }

        // Массовое расхождение бывает двух родов, и по одному ответу они неразличимы: либо
        // судья сбился (потерял нумерацию), либо генератор действительно наврал в большинстве
        // ключей - на стенде это происходило не раз. Спрашиваем второй раз: варианты
        // перемешиваются заново, поэтому сбитая нумерация повторно не воспроизведется, а
        // настоящее знание - воспроизведется.
        try {
            $second = $this->ask($questions);
        } catch (\Throwable $e) {
            // Переспросить не вышло - остаемся при осторожном исходе: вердикты снимаем.
            return self::without_verdicts($out, self::STATUS_DISTRUST);
        }
        if (!self::picks_agree($questions, $picks, $second)) {
            return self::without_verdicts($out, self::STATUS_DISTRUST);
        }
        $out['status'] = self::STATUS_CONFIRMED;
        return $out;
    }

    /** Один вызов модели с промтом судьи. */
    private function ask(array $questions): array {
        // Порог «пустого ответа» понижен: выбор по одному вопросу занимает 39 символов, и
        // при обычном пороге в 50 ярус был мертв для малых комплектов.
        $raw = $this->gen->generate_text(self::build_prompt($questions), 2048,
            ai_generator::MIN_REPLY_LEN_SHORT);
        return self::parse($raw, count($questions));
    }

    /** Совпадают ли два ответа судьи там, где он высказался оба раза. */
    private static function picks_agree(array $questions, array $first, array $second): bool {
        $n = count($questions);
        $common = 0;
        for ($i = 0; $i < $n; $i++) {
            if (($first[$i] ?? null) === null || ($second[$i] ?? null) === null) {
                continue;
            }
            $common++;
            if (self::choice_text((string)$first[$i]) !== self::choice_text((string)$second[$i])) {
                return false;
            }
        }
        // Ни одного общего высказывания - подтверждения нет, и выдавать его за согласие нельзя.
        return $common > 0;
    }

    /** Тот же ответ, но со снятыми вердиктами. */
    private static function without_verdicts(array $out, string $status): array {
        $out['verdicts'] = array_map(static fn(): string => 'ok', $out['verdicts']);
        // Выборы снимаем ВМЕСТЕ с вердиктами: снятый вердикт означает, что судье в этом заходе
        // не верят, и чинить по его выбору тем более нельзя.
        $out['at'] = array_map(static fn(): ?int => null, $out['at'] ?? []);
        $out['status'] = $status;
        return $out;
    }
}
