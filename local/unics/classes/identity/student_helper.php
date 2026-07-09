<?php
namespace local_unics\identity;

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
        // Нет категорий → «обычный» учащийся (без ОВЗ/одарённости).
        if (empty($labels)) {
            return get_string('category_normal', 'local_unics');
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
     * Массив отображаемых названий категорий по CSV ("1,3" → ['ОВЗ', 'Длительное лечение']).
     * В отличие от format_categories возвращает МАССИВ (для срезов: учащийся попадает
     * в каждую свою категорию отдельно). Пустой CSV → [].
     *
     * @param mixed $csv строка CSV / int / null (значение поля category)
     * @return string[]
     */
    public static function category_names($csv): array {
        $out = [];
        foreach (self::parse_csv($csv) as $id) {
            if (isset(self::CATEGORIES[$id])) {
                $out[] = get_string(self::CATEGORIES[$id], 'local_unics');
            }
        }
        return $out;
    }

    /**
     * Массив отображаемых названий видов ОВЗ по CSV. Пустой CSV (не-ОВЗ) → [].
     *
     * @param mixed $csv строка CSV / int / null (значение поля ovz_type)
     * @return string[]
     */
    public static function ovz_type_names($csv): array {
        $out = [];
        foreach (self::parse_csv($csv) as $id) {
            if (isset(self::OVZ_TYPES[$id])) {
                $out[] = get_string(self::OVZ_TYPES[$id], 'local_unics');
            }
        }
        return $out;
    }

    /** Все названия категорий в порядке справочника (для упорядочивания срезов). */
    public static function category_labels_in_order(): array {
        $o = [];
        foreach (self::CATEGORIES as $key) {
            $o[] = get_string($key, 'local_unics');
        }
        return $o;
    }

    /** Все названия видов ОВЗ в порядке справочника. */
    public static function ovz_labels_in_order(): array {
        $o = [];
        foreach (self::OVZ_TYPES as $key) {
            $o[] = get_string($key, 'local_unics');
        }
        return $o;
    }

    /**
     * SQL-фрагмент «у ученика есть данная категория» - для фильтров (WHERE {$frag}).
     * EXISTS по нормализованной unics_student_category (этап 2.6-B).
     *
     * @return array [sql_fragment, params]
     */
    public static function sql_has_category(int $category, string $alias = 's', string $param_name = 'unics_cat'): array {
        // Этап 2.6 (инкремент B): EXISTS по нормализованной таблице вместо
        // FIND_IN_SET по CSV-колонке (индекс (student_id, category) работает).
        return [
            "EXISTS (SELECT 1 FROM {unics_student_category} jc
                      WHERE jc.student_id = {$alias}.id AND jc.category = :{$param_name})",
            [$param_name => $category],
        ];
    }

    /** Аналогично для ovz_type. */
    public static function sql_has_ovz_type(int $ovz, string $alias = 's', string $param_name = 'unics_ovz'): array {
        return [
            "EXISTS (SELECT 1 FROM {unics_student_ovz} jo
                      WHERE jo.student_id = {$alias}.id AND jo.ovz_type = :{$param_name})",
            [$param_name => $ovz],
        ];
    }

    /**
     * Подсчёт «активных» учащихся: не удалён в Moodle (u.deleted=0), не
     * архивирован (s.archived_at IS NULL) и не выпущен (s.graduated_at IS NULL).
     * Опциональный фильтр по организации.
     *
     * @param array $filters поддерживает 'organization_id' => int
     */
    public static function count_active_students(array $filters = []): int {
        global $DB;
        $where  = ['s.archived_at IS NULL', 's.graduated_at IS NULL'];
        $params = [];
        if (!empty($filters['organization_id'])) {
            $where[] = 's.organization_id = :org';
            $params['org'] = (int)$filters['organization_id'];
        }
        $sql = "SELECT COUNT(s.id)
                  FROM {unics_students} s
                  JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
                 WHERE " . implode(' AND ', $where);
        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * SQL-фрагменты «категории/ОВЗ ученика как CSV» из нормализованных таблиц
     * (этап 2.6, инкремент B). Подзапросы с прежними алиасами сохраняют форму
     * строки - parse_csv и категорийные хелперы работают без изменений.
     * Нет строк -> NULL (для parse_csv эквивалентно пустому CSV).
     *
     * @param string $alias  алиас unics_students во внешнем запросе
     * @param string $cat_as алиас колонки категорий в результате
     * @param string $ovz_as алиас колонки видов ОВЗ в результате
     * @return array{0:string,1:string} [фрагмент категорий, фрагмент ОВЗ]
     */
    public static function taxonomy_select_sql(string $alias = 's', string $cat_as = 'category',
                                               string $ovz_as = 'ovz_type'): array {
        global $DB;
        $cat = '(SELECT ' . $DB->sql_group_concat('jc.category', ',', 'jc.category ASC')
             . " FROM {unics_student_category} jc WHERE jc.student_id = {$alias}.id) AS {$cat_as}";
        $ovz = '(SELECT ' . $DB->sql_group_concat('jo.ovz_type', ',', 'jo.ovz_type ASC')
             . " FROM {unics_student_ovz} jo WHERE jo.student_id = {$alias}.id) AS {$ovz_as}";
        return [$cat, $ovz];
    }
}
