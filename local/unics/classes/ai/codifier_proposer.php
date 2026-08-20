<?php
namespace local_unics\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Предложение структуры кодификатора моделью ([[codifier-ai-proposal-design]]).
 *
 * Кодификатор - опора всего адаптивного слоя: пул заданий отбирает задания по элементу,
 * калибровка IRT считает трудность внутри элемента, CAT выбирает тему по элементу. Опоры при
 * этом почти нет: на стенде четыре элемента про нефтепродукты и части света, остатки
 * демонстрационных прогонов.
 *
 * Модель отдает только смысл - названия и однострочные описания. Коды назначаем мы: в
 * кодификаторе они несут иерархию (import_from_rows выводит из кода родителя), а модель в
 * сквозной нумерации путается и сталкивается с занятыми.
 *
 * @package local_unics
 */
class codifier_proposer {

    /** Потолок разделов в одном предложении. */
    public const MAX_SECTIONS = 8;

    /** Потолок тем в разделе. */
    public const MAX_TOPICS = 8;

    /** Сколько раз просить структуру, если ответ не разобрался. */
    public const PARSE_ATTEMPTS = 2;

    /** Ожидание лока на кодификатор при записи, секунды. */
    const LOCK_TIMEOUT = 5;

    /** @var ai_generator генератор; внедряется конструктором - шов для тестов. */
    private ai_generator $gen;

    public function __construct(?ai_generator $gen = null) {
        $this->gen = $gen ?? new ai_generator();
    }

    /**
     * Промт структуры кодификатора.
     *
     * @param array $existing названия элементов, которые уже есть (чтобы модель не повторялась)
     */
    public static function build_prompt(string $subject, int $class_number, int $sections, int $per_section,
                                 string $extra = '', array $existing = []): string {
        $existing_block = '';
        if ($existing) {
            $titles = array_map(static function ($t): string {
                return '- ' . mb_substr(trim((string)$t), 0, 120);
            }, array_slice(array_values($existing), 0, 60));
            $existing_block = "\n\nВ кодификаторе уже есть такие элементы. НЕ повторяй их и не предлагай"
                . " близкие по смыслу:\n" . implode("\n", $titles) . "\n";
        }
        $extra_block = trim($extra) !== ''
            ? "\n\nУказания методиста, выполняй их в первую очередь:\n"
                . mb_substr(trim($extra), 0, 500) . "\n"
            : '';

        $total = $sections * $per_section;

        return "Ты - методист, составляющий кодификатор содержания по предмету «{$subject}»"
            . " для {$class_number} класса российской школы.

Кодификатор - это иерархия проверяемых элементов содержания, как у ФИПИ: крупные разделы, внутри каждого - темы.

Предложи {$sections} разделов, в каждом {$per_section} тем, всего {$total} строк.{$existing_block}{$extra_block}
Требования:
- Разделы идут в том порядке, в каком материал изучается в течение учебного года
- Строки одного раздела идут подряд, и поле «section» у них написано ОДИНАКОВО, слово в слово
- Название темы - то, что проверяется у ученика, а не заголовок параграфа учебника
- Описание - одна строка вида «что ученик умеет после темы», не длиннее 200 символов
- Никаких номеров и кодов в названиях: нумерацию присвоит система
- Не ставь запятую перед закрывающей скобкой: «\"описание\",}» - это ошибка формата
- ЗАПРЕЩЕНО использовать LaTeX-формулы, символы \$ и обратную косую черту. Формулы записывай обычным текстом.

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов. Список ПЛОСКИЙ, вложенных массивов нет:
{\"items\":[{\"section\":\"Название раздела\",\"topic\":\"Название темы\",\"description\":\"одна строка\"}]}";
    }

    /**
     * Спросить у модели структуру и разобрать ответ.
     *
     * Детектор отказа модели отдельно не зову: он стоит внутри generate_text и бросает сам
     * ([[ai-refusal-detector-design]]).
     */
    public function propose(string $subject, int $class_number, int $sections, int $per_section,
                            string $extra = '', array $existing = []): array {
        $prompt = self::build_prompt($subject, $class_number, $sections, $per_section, $extra, $existing);
        // Две попытки: порча разметки у модели случайна, и второй ответ на тот же промт обычно
        // приходит целым. Отказ модели сюда не долетает - generate_text бросает раньше и сам
        // уже отработал свой повтор.
        $last = null;
        for ($attempt = 1; $attempt <= self::PARSE_ATTEMPTS; $attempt++) {
            $raw = $this->gen->generate_text($prompt, 4096);
            try {
                return self::parse($raw, $sections, $per_section);
            } catch (\moodle_exception $e) {
                $last = $e;
            }
        }
        throw $last;
    }

    /**
     * Разбор ответа модели в список разделов с темами.
     *
     * Ответ ПЛОСКИЙ - список строк «раздел, тема, описание», а иерархию собираем мы
     * группировкой по названию раздела. Вложенный формат («разделы, внутри topics») модель
     * ломала в двух живых заходах из трех 2026-08-20: не закрывала объект темы перед началом
     * следующей и валила скобки в конце в произвольном порядке. Плоский список этой ошибки
     * не допускает в принципе - вложенность там ровно одна.
     *
     * @return array список ['title' => string, 'description' => string, 'topics' => [...]]
     * @throws \moodle_exception если не разобралось ни одного раздела
     */
    public static function parse(string $raw, int $max_sections = self::MAX_SECTIONS,
                                 int $max_topics = self::MAX_TOPICS): array {
        $data = json_reply::decode($raw, 'items');
        $out = [];
        $index = []; // название раздела => позиция в $out
        foreach ((array)($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $section = self::str_of($item['section'] ?? '');
            $topic   = self::str_of($item['topic'] ?? '');
            if ($section === '' || $topic === '') {
                continue;
            }
            if (!isset($index[$section])) {
                if (count($out) >= $max_sections) {
                    continue; // разделов уже достаточно, темы лишнего раздела не берем
                }
                $index[$section] = count($out);
                $out[] = ['title' => $section, 'description' => '', 'topics' => []];
            }
            $pos = $index[$section];
            if (count($out[$pos]['topics']) >= $max_topics) {
                continue;
            }
            $out[$pos]['topics'][] = ['title' => $topic,
                                      'description' => self::str_of($item['description'] ?? '')];
        }
        if (!$out) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'ИИ вернул некорректную структуру кодификатора. ' . json_reply::head_and_tail($raw));
        }
        return $out;
    }

