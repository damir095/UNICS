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
    public function build_prompt(string $subject, int $class_number, int $sections, int $per_section,
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

        return "Ты - методист, составляющий кодификатор содержания по предмету «{$subject}»"
            . " для {$class_number} класса российской школы.

Кодификатор - это иерархия проверяемых элементов содержания, как у ФИПИ: крупные разделы, внутри каждого - темы.

Предложи ровно {$sections} разделов, в каждом ровно {$per_section} тем.{$existing_block}{$extra_block}
Требования:
- Разделы идут в том порядке, в каком материал изучается в течение учебного года
- Название темы - то, что проверяется у ученика, а не заголовок параграфа учебника
- Описание - одна строка вида «что ученик умеет после темы», не длиннее 200 символов
- Никаких номеров и кодов в названиях: нумерацию присвоит система
- ЗАПРЕЩЕНО использовать LaTeX-формулы, символы \$ и обратную косую черту. Формулы записывай обычным текстом.

Верни ответ СТРОГО в формате JSON, без пояснений и без markdown-тегов:
{\"sections\":[{\"title\":\"Название раздела\",\"description\":\"одна строка\",\"topics\":[{\"title\":\"Название темы\",\"description\":\"одна строка\"}]}]}";
    }

    /**
     * Спросить у модели структуру и разобрать ответ.
     *
     * Детектор отказа модели отдельно не зову: он стоит внутри generate_text и бросает сам
     * ([[ai-refusal-detector-design]]).
     */
    public function propose(string $subject, int $class_number, int $sections, int $per_section,
                            string $extra = '', array $existing = []): array {
        $prompt = $this->build_prompt($subject, $class_number, $sections, $per_section, $extra, $existing);
        $raw = $this->gen->generate_text($prompt, 4096);
        return self::parse($raw, $sections, $per_section);
    }

    /**
     * Разбор ответа модели в список разделов с темами.
     *
     * @return array список ['title' => string, 'description' => string, 'topics' => [...]]
     * @throws \moodle_exception если не разобралось ни одного раздела
     */
    public static function parse(string $raw, int $max_sections = self::MAX_SECTIONS,
                                 int $max_topics = self::MAX_TOPICS): array {
        $data = json_reply::decode($raw, 'sections');
        $out = [];
        foreach ((array)($data['sections'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $title = self::str_of($s['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $topics = [];
            foreach ((array)($s['topics'] ?? []) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $ttitle = self::str_of($t['title'] ?? '');
                if ($ttitle === '') {
                    continue;
                }
                $topics[] = ['title' => $ttitle, 'description' => self::str_of($t['description'] ?? '')];
                if (count($topics) >= $max_topics) {
                    break;
                }
            }
            $out[] = ['title' => $title, 'description' => self::str_of($s['description'] ?? ''),
                      'topics' => $topics];
            if (count($out) >= $max_sections) {
                break;
            }
        }
        if (!$out) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '',
                'ИИ вернул некорректную структуру кодификатора: ' . mb_substr(trim($raw), 0, 300));
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

    /** Названия существующих элементов - для промта, чтобы модель не повторялась. */
    public static function existing_titles(int $codifier_id): array {
        global $DB;
        $rows = $DB->get_records('unics_codifier_element', ['codifier_id' => $codifier_id],
            'ordinal ASC, id ASC', 'id, title');
        return array_values(array_map(static function ($r): string {
            return (string)$r->title;
        }, $rows));
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

        self::assert_codes_free($codifier_id, $rows);

        $created = 0;
        $tx = $DB->start_delegated_transaction();
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
        return $created;
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
