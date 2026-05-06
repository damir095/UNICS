<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Управление иерархией организаций УНИКС.
 * Курсы Moodle не привязываются к организациям —
 * они создаются независимо и назначаются учащимся отдельно.
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

        return $DB->insert_record('unics_regions', (object)[
            'name'      => $name,
            'code'      => '72',
            'is_active' => 1,
        ]);
    }

    public static function update_region(int $id, string $name): void {
        global $DB;
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

        return $DB->insert_record('unics_districts', (object)[
            'region_id' => $region_id,
            'name'      => $name,
        ]);
    }

    public static function update_district(int $id, string $name): void {
        global $DB;
        $DB->update_record('unics_districts', (object)['id' => $id, 'name' => $name]);
    }

    // ----------------------------------------------------------------
    // ОРГАНИЗАЦИИ
    // ----------------------------------------------------------------

    public static function get_organizations(int $district_id): array {
        global $DB;
        return $DB->get_records('unics_organizations', ['district_id' => $district_id, 'is_active' => 1], 'name ASC');
    }

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

        return $DB->insert_record('unics_organizations', (object)[
            'district_id' => $district_id,
            'name'        => $name,
            'short_name'  => $short_name,
            'org_type'    => $org_type,
            'address'     => $address,
            'phone'       => $phone,
            'email'       => $email,
            'is_active'   => 1,
        ]);
    }

    public static function update_organization(int $id, array $data): void {
        global $DB;
        $data['id'] = $id;
        $DB->update_record('unics_organizations', (object)$data);
    }

    // ----------------------------------------------------------------
    // ПЕРЕВОД УЧАСТНИКОВ
    // ----------------------------------------------------------------

    public static function move_members(int $from_org_id, int $to_org_id): int {
        global $DB;
        $count = $DB->count_records('unics_user_org', ['organization_id' => $from_org_id]);
        if ($count > 0) {
            $DB->set_field('unics_user_org', 'organization_id', $to_org_id, ['organization_id' => $from_org_id]);
        }
        return $count;
    }

    // ----------------------------------------------------------------
    // УДАЛЕНИЕ
    // ----------------------------------------------------------------

    public static function delete_organization(int $id) {
        global $DB;

        $active = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {unics_user_org} uo
             JOIN {user} u ON u.id = uo.mdl_user_id
             WHERE uo.organization_id = :orgid AND u.deleted = 0",
            ['orgid' => $id]
        );
        if ($active > 0) {
            return 'Нельзя удалить: в организации есть пользователи. Сначала переведите или удалите их.';
        }

        $DB->set_field('unics_organizations', 'is_active', 0, ['id' => $id]);
        return true;
    }

    public static function delete_district(int $id) {
        global $DB;

        $count = $DB->count_records('unics_organizations', ['district_id' => $id, 'is_active' => 1]);
        if ($count > 0) {
            return "Нельзя удалить: в районе есть {$count} активных организаций.";
        }

        $DB->delete_records('unics_organizations', ['district_id' => $id, 'is_active' => 0]);
        $DB->delete_records('unics_districts', ['id' => $id]);
        return true;
    }

    public static function delete_region(int $id) {
        global $DB;

        $districts = $DB->get_records('unics_districts', ['region_id' => $id]);

        foreach ($districts as $dist) {
            $count = $DB->count_records('unics_organizations', ['district_id' => $dist->id, 'is_active' => 1]);
            if ($count > 0) {
                return "Нельзя удалить: в районе «{$dist->name}» есть активные организации.";
            }
        }

        foreach ($districts as $dist) {
            $DB->delete_records('unics_organizations', ['district_id' => $dist->id]);
        }
        $DB->delete_records('unics_districts', ['region_id' => $id]);
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
}
