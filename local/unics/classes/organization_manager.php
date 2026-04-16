<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Управление иерархией организаций УНИКС.
 *
 * При создании районов и организаций автоматически создаёт
 * соответствующие категории курсов в Moodle и сохраняет
 * их ID в unics_*.mdl_category_id.
 */
class unics_organization_manager {

    // ----------------------------------------------------------------
    // РЕГИОНЫ
    // ----------------------------------------------------------------

    public static function get_regions(): array {
        global $DB;
        return $DB->get_records('unics_regions', ['is_active' => 1], 'name ASC');
    }

    public static function create_region(string $name): int {
        global $DB;

        $cat_id = self::create_moodle_category($name, 0, 'unics_region_tyumen');

        return $DB->insert_record('unics_regions', (object)[
            'name'            => $name,
            'code'            => '72',
            'mdl_category_id' => $cat_id,
            'is_active'       => 1,
        ]);
    }

    public static function update_region(int $id, string $name): void {
        global $DB;
        $region = $DB->get_record('unics_regions', ['id' => $id], '*', MUST_EXIST);

        if ($region->mdl_category_id) {
            self::update_moodle_category((int)$region->mdl_category_id, $name);
        }
        $DB->update_record('unics_regions', (object)['id' => $id, 'name' => $name]);
    }

    // ----------------------------------------------------------------
    // РАЙОНЫ
    // ----------------------------------------------------------------

    public static function get_districts(int $region_id): array {
        global $DB;
        return $DB->get_records('unics_districts', ['region_id' => $region_id], 'name ASC');
    }

    public static function create_district(int $region_id, string $name): int {
        global $DB;

        $region     = $DB->get_record('unics_regions', ['id' => $region_id], '*', MUST_EXIST);
        $parent_cat = (int)($region->mdl_category_id ?? 0);

        $idnumber = 'unics_dist_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
        $cat_id = self::create_moodle_category($name, $parent_cat, $idnumber);

        return $DB->insert_record('unics_districts', (object)[
            'region_id'       => $region_id,
            'name'            => $name,
            'mdl_category_id' => $cat_id,
        ]);
    }

    public static function update_district(int $id, string $name): void {
        global $DB;
        $district = $DB->get_record('unics_districts', ['id' => $id], '*', MUST_EXIST);

        if ($district->mdl_category_id) {
            self::update_moodle_category((int)$district->mdl_category_id, $name);
        }
        $DB->update_record('unics_districts', (object)['id' => $id, 'name' => $name]);
    }

    // ----------------------------------------------------------------
    // ОРГАНИЗАЦИИ
    // ----------------------------------------------------------------

    public static function get_organizations(int $district_id): array {
        global $DB;
        return $DB->get_records('unics_organizations', ['district_id' => $district_id, 'is_active' => 1], 'name ASC');
    }

    /**
     * Для выпадающего списка: id => название (уже существует в user_manager,
     * но здесь с дополнением: показывает иерархию)
     */
    public static function get_organizations_grouped(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT o.id, o.name AS org_name, d.name AS dist_name
             FROM {unics_organizations} o
             JOIN {unics_districts} d ON d.id = o.district_id
             WHERE o.is_active = 1
             ORDER BY d.name, o.name"
        );

