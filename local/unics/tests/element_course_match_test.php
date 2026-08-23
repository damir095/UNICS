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
final class element_course_match_test extends \advanced_testcase {

    /** Категория курсов с кодификатором и одним элементом: [catid, courseid, elementid]. */
    private function subject(string $name): array {
        global $DB, $USER;
        $cat = $this->getDataGenerator()->create_category(['name' => $name]);
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $cid = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => (int)$cat->id, 'name' => $name,
            'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
        $eid = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $cid, 'parent_id' => null, 'code' => '1', 'title' => 'Тема ' . $name,
            'ordinal' => 0, 'path' => '/' . $cid . '/', 'timecreated' => time(),
        ]);
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
}