    /**
     * Назначить коды предложенным элементам, обходя занятые.
     *
     * Занятые коды подает вызывающий, а не читает сама функция: так она остается чистой и
     * проверяется без стенда.
     *
     * @param array $existing_codes коды, уже занятые в кодификаторе (плоско, разделы и темы)
     * @param array $parsed результат parse()
     * @return array план; 'natural' - номер, который элемент получил бы на пустом кодификаторе,
     *               'shifted' - признак того, что код пришлось сдвинуть
     */
    public static function plan(array $existing_codes, array $parsed): array {
        $used = [];
        foreach ($existing_codes as $c) {
            $used[(string)$c] = true;
        }
        $out = [];
        foreach (array_values($parsed) as $i => $sec) {
            // Единственный источник истины о занятости - реестр $used. Отдельный счетчик «следующий
            // свободный» рядом с ним завел бы вторую бухгалтерию, которая молча разошлась бы с
            // первой: коды перестали бы сталкиваться сами собой, а не по проверке.
            $n = 1;
            while (isset($used[(string)$n])) {
                $n++;
            }
            $code = (string)$n;
            $used[$code] = true;

            $topics = [];
            $tn = 1;
            foreach ($sec['topics'] as $t) {
                while (isset($used[$code . '.' . $tn])) {
                    $tn++;
                }
                $tcode = $code . '.' . $tn;
                $used[$tcode] = true;
                $topics[] = ['code' => $tcode, 'title' => $t['title'], 'description' => $t['description']];
            }

            $natural = (string)($i + 1);
            $out[] = [
                'code'        => $code,
                'natural'     => $natural,
                'shifted'     => $code !== $natural,
                'title'       => $sec['title'],
                'description' => $sec['description'],
                'topics'      => $topics,
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Чтение кодификатора и запись подтвержденного дерева
    // -----------------------------------------------------------------

    /** Коды, уже занятые в кодификаторе (плоско, разделы и темы вместе). */
    public static function existing_codes(int $codifier_id): array {
        global $DB;
        $codes = $DB->get_fieldset_select('unics_codifier_element', 'code',
            'codifier_id = :cid', ['cid' => $codifier_id]);
        return array_values(array_filter(array_map('strval', $codes), static function (string $c): bool {
            return $c !== '';
        }));
    }

    /**
     * Названия существующих элементов - для промта и для показа методисту.
     *
     * Порядок обхода дерева, а не плоская сортировка по ordinal: у тем свой ordinal внутри
     * родителя, поэтому плоский запрос перемешивал разделы с чужими темами, и список выглядел
     * бессвязным набором (замечено живым заходом 2026-08-20).
     */
    public static function existing_titles(int $codifier_id): array {
        return array_values(array_map(static function ($e): string {
            return (string)$e->title;
        }, \local_unics\codifier_manager::get_tree($codifier_id)));
    }

    /**
     * Записать подтвержденное методистом дерево.
     *
     * Строки без названия пропускаются: методист мог очистить поле, чтобы убрать элемент.
     * Раздел без названия уходит вместе со своими темами - тема без родителя повисла бы.
     *
     * Занятый код отклоняет ВЕСЬ шаг, а не пропускает строку молча: технически база дубликат
     * стерпит (уникального индекса на code нет), но import_from_rows и человек опознают элемент
     * именно по коду, и два элемента «1.1» ломают обоих.
     *
     * @param array $sections план (формат plan()), уже отфильтрованный по галочкам
     * @return int сколько элементов создано
     * @throws \moodle_exception если код занят или повторяется внутри пачки
     */
    public static function apply(int $codifier_id, array $sections): int {
        global $DB;

        $rows = [];
        foreach ($sections as $sec) {
            $stitle = self::str_of($sec['title'] ?? '');
            if ($stitle === '') {
                continue;
            }
            $topics = [];
            foreach ((array)($sec['topics'] ?? []) as $t) {
                $ttitle = self::str_of($t['title'] ?? '');
                if ($ttitle === '') {
                    continue;
                }
                $topics[] = ['code' => self::str_of($t['code'] ?? ''), 'title' => $ttitle,
                             'description' => self::str_of($t['description'] ?? '')];
            }
            $rows[] = ['code' => self::str_of($sec['code'] ?? ''), 'title' => $stitle,
                       'description' => self::str_of($sec['description'] ?? ''), 'topics' => $topics];
        }

        self::assert_topic_codes_match_sections($rows);

        // Проверка занятости и запись под ОДНИМ локом: между чтением занятых кодов и вставкой
        // соседняя вкладка успела бы вставить свои, и оба предложения прошли бы проверку. Тот же
        // класс гонки, что закрывали бронью мест в пуле заданий ([[item-pool-reservation-design]]).
        $lock = \core\lock\lock_config::get_lock_factory('local_unics_codifier')
            ->get_lock('codifier_' . $codifier_id, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'Кодификатор сейчас изменяет кто-то еще. Повторите через несколько секунд.');
        }
        try {
            self::assert_codes_free($codifier_id, $rows);

            $created = 0;
            $tx = $DB->start_delegated_transaction();
            try {
                foreach ($rows as $r) {
                    $sid = \local_unics\codifier_manager::add_element($codifier_id, null,
                        $r['code'], $r['title'], $r['description'] !== '' ? $r['description'] : null);
                    $created++;
                    foreach ($r['topics'] as $t) {
                        \local_unics\codifier_manager::add_element($codifier_id, $sid,
                            $t['code'], $t['title'], $t['description'] !== '' ? $t['description'] : null);
                        $created++;
                    }
                }
                $tx->allow_commit();
            } catch (\Exception $e) {
                // Без явного отката транзакция остается открытой до конца запроса, и КАЖДАЯ
                // последующая запись в этом запросе молча пропадает при разборе соединения.
                // Вызывающий ловит moodle_exception, а dml_exception - ее наследник.
                $tx->rollback($e);
            }
            return $created;
        } finally {
            $lock->release();
        }
    }

    /**
     * Код темы обязан начинаться с кода своего раздела.
     *
     * Иначе методист, поправивший на предпросмотре только код раздела (было 3, стало 7), получит
     * раздел «7» с темами «3.1» и «3.2»: в базе parent_id верный, но `import_from_rows` выводит
     * родителя ИЗ КОДА, отрезая хвост после точки. На первом же экспорте с обратным импортом
     * такие темы уедут под чужой раздел или пропадут как висячие.
     *
     * @throws \moodle_exception
     */
    private static function assert_topic_codes_match_sections(array $rows): void {
        foreach ($rows as $r) {
            $scode = (string)$r['code'];
            if ($scode === '') {
                continue;
            }
            foreach ($r['topics'] as $t) {
                $tcode = (string)$t['code'];
                if ($tcode === '' || strpos($tcode, $scode . '.') === 0) {
                    continue;
                }
                throw new \moodle_exception('generalexceptionmessage', 'error', '',
                    'Код темы «' . $tcode . '» не принадлежит разделу «' . $scode
                    . '»: код темы должен начинаться с кода раздела и точки.');
            }
        }
    }

    /**
     * Проверка кодов ДО первой вставки: занятый в кодификаторе и повторенный внутри пачки.
     *
     * @throws \moodle_exception
     */
    private static function assert_codes_free(int $codifier_id, array $rows): void {
        $taken = array_flip(self::existing_codes($codifier_id));
        $seen = [];
        foreach ($rows as $r) {
            foreach (array_merge([$r], $r['topics']) as $one) {
                $code = (string)$one['code'];
                if ($code === '') {
                    continue;
                }
                if (isset($taken[$code])) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '',
                        'Код «' . $code . '» уже занят в кодификаторе. Поправьте код и повторите.');
                }
                if (isset($seen[$code])) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '',
                        'Код «' . $code . '» встречается в предложении дважды. Поправьте код и повторите.');
                }
                $seen[$code] = true;
            }
        }
    }

    /** Строка из значения любого вида: модель иногда шлет объект вместо строки. */
    private static function str_of($v): string {
        return is_scalar($v) ? trim((string)$v) : '';
    }

}
