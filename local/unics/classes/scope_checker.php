<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Проверки скоупа для ролей с capability `local/unics:manageorg`.
 *
 * Скоуп берётся из `unics_user_org`: у одного пользователя ровно одна
 * запись с одним из (region_id, district_id, organization_id) непустым.
 * Иерархия: регион ⊃ районы ⊃ организации.
 *
 * Используется на write-страницах для проверки «target входит в мой скоуп?».
 * Сам по себе класс — pure logic, capability-проверки делает вызывающий код
 * (см. функции-обёртки `local_unics_require_manage_or_scope_*` в [lib.php](../lib.php)).
 */
class scope_checker {

    /**
     * Возвращает скоуп пользователя из `unics_user_org`.
     *
     * @return array{region_id: ?int, district_id: ?int, organization_id: ?int}
     */
    public static function get_user_scope(int $mdl_user_id): array {
        global $DB;

        $rec = $DB->get_record('unics_user_org', ['mdl_user_id' => $mdl_user_id],
            'region_id, district_id, organization_id', IGNORE_MULTIPLE);

        if (!$rec) {
            return ['region_id' => null, 'district_id' => null, 'organization_id' => null];
        }

        return [
            'region_id'       => $rec->region_id       ? (int)$rec->region_id       : null,
            'district_id'     => $rec->district_id     ? (int)$rec->district_id     : null,
            'organization_id' => $rec->organization_id ? (int)$rec->organization_id : null,
        ];
    }

    /**
     * Проверяет, что организация $org_id входит в скоуп пользователя.
     */
    public static function user_can_access_org(int $mdl_user_id, int $org_id): bool {
        global $DB;

        $scope = self::get_user_scope($mdl_user_id);

        if ($scope['organization_id'] !== null) {
            return $scope['organization_id'] === $org_id;
        }

        $org = $DB->get_record('unics_organizations', ['id' => $org_id], 'id, district_id', IGNORE_MISSING);
        if (!$org) {
            return false;
        }

        if ($scope['district_id'] !== null) {
            return (int)$org->district_id === $scope['district_id'];
        }

        if ($scope['region_id'] !== null) {
            $district = $DB->get_record('unics_districts', ['id' => $org->district_id],
                'id, region_id', IGNORE_MISSING);
            return $district && (int)$district->region_id === $scope['region_id'];
        }

        return false;
    }

    /**
     * Проверяет, что район $district_id входит в скоуп пользователя.
     */
    public static function user_can_access_district(int $mdl_user_id, int $district_id): bool {
        global $DB;

        $scope = self::get_user_scope($mdl_user_id);

        // Пользователь со скоупом «организация» не имеет прав на район как сущность.
        if ($scope['organization_id'] !== null) {
            return false;
        }

        if ($scope['district_id'] !== null) {
            return $scope['district_id'] === $district_id;
        }

        if ($scope['region_id'] !== null) {
            $district = $DB->get_record('unics_districts', ['id' => $district_id],
                'id, region_id', IGNORE_MISSING);
            return $district && (int)$district->region_id === $scope['region_id'];
        }

        return false;
    }

    /**
     * Проверяет, что регион $region_id входит в скоуп пользователя
     * (используется для region_admin со скоупом-регионом, при работе со списком районов).
     */
    public static function user_can_access_region(int $mdl_user_id, int $region_id): bool {
        $scope = self::get_user_scope($mdl_user_id);
        return $scope['region_id'] !== null && $scope['region_id'] === $region_id;
    }

    /**
     * Проверяет, что целевой пользователь входит в скоуп текущего.
     *
     * Орг target'а ищется в порядке: unics_students → unics_teachers → unics_user_org.
     * Для родителя (parent) — через unics_parent_student.student.organization_id.
     * Если у target нет ни одной привязки — возвращает false (безопасный default).
     */
    public static function user_can_access_user(int $mdl_user_id, int $target_mdl_user_id): bool {
        global $DB;

        $org_id = self::get_user_organization($target_mdl_user_id);
        if ($org_id !== null) {
            return self::user_can_access_org($mdl_user_id, $org_id);
        }

        // Fallback: target не привязан ни к одной орг (например, региональн. админ).
        // Сравниваем скоупы напрямую — нижестоящий должен входить в вышестоящий.
        $my     = self::get_user_scope($mdl_user_id);
        $target = self::get_user_scope($target_mdl_user_id);

        if ($target['district_id'] !== null) {
            return self::user_can_access_district($mdl_user_id, $target['district_id']);
        }
        if ($target['region_id'] !== null) {
            // target = регион-админ со скоупом-регионом. Доступ только у системного админа
            // (обёртка в lib.php пропускает через :manage), либо у region_admin того же региона.
            return $my['region_id'] !== null && $my['region_id'] === $target['region_id'];
        }

        return false;
    }

