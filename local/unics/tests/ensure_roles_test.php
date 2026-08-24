<?php
namespace local_unics;

use local_unics\identity\role_manager;

/**
 * Роли УНИКС заводятся и на ЧИСТОЙ установке ([[roles-on-fresh-install]]).
 *
 * Найдено 2026-08-24: роли создавались только шагами db/upgrade.php, а установка плагина идет из
 * install.xml - ни один шаг апгрейда при этом не выполняется. На развернутой с нуля копии не было
 * ни ролей, ни прав; держалось все на том, что боевой стенд рос апгрейдами.
 *
 * Тестовая база - как раз чистая установка, поэтому проверка тут настоящая.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(role_manager::class)]
final class ensure_roles_test extends \advanced_testcase {

    /** Роли, которых нет в стандартной поставке Moodle. */
    private const OURS = ['region_admin', 'methodist', 'district_methodist',
                          'region_methodist', 'parent'];

    public function test_fresh_install_has_all_unics_roles(): void {
        // Главная проверка задачи, и она честная по построению: тестовая база создается
        // установкой плагина из install.xml, то есть ровно тем путем, на котором ролей
        // раньше не появлялось вовсе. Их наличие здесь означает, что db/install.php отработал.
        global $DB;
        $this->resetAfterTest();

        foreach (self::OURS as $shortname) {
            $this->assertTrue($DB->record_exists('role', ['shortname' => $shortname]),
                "роль $shortname обязана заводиться при установке плагина");
        }
    }

    public function test_install_queues_the_matrix_task(): void {
        // Роль без прав бесполезна, но применить матрицу прямо в install.php нельзя: ядро
        // зовет его ДО регистрации capability плагина. Поэтому установка ставит разовую
        // задачу - проверяем, что она делает свое дело.
        global $DB;
        $this->resetAfterTest();

        // Задача пишет через mtrace: без перехвата PHPUnit метит тест рискованным.
        ob_start();
        (new \local_unics\task\apply_role_matrix())->execute();
        ob_end_clean();

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'methodist']);
        $this->assertTrue($DB->record_exists('role_capabilities',
            ['roleid' => $roleid, 'capability' => 'local/unics:manageorg',
             'permission' => CAP_ALLOW]),
            'после задачи у методиста обязано быть manageorg');
    }

    public function test_ensure_roles_creates_them(): void {
        global $DB;
        $this->resetAfterTest();
        foreach (self::OURS as $shortname) {
            delete_role((int)$DB->get_field('role', 'id', ['shortname' => $shortname]));
        }

        role_manager::ensure_roles();

        foreach (self::OURS as $shortname) {
            $this->assertTrue($DB->record_exists('role', ['shortname' => $shortname]),
                "роль $shortname обязана появиться");
        }
    }

    public function test_archetypes_match_the_model(): void {
        global $DB;
        $this->resetAfterTest();
        foreach (self::OURS as $shortname) {
            delete_role((int)$DB->get_field('role', 'id', ['shortname' => $shortname]));
        }

        role_manager::ensure_roles();

        $expected = [
            'region_admin' => 'manager',
            'region_methodist' => 'manager',
            'methodist' => 'editingteacher',
            'district_methodist' => 'editingteacher',
            'parent' => 'student',
        ];
        foreach ($expected as $shortname => $archetype) {
            $this->assertSame($archetype,
                $DB->get_field('role', 'archetype', ['shortname' => $shortname]),
                "архетип роли $shortname");
        }
    }

    public function test_parent_is_allowed_in_the_user_context(): void {
        // Роль родителя живет в контексте своего ребенка ([[parent-role-scope]]): без этого
        // уровня интерфейс ролей такую пару даже не покажет.
        //
        // Роль СНОСИМ перед проверкой: в тестовой базе она уже создана установкой, и без этого
        // тест смотрел бы на результат установки, а не на работу ensure_roles - мутация метода
        // его не роняла (найдено мутацией).
        global $DB;
        $this->resetAfterTest();
        delete_role((int)$DB->get_field('role', 'id', ['shortname' => 'parent']));

        role_manager::ensure_roles();

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'parent']);
        $levels = array_map('intval', $DB->get_fieldset_select('role_context_levels',
            'contextlevel', 'roleid = ?', [$roleid]));
        $this->assertContains(CONTEXT_USER, $levels);
        $this->assertNotContains(CONTEXT_SYSTEM, $levels,
            'системный уровень - это ровно та утечка, из-за которой роль и переехала');
    }

    public function test_staff_roles_are_allowed_in_the_system_context(): void {
        // Наши административные роли скоупятся через unics_user_org, а права им нужны
        // системные - иначе apply_matrix не сможет их назначить.
        global $DB;
        $this->resetAfterTest();

        $staff = ['region_admin', 'methodist', 'district_methodist', 'region_methodist'];
        foreach ($staff as $sn) {
            delete_role((int)$DB->get_field('role', 'id', ['shortname' => $sn]));
        }

        role_manager::ensure_roles();

        foreach ($staff as $sn) {
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $sn]);
            $levels = array_map('intval', $DB->get_fieldset_select('role_context_levels',
                'contextlevel', 'roleid = ?', [$roleid]));
            $this->assertContains(CONTEXT_SYSTEM, $levels, "уровни роли $sn");
        }
    }

    public function test_capabilities_are_applied(): void {
        // Роль без прав бесполезна: ensure_roles обязан довести дело до матрицы.
        global $DB;
        $this->resetAfterTest();

        role_manager::ensure_roles();
        role_manager::apply_matrix();

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'methodist']);
        $this->assertTrue($DB->record_exists('role_capabilities',
            ['roleid' => $roleid, 'capability' => 'local/unics:manageorg', 'permission' => CAP_ALLOW]),
            'методист обязан получить manageorg');
    }

    public function test_second_call_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        role_manager::ensure_roles();
        $before = $DB->count_records('role');
        $ids = $DB->get_records_menu('role', null, 'shortname', 'shortname, id');

        role_manager::ensure_roles();

        $this->assertSame($before, $DB->count_records('role'), 'дублей ролей быть не должно');
        $this->assertSame($ids, $DB->get_records_menu('role', null, 'shortname', 'shortname, id'),
            'идентификаторы ролей обязаны остаться теми же');
    }

    public function test_existing_role_is_left_alone(): void {
        // На живом сайте роли могли переименовать или перенастроить руками: «доводка до
        // образца» затерла бы чужую работу.
        global $DB;
        $this->resetAfterTest();
        $role = $DB->get_record('role', ['shortname' => 'methodist'], '*', MUST_EXIST);
        $DB->set_field('role', 'name', 'Мой методист', ['id' => $role->id]);
        $DB->set_field('role', 'archetype', 'teacher', ['id' => $role->id]);

        role_manager::ensure_roles();

        $after = $DB->get_record('role', ['shortname' => 'methodist']);
        $this->assertSame((int)$role->id, (int)$after->id);
        $this->assertSame('Мой методист', $after->name);
        $this->assertSame('teacher', $after->archetype);
    }
}
