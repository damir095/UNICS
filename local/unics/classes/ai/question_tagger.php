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
     * Спросить у модели разметку и разобрать ответ.
     *
     * Две попытки: порча разметки у модели случайна, устойчивую повтором не вылечить. Детектор
     * отказа отдельно не зову - он внутри generate_text ([[ai-refusal-detector-design]]).
     *
     * @param array $questions ['bankentryid' => int, 'name' => string, 'text' => string]
     * @param array $elements ['code' => string, 'title' => string, 'description' => string]
     * @return array список ['n' => int, 'code' => string, 'sure' => bool]
     */
    public function propose(array $questions, array $elements): array {
        $prompt = self::build_prompt($questions, $elements);
        $codes = array_column($elements, 'code');
        $last = null;
        for ($attempt = 1; $attempt <= self::PARSE_ATTEMPTS; $attempt++) {
            $raw = $this->gen->generate_text($prompt, 4096);
            try {
                return self::parse($raw, $codes, count($questions));
            } catch (\moodle_exception $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * Записать подтвержденные методистом привязки.
     *
     * Обе стороны пары проверяются на принадлежность ЭТОМУ кодификатору и его дисциплине, а не
     * берутся из формы на веру: поля предпросмотра приходят из браузера, а гейт страницы пускает
     * любого методиста или регионального администратора. Без проверки один POST связывал бы
     * вопрос чужого предмета с элементом чужого кодификатора, и эта связь ушла бы в пул заданий
     * и в CAT - ребенок получил бы задание не по своей теме.
     *
     * @param array $pairs список ['bankentryid' => int, 'element_id' => int]
     * @return int сколько привязок СОЗДАНО (существующие не считаются)
     */
    public static function apply(int $codifier_id, array $pairs, int $userid): int {
        global $DB;
        if (!$pairs) {
            // Страница зовет apply() безусловно, в том числе когда методист не отметил ничего.
            // Без этого выхода пустая отправка формы стоила бы двух подготовительных выборок.
            return 0;
        }
        $own_elements = array_flip(array_map('intval', $DB->get_fieldset_select(
            'unics_codifier_element', 'id', 'codifier_id = :cid', ['cid' => $codifier_id])));
        $own_questions = array_flip(self::subject_bankentry_ids($codifier_id));

        // Уже существующие привязки берем ОДНИМ запросом - но ТОЛЬКО по присланным вопросам.
        // Первая редакция тянула все привязки дисциплины: на зрелом предмете это тысячи строк
        // ради тридцати проверок, то есть ограниченная стоимость менялась на неограниченную
        // (найдено ревью). Условие по (target_type, target_id) ложится на индекс ix_link_target.
        //
        // JOIN к элементам тут тоже был лишним и ВРЕДНЫМ: набор элементов уже собран выше, а
        // соединение делало проверку уже уникального индекса - осиротевшая привязка (элемент
        // удален, строка осталась) в выборку не попадала, и следом insert падал на уникальном
        // индексе, роняя всю пачку.
        $beids = [];
        foreach ($pairs as $pair) {
            $beid = (int)($pair['bankentryid'] ?? 0);
            if ($beid > 0) {
                $beids[$beid] = true;
            }
        }
        if (!$beids || !$own_elements) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($beids), SQL_PARAMS_NAMED, 'be');
        // Тип обязателен: cmid и questionbankentryid - независимые последовательности, они
        // пересекаются. Без него привязка активности к тому же числу выдала бы себя за привязку
        // вопроса, и вопрос молча остался бы вне пула и вне CAT.
        $params['type'] = \local_unics\codifier_link_manager::TYPE_QUESTION;
        $existing = [];
        foreach ($DB->get_records_select('unics_codifier_link',
            "target_type = :type AND target_id $insql", $params, '', 'id, element_id, target_id') as $row) {
            $existing[$row->element_id . ':' . $row->target_id] = true;
        }

        $created = 0;
        foreach ($pairs as $pair) {
            $beid = (int)($pair['bankentryid'] ?? 0);
            $elid = (int)($pair['element_id'] ?? 0);
            if ($beid <= 0 || $elid <= 0) {
                continue;
            }
            if (!isset($own_elements[$elid]) || !isset($own_questions[$beid])) {
                continue;
            }
            // link_question идемпотентен, но у существующей привязки его звать незачем: он
            // сделает ту же выборку и вернет прежний id. «Создано» считаем сами - методисту
            // важно знать, сколько привязок реально добавилось.
            $key = $elid . ':' . $beid;
            if (isset($existing[$key])) {
                continue;
            }
            \local_unics\codifier_link_manager::link_question($elid, $beid, $userid);
            // Помечаем сразу: повтор той же пары ВНУТРИ одной пачки не должен считаться вторым
            // созданием (link_question его и не создаст, но счетчик соврал бы).
            $existing[$key] = true;
            $created++;
        }
        return $created;
    }

    // -----------------------------------------------------------------
    // Что размечать: вопросы дисциплины без привязок
    // -----------------------------------------------------------------

    /**
     * Вопросы дисциплины без привязки к элементам.
     *
     * Контексты берем по `path` контекста категории курсов: вопросы живут сразу на трех уровнях -
     * в общем пуле категории (туда кладет задания генератор УМК), в курсах и в тестах внутри
     * курсов. Все три лежат ниже категории, поэтому одного условия по пути достаточно.
     *
     * @return array список ['bankentryid' => int, 'name' => string, 'text' => string]
     */
    public static function untagged(int $codifier_id, int $limit): array {
        global $DB;
        list($sql, $params) = self::untagged_sql($codifier_id);
        $rows = $DB->get_records_sql($sql . ' ORDER BY qbe.id', $params, 0, $limit);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'bankentryid' => (int)$r->bankentryid,
                'name'        => (string)$r->name,
                'text'        => self::plain_text((string)$r->questiontext),
            ];
        }
        return $out;
    }

    /**
     * Все записи банка дисциплины, размеченные и нет: множество допустимых целей привязки.
     *
     * @return int[]
     */
    public static function subject_bankentry_ids(int $codifier_id): array {
        global $DB;
        list($sql, $params) = self::subject_sql($codifier_id);
        return array_map('intval', $DB->get_fieldset_sql(
            'SELECT bankentryid FROM (' . $sql . ') sub', $params));
    }

    /** Сколько всего вопросов дисциплины ждут разметки. */
    public static function untagged_count(int $codifier_id): int {
        global $DB;
        list($sql, $params) = self::untagged_sql($codifier_id);
        // Считаем по идентификатору, а не по всей выборке: иначе во временную таблицу уезжает
        // текст каждого вопроса целиком, а это LONGTEXT, и запрос идет на каждый показ формы.
        $sql = preg_replace('/^\s*SELECT .*? FROM /su', 'SELECT qbe.id FROM ', $sql, 1) ?? $sql;
        return (int)$DB->count_records_sql('SELECT COUNT(1) FROM (' . $sql . ') sub', $params);
    }

    /**
     * Запрос неразмеченных вопросов дисциплины.
     *
     * @return array{0: string, 1: array} [sql, params]
     */
    private static function untagged_sql(int $codifier_id): array {
        list($sql, $params) = self::subject_sql($codifier_id);
        if (!$params) {
            return [$sql, $params];
        }
        $sql .= " AND NOT EXISTS (SELECT 1 FROM {unics_codifier_link} l
                                   WHERE l.target_type = :ttype AND l.target_id = qbe.id)";
        $params['ttype'] = \local_unics\codifier_link_manager::TYPE_QUESTION;
        return [$sql, $params];
    }

    /**
     * Запрос всех пригодных вопросов дисциплины (и размеченных, и нет).
     *
     * Годной считается ПОСЛЕДНЯЯ ГОТОВАЯ версия вопроса. Фильтр по статусу обязателен: удаление
     * вопроса, который где-то используется, не удаляет его, а прячет версию - без фильтра модель
     * размечала бы удаленные вопросы, они съедали бы места в пачке и токены, а привязка навсегда
     * раздувала бы счетчики готовности к CAT заданиями, которых пул никогда не выдаст (тот же
     * фильтр стоит в `item_pool::candidates()`).
     *
     * @return array{0: string, 1: array} [sql, params]; пустой params означает «выборка пуста»
     */
    private static function subject_sql(int $codifier_id): array {
        global $DB;
        $catid = (int)$DB->get_field('unics_codifier', 'mdl_category_id', ['id' => $codifier_id]);
        $ctx = $catid ? \context_coursecat::instance($catid, IGNORE_MISSING) : false;
        if (!$ctx) {
            // Категорию удалили - отдаем заведомо пустой запрос, а не падаем.
            return ["SELECT qbe.id AS bankentryid, '' AS name, '' AS questiontext
                       FROM {question_bank_entries} qbe WHERE 1 = 0", []];
        }
        $sql = "SELECT qbe.id AS bankentryid, q.name, q.questiontext
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  JOIN {context} ctx ON ctx.id = qc.contextid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                       AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                          WHERE v.questionbankentryid = qbe.id
                                                AND v.status = :readyinner)
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE (ctx.id = :ctxid OR " . $DB->sql_like('ctx.path', ':ctxpath') . ")
                       AND qv.status = :ready
                       AND q.qtype <> 'random'";
        return [$sql, [
            'ctxid'      => $ctx->id,
            'ctxpath'    => $ctx->path . '/%',
            'ready'      => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
            'readyinner' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ]];
    }

    /** Элементы кодификатора для промта, в порядке обхода дерева. */
    public static function elements_for_prompt(int $codifier_id): array {
        $out = [];
        foreach (\local_unics\codifier_manager::get_tree($codifier_id) as $e) {
            $out[] = ['code' => (string)$e->code, 'title' => (string)$e->title,
                      'description' => (string)($e->description ?? '')];
        }
        return $out;
    }

    /** Текст вопроса без разметки и в пределах TEXT_LIMIT. */
    private static function plain_text(string $html): string {
        return mb_substr(trim(html_to_text($html, 0, false)), 0, self::TEXT_LIMIT);
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
