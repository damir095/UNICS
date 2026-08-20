<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Разметка банка вопросов кодификатором ([[codifier-bank-tagging-design]]).
 *
 * Кодификатор наполнен ([[codifier-ai-proposal-design]]), но содержимое к нему не привязано: на
 * стенде 2026-08-20 семнадцать элементов и восемнадцать привязок, то есть тринадцать элементов -
 * пустые полки, для которых не работает ни пул заданий, ни калибровка, ни CAT.
 *
 * Модель предлагает элемент для каждого вопроса, решение остается за методистом: ошибочная
 * привязка отправит ребенка на задание не по своей теме, и в пуле этого уже не видно.
 *
 * @package local_unics
 */
class question_tagger {

    /** Сколько вопросов уходит в один запрос к модели. */
    public const BATCH = 30;

    /** Сколько раз просить разметку, если ответ не разобрался. */
    public const PARSE_ATTEMPTS = 2;

    /** До скольки символов режется текст вопроса в промте. */
    public const TEXT_LIMIT = 300;

    /** @var ai_generator генератор; внедряется конструктором - шов для тестов. */
    private ai_generator $gen;

    public function __construct(?ai_generator $gen = null) {
        $this->gen = $gen ?? new ai_generator();
    }

    /**
     * Промт разметки.
     *
     * @param array $questions ['bankentryid' => int, 'name' => string, 'text' => string]
     * @param array $elements ['code' => string, 'title' => string, 'description' => string]
     */
    public static function build_prompt(array $questions, array $elements): string {
        $elist = [];
        foreach ($elements as $e) {
            $line = '- ' . $e['code'] . ' - ' . $e['title'];
            if (trim((string)($e['description'] ?? '')) !== '') {
                $line .= ' (' . $e['description'] . ')';
            }
            $elist[] = $line;
        }
        $qlist = [];
        $n = 0;
        foreach ($questions as $q) {
            $n++;
            $qlist[] = $n . '. ' . trim((string)($q['name'] ?? '')) . ': '
                . trim((string)($q['text'] ?? ''));
        }

        return "Ты - методист, который относит тестовые задания к элементам содержания кодификатора.

Элементы кодификатора:
" . implode("\n", $elist) . "

Задания:
" . implode("\n", $qlist) . "

Для КАЖДОГО задания выбери РОВНО ОДИН элемент - тот, который задание проверяет. Если подходящего элемента нет или выбор неочевиден, все равно назови ближайший, но поставь sure = false.

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов. Список ПЛОСКИЙ:
{\"tags\":[{\"n\":1,\"code\":\"код элемента\",\"sure\":true}]}
n - номер задания из списка выше, code - код элемента, sure - уверен ли ты в выборе.";
    }

    /**
     * Разбор ответа модели.
     *
     * @param array $valid_codes коды элементов кодификатора
     * @param int $count сколько заданий было в пачке
     * @return array список ['n' => int, 'code' => string, 'sure' => bool]
     * @throws \moodle_exception если не разобралось ни одной строки
     */
    public static function parse(string $raw, array $valid_codes, int $count): array {
        $data = json_reply::decode($raw, 'tags');
        $valid = array_flip(array_map('strval', $valid_codes));
        $out = [];
        $seen = [];
        foreach ((array)($data['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $n = (int)($tag['n'] ?? 0);
            $code = is_scalar($tag['code'] ?? null) ? trim((string)$tag['code']) : '';
            if ($n < 1 || $n > $count || $code === '' || !isset($valid[$code]) || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            // Молчание модели про уверенность трактуем в пользу методиста: галочка будет снята.
            $out[] = ['n' => $n, 'code' => $code, 'sure' => !empty($tag['sure'])];
        }
        if (!$out) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'ИИ вернул некорректную разметку. ' . json_reply::head_and_tail($raw));
        }
        return $out;
    }
}
