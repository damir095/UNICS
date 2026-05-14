<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Хелперы для работы с multi-value полями ученика (category, ovz_type),
 * которые хранятся как CSV строки "1,3".
 */
class student_helper {

    /** Все категории учащегося (1=ОВЗ, 2=семейное, 3=лечение, 4=одарённые). */
    public const CATEGORIES = [
        1 => 'category_ovz',
        2 => 'category_family',
        3 => 'category_treatment',
        4 => 'category_gifted',
    ];

    /** Все виды ОВЗ. */
    public const OVZ_TYPES = [
        1 => 'ovz_blind',
        2 => 'ovz_deaf',
        3 => 'ovz_motor',
        4 => 'ovz_zpd',
        5 => 'ovz_ras',
        6 => 'ovz_other',
    ];

    /**
     * Распарсить CSV ("1,3") или одиночное значение ("1") в массив int.
     * @param mixed $value строка CSV, int, null
     * @return int[] отсортированные уникальные id
     */
    public static function parse_csv($value): array {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_int($value)) {
            return [$value];
        }
        $parts = array_filter(array_map('trim', explode(',', (string)$value)), 'strlen');
        $ids = array_unique(array_map('intval', $parts));
        sort($ids);
        return array_values($ids);
    }

    /** Собрать массив id в CSV-строку "1,3". */
    public static function to_csv(array $ids): string {
        $ids = array_unique(array_map('intval', $ids));
        sort($ids);
        return implode(',', $ids);
    }

    /** Категории учащегося как массив int. */
    public static function get_categories(\stdClass $student): array {
        return self::parse_csv($student->category ?? null);
    }

    /** Виды ОВЗ учащегося как массив int. */
    public static function get_ovz_types(\stdClass $student): array {
        return self::parse_csv($student->ovz_type ?? null);
    }

    /** Есть ли у ученика данная категория. */
    public static function has_category(\stdClass $student, int $category): bool {
        return in_array($category, self::get_categories($student), true);
    }

    /** Есть ли у ученика данный вид ОВЗ. */
    public static function has_ovz_type(\stdClass $student, int $ovz): bool {
        return in_array($ovz, self::get_ovz_types($student), true);
    }

    /** Главная (первая) категория - для случаев, где нужен один скаляр (бэк-компат). */
    public static function primary_category(\stdClass $student): int {
        $cats = self::get_categories($student);
        return $cats[0] ?? 2;
    }

    /** Главный (первый) вид ОВЗ или null. */
    public static function primary_ovz_type(\stdClass $student): ?int {
        $types = self::get_ovz_types($student);
        return $types[0] ?? null;
    }

    /**
     * Человеко-читаемое перечисление категорий: "ОВЗ; Длительное лечение".
     * @param string $sep разделитель
     */
    public static function format_categories(\stdClass $student, string $sep = '; '): string {
        $ids = self::get_categories($student);
        $labels = [];
        foreach ($ids as $id) {
            if (isset(self::CATEGORIES[$id])) {
                $labels[] = get_string(self::CATEGORIES[$id], 'local_unics');
            }
        }
        return implode($sep, $labels);
    }

    /** Человеко-читаемое перечисление видов ОВЗ. */
    public static function format_ovz_types(\stdClass $student, string $sep = '; '): string {
        $ids = self::get_ovz_types($student);
        $labels = [];
        foreach ($ids as $id) {
            if (isset(self::OVZ_TYPES[$id])) {
                $labels[] = get_string(self::OVZ_TYPES[$id], 'local_unics');
            }
        }
        return implode($sep, $labels);
    }

    /**
     * SQL-фрагмент «category содержит данный id» - для фильтров.
     * Использует FIND_IN_SET (MariaDB/MySQL).
     * Пример: WHERE {$frag} → ["FIND_IN_SET(:cat, s.category)", ['cat' => 1]]
     *
     * @return array [sql_fragment, params]
     */
    public static function sql_has_category(int $category, string $alias = 's', string $param_name = 'unics_cat'): array {
        return [
            "FIND_IN_SET(:{$param_name}, {$alias}.category)",
            [$param_name => (string)$category],
        ];
    }

    /** Аналогично для ovz_type. */
    public static function sql_has_ovz_type(int $ovz, string $alias = 's', string $param_name = 'unics_ovz'): array {
        return [
            "FIND_IN_SET(:{$param_name}, {$alias}.ovz_type)",
            [$param_name => (string)$ovz],
        ];
    }
}