    /**
     * Определяет орг-привязку пользователя.
     * Возвращает null, если не привязан к организации (например, регион-админ или методист района).
     */
    public static function get_user_organization(int $mdl_user_id): ?int {
        global $DB;

        $org_id = $DB->get_field('unics_students', 'organization_id',
            ['mdl_user_id' => $mdl_user_id], IGNORE_MULTIPLE);
        if ($org_id) {
            return (int)$org_id;
        }

        $org_id = $DB->get_field('unics_teachers', 'organization_id',
            ['mdl_user_id' => $mdl_user_id], IGNORE_MULTIPLE);
        if ($org_id) {
            return (int)$org_id;
        }

        // Родитель — через ребёнка.
        $parent_org = $DB->get_field_sql(
            "SELECT s.organization_id
               FROM {unics_parent_student} ps
               JOIN {unics_students} s ON s.id = ps.student_id
              WHERE ps.parent_mdl_user_id = :uid
              LIMIT 1",
            ['uid' => $mdl_user_id]
        );
        if ($parent_org) {
            return (int)$parent_org;
        }

        // Сам пользователь привязан к орг через unics_user_org.organization_id.
        $org_id = $DB->get_field('unics_user_org', 'organization_id',
            ['mdl_user_id' => $mdl_user_id], IGNORE_MULTIPLE);
        if ($org_id) {
            return (int)$org_id;
        }

        return null;
    }

    /**
     * Возвращает SQL WHERE-фрагмент для фильтрации организаций по скоупу пользователя.
     * Используется на страницах со списками (`enrol_*`, `users.php`) для подгрузки
     * только тех орг, к которым у пользователя есть доступ.
     *
     * @param int $mdl_user_id
     * @param string $org_alias Алиас таблицы unics_organizations в запросе (например, 'o').
     * @return array{0: string, 1: array} [where_sql, params]
     */
    public static function org_filter_sql(int $mdl_user_id, string $org_alias = 'o'): array {
        global $DB;

        $scope = self::get_user_scope($mdl_user_id);

        if ($scope['organization_id'] !== null) {
            return ["{$org_alias}.id = :scp_org", ['scp_org' => $scope['organization_id']]];
        }

        if ($scope['district_id'] !== null) {
            return ["{$org_alias}.district_id = :scp_dist", ['scp_dist' => $scope['district_id']]];
        }

        if ($scope['region_id'] !== null) {
            return [
                "{$org_alias}.district_id IN (SELECT id FROM {unics_districts} WHERE region_id = :scp_reg)",
                ['scp_reg' => $scope['region_id']],
            ];
        }

        // Нет скоупа — пустой набор.
        return ['1=0', []];
    }

    /**
     * WHERE-фрагмент для фильтрации СПИСКА пользователей по скоупу смотрящего.
     * Матчит целевых пользователей по их записи `unics_user_org` ($uo_alias) и —
     * для оргскоупа цели — по district_id её организации ($org_alias, обычно
     * LEFT JOIN unics_organizations ON o.id = uo.organization_id).
     *
     * Используется на users.php, чтобы районный/региональный админ/методист видели
     * пользователей своего скоупа (включая нижестоящих админов/методистов без орг).
     *
     * @param int $mdl_user_id смотрящий
     * @param string $uo_alias алиас unics_user_org в запросе
     * @param string $org_alias алиас unics_organizations (LEFT JOIN по uo.organization_id)
     * @return array{0:string,1:array} [where, params]; '1=0' если скоупа нет.
     */
    public static function user_list_filter_sql(int $mdl_user_id, string $uo_alias = 'uo', string $org_alias = 'o'): array {
        $scope = self::get_user_scope($mdl_user_id);

        if ($scope['organization_id'] !== null) {
            return ["{$uo_alias}.organization_id = :uls_org", ['uls_org' => $scope['organization_id']]];
        }

        if ($scope['district_id'] !== null) {
            return [
                "({$uo_alias}.district_id = :uls_dist OR {$org_alias}.district_id = :uls_dist2)",
                ['uls_dist' => $scope['district_id'], 'uls_dist2' => $scope['district_id']],
            ];
        }

        if ($scope['region_id'] !== null) {
            return [
                "({$uo_alias}.region_id = :uls_reg
                  OR {$uo_alias}.district_id IN (SELECT id FROM {unics_districts} WHERE region_id = :uls_reg2)
                  OR {$org_alias}.district_id IN (SELECT id FROM {unics_districts} WHERE region_id = :uls_reg3))",
                [
                    'uls_reg'  => $scope['region_id'],
                    'uls_reg2' => $scope['region_id'],
                    'uls_reg3' => $scope['region_id'],
                ],
            ];
        }

        return ['1=0', []];
    }

    /**
     * Меню районов, доступных пользователю по его скоупу: [id => name].
     *
     * Системный админ видит все районы; региональный — районы своего региона;
     * районный/орг — только свой район (один элемент). Используется для построения
     * фильтров «Район» на списковых страницах (users.php, my_students.php и т.п.).
     * Capability-проверку (системный ли это админ) делает вызывающий код — отсюда
     * флаг $is_full_admin, а не проверка прав внутри (см. контракт класса).
     *
     * @param int  $mdl_user_id   смотрящий
     * @param bool $is_full_admin есть ли у него local/unics:manage
     * @return array<int,string>
     */
    public static function accessible_districts_menu(int $mdl_user_id, bool $is_full_admin): array {
        global $DB;

        if ($is_full_admin) {
            $rows = $DB->get_records('unics_districts', null, 'name ASC', 'id, name');
        } else {
            $scope = self::get_user_scope($mdl_user_id);
            if ($scope['region_id'] !== null) {
                $rows = $DB->get_records('unics_districts',
                    ['region_id' => $scope['region_id']], 'name ASC', 'id, name');
            } else if ($scope['district_id'] !== null) {
                $rows = $DB->get_records('unics_districts',
                    ['id' => $scope['district_id']], 'name ASC', 'id, name');
            } else {
                $rows = [];
            }
        }

        $menu = [];
        foreach ($rows as $d) {
            $menu[(int)$d->id] = $d->name;
        }
        return $menu;
    }
}
