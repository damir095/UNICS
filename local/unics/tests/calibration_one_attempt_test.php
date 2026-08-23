<?php
namespace local_unics;

/**
 * Калибровка считает по ОДНОМУ наблюдению на ученика ([[calibration-one-attempt]]).
 *
 * Зонд 2026-08-23: у задания было 42 ответа и всего 6 учеников - 36 повторных попыток тех же
 * детей. Для модели испытуемый один, а ответы на одно задание скачут то верно, то неверно;
 * оценка 2PL от таких данных вырождается (дискриминация упирается в нижнюю границу). Число
 * ответов росло, а число испытуемых - нет.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_irt_manager::class)]
final class calibration_one_attempt_test extends \advanced_testcase {

    /** Ученик УНИКС, записанный на курс. */
    private function student(int $courseid): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $courseid);
        $sid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'class_number' => 7, 'difficulty_level' => 2,
        ]);
        return [(int)$user->id, $sid];
    }

    /**
     * Завершенная попытка с заданным исходом по одному вопросу.
     *
     * Пишем напрямую в таблицы движка вопросов: поднимать настоящий mod_quiz ради одной дроби
     * дороже, а калибровке нужны ровно эти четыре записи.
     */
    private function attempt(int $quizid, int $userid, int $questionid, float $fraction,
                             int $number): void {
        global $DB;
        $uniqueid = (int)$DB->insert_record('question_usages', (object)[
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_quiz', 'preferredbehaviour' => 'deferredfeedback',
        ]);
        $qaid = (int)$DB->insert_record('question_attempts', (object)[
            'questionusageid' => $uniqueid, 'slot' => 1, 'behaviour' => 'deferredfeedback',
            'questionid' => $questionid, 'variant' => 1, 'maxmark' => 1, 'minfraction' => 0,
            'maxfraction' => 1, 'flagged' => 0, 'questionsummary' => '', 'rightanswer' => '',
            'responsesummary' => '', 'timemodified' => time(),
        ]);
        $DB->insert_record('question_attempt_steps', (object)[
            'questionattemptid' => $qaid, 'sequencenumber' => 1, 'state' => 'gradedright',
            'fraction' => $fraction, 'timecreated' => time(), 'userid' => $userid,
        ]);
        $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quizid, 'userid' => $userid, 'attempt' => $number, 'uniqueid' => $uniqueid,
            'layout' => '1,0', 'state' => 'finished', 'timestart' => time(),
            'timefinish' => time(), 'timemodified' => time(), 'sumgrades' => $fraction,
        ]);
    }

    /** Вопрос банка, привязанный к элементу кодификатора: [questionid, bankentryid]. */
    private function question(int $elementid): array {
        global $DB, $USER;
        $qcat = (int)$DB->insert_record('question_categories', (object)[
            'name' => 'т', 'contextid' => \context_system::instance()->id, 'info' => '',
            'infoformat' => FORMAT_HTML, 'stamp' => make_unique_id_code(), 'parent' => 0,
            'sortorder' => 0,
        ]);
        $qbe = (int)$DB->insert_record('question_bank_entries', (object)[
            'questioncategoryid' => $qcat, 'ownerid' => null,
        ]);
        $qid = (int)$DB->insert_record('question', (object)[
            'category' => $qcat, 'parent' => 0, 'name' => 'в', 'questiontext' => 'в',
            'questiontextformat' => FORMAT_HTML, 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'defaultmark' => 1, 'penalty' => 0,
            'qtype' => 'multichoice', 'length' => 1, 'stamp' => make_unique_id_code(),
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => 0, 'modifiedby' => 0,
        ]);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $qbe, 'version' => 1, 'questionid' => $qid, 'status' => 'ready',
        ]);
        codifier_link_manager::link_question($elementid, $qbe, (int)$USER->id);
        return [$qid, $qbe];
    }

    /** Кодификатор с элементом. */
    private function element(): int {
        global $DB, $USER;
        $cat = $this->getDataGenerator()->create_category();
        $cid = codifier_manager::create_codifier((int)$cat->id, 'к', (int)$USER->id);
        return codifier_manager::add_element($cid, null, '1', 'Тема');
    }

    public function test_repeat_attempts_count_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        [$eid] = [$this->element()];
        [$qid, $qbe] = $this->question($eid);
        [$uid, ] = $this->student((int)$course->id);

        // Один ученик, пять попыток: наблюдение все равно одно.
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt((int)$quiz->id, $uid, $qid, $i % 2 ? 1.0 : 0.0, $i);
        }

        $matrix = item_irt_manager::response_matrix();

        $this->assertCount(1, $matrix, 'пять попыток одного ученика - одно наблюдение');
        $this->assertSame($qbe, (int)$matrix[0]['item_ref']);
    }

    public function test_last_attempt_wins(): void {
        // Берем ПОСЛЕДНЮЮ попытку: она отражает нынешнее состояние ребенка.
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $eid = $this->element();
        [$qid, ] = $this->question($eid);
        [$uid, ] = $this->student((int)$course->id);

        $this->attempt((int)$quiz->id, $uid, $qid, 0.0, 1);
        $this->attempt((int)$quiz->id, $uid, $qid, 1.0, 2);

        $matrix = item_irt_manager::response_matrix();

        $this->assertCount(1, $matrix);
        $this->assertSame(1, (int)$matrix[0]['correct'], 'последняя попытка была верной');
    }

    public function test_different_students_are_different_observations(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $eid = $this->element();
        [$qid, ] = $this->question($eid);

        for ($i = 0; $i < 3; $i++) {
            [$uid, ] = $this->student((int)$course->id);
            $this->attempt((int)$quiz->id, $uid, $qid, 1.0, 1);
        }

        $this->assertCount(3, item_irt_manager::response_matrix(),
            'разные ученики - разные наблюдения');
    }

    public function test_unfinished_attempts_are_ignored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $eid = $this->element();
        [$qid, ] = $this->question($eid);
        [$uid, ] = $this->student((int)$course->id);

        $this->attempt((int)$quiz->id, $uid, $qid, 1.0, 1);
        $DB->set_field('quiz_attempts', 'state', 'inprogress', ['quiz' => $quiz->id]);

        $this->assertSame([], item_irt_manager::response_matrix());
    }
}
