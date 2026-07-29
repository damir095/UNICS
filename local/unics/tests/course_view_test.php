<?php
namespace local_unics;

use local_unics\output\course_view;

defined('MOODLE_INTERNAL') || die();

/**
 * Тесты хелпера данных ученического вида страницы курса
 * ({@see \local_unics\output\course_view::build_payload}, course/view.php, формат topics):
 * статусы карточек активностей, прогресс секции/курса (с русской формой числительного),
 * next-step, человекочитаемая причина блокировки, гейт is_child_view.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_view::class)]
final class course_view_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
    }

    /** Пометить активность выполненной (COMPLETION_COMPLETE) для пользователя. */
    private function mark_done(\completion_info $ci, string $modname, int $cmid, int $userid): void {
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
        $ci->update_state($cm, COMPLETION_COMPLETE, $userid);
    }

    /**
     * Курс с 2 секциями отслеживаемых активностей: секция 1 - 3 активности
     * (page1 выполнена, page2 и quiz1 - нет), секция 2 - 2 активности (обе
     * выполнены -> тема пройдена). Курс: 1 из 2 тем пройдена.
     *
     * @return array{0:\stdClass,1:\stdClass,2:array}
     */
    private function make_course_with_activities(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 2, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        $cma = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cmb = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cmc = $gen->create_module('quiz', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cmd = $gen->create_module('assign', ['course' => $course->id, 'section' => 2,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cme = $gen->create_module('page', ['course' => $course->id, 'section' => 2,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);

        $ci = new \completion_info($course);
        $this->mark_done($ci, 'page', $cma->cmid, $student->id);
        $this->mark_done($ci, 'assign', $cmd->cmid, $student->id);
        $this->mark_done($ci, 'page', $cme->cmid, $student->id);

        $ctx = [
            'secnum'              => '1',
            'expectedSectionDone' => 1,
            'trackedInSec'        => [$cma->cmid, $cmb->cmid, $cmc->cmid],
            'expectedNextCmid'    => (int)$cmb->cmid, // первая невыполненная в порядке курса
        ];
        return [$course, $student, $ctx];
    }

    /**
     * Курс с одной заблокированной активностью: quiz недоступен, пока не пройден
     * материал-зависимость (условие completion в availability).
     *
     * @return array{0:\stdClass,1:\stdClass,2:array}
     */
    private function make_course_with_locked(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        $dep = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'name' => 'Вводный материал', 'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $locked = $gen->create_module('quiz', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);

        $DB->set_field('course_modules', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'completion', 'cm' => (int)$dep->cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true],
        ]), ['id' => $locked->cmid]);
        rebuild_course_cache($course->id, true);

        return [$course, $student, ['lockedCmid' => (int)$locked->cmid, 'depName' => 'Вводный материал']];
    }

    public function test_build_payload_shape(): void {
        [$course, $student] = $this->make_course_with_activities();
        $p = course_view::build_payload($course, $student->id);

        $this->assertIsArray($p['cms']);
        $this->assertArrayHasKey('done', $p['course']);
        $this->assertArrayHasKey('total', $p['course']);
        // 1 из 2 тем пройдена - русская форма единственного числа («Пройдена 1 тема»).
        $this->assertSame(1, $p['course']['done']);
        $this->assertSame(2, $p['course']['total']);
        $this->assertSame('Пройдена 1 тема из 2', $p['course']['label']);
    }

    public function test_section_and_course_progress(): void {
        [$course, $student, $ctx] = $this->make_course_with_activities();
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame($ctx['expectedSectionDone'], $p['sections'][$ctx['secnum']]['done']);
        $this->assertSame(count($ctx['trackedInSec']), $p['sections'][$ctx['secnum']]['total']);
        $this->assertFalse($p['sections'][$ctx['secnum']]['complete']);
        // label/aria - готовая фраза для секции (JS не должен склеивать числа со словами сам).
        $this->assertSame('1 из 3', $p['sections'][$ctx['secnum']]['label']);
        $this->assertSame('Выполнено 1 из 3', $p['sections'][$ctx['secnum']]['aria']);
        // Секция 2 - обе активности выполнены, тема пройдена целиком.
        $this->assertSame([
            'done' => 2, 'total' => 2, 'complete' => true,
            'label' => '2 из 2', 'aria' => 'Выполнено 2 из 2',
        ], $p['sections']['2']);
    }

    public function test_next_step_is_first_available_incomplete(): void {
        [$course, $student, $ctx] = $this->make_course_with_activities();
        $p = course_view::build_payload($course, $student->id);

        $this->assertNotNull($p['next']);
        $this->assertSame($ctx['expectedNextCmid'], $p['next']['cmid']);
    }

    public function test_locked_activity_has_friendly_reason(): void {
        [$course, $student, $ctx] = $this->make_course_with_locked();
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('locked', $p['cms'][(string)$ctx['lockedCmid']]['status']);
        $this->assertStringContainsString($ctx['depName'], $p['cms'][(string)$ctx['lockedCmid']]['lockWhy']);
    }

    /**
     * Правило выбора русской формы числительного (course_progress_{one,few,many}):
     * последняя цифра 1 (кроме ...11) -> one; 2-4 (кроме ...12-14) -> few; иначе many.
     */
    public function test_plural_form_selection(): void {
        $method = new \ReflectionMethod(course_view::class, 'plural_form');
        $method->setAccessible(true);

        $this->assertSame('one', $method->invoke(null, 1));
        $this->assertSame('few', $method->invoke(null, 2));
        $this->assertSame('many', $method->invoke(null, 5));
        $this->assertSame('many', $method->invoke(null, 0));
        $this->assertSame('many', $method->invoke(null, 11));
        $this->assertSame('one', $method->invoke(null, 21));
    }

    /**
     * Гейт «ученический вид»: true только для пользователя с записью unics_students.
     * Два разных пользователя (а не один и тот же до/после insert) - у
     * access::student_record() есть запросный кеш, отрицательный результат тоже
     * кешируется, поэтому один и тот же userid до/после insert внутри теста дал бы
     * ложный негатив.
     */
    public function test_is_child_view_gate(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);

        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->assertFalse(course_view::is_child_view($course));

        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $student->id]);
        $this->setUser($student);
        $this->assertTrue(course_view::is_child_view($course));
    }
}
