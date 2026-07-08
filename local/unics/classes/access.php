<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Гарды доступа и ролевые хелперы плагина (этап 2.1 разгрузки lib.php).
 *
 * Тела перенесены из lib.php; там остались тонкие обёртки с прежними именами
 * (`local_unics_require_not_student()` и т.д.) - страницы вызывают их как раньше.
 *
 * Два семейства:
 *  - `require_*` - guard'ы страниц: либо пропускают, либо redirect/exception;
 *  - `is_* / user_has_role / creatable_roles / get_role_for_user` - предикаты ролей.
 *
 * Скоуп-проверки делегируются {@see scope_checker}, коды ролей - {@see role_manager}.
 */
class access {

    /** Запросный кеш identity-чтений (db/caches.php, этап 3.3). */
    private static function cache(): \cache {
        return \cache::make('local_unics', 'identity');
    }

    /**
     * Запись unics_students пользователя или null - с запросным кешем:
     * рельс, навигация и гарды спрашивают её по несколько раз за страницу.
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     */
    public static function student_record(?int $userid = null): ?\stdClass {
        global $DB, $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        if ($userid <= 0) {
            return null;
        }
        $cache = self::cache();
        $key = 'student_' . $userid;
        $val = $cache->get($key);
        if ($val === false) {
            // «Записи нет» кешируем как 0 (false в MUC означает промах).
            $val = $DB->get_record('unics_students', ['mdl_user_id' => $userid]) ?: 0;
            $cache->set($key, $val);
        }
        return $val === 0 ? null : $val;
    }

    /**
     * true, если пользователь - родитель (есть привязка в unics_parent_student);
     * с запросным кешем (та же причина, что и у {@see access::student_record()}).
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     */
    public static function is_parent(?int $userid = null): bool {
        global $DB, $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        if ($userid <= 0) {
            return false;
        }
        $cache = self::cache();
        $key = 'parent_' . $userid;
        $val = $cache->get($key);
        if ($val === false) {
            $val = $DB->record_exists('unics_parent_student', ['parent_mdl_user_id' => $userid]) ? 1 : 2;
            $cache->set($key, $val);
        }
        return $val === 1;
    }

    /**
     * Редиректит учащегося на его дашборд, если он пытается открыть педагогическую страницу.
     * Вызывать в начале каждой страницы, доступной педагогам/администраторам.
     */
    public static function require_not_student(): void {
        if (self::student_record() !== null) {
            redirect(new \moodle_url('/local/unics/pages/dashboard.php'));
        }
    }

    /**
     * Возвращает true, если у пользователя назначена одна из Moodle-ролей
     * с указанными shortname (на любом контексте). Результат кешируется в
     * пределах запроса (is_methodist/is_scoped_admin зовут это из рельса,
     * навигации и страниц по несколько раз).
     *
     * @param int $userid id пользователя
     * @param string[] $shortnames список shortname ролей
     * @return bool
     */
    public static function user_has_role(int $userid, array $shortnames): bool {
        global $DB;
        if (!$userid || empty($shortnames)) {
            return false;
        }
        $cache = self::cache();
        $key = 'roles_' . $userid . '_' . implode('_', $shortnames);
        $val = $cache->get($key);
        if ($val === false) {
            [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'sn');
            $params['uid'] = $userid;
            $val = $DB->record_exists_sql(
                "SELECT 1 FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                  WHERE ra.userid = :uid AND r.shortname {$insql}",
                $params
            ) ? 1 : 2;
            $cache->set($key, $val);
        }
        return $val === 1;
    }

    /**
     * Возвращает true, если пользователь - методист (организации ИЛИ района).
     * Методист = Moodle-роль с shortname 'methodist' или 'district_methodist'
     * (переработка ролей 2026-05-23 - оба методиста ведут себя одинаково на наших
     * страницах, различаются только скоупом в unics_user_org).
     *
     * Проверяем только Moodle-роль - это единственный надёжный маркер
     * (unics_user_manager::create_user() создаёт запись в unics_teachers и для методиста,
     * поэтому по таблице teacher'ов методиста не отличить).
     *
     * Capability local/unics:viewstudents проверяется отдельно вызывающим кодом.
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     * @return bool
     */
    public static function is_methodist(?int $userid = null): bool {
        global $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        return self::user_has_role($userid, ['methodist', 'district_methodist']);
    }

