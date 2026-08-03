<?php
namespace local_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Разбор дерева ограничений доступа (course_modules.availability) в плоский список
 * листовых условий. Дерево ядра - вложенные узлы {op, c[], showc[]}; лист - это
 * условие с полем type ('group', 'completion', 'date', 'grade', 'profile' и т.д.).
 * Общий для ученического вида ({@see course_view::humanize_lock()}, человеческая
 * причина блокировки) и педагогского ({@see course_variants}, пометка аудитории):
 * третья копия одного и того же обхода в проекте не нужна.
 */
class availability_tree {

    /**
     * @param ?string $json содержимое course_modules.availability (может быть null/пустым/битым)
     * @return array<int,array> листовые условия в порядке обхода; пустой массив, если условий нет
     */
    public static function leaves(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $tree = json_decode($json, true);
        return is_array($tree) ? self::walk($tree) : [];
    }

    /**
     * Оператор КОРНЕВОГО узла дерева - '&' (И), '|' (ИЛИ), '!&' (НЕ-И) или '!|' (НЕ-ИЛИ).
     * leaves() сознательно его отбрасывает (там задача - плоский список условий), но потребителям,
     * которым важна логика сочетания условий (например, «сирота ли активность» в course_variants),
     * одного списка листьев недостаточно: '&' с несколькими условиями - это пересечение аудиторий,
     * а не объединение, а отрицание ('!&'/'!|') переворачивает смысл на «все, КРОМЕ».
     * @return ?string null, если JSON пуст/битый или без валидного 'op'
     */
    public static function root_op(?string $json): ?string {
        if ($json === null || $json === '') {
            return null;
        }
        $tree = json_decode($json, true);
        if (!is_array($tree) || !isset($tree['op']) || !is_string($tree['op'])) {
            return null;
        }
        return $tree['op'];
    }

    /** Рекурсивный обход: узел с ключом 'c' - ветка, все остальное - лист. */
    private static function walk(array $node): array {
        if (empty($node['c']) || !is_array($node['c'])) {
            return [];
        }
        $out = [];
        foreach ($node['c'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (isset($child['c'])) {
                $out = array_merge($out, self::walk($child));
            } else {
                $out[] = $child;
            }
        }
        return $out;
    }
}
