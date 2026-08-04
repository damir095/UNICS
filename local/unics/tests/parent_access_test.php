<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Доступ родителя к штабным страницам курса ([[parent-leak-fix-design]]).
 *
 * Утечка, ради которой это написано: Moodle-роль parent на стенде несет
 * moodle/grade:viewall, а четыре штабные страницы гейтились именно по нему -
 * родитель прямым URL получал ФИО, категорию ОВЗ, адаптивный уровень и группу
 * риска ВСЕХ детей класса. Воспроизведено 2026-08-04 под uid 83 на курсе 21.
 *
 * Фикстуры намеренно дают родителю права через enrol_user(..., 'teacher'):
 * архетип non-editing педагога несет и grade:viewall, и course:viewparticipants.
 * Без этого тест зеленел бы вхолостую - родитель не прошел бы гейт и без
 * исправления. НЕ упрощать фикстуру.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
final class parent_access_test extends \advanced_testcase {

    /**
     * Создать (если нет) Moodle-роль по shortname и назначить ее на системном контексте -
     * access::user_has_role() ищет роль без учета контекста. Роль 'methodist' в окружении
     * PHPUnit не создается ни install.xml, ни db/upgrade.php.
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

    /** Родитель ребенка системы: строки в unics_students и unics_parent_student. */
    private function make_parent(\stdClass $parent): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $sid = $DB->insert_record('unics_students', (object)['mdl_user_id' => $student->id]);
        $DB->insert_record('unics_parent_student',
            (object)['parent_mdl_user_id' => $parent->id, 'student_id' => $sid]);
    }

    public function test_plain_parent_is_not_staff_person(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $parent = $gen->create_user();
        $this->make_parent($parent);
        $gen->enrol_user($parent->id, $course->id, 'teacher');
        $this->setUser($parent);

        $this->assertFalse(access::is_staff_person((int)$parent->id,
            \context_course::instance($course->id)));
    }

    public function test_teacher_with_unics_row_is_staff_person(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $teacher = $gen->create_user();
        // Non-editing педагог: ни manage, ни methodist, ни manageactivities - держится
        // ровно на строке unics_teachers, которую заводит user_manager::create_user().
        $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $teacher->id]);
        $gen->enrol_user($teacher->id, $course->id, 'teacher');
        $this->setUser($teacher);

        $this->assertTrue(access::is_staff_person((int)$teacher->id,
            \context_course::instance($course->id)));
    }

    public function test_methodist_is_staff_person(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $m = $gen->create_user();
        $this->assign_role('methodist', 'teacher', (int)$m->id);
        $this->setUser($m);

        $this->assertTrue(access::is_staff_person((int)$m->id));
    }

    public function test_editing_teacher_is_staff_person_via_context(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $this->setUser($t);

        // Без строки unics_teachers, но с manageactivities в контексте курса.
        $this->assertTrue(access::is_staff_person((int)$t->id,
            \context_course::instance($course->id)));
    }

    public function test_is_staff_person_honours_userid_not_current_user(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user();
        $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $teacher->id]);
        $outsider = $gen->create_user();

        // Текущий - посторонний, спрашиваем про педагога.
        $this->setUser($outsider);
        $this->assertTrue(access::is_staff_person((int)$teacher->id));
        // Текущий - педагог, спрашиваем про постороннего.
        $this->setUser($teacher);
        $this->assertFalse(access::is_staff_person((int)$outsider->id));
    }

    public function test_parent_cannot_view_course_staff_even_with_grade_viewall(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $parent = $gen->create_user();
        $this->make_parent($parent);
        // Архетип non-editing педагога несет и grade:viewall, и course:viewparticipants -
        // ровно так родитель и проходил старый гейт. НЕ упрощать.
        $gen->enrol_user($parent->id, $course->id, 'teacher');
        $this->setUser($parent);

        $this->assertFalse(access::can_view_course_staff(
            \context_course::instance($course->id), (int)$parent->id));
    }

    public function test_nonediting_teacher_can_view_course_staff(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'teacher');
        $this->setUser($t);

        $this->assertTrue(access::can_view_course_staff(
            \context_course::instance($course->id), (int)$t->id));
    }

    public function test_editing_teacher_can_view_course_staff(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $this->setUser($t);

        $this->assertTrue(access::can_view_course_staff(
            \context_course::instance($course->id), (int)$t->id));
    }

    public function test_methodist_can_view_course_staff_without_enrolment(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $m = $gen->create_user();
        $this->assign_role('methodist', 'teacher', (int)$m->id);
        $this->setUser($m);

        $this->assertTrue(access::can_view_course_staff(
            \context_course::instance($course->id), (int)$m->id));
    }

    public function test_outsider_cannot_view_course_staff(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $u = $gen->create_user();
        $this->setUser($u);

        $this->assertFalse(access::can_view_course_staff(
            \context_course::instance($course->id), (int)$u->id));
    }

    /**
     * Регресс: сотрудник, который ОДНОВРЕМЕННО родитель ученика системы, доступ СОХРАНЯЕТ.
     * Первая версия проверки родителя отбирала его у non-editing педагога-родителя.
     */
    public function test_staff_who_is_also_parent_keeps_access(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $teacher = $gen->create_user();
        $this->make_parent($teacher);
        $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $teacher->id]);
        $gen->enrol_user($teacher->id, $course->id, 'teacher');
        $this->setUser($teacher);

        $this->assertTrue(access::can_view_course_staff(
            \context_course::instance($course->id), (int)$teacher->id));
    }

    public function test_can_view_course_staff_honours_userid_not_current_user(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $ctx = \context_course::instance($course->id);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $outsider = $gen->create_user();

        $this->setUser($outsider);
        $this->assertTrue(access::can_view_course_staff($ctx, (int)$t->id));
        $this->setUser($t);
        $this->assertFalse(access::can_view_course_staff($ctx, (int)$outsider->id));
    }

    /**
     * Матрица обязана ОТБИРАТЬ у роли parent весь грейд-набор, а не просто не выдавать его.
     *
     * ЛОВУШКА: apply_matrix() аддитивен - он трогает только перечисленные capability
     * (role_manager.php:54-72). Если убрать право лишь из списка allow, на живом стенде
     * строка CAP_ALLOW останется в mdl_role_capabilities и фикс не подействует. Поэтому
     * права обязаны быть в prevent, и тест проверяет именно значение permission.
     */
    public function test_matrix_prevents_grade_capabilities_for_parent(): void {
        $this->resetAfterTest();
        global $DB;

        $roleid = create_role('Родитель', 'parent', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);

        \local_unics\identity\role_manager::apply_matrix();

        $ctxid = \context_system::instance()->id;
        $caps = [
            'moodle/grade:view', 'moodle/grade:viewall',
            'gradereport/grader:view', 'gradereport/user:view',
            'gradereport/overview:view', 'gradereport/history:view',
            'gradereport/outcomes:view', 'gradereport/singleview:view',
            'gradereport/summary:view',
        ];
        foreach ($caps as $cap) {
            // Сначала само существование: apply_matrix() молча пропускает неизвестные
            // capability, и опечатка в имени иначе выглядела бы как «право не запрещено».
            $this->assertNotEmpty(get_capability_info($cap),
                "capability {$cap} не существует в сборке - опечатка в матрице?");
            $perm = $DB->get_field('role_capabilities', 'permission',
                ['roleid' => $roleid, 'contextid' => $ctxid, 'capability' => $cap]);
            $this->assertEquals(CAP_PREVENT, (int)$perm,
                "{$cap} обязана быть запрещена роли parent, а не разрешена или не задана");
        }
    }
}