    /**
     * Возвращает true, если пользователь - управленец уровня региона со scope-доступом
     * через local/unics:manageorg, но БЕЗ системного local/unics:manage:
     * региональный администратор ('region_admin') ИЛИ региональный методист
     * ('region_methodist', v3 фаза 2 [[role-model-v3-2026-06-11]]) - права и скоуп (регион)
     * у них совпадают, обе получают управленческое меню. Различие - организационное
     * (admin = техобслуживание, methodist = распределение курсов/отчётность) и в заголовке меню.
     * Муниципальный администратор (district_admin) удалён в v3 - муниципальный уровень ведёт
     * district_methodist (методическое меню), см. {@see access::is_methodist()}.
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     * @return bool
     */
    public static function is_scoped_admin(?int $userid = null): bool {
        global $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        return self::user_has_role($userid, ['region_admin', 'region_methodist']);
    }

    /**
     * Возвращает true, если пользователь - педагог без права редактирования
     * (Moodle-роль 'teacher', код 6). Такой педагог видит курс и оценивает, но не
     * создаёт/не редактирует контент - наши страницы для него read-only.
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     * @return bool
     */
    public static function is_nonediting_teacher(?int $userid = null): bool {
        global $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        return self::user_has_role($userid, ['teacher']);
    }

    /**
     * Какие коды unics_role пользователь вправе назначать при создании другого пользователя.
     * Принцип: нельзя создать роль своего уровня или выше - только нижестоящих.
     *   - системный администратор (manage) - все роли;
     *   - региональный администратор - региональный методист (10) и ниже (не другого региона);
     *   - региональный методист - муниципальный методист (9) и ниже (назначает мун. методистов);
     *   - методист организации/муниципалитета - только педагоги, учащиеся, родители;
     *   - остальные - никого.
     * Муниципальный администратор (код 2) удалён в v3 [[role-model-v3-2026-06-11]];
     * региональный методист (код 10) добавлен в фазе 2.
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     * @return int[] разрешённые коды unics_role
     */
    public static function creatable_roles(?int $userid = null): array {
        global $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        $ctx = \context_system::instance();

        if (has_capability('local/unics:manage', $ctx, $userid)) {
            return [\local_unics\identity\role_manager::ROLE_REGION_ADMIN, \local_unics\identity\role_manager::ROLE_REGION_METHODIST,
                \local_unics\identity\role_manager::ROLE_DISTRICT_METHODIST, \local_unics\identity\role_manager::ROLE_METHODIST,
                \local_unics\identity\role_manager::ROLE_COURSE_CREATOR, \local_unics\identity\role_manager::ROLE_TEACHER,
                \local_unics\identity\role_manager::ROLE_STUDENT, \local_unics\identity\role_manager::ROLE_PARENT];
        }
        if (self::user_has_role($userid, ['region_admin'])) {
            return [\local_unics\identity\role_manager::ROLE_REGION_METHODIST, \local_unics\identity\role_manager::ROLE_DISTRICT_METHODIST,
                \local_unics\identity\role_manager::ROLE_METHODIST, \local_unics\identity\role_manager::ROLE_COURSE_CREATOR,
                \local_unics\identity\role_manager::ROLE_TEACHER, \local_unics\identity\role_manager::ROLE_STUDENT,
                \local_unics\identity\role_manager::ROLE_PARENT];
        }
        if (self::user_has_role($userid, ['region_methodist'])) {
            return [\local_unics\identity\role_manager::ROLE_DISTRICT_METHODIST, \local_unics\identity\role_manager::ROLE_METHODIST,
                \local_unics\identity\role_manager::ROLE_COURSE_CREATOR, \local_unics\identity\role_manager::ROLE_TEACHER,
                \local_unics\identity\role_manager::ROLE_STUDENT, \local_unics\identity\role_manager::ROLE_PARENT];
        }
        if (self::is_methodist($userid)) {
            return [\local_unics\identity\role_manager::ROLE_COURSE_CREATOR, \local_unics\identity\role_manager::ROLE_TEACHER,
                \local_unics\identity\role_manager::ROLE_STUDENT, \local_unics\identity\role_manager::ROLE_PARENT];
        }
        return [];
    }

