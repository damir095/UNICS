<?php
namespace local_unics;

use local_unics\ai\assistant;
use local_unics\tests\fake_ai_generator;

require_once(__DIR__ . '/fixtures/fake_ai_generator.php');

/**
 * ИИ-ассистент ученика ([[assistant-design]]).
 *
 * Функцию снимали 2026-05-15 с тремя причинами: стоимость токенов, модерация ответов детям, объем.
 * Тесты проверяют, что каждая закрыта предохранителем, а не обещанием, - и четвертый сверх того:
 * ассистент не решает задания за ребенка.
 *
 * @package local_unics
 */
final class assistant_test extends \advanced_testcase {

    private \stdClass $course;
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course->id);
    }

    /** Опубликованный УМК с учебным текстом; возвращает umk_id. */
    private function make_umk(string $text, ?int $groupid = null): int {
        global $DB, $USER;
        $page = $this->getDataGenerator()->create_module('page',
            ['course' => $this->course->id, 'content' => $text, 'contentformat' => FORMAT_MARKDOWN]);
        $umk = (int)$DB->insert_record('unics_umk', (object)[
            'mdl_course_id' => (int)$this->course->id, 'difficulty_level' => 2,
            'mdl_group_id' => $groupid, 'title' => 'Комплект', 'topic' => 'Дроби',
            'target_section' => 0, 'status' => 3, 'generated_at' => time(),
            'published_at' => time(),
        ]);
        $DB->insert_record('unics_umk_materials', (object)[
            'umk_id' => $umk, 'mdl_course_module_id' => (int)$page->cmid,
            'material_type' => 1, 'sort_order' => 0,
        ]);
        return $umk;
    }

    /** Заглушка, отвечающая заданным текстом. */
    private function assistant(string $reply = 'Это дробь.'): assistant {
        $gen = new class($reply) extends fake_ai_generator {
            public function __construct(private string $reply) {
                parent::__construct();
            }
            protected function quiz_reply(string $prompt): string {
                return $this->reply;
            }
        };
        return new assistant($gen);
    }

    public function test_answers_from_the_material(): void {
        global $DB;
        $this->make_umk('Дробь - это часть целого.');

        $out = $this->assistant('Дробь - часть целого.')
            ->ask((int)$this->user->id, (int)$this->course->id, 'Что такое дробь?');

        $this->assertSame(assistant::ANSWERED, $out->outcome);
        $this->assertSame('Дробь - часть целого.', $out->answer);
        $this->assertTrue($DB->record_exists('unics_assistant_message', ['id' => $out->id]));
    }

    public function test_without_material_it_does_not_invent(): void {
        // Ассистент не энциклопедия: нет материала - нет ответа. Это и есть ограничение объема,
        // одна из трех причин, по которым функцию откладывали.
        $out = $this->assistant('Дробь - это часть целого, и вообще...')
            ->ask((int)$this->user->id, (int)$this->course->id, 'Что такое дробь?');

        $this->assertSame(assistant::NO_MATERIAL, $out->outcome);
        $this->assertNull($out->answer, 'без материала ответа быть не должно');
    }

    public function test_material_of_another_group_is_not_used(): void {
        // Комплекты разведены по группам доступа. Чужой текст ребенку не показывают - и в промт
        // ассистента он тоже попасть не может.
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->make_umk('Чужой материал про дроби.', (int)$group->id);

        $out = $this->assistant()->ask((int)$this->user->id, (int)$this->course->id, 'Что такое дробь?');

        $this->assertSame(assistant::NO_MATERIAL, $out->outcome);
    }

    public function test_daily_limit_stops_the_flood(): void {
        global $DB;
        $this->make_umk('Дробь - это часть целого.');
        for ($i = 0; $i < assistant::DAILY_LIMIT; $i++) {
            $DB->insert_record('unics_assistant_message', (object)[
                'mdl_user_id' => (int)$this->user->id, 'mdl_course_id' => (int)$this->course->id,
                'question' => 'в', 'answer' => 'о', 'outcome' => assistant::ANSWERED,
                'timecreated' => time(),
            ]);
        }

        $out = $this->assistant()->ask((int)$this->user->id, (int)$this->course->id, 'Что такое дробь?');

        $this->assertSame(assistant::LIMIT, $out->outcome);
    }

    public function test_yesterday_questions_do_not_count_against_the_limit(): void {
        global $DB;
        $this->make_umk('Дробь - это часть целого.');
        for ($i = 0; $i < assistant::DAILY_LIMIT; $i++) {
            $DB->insert_record('unics_assistant_message', (object)[
                'mdl_user_id' => (int)$this->user->id, 'mdl_course_id' => (int)$this->course->id,
                'question' => 'в', 'answer' => 'о', 'outcome' => assistant::ANSWERED,
                'timecreated' => time() - DAYSECS - 60,
            ]);
        }

        $out = $this->assistant()->ask((int)$this->user->id, (int)$this->course->id, 'Что такое дробь?');

        $this->assertSame(assistant::ANSWERED, $out->outcome, 'лимит суточный, а не пожизненный');
    }

    public function test_task_copied_word_for_word_is_refused(): void {
        $this->make_umk('Дробь - это часть целого.');
        $text = 'Какая из дробей больше: три четвертых или две третьих?';
        $this->make_question($text);

        $out = $this->assistant()->ask((int)$this->user->id, (int)$this->course->id, $text);

        $this->assertSame(assistant::LOOKS_LIKE_TASK, $out->outcome);
        $this->assertNull($out->answer, 'готового ответа на задание ребенок не получает');
    }

    public function test_own_question_about_the_same_topic_is_answered(): void {
        // Проверка НАРОЧНО узкая: широкая («вопрос про то же самое») запрещала бы учиться.
        $this->make_umk('Дробь - это часть целого.');
        $this->make_question('Какая из дробей больше: три четвертых или две третьих?');

        $out = $this->assistant()->ask((int)$this->user->id, (int)$this->course->id,
            'Не понимаю, как сравнивать дроби с разными знаменателями, объясни');

        $this->assertSame(assistant::ANSWERED, $out->outcome);
    }

    public function test_ai_failure_is_logged_and_not_passed_off_as_an_answer(): void {
        global $DB;
        $this->make_umk('Дробь - это часть целого.');
        $gen = new class extends fake_ai_generator {
            protected function quiz_reply(string $prompt): string {
                throw new \moodle_exception('generalexceptionmessage', 'error', '', 'сеть');
            }
        };

        $out = (new assistant($gen))->ask((int)$this->user->id, (int)$this->course->id, 'Что такое дробь?');

        $this->assertSame(assistant::AI_FAILED, $out->outcome);
        $this->assertNull($out->answer);
        $this->assertTrue($DB->record_exists('unics_assistant_message',
            ['id' => $out->id, 'outcome' => assistant::AI_FAILED]),
            'отказ ИИ обязан остаться в журнале: педагог видит, что ребенок спрашивал');
    }

    public function test_every_outcome_reaches_the_log(): void {
        // Модерация - причина, по которой функцию откладывали. Она работает только если в журнал
        // попадает ВСЁ, включая отказы.
        global $DB;
        $this->assistant()->ask((int)$this->user->id, (int)$this->course->id, 'Вопрос без материала');

        $rows = $DB->get_records('unics_assistant_message', ['mdl_user_id' => (int)$this->user->id]);

        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('Вопрос без материала', $row->question);
    }

    /**
     * Педагог видит переписку ТОЛЬКО закрепленных за ним учащихся.
     *
     * Журнал ассистента - такой же персональный материал ребенка, как оценки: чужой педагог не
     * должен его читать. Утечка родителю уже случалась (роль на системном контексте вместо
     * контекста своего ребенка), и повторять ее на новом журнале нельзя.
     */
    public function test_teacher_sees_only_bound_students(): void {
        global $DB;
        $mine = $this->getDataGenerator()->create_user();
        $alien = $this->getDataGenerator()->create_user();
        $teacheruser = $this->getDataGenerator()->create_user();

        $region = (int)$DB->insert_record('unics_regions', (object)['name' => 'Тюменская область']);
        $district = (int)$DB->insert_record('unics_districts',
            (object)['region_id' => $region, 'name' => 'Муниципалитет']);
        $org = (int)$DB->insert_record('unics_organizations', (object)[
            'district_id' => $district, 'name' => 'Школа', 'short_name' => 'Ш', 'org_type' => 1,
        ]);
        $mkstudent = function (int $userid) use ($DB, $org): int {
            return (int)$DB->insert_record('unics_students', (object)[
                'mdl_user_id' => $userid, 'organization_id' => $org, 'class_number' => 7,
                'class_letter' => 'А', 'difficulty_level' => 2,
            ]);
        };
        $mineid = $mkstudent((int)$mine->id);
        $alienid = $mkstudent((int)$alien->id);

        $teacherid = (int)$DB->insert_record('unics_teachers', (object)[
            'mdl_user_id' => (int)$teacheruser->id, 'organization_id' => $org,
        ]);
        $DB->insert_record('unics_teacher_student', (object)[
            'teacher_id' => $teacherid, 'student_id' => $mineid,
        ]);

        // Чужой ученик привязан к ДРУГОМУ педагогу, а не просто «ни к кому». Без этого запросу
        // нечего различать: он вернул бы ту же единственную строку и с любым условием, и мутация
        // «не учитывать привязку» прошла бы незамеченной.
        $otherteacher = (int)$DB->insert_record('unics_teachers', (object)[
            'mdl_user_id' => (int)$this->getDataGenerator()->create_user()->id,
            'organization_id' => $org,
        ]);
        $DB->insert_record('unics_teacher_student', (object)[
            'teacher_id' => $otherteacher, 'student_id' => $alienid,
        ]);

        $visible = assistant::visible_student_userids((int)$teacheruser->id);

        $this->assertSame([(int)$mine->id], $visible,
            'чужой ученик в журнал педагога попадать не должен');
    }

    /** Пользователь без роли и привязок не читает ничего. */
    public function test_stranger_sees_nothing(): void {
        $nobody = $this->getDataGenerator()->create_user();

        $this->assertSame([], assistant::visible_student_userids((int)$nobody->id));
    }

    /** Полный админ читает всё: null означает «без ограничения». */
    public function test_admin_sees_everything(): void {
        global $USER;
        $this->setAdminUser();

        $this->assertNull(assistant::visible_student_userids((int)$USER->id));
    }

    /** Вопрос в банке курса. */
    private function make_question(string $text): void {
        $gen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $gen->create_question_category(
            ['contextid' => \context_course::instance($this->course->id)->id]);
        $gen->create_question('truefalse', null, ['name' => 'в', 'category' => $cat->id,
            'questiontext' => ['text' => $text, 'format' => FORMAT_HTML]]);
    }
}
