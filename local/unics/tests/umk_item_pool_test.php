<?php
namespace local_unics;

use local_unics\ai\course_builder;
use local_unics\learning\item_pool;

/**
 * Сквозная проверка замысла: два комплекта по одному элементу опираются на ОДНИ И ТЕ ЖЕ задания.
 *
 * Сегодня, до правки, каждая генерация создавала свои пять вопросов: на стенде 17 заданий из 22
 * имели ровно один ответ, и IRT не мог откалибровать ничего. Этот тест обязан быть красным на
 * старом поведении и зеленым на новом ([[umk-item-pool-design]]).
 *
 * @package local_unics
 */
final class umk_item_pool_test extends \advanced_testcase {

    /** Записи банка, на которые ссылаются слоты теста. */
    private function slots_of(int $cmid): array {
        global $DB;
        $quizid = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        return array_map('intval', $DB->get_fieldset_sql("
            SELECT qr.questionbankentryid
              FROM {quiz_slots} qs
              JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz'
             WHERE qs.quizid = ? ORDER BY qs.slot", [$quizid]));
    }

    private function fake_questions(int $n): array {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['text' => 'Вопрос ' . $i, 'answers' => ['а', 'б', 'в'], 'correct' => 0];
        }
        return $out;
    }

    public function test_second_generation_reuses_the_first_items(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $codifier = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => (int)$course->category, 'name' => 'к',
            'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
        $element = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $codifier, 'parent_id' => null, 'code' => '1', 'title' => 'Тема',
            'ordinal' => 0, 'path' => '/1/', 'timecreated' => time(),
        ]);
        $builder = new course_builder();

        // Первый комплект: пула нет, создаются пять заданий и привязываются к элементу.
        $need = item_pool::take($element, 2, 5);
        $this->assertSame(5, $need['missing']);
        $first_cmid = $builder->add_quiz_with_questions((int)$course->id, 0, 'Первый',
            $this->fake_questions($need['missing']), $need['ids']);
        foreach ($this->slots_of($first_cmid) as $ref) {
            codifier_link_manager::link_question($element, (int)$ref, (int)$USER->id);
            item_pool::remember_level((int)$ref, 2, (int)$USER->id);
        }

        // Второй комплект по тому же элементу: догенерировать нечего.
        $again = item_pool::take($element, 2, 5);
        $this->assertSame(0, $again['missing'], 'пул обязан покрыть весь тест целиком');
        $second_cmid = $builder->add_quiz_with_questions((int)$course->id, 0, 'Второй',
            $this->fake_questions($again['missing']), $again['ids']);

        $first  = $this->slots_of($first_cmid);
        $second = $this->slots_of($second_cmid);
        sort($first);
        sort($second);
        $this->assertSame($first, $second,
            'второй ученик обязан получить те же задания - иначе калибровка невозможна');
    }
}
