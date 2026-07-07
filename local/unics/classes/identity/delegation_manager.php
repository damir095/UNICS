<?php
namespace local_unics\identity;

defined('MOODLE_INTERNAL') || die();

/**
 * Делегирование курсов скоупу (роли v3 фаза 3, [[role-model-v3-2026-06-11]]).
 *
 * Региональный администратор / методист делегируют курс муниципалитету или
 * организации. Методист видит для назначения учеников ТОЛЬКО делегированные его
 * подчинению курсы; региональные роли и педагог-создатель - все.
 *
 * Делегируем скоупу (district|org), не персонально. Наследование вниз:
 * делегирование муниципалитету покрывает все его организации.
 */
class delegation_manager {

    const SCOPE_DISTRICT = 'district';
    const SCOPE_ORG = 'org';

    /** Делегировать курс скоупу (идемпотентно). Возвращает id записи. */
    public static function delegate(int $courseid, string $scope_type, int $scope_id, int $userid): int {
        global $DB;
        $existing = $DB->get_record('unics_course_delegation',
            ['courseid' => $courseid, 'scope_type' => $scope_type, 'scope_id' => $scope_id]);
        if ($existing) {
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('unics_course_delegation', (object)[
            'courseid'    => $courseid,
            'scope_type'  => $scope_type,
            'scope_id'    => $scope_id,
            'usercreated' => $userid,
            'timecreated' => time(),
        ]);
    }

    public static function undelegate(int $delegation_id): void {
        global $DB;
        $DB->delete_records('unics_course_delegation', ['id' => $delegation_id]);
    }

    /** Делегирования курса с резолвом имени скоупа: [{id, scope_type, scope_id, name}]. */
    public static function get_course_delegations(int $courseid): array {
        global $DB;
        $rows = $DB->get_records('unics_course_delegation', ['courseid' => $courseid], 'scope_type, scope_id');
        foreach ($rows as $r) {
            $r->name = self::scope_label($r->scope_type, (int)$r->scope_id);
        }
        return array_values($rows);
    }

    /** Человекочитаемое имя скоупа делегирования. */
    public static function scope_label(string $scope_type, int $scope_id): string {
        global $DB;
        if ($scope_type === self::SCOPE_DISTRICT) {
            $n = $DB->get_field('unics_districts', 'name', ['id' => $scope_id]);
            return $n ? ('Муниципалитет: ' . $n) : ('Муниципалитет #' . $scope_id);
        }
        $n = $DB->get_field('unics_organizations', 'name', ['id' => $scope_id]);
        return $n ? ('Организация: ' . $n) : ('Организация #' . $scope_id);
    }

    /**
     * Id курсов, доступных пользователю для назначения, с учётом делегирования.
     * Возвращает NULL = фильтр не применяется (видны ВСЕ курсы): системный админ,
     * региональный админ/методист, педагог-создатель. Для муниципального методиста и
     * методиста организации возвращает массив id (возможно пустой).
     *
     * @return int[]|null
     */
    public static function get_delegated_course_ids_for_user(int $userid): ?array {
        global $DB;

        if (!function_exists('local_unics_user_has_role')) {
            require_once(__DIR__ . '/../../lib.php');
        }

        if (has_capability('local/unics:manage', \context_system::instance(), $userid)) {
            return null; // системный админ - без фильтра
        }
        // Региональные роли и педагог-создатель видят все курсы.
        if (local_unics_user_has_role($userid, ['region_admin', 'region_methodist', 'editingteacher'])) {
            return null;
        }

        $is_district = local_unics_user_has_role($userid, ['district_methodist']);
        $is_org = local_unics_user_has_role($userid, ['methodist']);
        if (!$is_district && !$is_org) {
            return null; // прочие роли фильтром делегирования не ограничиваем
        }

        $scope = scope_checker::get_user_scope($userid);
        $conds = [];
        $params = [];

        if ($is_district && !empty($scope['district_id'])) {
            $did = (int)$scope['district_id'];
            $conds[] = "(scope_type = 'district' AND scope_id = :d_self)";
            $params['d_self'] = $did;
            // курсы, делегированные организациям этого муниципалитета.
            $orgids = $DB->get_fieldset_select('unics_organizations', 'id', 'district_id = :dd', ['dd' => $did]);
            if ($orgids) {
                list($insql, $inp) = $DB->get_in_or_equal($orgids, SQL_PARAMS_NAMED, 'do');
                $conds[] = "(scope_type = 'org' AND scope_id $insql)";
                $params += $inp;
            }
        }

        if ($is_org && !empty($scope['organization_id'])) {
            $oid = (int)$scope['organization_id'];
            $conds[] = "(scope_type = 'org' AND scope_id = :o_self)";
            $params['o_self'] = $oid;
            // курсы, делегированные муниципалитету этой организации (наследование вниз).
            $did = (int)$DB->get_field('unics_organizations', 'district_id', ['id' => $oid]);
            if ($did) {
                $conds[] = "(scope_type = 'district' AND scope_id = :o_dist)";
                $params['o_dist'] = $did;
            }
        }

        if (!$conds) {
            return []; // методист без скоупа - ни одного курса
        }

        $sql = "SELECT DISTINCT courseid FROM {unics_course_delegation} WHERE " . implode(' OR ', $conds);
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }
}
