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

    /** Строка из значения любого вида: модель иногда шлет объект вместо строки. */
    private static function str_of($v): string {
        return is_scalar($v) ? trim((string)$v) : '';
    }
}
