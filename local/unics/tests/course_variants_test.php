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

    /**
     * Ограничить активность одной или несколькими группами - как это делает course_builder
     * (всегда $op='&'). Параметр $op позволяет тестам собрать НЕОДНОЗНАЧНУЮ форму (несколько групп
     * или отрицание), которую руками может собрать педагог, а course_builder никогда не порождает.
     *
     * Show-опции зависят от корневого оператора ({@see \core_availability\tree::__construct()}):
     * '&'/'!|' хотят поэлементный 'showc', а '|'/'!&' - один общий булевый 'show' на все дерево;
     * при несовпадении ядро молча считает структуру битой (debugging() + непредсказуемое
     * поведение is_available()), поэтому строим структуру по правилам ядра, а не как попало.
     */
    private function restrict_to_groups(int $cmid, array $gids, string $op = '&'): void {
        global $DB;
        $c = [];
        foreach ($gids as $gid) {
            $c[] = ['type' => 'group', 'id' => $gid];
        }
        $structure = ['op' => $op, 'c' => $c];
        if ($op === '&' || $op === '!|') {
            $structure['showc'] = array_fill(0, count($gids), false);
        } else {
            $structure['show'] = false;
        }
        $DB->set_field('course_modules', 'availability', json_encode($structure), ['id' => $cmid]);
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

    /**
     * I2: build() обязан считать видимость от ЯВНОГО $viewerid, а не от глобального $USER. Без
     * этого теста вся остальная сюита зеленеет по случайности окружения - make_course() делает
     * setUser($t), и во всех остальных тестах $viewerid тоже $t->id, так что смотрящий и
     * глобальный $USER всегда совпадают, и подмена build()'ом get_fast_modinfo($course) (без
     * второго аргумента) осталась бы незамеченной. Разводим: глобальный $USER - ученик без
     * moodle/course:viewhiddenactivities, а $viewerid, переданный в build(), - педагог с этим
     * правом. Скрытая активность обязана попасть в variants именно по праву педагога.
     */
    public function test_uses_explicit_viewerid_not_global_user_for_hidden_activity(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 2, 'Нефть', [$s1->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $this->setUser($s1); // глобальный $USER - ученик, у него нет прав видеть скрытое

        $p = course_variants::build($course, $t->id); // смотрящий передан явно - педагог

        $this->assertArrayHasKey((string)$page->cmid, $p['variants']);
        $this->assertSame('Стандартный · скрыта от учеников', $p['variants'][(string)$page->cmid]['label']);
    }

    /**
     * I2, зеркальный случай. Глобальный $USER - педагог (make_course() уже сделал setUser($t),
     * намеренно не меняем), а $viewerid, переданный в build(), - ученик без права видеть скрытое.
     * Скрытая активность НЕ должна попасть в variants: если бы build() смотрел на глобального
     * $USER вместо параметра, тест бы упал в обратную сторону от предыдущего.
     */
    public function test_uses_explicit_viewerid_not_global_user_hides_from_student(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 2, 'Нефть', [$s1->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $p = course_variants::build($course, $s1->id); // смотрящий передан явно - ученик

        $this->assertArrayNotHasKey((string)$page->cmid, $p['variants']);
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

    /**
     * Две группы под '&' - форма неоднозначная (I1): реальная аудитория такого ограничения -
     * ПЕРЕСЕЧЕНИЕ групп, а не их сумма, и наш код это пересечение не считает. Поэтому вердикта
     * "сирота" здесь НЕ должно быть ни в одну, ни в другую сторону - только пометка, которая
     * перечисляет обе группы как есть (это информативно при любом операторе).
     */
    public function test_two_groups_under_and_gives_no_verdict(): void {
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
        $this->assertNull($p['orphans'], 'неоднозначная форма не должна прибавляться к сводке');
    }

    /**
     * Два групповых условия под '&', ОБЕ группы пустые (I1): без гейта по количеству групп это
     * тоже дало бы orphan=true и попало в сводку, хотя форма все равно неоднозначная - код не
     * вправе утверждать "не видит никто" там, где он умеет считать только сумму, а не пересечение.
     */
    public function test_two_empty_groups_under_and_gives_no_verdict(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $empty1 = $this->make_level_group($course, 1, 'Нефть', []);
        $empty2 = $this->make_level_group($course, 2, 'Нефть', []);
        $this->restrict_to_groups((int)$page->cmid, [$empty1, $empty2]);

        $p = course_variants::build($course, $t->id);

        $this->assertFalse($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertNull($p['orphans']);
    }

    /**
     * Отрицательный корневой оператор (I1, ловушка "инверсия"): '!&' с одной группой значит
     * "все, КРОМЕ этой группы" - совсем не то же самое, что "только эта группа". Аудитория группы
     * тут не равна аудитории варианта (нужна была бы аудитория курса МИНУС группа), поэтому даже
     * при пустой группе вердикта "сирота" быть не должно - у отрицания все наоборот: пустая группа
     * условия означает, что условию не удовлетворяет никто, то есть видят ВСЕ, а не никто.
     */
    public function test_negated_operator_gives_no_verdict(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 1, 'Нефть', []);
        $this->restrict_to_groups((int)$page->cmid, [$gid], '!&');

        $p = course_variants::build($course, $t->id);

        $this->assertFalse($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertNull($p['orphans']);
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

    /**
     * I3: персональная УМК-группа адресной выдачи ученику
     * (\local_unics\ai\course_builder::restrict_activity_to_student_group()) заводится с idnumber
     * umk_s{uid}_c{courseid} и ИМЕНЕМ "УМК: <ФИО ученика>". Если бы такая группа участвовала в
     * пометке как обычная, who_label() отрисовал бы "для группы УМК: Иванов Иван · 1 ученик" прямо
     * на странице курса - раскрытие ФИО ребенка педагогам без права это видеть. Пропускаем ее
     * полностью, как удаленную группу: ни пометки, ни вердикта, ни вклада в аудиторию.
     */
    public function test_personal_umk_group_gives_no_entry(): void {
        $this->resetAfterTest();
        [$course, $s1, , $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = (int)$this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'УМК: Иванов Иван',
            'idnumber' => 'umk_s' . $s1->id . '_c' . $course->id,
        ])->id;
        $this->getDataGenerator()->create_group_member(['groupid' => $gid, 'userid' => $s1->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);

        $p = course_variants::build($course, $t->id);

        $this->assertArrayNotHasKey((string)$page->cmid, $p['variants']);
        $this->assertNull($p['orphans']);
    }

    /**
     * M5: подзапрос аудитории отфильтровывает заархивированных учеников (s.archived_at IS NULL) -
     * та же семантика, что и во всей остальной отчетности проекта. Заархивированный ученик все
     * еще состоит в группе, но для аудитории считается выбывшим; если из-за архивации группа
     * опустела, вариант становится сиротой.
     */
    public function test_archived_student_excluded_from_audience(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, , $s2, $t] = $this->make_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 1, 'Нефть', [$s2->id]);
        $DB->set_field('unics_students', 'archived_at', time(), ['mdl_user_id' => $s2->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('Базовый · не видит никто', $p['variants'][(string)$page->cmid]['label']);
        $this->assertTrue($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertSame(1, $p['orphans']['count']);
    }

    /**
     * M5: подзапрос аудитории отфильтровывает приостановленные записи на курс (ue.status = 1,
     * ENROL_USER_SUSPENDED) - ученик формально числится в unics_students и в группе, но доступа
     * к курсу у него нет, поэтому в аудитории варианта его быть не должно.
     */
    public function test_suspended_enrolment_excluded_from_audience(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, , , $t] = $this->make_course();
        $suspended = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            $suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $suspended->id]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $gid = $this->make_level_group($course, 1, 'Нефть', [$suspended->id]);
        $this->restrict_to_groups((int)$page->cmid, [$gid]);

        $p = course_variants::build($course, $t->id);

        $this->assertSame('Базовый · не видит никто', $p['variants'][(string)$page->cmid]['label']);
        $this->assertTrue($p['variants'][(string)$page->cmid]['orphan']);
        $this->assertSame(1, $p['orphans']['count']);
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
