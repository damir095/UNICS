<?php
namespace local_unics;

use local_unics\output\course_staff_view;

defined('MOODLE_INTERNAL') || die();

/**
 * Тесты хелпера данных педагогского вида страницы курса ({@see course_staff_view}):
 * гейт is_staff_view и состав класса смотрящего (class_members) - педагог видит только
 * привязанных к нему учеников (unics_teacher_student), привязка без записи на курс
 * в класс не попадает; методист/скоупный админ видит по скоупу, а не по привязкам
 * (регресс: у методиста тоже есть строка в unics_teachers - см. class_members()); системный
 * админ видит всех записанных на курс.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_staff_view::class)]
final class course_staff_view_test extends \advanced_testcase {

    /**
     * Создать (если такой роли еще нет) Moodle-роль по shortname и назначить ее пользователю
     * на системном контексте - {@see \local_unics\access::user_has_role()} (и is_methodist())
     * ищут роль без учета контекста, поэтому системного контекста достаточно.
     * В окружении PHPUnit роль 'methodist' не создается ни install.xml (там только схема),
     * ни db/upgrade.php (там создаются только region_admin/district_methodist/region_methodist) -
     * на живом сайте ее заводят вручную через «Определить роли» (см. pages/setup_roles.php),
     * поэтому тест обязан завести ее сам.
     */
    private function assign_role(string $shortname, string $archetype, int $userid): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $roleid = create_role(ucfirst($shortname), $shortname, '', $archetype);
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        role_assign((int)$roleid, $userid, \context_system::instance()->id);
    }

    /**
     * Иерархия скоупа регион -> округ -> 2 организации (для проверки, что скоуп-фильтр
     * ДЕЙСТВИТЕЛЬНО ограничивает список чужой организацией, а не просто "не пустой").
     * @return array{0:int,1:int} [id организации А, id организации Б]
     */
    private function make_two_organizations(): array {
        global $DB;
        $rid = $DB->insert_record('unics_regions', (object)['name' => 'Тест-регион']);
        $did = $DB->insert_record('unics_districts', (object)['region_id' => $rid, 'name' => 'Тест-округ']);
        $orga = $DB->insert_record('unics_organizations', (object)['district_id' => $did, 'name' => 'Организация А']);
        $orgb = $DB->insert_record('unics_organizations', (object)['district_id' => $did, 'name' => 'Организация Б']);
        return [(int)$orga, (int)$orgb];
    }

    /**
     * Записать ученика на курс + unics_students + unics_user_org со скоупом организации
     * (scope_checker::user_list_filter_sql фильтрует именно по unics_user_org записываемого,
     * а не по unics_students.organization_id).
     */
    private function enrol_student_in_org(\stdClass $course, int $orgid): \stdClass {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $student->id]);
        $DB->insert_record('unics_user_org',
            (object)['mdl_user_id' => $student->id, 'organization_id' => $orgid, 'unics_role' => 7]);
        return $student;
    }

    /**
     * Курс с двумя учениками (оба записаны) и педагогом, привязанным к ПЕРВОМУ.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass} [курс, ученик1, ученик2, педагог]
     */
    private function make_course_with_class(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 1, 'numsections' => 2]);

        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $t  = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($s2->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');

        $sid1 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $s2->id]);
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid1]);

        return [$course, $s1, $s2, $t];
    }

    public function test_class_members_are_limited_to_teacher_bindings(): void {
        $this->resetAfterTest();
        [$course, $s1, $s2, $t] = $this->make_course_with_class();

        $members = course_staff_view::class_members($course, $t->id);

        $this->assertSame([(int)$s1->id], $members);
        $this->assertNotContains((int)$s2->id, $members);
    }

    public function test_bound_student_not_enrolled_is_excluded(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t] = $this->make_course_with_class();

        $outsider = $this->getDataGenerator()->create_user();
        $oid = $DB->insert_record('unics_students', (object)['mdl_user_id' => $outsider->id]);
        $tid = $DB->get_field('unics_teachers', 'id', ['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $oid]);

        $members = course_staff_view::class_members($course, $t->id);

        $this->assertSame([(int)$s1->id], $members, 'привязанный, но не записанный на курс - не в классе');
    }

    public function test_gate_is_false_for_child(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1] = $this->make_course_with_class();
        $this->setUser($s1);

        $this->assertFalse(course_staff_view::is_staff_view($course));
    }

    public function test_gate_is_true_for_teacher(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course_with_class();
        $this->setUser($t);

        $this->assertTrue(course_staff_view::is_staff_view($course));
    }

    /**
     * Регресс: методист организации ошибочно уходил в ветку привязок педагога, потому что
     * unics_user_manager::create_user() пишет строку в unics_teachers и для методиста (роль 4) -
     * см. докблок class_members(). Заводим методисту строку в unics_teachers НАРОЧНО (как в
     * проде) и НЕ заводим ни одной unics_teacher_student - если ветка выбирается по этой
     * таблице, класс будет пуст; должно быть ограничено скоупом (своя организация - виден,
     * чужая - нет).
     */
    public function test_methodist_sees_only_own_organization(): void {
        $this->resetAfterTest();
        global $DB;
        [$orga, $orgb] = $this->make_two_organizations();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);

        $own = $this->enrol_student_in_org($course, $orga);
        $this->enrol_student_in_org($course, $orgb);

        $methodist = $this->getDataGenerator()->create_user();
        $DB->insert_record('unics_user_org',
            (object)['mdl_user_id' => $methodist->id, 'organization_id' => $orga, 'unics_role' => 4]);
        $DB->insert_record('unics_teachers',
            (object)['mdl_user_id' => $methodist->id, 'organization_id' => $orga]);
        $this->assign_role('methodist', 'editingteacher', (int)$methodist->id);

        $members = course_staff_view::class_members($course, (int)$methodist->id);

        $this->assertSame([(int)$own->id], $members,
            'методист должен видеть только своих учеников по скоупу, а не пустой класс по unics_teachers');
    }

    /** Системный админ (local/unics:manage) - видит всех записанных на курс, без учета скоупа/привязок. */
    public function test_system_admin_sees_all_enrolled_students(): void {
        $this->resetAfterTest();
        [$course, $s1, $s2, ] = $this->make_course_with_class();

        $expected = [(int)$s1->id, (int)$s2->id];
        sort($expected);

        $members = course_staff_view::class_members($course, (int)get_admin()->id);

        $this->assertSame($expected, $members);
    }
}