    /**
     * Возвращает роль пользователя в УНИКС: student | parent | admin | methodist | teacher | guest.
     * Приоритет совпадает с порядком веток в local_unics_extend_navigation.
     *
     * @param int|null $userid id пользователя; null = текущий $USER->id
     * @return string
     */
    public static function get_role_for_user(?int $userid = null): string {
        global $DB, $USER;
        if ($userid === null) {
            $userid = (int)$USER->id;
        }
        if (!$userid || isguestuser($userid)) {
            return 'guest';
        }
        if (self::student_record($userid) !== null) {
            return 'student';
        }
        // Родитель: либо привязан к ребёнку в unics_parent_student,
        // либо у него unics_role=8 в unics_user_org (даже без активной привязки).
        if (self::is_parent($userid)
            || $DB->record_exists('unics_user_org', ['mdl_user_id' => $userid,
                'unics_role' => \local_unics\identity\role_manager::ROLE_PARENT])) {
            return 'parent';
        }
        $ctx = \context_system::instance();
        // Системный админ (manage) и региональный/районный админ (manageorg) → 'admin'.
        if (has_capability('local/unics:manage', $ctx, $userid) || self::is_scoped_admin($userid)) {
            return 'admin';
        }
        if (has_capability('local/unics:viewstudents', $ctx, $userid)) {
            return self::is_methodist($userid) ? 'methodist' : 'teacher';
        }
        // Fallback по unics_role в unics_user_org - на случай если capability не пробрасываются.
        $unics_role = $DB->get_field('unics_user_org', 'unics_role', ['mdl_user_id' => $userid]);
        if ($unics_role !== false) {
            return match ((int)$unics_role) {
                1, 2, 3 => 'admin',     // региональн. / районный / org-админ (legacy)
                4, 9    => 'methodist', // методист орг. / районный методист
                5, 6    => 'teacher',   // педагог создающий курсы / non-editing
                7       => 'student',
                8       => 'parent',
                default => 'guest',
            };
        }
        return 'guest';
    }

    /**
     * Guard для входа на write-страницу без конкретного target'а (просмотр формы).
     * Принимает любого, у кого есть либо `local/unics:manage`, либо `local/unics:manageorg`.
     *
     * Конкретный target (org/district/user) ДОЛЖЕН проверяться отдельно после
     * получения данных формы - через `require_manage_or_scope_{org,district,user}`.
     */
    public static function require_manage_or_manageorg(): void {
        $ctx = \context_system::instance();
        if (has_capability('local/unics:manage', $ctx)) {
            return;
        }
        require_capability('local/unics:manageorg', $ctx);
    }

    /**
     * Guard для write-операций над регионом. Семантика та же, что и `_org`.
     */
    public static function require_manage_or_scope_region(int $region_id): void {
        global $USER;
        $ctx = \context_system::instance();

        if (has_capability('local/unics:manage', $ctx)) {
            return;
        }
        require_capability('local/unics:manageorg', $ctx);

        if (!\local_unics\identity\scope_checker::user_can_access_region((int)$USER->id, $region_id)) {
            throw new \moodle_exception('nopermissions', 'error', '',
                'регион вне вашего скоупа');
        }
    }

    /**
     * Guard для write-операций над организацией.
     *
     * Принимает, если:
     *   - у текущего пользователя есть `local/unics:manage` (системный админ), ИЛИ
     *   - есть `local/unics:manageorg` И организация входит в его скоуп.
     *
     * Иначе кидает `required_capability_exception` (manageorg) или
     * `moodle_exception('nopermissions')` (scope mismatch).
     *
     * Используется на write-страницах в связке с {@see scope_checker}.
     */
    public static function require_manage_or_scope_org(int $org_id): void {
        global $USER;
        $ctx = \context_system::instance();

        if (has_capability('local/unics:manage', $ctx)) {
            return;
        }
        require_capability('local/unics:manageorg', $ctx);

        if (!\local_unics\identity\scope_checker::user_can_access_org((int)$USER->id, $org_id)) {
            throw new \moodle_exception('nopermissions', 'error', '',
                'организация вне вашего скоупа');
        }
    }

    /**
     * Guard для write-операций над районом. Семантика та же, что и `_org`.
     */
    public static function require_manage_or_scope_district(int $district_id): void {
        global $USER;
        $ctx = \context_system::instance();

        if (has_capability('local/unics:manage', $ctx)) {
            return;
        }
        require_capability('local/unics:manageorg', $ctx);

        if (!\local_unics\identity\scope_checker::user_can_access_district((int)$USER->id, $district_id)) {
            throw new \moodle_exception('nopermissions', 'error', '',
                'муниципалитет вне вашего скоупа');
        }
    }

    /**
     * Guard для write-операций над пользователем.
     * Скоуп target'а вычисляется через `\local_unics\identity\scope_checker::get_user_organization` с fallback на сам скоуп.
     */
    public static function require_manage_or_scope_user(int $target_mdl_user_id): void {
        global $USER;
        $ctx = \context_system::instance();

        if (has_capability('local/unics:manage', $ctx)) {
            return;
        }
        require_capability('local/unics:manageorg', $ctx);

        if (!\local_unics\identity\scope_checker::user_can_access_user((int)$USER->id, $target_mdl_user_id)) {
            throw new \moodle_exception('nopermissions', 'error', '',
                'пользователь вне вашего скоупа');
        }
    }
}
