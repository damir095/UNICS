<?php
namespace local_unics;

use local_unics\learning\grade_scale;

/**
 * Тесты read-модели журнала gradebook::matrix (этап 5.2 аудита), включая
 * пагинацию этапа 3.1: строки-ученики страницей, а колонки и «средний по
 * классу» - по всей выборке.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(gradebook::class)]
final class gradebook_test extends \advanced_testcase {

    /** @var \stdClass курс */
    private $course;
    /** @var \stdClass[] пользователи-ученики (u1 3 класс, u2/u3 5 класс) */
    private array $users = [];
    /** @var \stdClass[] тесты (quiz) с grade item типа mod */
    private array $quizzes = [];

    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();

        $spec = [
            ['lastname' => 'Аронов',  'class' => 3],
            ['lastname' => 'Борисов', 'class' => 5],
            ['lastname' => 'Волков',  'class' => 5],
        ];
        foreach ($spec as $s) {
            $u = $gen->create_user(['lastname' => $s['lastname']]);
            $gen->enrol_user($u->id, $this->course->id, 'student');
            $DB->insert_record('unics_students', (object)[
                'mdl_user_id'  => $u->id,
                'class_number' => $s['class'],
            ]);
            $this->users[] = $u;
        }

        $this->quizzes[] = $gen->create_module('quiz', ['course' => $this->course->id, 'grade' => 100]);
        $this->quizzes[] = $gen->create_module('quiz', ['course' => $this->course->id, 'grade' => 100]);

        // Оценки: u1 - 80 и 40; u2 - 100 за первый тест; u3 - без оценок.
        $this->grade(0, 0, 80.0);
        $this->grade(0, 1, 40.0);
        $this->grade(1, 0, 100.0);
    }

    private function grade(int $userindex, int $quizindex, float $raw): void {
        grade_update('mod/quiz', $this->course->id, 'mod', 'quiz',
            $this->quizzes[$quizindex]->id, 0,
            ['userid' => $this->users[$userindex]->id, 'rawgrade' => $raw]);
    }

    public function test_full_matrix(): void {
        $m = gradebook::matrix((int)$this->course->id, 0, '');

        $this->assertNull($m['notice']);
        $this->assertSame(3, $m['total']);
        $this->assertCount(3, $m['students']);
        // Колонки - оба теста; средний по классу: q1 (80+100)/2=90 -> 4.5, q2 40 -> 2.0.
        $this->assertCount(2, $m['item_meta']);
        $this->assertContains(grade_scale::from_percent(90), $m['item_class_avg']);
        $this->assertContains(grade_scale::from_percent(40), $m['item_class_avg']);
        // Оценки по ученикам: у u1 две, у u2 одна, u3 отсутствует.
        $this->assertCount(2, $m['by_user'][(int)$this->users[0]->id]);
        $this->assertCount(1, $m['by_user'][(int)$this->users[1]->id]);
        $this->assertArrayNotHasKey((int)$this->users[2]->id, $m['by_user']);
    }

    public function test_pagination_keeps_columns_and_averages(): void {
        $full  = gradebook::matrix((int)$this->course->id, 0, '');
        $page0 = gradebook::matrix((int)$this->course->id, 0, '', 0, 2);
        $page1 = gradebook::matrix((int)$this->course->id, 0, '', 1, 2);

        $this->assertSame(3, $page0['total']);
        $this->assertSame(3, $page1['total']);
        $this->assertCount(2, $page0['students']);
        $this->assertCount(1, $page1['students']);

        // Колонки и средние одинаковы на всех страницах и равны полной выборке.
        $this->assertSame(array_keys($full['item_meta']), array_keys($page0['item_meta']));
        $this->assertSame(array_keys($full['item_meta']), array_keys($page1['item_meta']));
        $this->assertEquals($full['item_class_avg'], $page0['item_class_avg']);
        $this->assertEquals($full['item_class_avg'], $page1['item_class_avg']);

        // Страницы разбивают выборку без пересечений (сортировка: класс, фамилия).
        $ids0 = array_column(array_values($page0['students']), 'student_id');
        $ids1 = array_column(array_values($page1['students']), 'student_id');
        $this->assertSame([], array_intersect($ids0, $ids1));
        $this->assertCount(3, array_merge($ids0, $ids1));
    }

    public function test_class_filter(): void {
        $m = gradebook::matrix((int)$this->course->id, 3, '');
        $this->assertSame(1, $m['total']);
        $first = reset($m['students']);
        $this->assertSame('Аронов', $first->lastname);
    }

    public function test_notice_when_no_students_match(): void {
        $m = gradebook::matrix((int)$this->course->id, 11, 'Ж');
        $this->assertNotNull($m['notice']);
        $this->assertSame(0, $m['total']);
        $this->assertSame([], $m['students']);
    }

    public function test_notice_when_no_grades(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $bare = $gen->create_course();
        $u = $gen->create_user();
        $gen->enrol_user($u->id, $bare->id, 'student');
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $u->id]);

        $m = gradebook::matrix((int)$bare->id, 0, '');
        $this->assertNotNull($m['notice']);
        $this->assertSame(1, $m['total']);
    }
}
