<?php
namespace local_unics;

use local_unics\output\course_variants;

#[\PHPUnit\Framework\Attributes\CoversClass(course_variants::class)]
final class course_variants_test extends \advanced_testcase {

    /**
     * Курс с двумя учениками и педагогом. Педагог нужен по-настоящему: скрытые от учеников
     * активности попадают в пометку только у того, кто имеет moodle/course:viewhiddenactivities,
     * а дефолтный пользователь PHPUnit после reset - бесправный ($USER->id = 0). Тесты обязаны
     * звать setUser($t) и передавать $t->id, иначе они зеленеют по случайности окружения,
     * а не потому, что код прав.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass} [курс, ученик1, ученик2, педагог]
     */
    private function make_course(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 1, 'numsections' => 2]);
        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $t = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($s2->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $s2->id]);
        $this->setUser($t);
        return [$course, $s1, $s2, $t];
    }

    /** Группа с УМК-конвенцией idnumber; $members - Moodle user id. */
    private function make_level_group(\stdClass $course, int $level, string $topic, array $members): int {
        $gen = $this->getDataGenerator();
        $gid = (int)$gen->create_group([
            'courseid' => $course->id,
            'name' => $topic . ' - уровень ' . $level,
            'idnumber' => 'umk_lvl' . $level . '_c' . $course->id . '_' . substr(md5($topic), 0, 8),
        ])->id;
        foreach ($members as $uid) {
            $gen->create_group_member(['groupid' => $gid, 'userid' => $uid]);
        }
        return $gid;
    }

    /** Ограничить активность одной или несколькими группами - как это делает course_builder. */
    private function restrict_to_groups(int $cmid, array $gids): void {
        global $DB;
        $c = [];
        $showc = [];
        foreach ($gids as $gid) {
            $c[] = ['type' => 'group', 'id' => $gid];
            $showc[] = false;
        }
        $DB->set_field('course_modules', 'availability',
            json_encode(['op' => '&', 'c' => $c, 'showc' => $showc]), ['id' => $cmid]);
        rebuild_course_cache($DB->get_field('course_modules', 'course', ['id' => $cmid]), true);
    }

    public function test_level_group_label_uses_idnumber_and_audience(): void {
        $this->resetAfterTest();
        [$course, $s1, $s2, $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 2, 'Нефть', [$s1->id, $s2->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('Стандартный · 2 ученика', $p['variants'][(string)$page->cmid]['label']);
        $this->assertFalse($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertNull($p['orphans']);
    }

    public function test_empty_group_is_orphan(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 1, 'Нефть', []);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('Базовый · не видит никто', $p['variants'][(string)$page->cmid]['label']);
        $this->assertTrue($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertSame(1, $p['orphans']['count']);
        $this->assertSame('1 вариант тем не видит ни один ученик', $p['orphans']['label']);
    }

    public function test_hidden_activity_with_real_audience_is_orphan_but_not_counted(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 3, 'Нефть', [$s1->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('Продвинутый · скрыта от учеников', $p['variants'][(string)$page->cmid]['label']);
        $this->assertTrue($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertNull($p['orphans'], 'скрытие при непустой группе в сводку не входит');
    }

    public function test_empty_group_wins_over_hidden(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, , , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 1, 'Нефть', []);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('Базовый · не видит никто', $p['variants'][(string)$page->cmid]['label']);
    }

    public function test_plain_group_shows_its_name(): void {
        $this->resetAfterTest();
        [$course, $s1, , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = (int)$this->getDataGenerator()->create_group(
            ['courseid' => $course->id, 'name' => '7А класс'])->id;
        $this->getDataGenerator()->create_group_member(['groupid' => $gid, 'userid' => $s1->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('для группы 7А класс · 1 ученик', $p['variants'][(string)$page->cmid]['label']);
    }

    public function test_two_groups_one_non_empty_is_not_orphan(): void {
        $this->resetAfterTest();
        [$course, $s1, , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $full = $this->make_level_group($course, 2, 'Нефть', [$s1->id]);
        $empty = $this->make_level_group($course, 1, 'Нефть', []);
        $this->restrict_to_groups((int)$page->cmid, [$full, $empty]);

        $p = course_variants::build($course, $t->id);

        $this->assertFalse($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertStringContainsString('Стандартный · 1 ученик', $p['variants'][(string)$page->cmid]['label']);
        $this->assertStringContainsString('Базовый', $p['variants'][(string)$page->cmid]['label']);
    }

    public function test_activity_without_group_restriction_has_no_entry(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        $p = course_variants::build($course, $t->id);

        $this->assertArrayNotHasKey((string)$page->cmid, $p['variants']);
    }

    public function test_deleted_group_gives_no_entry(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 1, 'Нефть', []);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);
        groups_delete_group($gid);
        rebuild_course_cache($course->id, true);

        $p = course_variants::build($course, $t->id);

        $this->assertArrayNotHasKey((string)$page->cmid, $p['variants']);
        $this->assertNull($p['orphans']);
    }

    public function test_five_activities_of_one_empty_group_count_as_one_variant(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course();
        $gid = $this->make_level_group($course, 1, 'Нефть', []);
        for ($i = 0; $i < 5; $i++) {
            $cm = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
            $this->restrict_to_groups((int)$cm->cmid, [$gid]);
        }

        $p = course_variants::build($course, $t->id);

        $this->assertCount(5, $p['variants']);
        $this->assertSame(1, $p['orphans']['count'], 'сирота считается по группам, а не по активностям');
    }
}
