<?php
namespace local_unics;

/**
 * Удаление элемента кодификатора не должно оставлять висячих ссылок.
 *
 * На `unics_codifier_element.id` ссылаются СЕМЬ таблиц, а чистилась одна
 * (`unics_codifier_link`). Остальные шесть накапливали ссылки на несуществующий
 * навык: «владение навыком», которого нет; сессия CAT по удалённому навыку;
 * предложение педагогу «дай ремедиацию» по нему же. Читатели деградировали
 * молча - `get_field()` возвращал false, и подпись навыка становилась пустой.
 *
 * Политика на каждую таблицу разная и объяснена в `delete_element()`: где строка
 * без навыка теряет смысл - удаляем, где навык был лишь пометкой на чужой
 * сущности - обнуляем.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(codifier_manager::class)]
final class codifier_delete_cascade_test extends \advanced_testcase {

    /** Ученик: unics_students.id. */
    private function make_student(): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        return (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id'      => (int)$user->id,
            'difficulty_level' => 2,
        ]);
    }

    /** Кодификатор с деревом: корень -> ребёнок. Возвращает [root_id, child_id]. */
    private function make_tree(): array {
        $cid  = codifier_manager::create_codifier(1, 'Тестовый кодификатор', 2);
        $root = codifier_manager::add_element($cid, null, 'R', 'Корень');
        $kid  = codifier_manager::add_element($cid, $root, 'R.1', 'Ребёнок');
        return [$root, $kid];
    }

    public function test_delete_element_clears_every_reference(): void {
        global $DB;
        $this->resetAfterTest();

        [$root, $kid] = $this->make_tree();
        $sid = $this->make_student();
        $now = time();

        // Ссылки на РЕБЁНКА: он уходит вместе с поддеревом корня.
        $DB->insert_record('unics_skill_mastery', (object)[
            'student_id' => $sid, 'element_id' => $kid, 'score' => 55.0,
            'band' => 1, 'attempts_n' => 3, 'updated_at' => $now,
        ]);
        $DB->insert_record('unics_mastery_history', (object)[
            'student_id' => $sid, 'element_id' => $kid, 'old_score' => 40.0,
            'new_score' => 55.0, 'old_band' => 1, 'new_band' => 2, 'changed_at' => $now,
        ]);
        $DB->insert_record('unics_adaptive_suggestion', (object)[
            'student_id' => $sid, 'element_id' => $kid, 'kind' => 2,
            'status' => 0, 'created_at' => $now,
        ]);
        $catid = (int)$DB->insert_record('unics_cat_session', (object)[
            'student_id' => $sid, 'element_id' => $kid, 'status' => 0,
            'items_administered' => 0, 'started_at' => $now,
        ]);
        $DB->insert_record('unics_cat_step', (object)[
            'session_id' => $catid, 'slot' => 1, 'item_ref' => 777,
            'correct' => 1, 'created_at' => $now,
        ]);
        $irtid = (int)$DB->insert_record('unics_item_irt', (object)[
            'item_ref' => 777, 'element_id' => $kid, 'model' => 'rasch',
            'a' => 1.0, 'b' => 0.5, 'c' => 0.0, 'calibrated_n' => 12, 'updated_at' => $now,
        ]);

        // Шаг ИОМ - реальная работа ребёнка, у него свой заголовок и курс.
        $pathid = (int)$DB->insert_record('unics_learning_path', (object)[
            'student_id' => $sid, 'created_by_mdl_user_id' => 2, 'status' => 1, 'created_at' => $now,
        ]);
        $stepid = (int)$DB->insert_record('unics_path_step', (object)[
            'path_id' => $pathid, 'ordinal' => 1, 'title' => 'Повторить дроби',
            'mdl_course_id' => 21, 'element_id' => $kid, 'source' => 3,
            'status' => 0, 'created_at' => $now,
        ]);

        codifier_manager::delete_element($root);

        // Дерево ушло целиком.
        $this->assertFalse($DB->record_exists('unics_codifier_element', ['id' => $root]));
        $this->assertFalse($DB->record_exists('unics_codifier_element', ['id' => $kid]));

        // Строки, потерявшие смысл без навыка, удалены.
        $this->assertSame(0, $DB->count_records('unics_skill_mastery', ['element_id' => $kid]),
            'владение несуществующим навыком');
        $this->assertSame(0, $DB->count_records('unics_mastery_history', ['element_id' => $kid]),
            'история владения несуществующим навыком');
        $this->assertSame(0, $DB->count_records('unics_adaptive_suggestion', ['element_id' => $kid]),
            'предложение педагогу по несуществующему навыку');
        $this->assertSame(0, $DB->count_records('unics_cat_session', ['element_id' => $kid]),
            'сессия CAT по несуществующему навыку');
        $this->assertSame(0, $DB->count_records('unics_cat_step', ['session_id' => $catid]),
            'шаги удалённой сессии CAT');

        // Параметры IRT принадлежат ЗАДАНИЮ: строка живёт, ссылка на навык снята.
        $irt = $DB->get_record('unics_item_irt', ['id' => $irtid], '*', MUST_EXIST);
        $this->assertNull($irt->element_id);

        // Шаг ИОМ живёт: удалять маршрут ребёнка из-за правки кодификатора нельзя.
        $step = $DB->get_record('unics_path_step', ['id' => $stepid], '*', MUST_EXIST);
        $this->assertNull($step->element_id);
        $this->assertSame('Повторить дроби', $step->title);
    }

    public function test_delete_element_touches_only_its_own_subtree(): void {
        global $DB;
        $this->resetAfterTest();

        [$root, $kid] = $this->make_tree();
        // Второй корень в том же кодификаторе - его трогать нельзя.
        $cid   = (int)$DB->get_field('unics_codifier_element', 'codifier_id', ['id' => $root]);
        $other = codifier_manager::add_element($cid, null, 'S', 'Соседний корень');
        $sid   = $this->make_student();
        $now   = time();

        $keep = (int)$DB->insert_record('unics_skill_mastery', (object)[
            'student_id' => $sid, 'element_id' => $other, 'score' => 90.0,
            'band' => 3, 'attempts_n' => 1, 'updated_at' => $now,
        ]);

        codifier_manager::delete_element($kid);

        $this->assertTrue($DB->record_exists('unics_codifier_element', ['id' => $root]),
            'родитель удалённого элемента остаётся');
        $this->assertTrue($DB->record_exists('unics_codifier_element', ['id' => $other]));
        $this->assertTrue($DB->record_exists('unics_skill_mastery', ['id' => $keep]),
            'владение по соседнему навыку не тронуто');
    }
}
