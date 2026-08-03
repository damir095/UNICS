<?php
namespace local_unics;

use local_unics\output\course_hub;

defined('MOODLE_INTERNAL') || die();

/**
 * Тесты состава плиток хаба курса ({@see course_hub::tiles()}): кто какие плитки видит.
 * Гейт плитки обязан совпадать с гейтом ее страницы - регресс на жесткий тупик
 * «Кодификатор» (пункт показывался по grade:viewall, страница требует manageactivities,
 * non-editing педагог получал «Недостаточно прав»), см. [[course-hub-design]].
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_hub::class)]
final class course_hub_test extends \advanced_testcase {

    /**
     * Создать (если такой роли еще нет) Moodle-роль по shortname и назначить ее пользователю
     * на системном контексте - access::user_has_role() ищет роль без учета контекста.
     * Роль 'methodist' в окружении PHPUnit не создается ни install.xml, ни db/upgrade.php
     * (на живом сайте ее заводят через pages/setup_roles.php) - тест обязан завести ее сам.
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

    /** Плоский список подписей плиток в порядке отдачи. */
    private function tile_labels(array $groups): array {
        $out = [];
        foreach ($groups as $g) {
            foreach ($g['tiles'] as $t) {
                $out[] = $t['label'];
            }
        }
        return $out;
    }

    public function test_editing_teacher_sees_nine_tiles_in_two_groups(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'editingteacher');

        $groups = course_hub::tiles($course, \context_course::instance($course->id), (int)$t->id);

        $this->assertCount(2, $groups);
        $this->assertSame('progress', $groups[0]['key']);
        $this->assertSame('setup', $groups[1]['key']);
        $this->assertCount(4, $groups[0]['tiles']);
        $this->assertCount(5, $groups[1]['tiles']);
    }

    public function test_nonediting_teacher_sees_only_progress_group(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'teacher');

        $groups = course_hub::tiles($course, \context_course::instance($course->id), (int)$t->id);

        $this->assertCount(1, $groups);
        $this->assertSame('progress', $groups[0]['key']);
        $this->assertCount(4, $groups[0]['tiles']);
        // Регресс жесткого тупика: страница кодификатора его развернет, значит плитки быть не должно.
        $this->assertNotContains(get_string('hub_codifier', 'local_unics'), $this->tile_labels($groups));
    }

    public function test_methodist_sees_nine_tiles(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $m = $gen->create_user();
        $this->assign_role('methodist', 'teacher', (int)$m->id);

        $groups = course_hub::tiles($course, \context_course::instance($course->id), (int)$m->id);

        $this->assertCount(2, $groups);
        $this->assertCount(4, $groups[0]['tiles']);
        $this->assertCount(5, $groups[1]['tiles']);
    }

    public function test_outsider_gets_empty_array(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $u = $gen->create_user();

        $this->assertSame([], course_hub::tiles($course, \context_course::instance($course->id), (int)$u->id));
    }

    /**
     * Родитель - не персонал курса (M-дефект живой проверки, курс 21, uid 83-85): predicate STAFF
     * внутри tiles() - это manage || is_methodist || moodle/grade:viewall, а Moodle-роль parent
     * на проде несет grade:viewall, поэтому родитель проходил STAFF-гейт и видел журнал/отчет/
     * состав класса чужого ребенка - персональные данные других учащихся курса.
     *
     * Чтобы тест ловил именно ЭТОТ дефект (а не просто «is_parent() отсекает всех»), родителю
     * ЗДЕСЬ НАРОЧНО дают grade:viewall - записывают на курс ролью 'teacher' (non-editing педагог,
     * archetype несет grade:viewall, но не manageactivities). Без исправления в tiles() это дает
     * $staff=true и 4 плитки; тест обязан их НЕ увидеть. Если кто-то в будущем «упростит» тест и
     * уберет enrol_user(...,'teacher'), родитель не пройдет STAFF-гейт и без каких-либо строк
     * source и без исправления, и тест будет зеленеть вхолостую, не проверяя ничего - НЕ убирать.
     */
    public function test_parent_gets_empty_array_even_with_grade_viewall(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $student = $gen->create_user();
        $parent = $gen->create_user();

        $sid = $DB->insert_record('unics_students', (object)['mdl_user_id' => $student->id]);
        $DB->insert_record('unics_parent_student',
            (object)['parent_mdl_user_id' => $parent->id, 'student_id' => $sid]);
        // Дает grade:viewall (STAFF), но НЕ manageactivities/editingteacher - без исправления
        // ровно это и приводило родителя в штабную группу плиток.
        $gen->enrol_user($parent->id, $course->id, 'teacher');

        $groups = course_hub::tiles($course, \context_course::instance($course->id), (int)$parent->id);

        $this->assertSame([], $groups);
    }

    public function test_userid_is_honoured_in_both_directions(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);
        $ctx = \context_course::instance($course->id);
        $t = $gen->create_user();
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $outsider = $gen->create_user();

        // Текущий пользователь - посторонний, считаем для педагога: плитки обязаны быть.
        $this->setUser($outsider);
        $this->assertCount(2, course_hub::tiles($course, $ctx, (int)$t->id));

        // Текущий пользователь - педагог, считаем для постороннего: плиток быть не должно.
        $this->setUser($t);
        $this->assertSame([], course_hub::tiles($course, $ctx, (int)$outsider->id));
    }
}
