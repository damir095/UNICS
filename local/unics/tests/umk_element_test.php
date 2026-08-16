<?php
namespace local_unics;

use local_unics\ai\umk_launcher;

/**
 * Комплект помнит элемент кодификатора, под который сгенерирован.
 *
 * Без этого пул нечем адресовать: тема урока - свободный текст, а элемент - точка, вокруг которой
 * копятся задания и их калибровка ([[umk-item-pool-design]]).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(umk_launcher::class)]
final class umk_element_test extends \advanced_testcase {

    /** Минимальный запуск: один ученик, один комплект. */
    private function launch_with(?int $element_id): \stdClass {
        global $DB;
        $course  = $this->getDataGenerator()->create_course();
        $user    = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $student = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'class_number' => 7, 'class_letter' => 'А',
            'difficulty_level' => 2, 'diagnosed' => 0, 'points' => 0,
        ]);

        umk_launcher::launch((int)$course->id, [
            'k1' => ['level' => 2, 'students' => [$student]],
        ], [
            'title'          => 'Название',
            'topic'          => 'Тема',
            'target_section' => 0,
            'extra_prompt'   => '',
            'individual'     => 1,
            'element_id'     => $element_id,
            'flags'          => ['generate_text' => 1, 'generate_quiz' => 1],
        ]);

        return $DB->get_record_sql('SELECT * FROM {unics_umk} ORDER BY id DESC', [], IGNORE_MULTIPLE);
    }

    public function test_selected_element_is_remembered(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $codifier = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'к', 'created_by_mdl_user_id' => 2, 'timecreated' => time(),
        ]);
        $element = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $codifier, 'parent_id' => null, 'code' => '1', 'title' => 'Тема',
            'ordinal' => 0, 'path' => '/1/', 'timecreated' => time(),
        ]);

        $umk = $this->launch_with($element);

        $this->assertSame($element, (int)$umk->element_id);
    }

    /** Не выбрал - работает как раньше, привязки нет. */
    public function test_without_element_umk_has_none(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $umk = $this->launch_with(null);

        $this->assertNull($umk->element_id);
    }
}