        $result = [];
        foreach ($rows as $r) {
            $result[$r->id] = $r->dist_name . ' / ' . $r->org_name;
        }
        return $result;
    }

    public static function create_organization(
        int $district_id, string $name, string $short_name,
        int $org_type, string $address = '', string $phone = '', string $email = ''
    ): int {
        global $DB;

        $district   = $DB->get_record('unics_districts', ['id' => $district_id], '*', MUST_EXIST);
        $parent_cat = (int)($district->mdl_category_id ?? 0);

        $display  = $short_name ?: $name;
        $idnumber = 'unics_org_' . time() . '_' . $district_id;
        $cat_id   = self::create_moodle_category($display, $parent_cat, $idnumber);

        return $DB->insert_record('unics_organizations', (object)[
            'district_id'     => $district_id,
            'name'            => $name,
            'short_name'      => $short_name,
            'org_type'        => $org_type,
            'address'         => $address,
            'phone'           => $phone,
            'email'           => $email,
            'mdl_category_id' => $cat_id,
            'is_active'       => 1,
        ]);
    }

    public static function update_organization(int $id, array $data): void {
        global $DB;
        $org = $DB->get_record('unics_organizations', ['id' => $id], '*', MUST_EXIST);

        if ($org->mdl_category_id && !empty($data['name'])) {
            $display = $data['short_name'] ?? $data['name'];
            self::update_moodle_category((int)$org->mdl_category_id, $display);
        }
        $data['id'] = $id;
        $DB->update_record('unics_organizations', (object)$data);
    }

    // ----------------------------------------------------------------
    // УДАЛЕНИЕ
    // ----------------------------------------------------------------

    /**
     * Удалить организацию (мягкое удаление, скрытие категории Moodle).
     * Возвращает true при успехе, строку с ошибкой если нельзя удалить.
     */
    public static function delete_organization(int $id) {
        global $DB;
        $org = $DB->get_record('unics_organizations', ['id' => $id], '*', MUST_EXIST);

        // Проверяем нет ли активных пользователей в организации
        $active = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {unics_user_org} uo
             JOIN {user} u ON u.id = uo.mdl_user_id
             WHERE uo.organization_id = :orgid AND u.deleted = 0",
            ['orgid' => $id]
        );
        if ($active > 0) {
            return 'Нельзя удалить: в организации есть пользователи. Сначала переведите или удалите их.';
        }

        // Скрываем категорию Moodle (не удаляем — могут быть курсы)
        if ($org->mdl_category_id) {
            $cat = core_course_category::get((int)$org->mdl_category_id, IGNORE_MISSING);
            if ($cat) {
                $cat->update(['visible' => 0]);
            }
        }

        $DB->set_field('unics_organizations', 'is_active', 0, ['id' => $id]);
        return true;
    }

    /**
     * Удалить район. Только если нет активных организаций.
     */
    public static function delete_district(int $id) {
        global $DB;

        $count = $DB->count_records('unics_organizations', ['district_id' => $id, 'is_active' => 1]);
        if ($count > 0) {
            return "Нельзя удалить: в районе есть {$count} активных организаций.";
        }

        // Удаляем неактивные организации района (FK не даст удалить район пока они есть)
        $inactive_orgs = $DB->get_records('unics_organizations', ['district_id' => $id, 'is_active' => 0]);
        foreach ($inactive_orgs as $org) {
            if ($org->mdl_category_id) {
                $cat = core_course_category::get((int)$org->mdl_category_id, IGNORE_MISSING);
                if ($cat) {
                    $cat->update(['visible' => 0]);
                }
            }
        }
        $DB->delete_records('unics_organizations', ['district_id' => $id, 'is_active' => 0]);

        $district = $DB->get_record('unics_districts', ['id' => $id], '*', MUST_EXIST);
        if ($district->mdl_category_id) {
            $cat = core_course_category::get((int)$district->mdl_category_id, IGNORE_MISSING);
            if ($cat) {
                $cat->update(['visible' => 0]);
            }
        }

        $DB->delete_records('unics_districts', ['id' => $id]);
        return true;
    }

    /**
     * Удалить регион. Только если нет районов.
     */
    public static function delete_region(int $id) {
        global $DB;

        $districts = $DB->get_records('unics_districts', ['region_id' => $id]);

        // Проверяем нет ли активных организаций в районах региона
        foreach ($districts as $dist) {
            $count = $DB->count_records('unics_organizations', ['district_id' => $dist->id, 'is_active' => 1]);
            if ($count > 0) {
                return "Нельзя удалить: в районе «{$dist->name}» есть активные организации.";
            }
        }

        // Удаляем все организации и районы региона (FK-цепочка)
        foreach ($districts as $dist) {
            $orgs = $DB->get_records('unics_organizations', ['district_id' => $dist->id]);
            foreach ($orgs as $org) {
                if ($org->mdl_category_id) {
                    $cat = core_course_category::get((int)$org->mdl_category_id, IGNORE_MISSING);
                    if ($cat) { $cat->update(['visible' => 0]); }
                }
            }
            $DB->delete_records('unics_organizations', ['district_id' => $dist->id]);

            if ($dist->mdl_category_id) {
                $cat = core_course_category::get((int)$dist->mdl_category_id, IGNORE_MISSING);
                if ($cat) { $cat->update(['visible' => 0]); }
            }
        }
        $DB->delete_records('unics_districts', ['region_id' => $id]);

        $region = $DB->get_record('unics_regions', ['id' => $id], '*', MUST_EXIST);
        if ($region->mdl_category_id) {
            $cat = core_course_category::get((int)$region->mdl_category_id, IGNORE_MISSING);
            if ($cat) {
                $cat->update(['visible' => 0]);
            }
        }

        $DB->delete_records('unics_regions', ['id' => $id]);
        return true;
    }

    // ----------------------------------------------------------------
    // Полное дерево: регион → районы → организации
    // ----------------------------------------------------------------

    public static function get_tree(): array {
        global $DB;

        $regions = $DB->get_records('unics_regions', ['is_active' => 1], 'name ASC');
        foreach ($regions as &$region) {
            $region->districts = $DB->get_records(
                'unics_districts', ['region_id' => $region->id], 'name ASC'
            );
            foreach ($region->districts as &$dist) {
                $dist->organizations = $DB->get_records(
                    'unics_organizations',
                    ['district_id' => $dist->id, 'is_active' => 1],
                    'name ASC'
                );
            }
        }
        return $regions;
    }

    // ----------------------------------------------------------------
    // Helpers: работа с mdl_course_categories
    // ----------------------------------------------------------------

    private static function create_moodle_category(string $name, int $parent, string $idnumber): int {
        global $DB;

        // Если уже есть категория с таким idnumber — вернуть её id
        if ($idnumber) {
            $existing = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
            if ($existing) {
                return (int)$existing->id;
            }
        }

        $data           = new stdClass();
        $data->name     = $name;
        $data->idnumber = $idnumber;
        $data->parent   = $parent;
        $data->visible  = 1;

        // core_course_category::create пересчитывает depth и path автоматически
        $cat = core_course_category::create($data);
        return (int)$cat->id;
    }

    private static function update_moodle_category(int $cat_id, string $new_name): void {
        $cat = core_course_category::get($cat_id, IGNORE_MISSING);
        if ($cat) {
            $cat->update(['name' => $new_name]);
        }
    }
}
