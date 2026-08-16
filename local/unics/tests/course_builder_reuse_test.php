<?php
namespace local_unics;

use local_unics\ai\course_builder;

/**
 * Сборка теста из ГОТОВЫХ записей банка.
 *
 * Слот теста ссылается на задание через question_references.questionbankentryid, а не хранит его
 * копию - значит одно задание может стоять в тестах разных учеников и копить их ответы. На этом
 * держится весь пул ([[umk-item-pool-design]]).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_builder::class)]
final class course_builder_reuse_test extends \advanced_testcase {

    /** Пять готовых вопросов в формате ai_generator::generate_quiz(). */
    private function fake_questions(int $n): array {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['text' => 'Вопрос ' . $i, 'answers' => ['а', 'б', 'в'], 'correct' => 0];
        }
        return $out;
    }

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

    public function test_new_questions_land_in_the_course_category_bank(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();

        $cmid = $builder->add_quiz_with_questions((int)$course->id, 0, 'Тест', $this->fake_questions(2));

        $refs = $this->slots_of($cmid);
        $this->assertCount(2, $refs);
        $catid = (int)$DB->get_field('question_bank_entries', 'questioncategoryid',
            ['id' => reset($refs)]);
        $contextid = (int)$DB->get_field('question_categories', 'contextid', ['id' => $catid]);
        $expected = \context_coursecat::instance((int)$course->category)->id;
        $this->assertSame($expected, $contextid,
            'банк обязан жить выше курса, иначе удаление курса уносит задания и их калибровку');

        // Категория обязана быть НАСТОЯЩЕЙ, а не служебной top: интерфейс банка показывает
        // только категории с parent <> 0, и пул в top методист не увидел бы никогда.
        $parent = (int)$DB->get_field('question_categories', 'parent', ['id' => $catid]);
        $this->assertNotSame(0, $parent, 'вопросы не должны лежать в служебной категории top');
    }

    public function test_reused_entries_go_into_slots_without_new_questions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();

        // Первый тест создает задания, второй переиспользует их же.
        $first = $builder->add_quiz_with_questions((int)$course->id, 0, 'Первый', $this->fake_questions(3));
        $reuse = $this->slots_of($first);
        $before = $DB->count_records('question');

        $second = $builder->add_quiz_with_questions((int)$course->id, 0, 'Второй', [], $reuse);

        $this->assertSame($reuse, $this->slots_of($second), 'слоты обязаны ссылаться на те же записи');
        $this->assertSame($before, $DB->count_records('question'),
            'переиспользование не должно создавать ни одного нового вопроса');
    }

    public function test_reused_and_new_can_be_mixed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();
        $first = $builder->add_quiz_with_questions((int)$course->id, 0, 'Первый', $this->fake_questions(2));
        $reuse = $this->slots_of($first);

        $mixed = $builder->add_quiz_with_questions((int)$course->id, 0, 'Смешанный',
            $this->fake_questions(1), $reuse);

        $this->assertCount(3, $this->slots_of($mixed));
    }
}
