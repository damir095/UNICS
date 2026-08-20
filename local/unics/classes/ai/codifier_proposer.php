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

    /** Строка из значения любого вида: модель иногда шлет объект вместо строки. */
    private static function str_of($v): string {
        return is_scalar($v) ? trim((string)$v) : '';
    }
}
