<?php
namespace local_unics;

/**
 * Роль родителя действует только на своего ребенка ([[parent-role-scope]]).
 *
 * Зонд 2026-08-24: роль назначалась на СИСТЕМНЫЙ контекст, а у нее архетип «студент» с правами
 * moodle/user:viewalldetails, moodle/site:viewreports и report/log:view. Родитель открыл журнал
 * оценок ЧУЖОГО ребенка обычным адресом Moodle: наши страницы проверяют unics_parent_student,
 * ядровые о ней не знают.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\unics_user_manager::class)]
final class parent_role_scope_test extends \advanced_testcase {

    public static function setUpBeforeClass(): void {
        global $CFG;
        // Класс лежит вне автозагрузки (глобальное имя unics_user_manager) - как в страницах.
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        parent::setUpBeforeClass();
    }

    /** Ученик УНИКС: [student_id, mdl_user_id]. */
    private function student(): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'class_number' => 7, 'difficulty_level' => 2,
        ]);
        return [$sid, (int)$user->id];
    }

    /** Роль parent должна существовать - в install.php она заводится вместе с прочими. */
    private function parent_role_id(): int {
        global $DB;
        $id = $DB->get_field('role', 'id', ['shortname' => 'parent']);
        if (!$id) {
            // В тестовой базе ролей плагина нет: они заводятся в upgrade.php, а тесты ставят
            // плагин начисто. Роль воспроизводим вместе с правом, ради которого задача и
            // затевалась, - именно оно на системном контексте открывало чужих детей.
            $id = create_role('Родитель', 'parent', 'Родитель учащегося', 'student');
            assign_capability('moodle/user:viewdetails', CAP_ALLOW, (int)$id,
                \context_system::instance()->id, true);
        }
        return (int)$id;
    }

    public function test_link_assigns_role_in_the_child_context(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = $this->parent_role_id();
        $parent = $this->getDataGenerator()->create_user();
        [$sid, $childuid] = $this->student();

        \unics_user_manager::assign_parent_student((int)$parent->id, $sid);

        $childctx = \context_user::instance($childuid);
        $this->assertTrue($DB->record_exists('role_assignments',
            ['roleid' => $roleid, 'userid' => (int)$parent->id, 'contextid' => $childctx->id]),
            'роль обязана появиться в контексте ребенка');
    }

    public function test_no_role_in_the_system_context(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = $this->parent_role_id();
        $parent = $this->getDataGenerator()->create_user();
        [$sid, ] = $this->student();

        \unics_user_manager::assign_parent_student((int)$parent->id, $sid);

        $this->assertFalse($DB->record_exists('role_assignments',
            ['roleid' => $roleid, 'userid' => (int)$parent->id,
             'contextid' => \context_system::instance()->id]),
            'на системном контексте права действуют на ВСЕХ пользователей сайта');
    }

    public function test_parent_sees_own_child_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->parent_role_id();
        $parent = $this->getDataGenerator()->create_user();
        [$sid, $ownuid] = $this->student();
        [, $foreignuid] = $this->student();

        \unics_user_manager::assign_parent_student((int)$parent->id, $sid);
        $this->setUser($parent);

        $this->assertTrue(has_capability('moodle/user:viewdetails',
            \context_user::instance($ownuid)), 'своего ребенка родитель видит');
        $this->assertFalse(has_capability('moodle/user:viewdetails',
            \context_user::instance($foreignuid)), 'чужого - нет');
    }

    public function test_unlink_removes_the_role(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = $this->parent_role_id();
        $parent = $this->getDataGenerator()->create_user();
        [$sid, $childuid] = $this->student();
        \unics_user_manager::assign_parent_student((int)$parent->id, $sid);
        $linkid = (int)$DB->get_field('unics_parent_student', 'id',
            ['parent_mdl_user_id' => (int)$parent->id, 'student_id' => $sid]);

        \unics_user_manager::remove_parent_student($linkid);

        $this->assertFalse($DB->record_exists('role_assignments',
            ['roleid' => $roleid, 'userid' => (int)$parent->id,
             'contextid' => \context_user::instance($childuid)->id]),
            'отвязали ребенка - права на него уходят вместе с привязкой');
    }

    public function test_second_child_does_not_disturb_the_first(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = $this->parent_role_id();
        $parent = $this->getDataGenerator()->create_user();
        [$sid1, $uid1] = $this->student();
        [$sid2, $uid2] = $this->student();
        \unics_user_manager::assign_parent_student((int)$parent->id, $sid1);
        \unics_user_manager::assign_parent_student((int)$parent->id, $sid2);

        // Отвязываем второго: права на первого обязаны остаться.
        $linkid = (int)$DB->get_field('unics_parent_student', 'id',
            ['parent_mdl_user_id' => (int)$parent->id, 'student_id' => $sid2]);
        \unics_user_manager::remove_parent_student($linkid);

        $this->assertTrue($DB->record_exists('role_assignments',
            ['roleid' => $roleid, 'userid' => (int)$parent->id,
             'contextid' => \context_user::instance($uid1)->id]));
        $this->assertFalse($DB->record_exists('role_assignments',
            ['roleid' => $roleid, 'userid' => (int)$parent->id,
             'contextid' => \context_user::instance($uid2)->id]));
    }

    public function test_creating_a_parent_grants_nothing_globally(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = $this->parent_role_id();

        $uid = \unics_user_manager::create_user([
            'username' => 'parent.test', 'firstname' => 'Родитель', 'lastname' => 'Тестовый',
            'email' => 'parent.test@demo.unics.local', 'password' => 'F&2gR@Gf#6',
            'unics_role' => \local_unics\identity\role_manager::ROLE_PARENT,
        ]);

        // Ядро чистит поле lang и пишет отладочное сообщение - к нашей задаче отношения не
        // имеет, но иначе PHPUnit метит тест рискованным.
        $this->resetDebugging();

        $this->assertGreaterThan(0, (int)$uid);
        $this->assertFalse($DB->record_exists('role_assignments',
            ['roleid' => $roleid, 'userid' => (int)$uid,
             'contextid' => \context_system::instance()->id]),
            'пока ребенка нет, назначать роль не на что');
    }
}
