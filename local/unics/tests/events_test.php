<?php
namespace local_unics;

use local_unics\event\points_awarded;
use local_unics\event\points_spent;
use local_unics\event\level_changed;
use local_unics\event\umk_published;
use local_unics\event\user_created;
use local_unics\event\user_updated;
use local_unics\event\teacher_student_assigned;
use local_unics\event\parent_student_assigned;
use local_unics\event\organization_created;
use local_unics\event\organization_updated;
use local_unics\event\organization_deleted;
use local_unics\event\teacher_student_unassigned;
use local_unics\event\parent_student_unassigned;
use local_unics\social\points_manager;
use local_unics\learning\adaptive_engine;

/**
 * Тесты событий local_unics (этап 2.4 + 4.4 аудита): классы и эмиссия из менеджеров.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(points_awarded::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(points_spent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(level_changed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(umk_published::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(user_created::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(user_updated::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(teacher_student_assigned::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(parent_student_assigned::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(organization_created::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(organization_updated::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(organization_deleted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(teacher_student_unassigned::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(parent_student_unassigned::class)]
final class events_test extends \advanced_testcase {

    /** Временный ученик: [unics_students.id, mdl_user_id]. */
    private function make_student(): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $sid  = $DB->insert_record('unics_students', (object)[
            'mdl_user_id'      => (int)$user->id,
            'difficulty_level' => 2,
        ]);
        return [(int)$sid, (int)$user->id];
    }

    public function test_event_classes_create_and_describe(): void {
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();
        $course = $this->getDataGenerator()->create_course();
        $sink = $this->redirectEvents();

        points_awarded::create([
            'context'       => \context_system::instance(),
            'objectid'      => 1,
            'relateduserid' => $uid,
            'other'         => ['student_id' => $sid, 'points' => 10, 'reason_type' => 4],
        ])->trigger();
        points_spent::create([
            'context'       => \context_system::instance(),
            'objectid'      => 2,
            'relateduserid' => $uid,
            'other'         => ['student_id' => $sid, 'points' => 30],
        ])->trigger();
        level_changed::create([
            'context'       => \context_system::instance(),
            'objectid'      => $sid,
            'relateduserid' => $uid,
            'other'         => ['old_level' => 2, 'new_level' => 1, 'source' => 'apply'],
        ])->trigger();
        umk_published::create([
            'context'  => \context_course::instance($course->id),
            'objectid' => 123,
            'other'    => ['title' => 'Тестовый УМК', 'topic' => 'Тема'],
        ])->trigger();

        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(4, $events);
        foreach ($events as $e) {
            $this->assertNotSame('', (string)$e->get_name());
            $this->assertNotSame('', (string)$e->get_description());
        }
        $this->assertStringContainsString("#{$sid}", $events[2]->get_description());
        $this->assertStringContainsString('apply', $events[2]->get_description());
        $this->assertStringContainsString('Тестовый УМК', $events[3]->get_description());
    }

    public function test_missing_other_keys_rejected(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        points_awarded::create([
            'context'       => \context_system::instance(),
            'objectid'      => 1,
            'relateduserid' => 2,
            'other'         => ['points' => 5], // нет student_id/reason_type
        ]);
    }

    public function test_points_award_emits_event(): void {
        global $DB;
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();

        $sink = $this->redirectEvents();
        points_manager::award($sid, 10, points_manager::REASON_QUIZ_PASS, 'тест сдан');
        $events = array_values(array_filter($sink->get_events(),
            static fn($e) => $e instanceof points_awarded));
        $sink->close();

        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame($uid, (int)$e->relateduserid);
        $this->assertSame($sid, (int)$e->other['student_id']);
        $this->assertSame(10, (int)$e->other['points']);
        $this->assertSame(points_manager::REASON_QUIZ_PASS, (int)$e->other['reason_type']);
        $this->assertTrue($DB->record_exists('unics_points_log', ['id' => $e->objectid]));
    }

    public function test_points_spend_emits_event(): void {
        global $DB;
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();
        points_manager::award($sid, 50, points_manager::REASON_BADGE, 'значок');

        $sink = $this->redirectEvents();
        $ok = points_manager::spend($sid, 30, 'покупка стикера');
        $events = array_values(array_filter($sink->get_events(),
            static fn($e) => $e instanceof points_spent));
        $sink->close();

        $this->assertTrue($ok);
        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame($uid, (int)$e->relateduserid);
        $this->assertSame($sid, (int)$e->other['student_id']);
        $this->assertSame(30, (int)$e->other['points']);
        $this->assertTrue($DB->record_exists('unics_points_log', ['id' => $e->objectid]));
        // Отказ по балансу события не эмитит.
        $sink2 = $this->redirectEvents();
        $this->assertFalse(points_manager::spend($sid, 999, 'дорого'));
        $this->assertCount(0, array_filter($sink2->get_events(),
            static fn($e) => $e instanceof points_spent));
        $sink2->close();
    }

    public function test_apply_level_emits_event(): void {
        global $CFG;
        // Легаси-глобальный класс без неймспейса - автолоадер его не видит.
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();

        $this->redirectMessages(); // apply_level шлет уведомления
        $sink = $this->redirectEvents();
        adaptive_engine::apply_level($sid, 1, 45.0); // понижение 2->1: без начисления баллов
        $events = array_values(array_filter($sink->get_events(),
            static fn($e) => $e instanceof level_changed));
        $sink->close();

        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame($sid, (int)$e->objectid);
        $this->assertSame($uid, (int)$e->relateduserid);
        $this->assertSame(2, (int)$e->other['old_level']);
        $this->assertSame(1, (int)$e->other['new_level']);
        $this->assertSame('apply', $e->other['source']);
    }

    // ---- Этап 4.4: аудит админ-мутаций ----

    public function test_audit_event_classes_create_and_describe(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $uid = (int)$user->id;
        $ctx = \context_system::instance();
        $sink = $this->redirectEvents();

        user_created::create(['context' => $ctx, 'objectid' => $uid, 'relateduserid' => $uid,
            'other' => ['unics_role' => 7, 'region_id' => null, 'district_id' => null,
                        'organization_id' => 3]])->trigger();
        user_updated::create(['context' => $ctx, 'objectid' => $uid, 'relateduserid' => $uid,
            'other' => ['changed' => ['firstname', 'email']]])->trigger();
        teacher_student_assigned::create(['context' => $ctx, 'objectid' => $uid, 'relateduserid' => $uid,
            'other' => ['teacher_id' => 5, 'student_id' => 9]])->trigger();
        parent_student_assigned::create(['context' => $ctx, 'objectid' => $uid, 'relateduserid' => $uid,
            'other' => ['parent_mdl_user_id' => $uid, 'student_id' => 9]])->trigger();
        organization_created::create(['context' => $ctx, 'objectid' => 7,
            'other' => ['name' => 'Тест-орг', 'district_id' => 2]])->trigger();
        organization_updated::create(['context' => $ctx, 'objectid' => 7,
            'other' => ['name' => 'Тест-орг 2']])->trigger();
        organization_deleted::create(['context' => $ctx, 'objectid' => 7, 'other' => []])->trigger();

        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(7, $events);
        foreach ($events as $e) {
            $this->assertNotSame('', (string)$e->get_name());
            $this->assertNotSame('', (string)$e->get_description());
        }
    }

    public function test_user_created_requires_role(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        user_created::create(['context' => \context_system::instance(), 'objectid' => 1,
            'relateduserid' => 1, 'other' => ['organization_id' => 3]]); // нет unics_role
    }

    public function test_create_user_emits_event(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        $uid = \unics_user_manager::create_user([
            'firstname' => 'Тест', 'lastname' => 'Педагогов',
            'email' => 'auditprobe1@demo.unics.local', 'username' => 'auditprobe1',
            'password' => 'Pass123!x', 'unics_role' => 5, 'organization_id' => null,
        ]);
        // create_user ставит lang='ru'; в phpunit-среде пакет ru отсутствует -> ядро чистит
        // и дебажит (в проде ru установлен, дебага нет). Чистим буфер - артефакт среды.
        $this->resetDebugging();
        $events = array_values(array_filter($sink->get_events(),
            static fn($e) => $e instanceof user_created));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame($uid, (int)$events[0]->objectid);
        $this->assertSame(5, (int)$events[0]->other['unics_role']);
    }

    public function test_update_user_emits_event(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        $uid = \unics_user_manager::create_user([
            'firstname' => 'Тест', 'lastname' => 'Родителев',
            'email' => 'auditprobe2@demo.unics.local', 'username' => 'auditprobe2',
            'password' => 'Pass123!x', 'unics_role' => 8, 'organization_id' => null,
        ]);
        $this->resetDebugging(); // lang=ru чистится в phpunit-среде (нет ru-пакета)
        $sink = $this->redirectEvents();
        \unics_user_manager::update_user($uid, ['firstname' => 'Изменен', 'email' => 'auditprobe2b@demo.unics.local']);
        $events = array_values(array_filter($sink->get_events(),
            static fn($e) => $e instanceof user_updated));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertContains('firstname', $events[0]->other['changed']);
        $this->assertContains('email', $events[0]->other['changed']);
    }

    public function test_assign_links_emit_events(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        $tu = $this->getDataGenerator()->create_user();
        $su = $this->getDataGenerator()->create_user();
        $pu = $this->getDataGenerator()->create_user();
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $tu->id]);
        $sid = $DB->insert_record('unics_students', (object)['mdl_user_id' => $su->id, 'difficulty_level' => 2]);

        $sink = $this->redirectEvents();
        \unics_user_manager::assign_teacher_student((int)$tid, (int)$sid, (int)$tu->id);
        \unics_user_manager::assign_parent_student((int)$pu->id, (int)$sid);
        $te = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof teacher_student_assigned));
        $pe = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof parent_student_assigned));
        $sink->close();
        $this->assertCount(1, $te);
        $this->assertSame((int)$tid, (int)$te[0]->other['teacher_id']);
        $this->assertCount(1, $pe);
        $this->assertSame((int)$sid, (int)$pe[0]->other['student_id']);
    }

    public function test_organization_events(): void {
        global $DB, $CFG;
        // Легаси-глобальный класс без неймспейса - автолоадер не видит.
        require_once($CFG->dirroot . '/local/unics/classes/identity/organization_manager.php');
        $this->resetAfterTest();
        $did = $DB->insert_record('unics_districts', (object)['name' => 'Тест-район', 'region_id' => 0]);

        $sink = $this->redirectEvents();
        $oid = \unics_organization_manager::create_organization((int)$did, 'Аудит-орг', 'АО', 1);
        \unics_organization_manager::update_organization($oid, ['name' => 'Аудит-орг 2']);
        $res = \unics_organization_manager::delete_organization($oid); // нет пользователей -> успех
        $created = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof organization_created));
        $updated = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof organization_updated));
        $deleted = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof organization_deleted));
        $sink->close();

        $this->assertTrue($res === true);
        $this->assertCount(1, $created);
        $this->assertSame($oid, (int)$created[0]->objectid);
        $this->assertSame('Аудит-орг', $created[0]->other['name']);
        $this->assertCount(1, $updated);
        $this->assertCount(1, $deleted);
    }

    public function test_unassign_links_emit_events(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        $tu = $this->getDataGenerator()->create_user();
        $su = $this->getDataGenerator()->create_user();
        $pu = $this->getDataGenerator()->create_user();
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $tu->id]);
        $sid = $DB->insert_record('unics_students', (object)['mdl_user_id' => $su->id, 'difficulty_level' => 2]);
        $ts_id = $DB->insert_record('unics_teacher_student',
            (object)['teacher_id' => $tid, 'student_id' => $sid, 'assigned_by' => $tu->id, 'assigned_at' => time()]);
        $ps_id = $DB->insert_record('unics_parent_student',
            (object)['parent_mdl_user_id' => $pu->id, 'student_id' => $sid]);

        $sink = $this->redirectEvents();
        \unics_user_manager::remove_teacher_student((int)$ts_id);
        \unics_user_manager::remove_parent_student((int)$ps_id);
        $te = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof teacher_student_unassigned));
        $pe = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof parent_student_unassigned));
        $sink->close();

        $this->assertCount(1, $te);
        $this->assertSame((int)$tid, (int)$te[0]->other['teacher_id']);
        $this->assertSame((int)$sid, (int)$te[0]->other['student_id']);
        $this->assertSame((int)$su->id, (int)$te[0]->relateduserid);
        $this->assertCount(1, $pe);
        $this->assertSame((int)$pu->id, (int)$pe[0]->other['parent_mdl_user_id']);
    }

    public function test_unassign_missing_link_no_event(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        \unics_user_manager::remove_teacher_student(999999); // нет такой связи
        $ev = array_filter($sink->get_events(), static fn($e) => $e instanceof teacher_student_unassigned);
        $sink->close();
        $this->assertCount(0, $ev);
    }

    public function test_organization_delete_blocked_no_event(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/organization_manager.php');
        $this->resetAfterTest();
        $did = $DB->insert_record('unics_districts', (object)['name' => 'Тест-район2', 'region_id' => 0]);
        $oid = \unics_organization_manager::create_organization((int)$did, 'Занятая орг', 'ЗО', 1);
        // Активный пользователь в орг -> удаление блокируется.
        $u = $this->getDataGenerator()->create_user();
        $DB->insert_record('unics_user_org', (object)['mdl_user_id' => $u->id,
            'organization_id' => $oid, 'unics_role' => 5]);

        $sink = $this->redirectEvents();
        $res = \unics_organization_manager::delete_organization($oid);
        $deleted = array_filter($sink->get_events(), static fn($e) => $e instanceof organization_deleted);
        $sink->close();
        $this->assertIsString($res); // строка-отказ
        $this->assertCount(0, $deleted);
    }
}
