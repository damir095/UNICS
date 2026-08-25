<?php
namespace local_unics;

use local_unics\learning\adaptive_engine;
use local_unics\learning\suggestion_service;

/**
 * Понижение уровня одаренному решает педагог, а не автоматика
 * ([[gifted-level-drop-design]]).
 *
 * У одаренного ребенка низкий балл чаще означает не «не тянет», а «не включен»: скука,
 * отсутствие вызова, потеря интереса. Автоматическое упрощение замыкает порочный круг - материал
 * становится еще скучнее, балл падает дальше, система понижает снова. Отдельно есть дважды
 * исключительные (одаренность плюс ОВЗ), где низкий балл идет от нарушения и лечится формой
 * подачи, а не снижением сложности.
 *
 * Повышение и понижение всем прочим - как раньше: там автоматика уместна.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(adaptive_engine::class)]
final class gifted_level_drop_test extends \advanced_testcase {

    /** Ученик с заданными категориями и уровнем. */
    private function student(int $level, array $categories = []): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'class_number' => 7, 'difficulty_level' => $level,
        ]);
        foreach ($categories as $c) {
            $DB->insert_record('unics_student_category',
                (object)['student_id' => $sid, 'category' => $c]);
        }
        return $sid;
    }

    /** Пять оцененных попыток с заданным процентом - столько смотрит preview_student(). */
    private function grades(int $student_id, float $pct): void {
        // Строки оценок пишем напрямую: preview_student() читает именно grade_items + grade_grades,
        // а create_module('quiz') в цикле выдавал один и тот же grade_item и ронял вставку по
        // уникальному ключу (userid, itemid).
        global $DB;
        $mdlid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => $student_id]);
        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < 5; $i++) {
            $itemid = (int)$DB->insert_record('grade_items', (object)[
                'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz',
                'iteminstance' => 1000 + $i, 'itemnumber' => 0, 'itemname' => 'Тест ' . $i,
                'grademax' => 10.0, 'grademin' => 0.0, 'gradetype' => GRADE_TYPE_VALUE,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
            $DB->insert_record('grade_grades', (object)[
                'itemid' => $itemid, 'userid' => $mdlid,
                'finalgrade' => $pct / 10.0, 'rawgrademax' => 10.0, 'rawgrademin' => 0.0,
                'timecreated' => time(), 'timemodified' => time() + $i,
            ]);
        }
    }

    private function level_of(int $student_id): int {
        global $DB;
        return (int)$DB->get_field('unics_students', 'difficulty_level', ['id' => $student_id]);
    }

    private function level_suggestions(int $student_id): array {
        global $DB;
        return array_values($DB->get_records('unics_adaptive_suggestion',
            ['student_id' => $student_id, 'kind' => suggestion_service::KIND_LEVEL_CHANGE]));
    }

    // ---------------------------------------------------------------
    // Одаренный
    // ---------------------------------------------------------------

    public function test_drop_for_gifted_becomes_a_suggestion(): void {
        // Главная проверка: даже при нулевой отсрочке (обычно это «применить сразу»)
        // понижение одаренному уходит педагогу.
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 0, 'local_unics');
        $s = $this->student(3, [4]);
        $this->grades($s, 30.0);

        adaptive_engine::gate_level_change($s);

        $this->assertSame(3, $this->level_of($s), 'уровень менять автоматически нельзя');
        $this->assertCount(1, $this->level_suggestions($s), 'решение отдано педагогу');
    }

    public function test_suggestion_explains_why(): void {
        // Карточка без объяснения выглядит как каприз системы: педагог должен понимать,
        // почему именно здесь решение оставили ему.
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 0, 'local_unics');
        $s = $this->student(3, [4]);
        $this->grades($s, 30.0);

        adaptive_engine::gate_level_change($s);

        $sug = $this->level_suggestions($s)[0];
        $this->assertNotEmpty($sug->rationale, 'причина обязана быть');
        $this->assertStringContainsString('интерес', mb_strtolower((string)$sug->rationale));
    }

    public function test_raise_for_gifted_still_applies(): void {
        // Обратную сторону не трогаем: повышение одаренному - именно то, что нужно.
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 0, 'local_unics');
        $s = $this->student(2, [4]);
        $this->grades($s, 95.0);

        adaptive_engine::gate_level_change($s);

        $this->assertSame(3, $this->level_of($s), 'повышение применяется сразу, как раньше');
        $this->assertCount(0, $this->level_suggestions($s));
    }

    // ---------------------------------------------------------------
    // Прочие категории
    // ---------------------------------------------------------------

    public function test_drop_for_ovz_applies_as_before(): void {
        // Для остальных автоматика уместна: ребенку с ОВЗ более простой материал нужен
        // тогда, когда он не справляется, и ждать решения педагога незачем.
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 0, 'local_unics');
        $s = $this->student(3, [1]);
        $this->grades($s, 30.0);

        adaptive_engine::gate_level_change($s);

        $this->assertSame(2, $this->level_of($s));
        $this->assertCount(0, $this->level_suggestions($s));
    }

    public function test_drop_without_categories_applies_as_before(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 0, 'local_unics');
        $s = $this->student(2);
        $this->grades($s, 20.0);

        adaptive_engine::gate_level_change($s);

        $this->assertSame(1, $this->level_of($s));
    }

    public function test_gifted_with_ovz_also_goes_to_the_teacher(): void {
        // Дважды исключительный: низкий балл может идти от нарушения, и снижение сложности
        // тут не лечение. Тем более решает человек.
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 0, 'local_unics');
        $s = $this->student(3, [1, 4]);
        $this->grades($s, 30.0);

        adaptive_engine::gate_level_change($s);

        $this->assertSame(3, $this->level_of($s));
        $this->assertCount(1, $this->level_suggestions($s));
    }

    public function test_nonzero_delay_still_suggests_for_everyone(): void {
        // При ненулевой отсрочке предложение создается всем - правка не должна это менять.
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_autoapply_days', 3, 'local_unics');
        $s = $this->student(3, [1]);
        $this->grades($s, 30.0);

        adaptive_engine::gate_level_change($s);

        $this->assertSame(3, $this->level_of($s));
        $this->assertCount(1, $this->level_suggestions($s));
    }
}
