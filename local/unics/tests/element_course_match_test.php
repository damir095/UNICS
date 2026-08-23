<?php
namespace local_unics;

/**
 * Элемент кодификатора должен принадлежать предмету курса ([[element-course-match]]).
 *
 * Зонд 2026-08-23: после разведения предметов форма генерации УМК стала показывать элементы ВСЕХ
 * кодификаторов, и для курса географии в списке была математика. Ошибка тут не косметическая:
 * задание уезжает в чужой пул, а калибровка считает трудность темы по чужим ответам.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(codifier_manager::class)]
final class element_course_match_test extends \advanced_testcase {

    /** Категория курсов с кодификатором и одним элементом: [catid, courseid, elementid]. */
    private function subject(string $name): array {
        global $DB, $USER;
        $cat = $this->getDataGenerator()->create_category(['name' => $name]);
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        // Через API кодификатора, а не ручными вставками: только так у элемента будет
        // верный path (цепочка id ЭЛЕМЕНТОВ) и статус, который пишет create_codifier().
        $cid = codifier_manager::create_codifier((int)$cat->id, $name, (int)$USER->id);
        $eid = codifier_manager::add_element($cid, null, '1', 'Тема ' . $name);
        return [(int)$cat->id, (int)$course->id, $eid];
    }

    public function test_own_element_belongs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $course, $element] = $this->subject('География');

        $this->assertTrue(codifier_manager::element_belongs_to_course($element, $course));
    }

    public function test_foreign_element_does_not_belong(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $geocourse, ] = $this->subject('География');
        [, , $mathelement] = $this->subject('Математика');

        $this->assertFalse(codifier_manager::element_belongs_to_course($mathelement, $geocourse),
            'элемент чужого предмета уводит задания в чужой пул');
    }

    public function test_zero_element_is_allowed(): void {
        // «Не привязывать» - законный выбор: тест соберется из новых вопросов, как раньше.
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $course, ] = $this->subject('География');

        $this->assertTrue(codifier_manager::element_belongs_to_course(0, $course));
    }

    public function test_missing_element_does_not_belong(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $course, ] = $this->subject('География');

        $this->assertFalse(codifier_manager::element_belongs_to_course(999999, $course));
    }

    public function test_course_without_codifier_rejects_any_element(): void {
        // У предмета кодификатора еще нет: привязывать не к чему, и молча принимать чужой
        // элемент нельзя.
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $element] = $this->subject('География');
        $bare = $this->getDataGenerator()->create_course();

        $this->assertFalse(codifier_manager::element_belongs_to_course($element, (int)$bare->id));
        $this->assertTrue(codifier_manager::element_belongs_to_course(0, (int)$bare->id),
            'без привязки генерация в таком курсе должна остаться возможной');
    }
    public function test_course_in_subcategory_finds_its_subject(): void {
        // Курсы школы часто лежат уровнем ниже дисциплины: «Математика» -> «7 класс». Без
        // подъема по дереву методист не смог бы привязать НИ ОДИН элемент, причем молча.
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cat, , $element] = $this->subject('Математика');
        $sub = $this->getDataGenerator()->create_category(['name' => '7 класс', 'parent' => $cat]);
        $course = $this->getDataGenerator()->create_course(['category' => $sub->id]);

        $this->assertTrue(
            codifier_manager::element_belongs_to_course($element, (int)$course->id));
    }

    public function test_subcategory_does_not_borrow_a_foreign_subject(): void {
        // Подъем идет по СВОЕЙ ветке: подкатегория математики не должна принимать географию.
        $this->resetAfterTest();
        $this->setAdminUser();
        [$mathcat, , ] = $this->subject('Математика');
        [, , $geoelement] = $this->subject('География');
        $sub = $this->getDataGenerator()->create_category(['name' => '7 класс', 'parent' => $mathcat]);
        $course = $this->getDataGenerator()->create_course(['category' => $sub->id]);

        $this->assertFalse(
            codifier_manager::element_belongs_to_course($geoelement, (int)$course->id));
    }

    public function test_negative_element_is_rejected(): void {
        // PARAM_INT пропускает минус, а дальше по коду стоят проверки !empty(): отрицательный
        // id доехал бы до unics_umk.element_id висячей ссылкой.
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $course, ] = $this->subject('География');

        $this->assertFalse(codifier_manager::element_belongs_to_course(-5, $course));
    }

    public function test_archived_codifier_stops_binding(): void {
        // Задокументированное следствие: архивация кодификатора закрывает привязку по всему
        // предмету. Пусть это будет видно тестом, а не всплывет у методиста.
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cat, $course, $element] = $this->subject('География');
        $this->assertTrue(codifier_manager::element_belongs_to_course($element, $course));

        $DB->set_field('unics_codifier', 'status', codifier_manager::STATUS_ARCHIVED,
            ['mdl_category_id' => $cat]);

        $this->assertFalse(codifier_manager::element_belongs_to_course($element, $course),
            'у архивного кодификатора привязка закрыта - по всему предмету');
    }

    public function test_launcher_rejects_a_foreign_element(): void {
        // Проверка стоит в единственной точке записи element_id: страница - лишь один из
        // возможных входов, и второй унаследовал бы дыру.
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $geocourse, ] = $this->subject('География');
        [, , $mathelement] = $this->subject('Математика');
        // Группы собираем вручную: до работы с ними launch() не доходит - проверка
        // элемента стоит раньше всего остального.
        $groups = ['k' => ['profile' => [], 'level' => 2, 'students' => [1]]];
        $this->expectException(\moodle_exception::class);
        \local_unics\ai\umk_launcher::launch((int)$geocourse, $groups, [
            'title' => 'т', 'topic' => 'т', 'target_section' => 0, 'extra_prompt' => '',
            'individual' => 0, 'element_id' => $mathelement,
            'flags' => ['generate_quiz' => 1],
        ]);
    }
}
