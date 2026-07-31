<?php
namespace local_unics;

use local_unics\output\course_staff_view;

defined('MOODLE_INTERNAL') || die();

/**
 * Тесты хелпера данных педагогского вида страницы курса ({@see course_staff_view}):
 * гейт is_staff_view и состав класса смотрящего (class_members) - педагог видит только
 * привязанных к нему учеников (unics_teacher_student), привязка без записи на курс
 * в класс не попадает.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_staff_view::class)]
final class course_staff_view_test extends \advanced_testcase {

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
}
