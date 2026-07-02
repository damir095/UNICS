<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Кодификатор - шапка (1 на дисциплину/категорию) и дерево элементов содержания.
 * [[codifier-design]]. Дерево - self-ref + материализованный path (вкл. сам элемент):
 * корень id=3 -> "/3/", ребёнок id=9 -> "/3/9/". Поддерево = path LIKE "<path>%".
 */
class codifier_manager {

    const STATUS_ACTIVE = 1;
    const STATUS_ARCHIVED = 2;

    // -----------------------------------------------------------------
    // Шапка
    // -----------------------------------------------------------------

    public static function get_codifier_for_category(int $categoryid): ?object {
        global $DB;
        $rec = $DB->get_record('unics_codifier',
            ['mdl_category_id' => $categoryid, 'status' => self::STATUS_ACTIVE]);
        return $rec ?: null;
    }

    /** Резолв кодификатора курса через категорию курса. */
    public static function get_codifier_for_course(int $courseid): ?object {
        global $DB;
        $catid = (int)$DB->get_field('course', 'category', ['id' => $courseid]);
        if (!$catid) {
            return null;
        }
        return self::get_codifier_for_category($catid);
    }

    public static function create_codifier(int $categoryid, string $name, int $created_by): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id'        => $categoryid,
            'name'                   => $name,
            'status'                 => self::STATUS_ACTIVE,
            'created_by_mdl_user_id' => $created_by,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ]);
    }

    /** Список категорий-дисциплин (видимые категории курсов), id => name. */
    public static function list_subject_categories(): array {
        global $DB;
        return $DB->get_records_select_menu('course_categories', 'visible = 1',
            null, 'sortorder ASC', 'id, name');
    }

    // -----------------------------------------------------------------
    // Дерево
    // -----------------------------------------------------------------

    /**
     * Элементы кодификатора в порядке обхода дерева (pre-order): родитель, затем его
     * потомки; соседи - по ordinal. Каждому элементу проставляется ->depth (0 = корень).
     * Строим в PHP, а не строковой сортировкой path (та не даёт ни числового порядка,
     * ни порядка по ordinal).
     */
    public static function get_tree(int $codifier_id): array {
        global $DB;
        $all = $DB->get_records('unics_codifier_element',
            ['codifier_id' => $codifier_id], 'ordinal ASC, id ASC');
        $children = []; // parentkey (0 = корень) => [записи в порядке ordinal]
        foreach ($all as $e) {
            $children[(int)$e->parent_id][] = $e;
        }
        $out = [];
        $walk = function ($parentkey, $depth) use (&$walk, &$children, &$out) {
            if (empty($children[$parentkey])) {
                return;
            }
            foreach ($children[$parentkey] as $e) {
                $e->depth = $depth;
                $out[] = $e;
                $walk((int)$e->id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    public static function add_element(int $codifier_id, ?int $parent_id, string $code, string $title): int {
        global $DB;
        $now = time();
        // ordinal = в хвост среди соседей.
        if ($parent_id) {
            $ordinal = (int)$DB->get_field_sql(
                "SELECT COALESCE(MAX(ordinal), 0) + 1 FROM {unics_codifier_element}
                  WHERE codifier_id = :cid AND parent_id = :pid",
                ['cid' => $codifier_id, 'pid' => $parent_id]);
        } else {
            $ordinal = (int)$DB->get_field_sql(
                "SELECT COALESCE(MAX(ordinal), 0) + 1 FROM {unics_codifier_element}
                  WHERE codifier_id = :cid AND parent_id IS NULL",
                ['cid' => $codifier_id]);
        }
        $id = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id'  => $codifier_id,
            'parent_id'    => $parent_id,
            'code'         => $code,
            'title'        => $title,
            'ordinal'      => $ordinal,
            'path'         => null,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        // path = path родителя + собственный id + "/".
        $ppath = $parent_id ? (string)$DB->get_field('unics_codifier_element', 'path', ['id' => $parent_id]) : '/';
        $DB->set_field('unics_codifier_element', 'path', $ppath . $id . '/', ['id' => $id]);
        return $id;
    }

    public static function update_element(int $element_id, array $data): void {
        global $DB;
        $rec = ['id' => $element_id, 'timemodified' => time()];
        foreach (['code', 'title', 'description'] as $k) {
            if (array_key_exists($k, $data)) {
                $rec[$k] = $data[$k];
            }
        }
        $DB->update_record('unics_codifier_element', (object)$rec);
    }

    /** Сдвиг порядка среди соседей: $dir = 'up'|'down'. */
    public static function move_ordinal(int $element_id, string $dir): void {
        global $DB;
        $el = $DB->get_record('unics_codifier_element', ['id' => $element_id], '*', MUST_EXIST);
        $cmp = $dir === 'up' ? '<' : '>';
        $sort = $dir === 'up' ? 'DESC' : 'ASC';
        $params = ['cid' => $el->codifier_id, 'ord' => $el->ordinal];
        $where = "codifier_id = :cid AND ordinal $cmp :ord AND "
            . ($el->parent_id ? "parent_id = :pid" : "parent_id IS NULL");
        if ($el->parent_id) {
            $params['pid'] = $el->parent_id;
        }
        $neighbours = $DB->get_records_select('unics_codifier_element', $where, $params, "ordinal $sort", '*', 0, 1);
        $neighbour = reset($neighbours);
        if (!$neighbour) {
            return;
        }
        $DB->set_field('unics_codifier_element', 'ordinal', $neighbour->ordinal, ['id' => $el->id]);
        $DB->set_field('unics_codifier_element', 'ordinal', $el->ordinal, ['id' => $neighbour->id]);
    }

    /** Удаление элемента каскадом по поддереву (вместе со связями). */
    public static function delete_element(int $element_id): void {
        global $DB;
        $el = $DB->get_record('unics_codifier_element', ['id' => $element_id], '*', MUST_EXIST);
        if ($el->path) {
            $subtree = $DB->get_fieldset_select('unics_codifier_element', 'id',
                'codifier_id = :cid AND ' . $DB->sql_like('path', ':p'),
                ['cid' => $el->codifier_id, 'p' => $el->path . '%']);
        } else {
            $subtree = [$element_id];
        }
        if (!$subtree) {
            $subtree = [$element_id];
        }
        list($insql, $params) = $DB->get_in_or_equal($subtree, SQL_PARAMS_NAMED);
        $DB->delete_records_select('unics_codifier_link', "element_id $insql", $params);
        $DB->delete_records_select('unics_codifier_element', "id $insql", $params);
    }

    /** Полная пересборка path для кодификатора (после импорта/массовых правок). */
    public static function rebuild_paths(int $codifier_id): void {
        global $DB;
        $all = $DB->get_records('unics_codifier_element', ['codifier_id' => $codifier_id], '', 'id, parent_id');
        $cache = [];
        $resolve = function ($id) use (&$resolve, &$cache, $all) {
            if (isset($cache[$id])) {
                return $cache[$id];
            }
            $node = $all[$id];
            $p = $node->parent_id ? $resolve((int)$node->parent_id) : '/';
            return $cache[$id] = $p . $id . '/';
        };
        foreach ($all as $id => $node) {
            $DB->set_field('unics_codifier_element', 'path', $resolve((int)$id), ['id' => $id]);
        }
    }

    /**
     * Приём распарсенных строк ФИПИ: каждая строка ['code','title','parent_code'].
     * Если parent_code пуст, родитель ВЫВОДИТСЯ из самого кода (отрезаем последний
     * сегмент после точки: «1.1.1» -> родитель «1.1», «1» -> корень) - так устроены
     * кодификаторы ФИПИ (иерархия закодирована в номере). Создаёт элементы, связывая
     * по code в пределах этого кодификатора, затем пересобирает path. Идемпотентно по
     * code. Возвращает число созданных элементов.
     */
    public static function import_from_rows(int $codifier_id, array $rows): int {
        global $DB;
        $codeToId = [];
        // существующие коды (чтобы импорт был идемпотентным по коду)
        foreach ($DB->get_records('unics_codifier_element', ['codifier_id' => $codifier_id], '', 'id, code') as $e) {
            if ($e->code !== '') {
                $codeToId[$e->code] = (int)$e->id;
            }
        }
        $created = 0;
        // несколько проходов: родитель может идти после ребёнка.
        $pending = $rows;
        $guard = 0;
        while ($pending && $guard++ < 20) {
            $next = [];
            foreach ($pending as $r) {
                $code = trim((string)($r['code'] ?? ''));
                $title = trim((string)($r['title'] ?? ''));
                $pcode = trim((string)($r['parent_code'] ?? ''));
                if ($title === '') {
                    continue;
                }
                if ($code !== '' && isset($codeToId[$code])) {
                    continue; // уже есть
                }
                // ФИПИ: родителя нет в данных - выводим из кода (отрезаем хвост после точки).
                if ($pcode === '' && $code !== '' && strpos($code, '.') !== false) {
                    $pcode = substr($code, 0, strrpos($code, '.'));
                }
                $parentId = null;
                if ($pcode !== '') {
                    if (!isset($codeToId[$pcode])) {
                        $next[] = $r; // родитель ещё не создан - отложить
                        continue;
                    }
                    $parentId = $codeToId[$pcode];
                }
                $id = self::add_element($codifier_id, $parentId, $code, $title);
                if ($code !== '') {
                    $codeToId[$code] = $id;
                }
                $created++;
            }
            if (count($next) === count($pending)) {
                break; // прогресса нет (висячие parent_code) - выходим
            }
            $pending = $next;
        }
        self::rebuild_paths($codifier_id);
        return $created;
    }
}
