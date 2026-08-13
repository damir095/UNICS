<?php
namespace local_unics;

/**
 * Ссылка «к материалу» на шаге ИОМ не должна падать, если навык шага удален.
 *
 * `step_material_url()` зовет `codifier_link_manager::get_activities_for_element()`,
 * а тот читает элемент с MUST_EXIST. Ревью указывало на это четырежды: висячий
 * `element_id` ронял детскую страницу «Мой маршрут» и родительский вид.
 *
 * Источник висячих ссылок закрыт 2026-08-12 (delete_element обнуляет
 * unics_path_step.element_id), но страница детская, и падать она не должна ни при
 * каких данных - в том числе на старых базах, где чистки не было.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(path_manager::class)]
final class path_material_url_test extends \advanced_testcase {

    /** Шаг маршрута с заданным element_id. */
    private function make_step(?int $elementid, ?int $courseid): object {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id'      => (int)$user->id,
            'difficulty_level' => 2,
        ]);
        $now = time();
        $pathid = (int)$DB->insert_record('unics_learning_path', (object)[
            'student_id' => $sid, 'created_by_mdl_user_id' => 2, 'status' => 1, 'created_at' => $now,
        ]);
        $stepid = (int)$DB->insert_record('unics_path_step', (object)[
            'path_id' => $pathid, 'ordinal' => 1, 'title' => 'Повторить дроби',
            'mdl_course_id' => $courseid, 'element_id' => $elementid, 'source' => 3,
            'status' => 1, 'created_at' => $now,
        ]);
        return $DB->get_record('unics_path_step', ['id' => $stepid], '*', MUST_EXIST);
    }

    public function test_deleted_element_falls_back_to_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        // element_id указывает в пустоту: элемент удален раньше, чистки не было.
        $step = $this->make_step(999999, (int)$course->id);

        $mat = path_manager::step_material_url($step);

        $this->assertNotNull($mat, 'страница обязана дать хоть какую-то ссылку');
        $this->assertSame('course', $mat['kind']);
        $this->assertStringContainsString('/course/view.php', $mat['url']->out(false));
    }

    public function test_deleted_element_without_course_returns_null(): void {
        $this->resetAfterTest();
        // Ни навыка, ни курса - ссылки нет, но и падения нет.
        $step = $this->make_step(999999, null);
        $this->assertNull(path_manager::step_material_url($step));
    }

    public function test_step_without_element_uses_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $step = $this->make_step(null, (int)$course->id);

        $mat = path_manager::step_material_url($step);

        $this->assertSame('course', $mat['kind']);
    }
}
