<?php
namespace local_unics;

use local_unics\output\course_view;

defined('MOODLE_INTERNAL') || die();

/**
 * Тесты хелпера данных ученического вида страницы курса
 * ({@see \local_unics\output\course_view::build_payload}, course/view.php, формат topics):
 * статусы карточек активностей, прогресс секции/курса (с русской формой числительного),
 * next-step, уточнение под меткой типа (sub), человекочитаемая причина блокировки,
 * гейт is_child_view.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_view::class)]
final class course_view_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
    }

    /** Пометить активность выполненной (COMPLETION_COMPLETE) для пользователя. */
    private function mark_done(\completion_info $ci, string $modname, int $cmid, int $userid): void {
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
        $ci->update_state($cm, COMPLETION_COMPLETE, $userid);
    }

    /**
     * Курс с 2 секциями отслеживаемых активностей: секция 1 - 3 активности
     * (page1 выполнена, page2 и quiz1 - нет), секция 2 - 2 активности (обе
     * выполнены -> тема пройдена). Курс: 1 из 2 тем пройдена.
     *
     * @return array{0:\stdClass,1:\stdClass,2:array}
     */
    private function make_course_with_activities(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 2, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        $cma = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cmb = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cmc = $gen->create_module('quiz', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cmd = $gen->create_module('assign', ['course' => $course->id, 'section' => 2,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $cme = $gen->create_module('page', ['course' => $course->id, 'section' => 2,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);

        $ci = new \completion_info($course);
        $this->mark_done($ci, 'page', $cma->cmid, $student->id);
        $this->mark_done($ci, 'assign', $cmd->cmid, $student->id);
        $this->mark_done($ci, 'page', $cme->cmid, $student->id);

        $ctx = [
            'secnum'              => '1',
            'expectedSectionDone' => 1,
            'trackedInSec'        => [$cma->cmid, $cmb->cmid, $cmc->cmid],
            'expectedNextCmid'    => (int)$cmb->cmid, // первая невыполненная в порядке курса
        ];
        return [$course, $student, $ctx];
    }

    /**
     * Курс с одной заблокированной активностью: quiz недоступен, пока не пройден
     * материал-зависимость (условие completion в availability).
     *
     * @return array{0:\stdClass,1:\stdClass,2:array}
     */
    private function make_course_with_locked(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        $dep = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'name' => 'Вводный материал', 'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $locked = $gen->create_module('quiz', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);

        $DB->set_field('course_modules', 'availability', json_encode([
            'op'    => '&',
            'c'     => [['type' => 'completion', 'cm' => (int)$dep->cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true],
        ]), ['id' => $locked->cmid]);
        rebuild_course_cache($course->id, true);

        return [$course, $student, ['lockedCmid' => (int)$locked->cmid, 'depName' => 'Вводный материал']];
    }

    public function test_build_payload_shape(): void {
        [$course, $student] = $this->make_course_with_activities();
        $p = course_view::build_payload($course, $student->id);

        $this->assertIsArray($p['cms']);
        $this->assertArrayHasKey('done', $p['course']);
        $this->assertArrayHasKey('total', $p['course']);
        // 1 из 2 тем пройдена - русская форма единственного числа («Пройдена 1 тема»).
        $this->assertSame(1, $p['course']['done']);
        $this->assertSame(2, $p['course']['total']);
        $this->assertSame('Пройдена 1 тема из 2', $p['course']['label']);
        // Доступные имена прогресс-баров (WCAG 4.1.2 - aria-valuetext именем не является, AMD
        // ставит их отдельным aria-label из этих готовых серверных строк).
        $this->assertSame('Прогресс по курсу', $p['strings']['progressCourseName']);
        $this->assertSame('Прогресс по теме', $p['strings']['progressSectionName']);
    }

    public function test_section_and_course_progress(): void {
        [$course, $student, $ctx] = $this->make_course_with_activities();
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame($ctx['expectedSectionDone'], $p['sections'][$ctx['secnum']]['done']);
        $this->assertSame(count($ctx['trackedInSec']), $p['sections'][$ctx['secnum']]['total']);
        $this->assertFalse($p['sections'][$ctx['secnum']]['complete']);
        // label/aria - готовая фраза для секции (JS не должен склеивать числа со словами сам).
        $this->assertSame('1 из 3', $p['sections'][$ctx['secnum']]['label']);
        $this->assertSame('Выполнено 1 из 3', $p['sections'][$ctx['secnum']]['aria']);
        // Секция 2 - обе активности выполнены, тема пройдена целиком.
        $this->assertSame([
            'done' => 2, 'total' => 2, 'complete' => true,
            'label' => '2 из 2', 'aria' => 'Выполнено 2 из 2',
        ], $p['sections']['2']);
    }

    public function test_next_step_is_first_available_incomplete(): void {
        [$course, $student, $ctx] = $this->make_course_with_activities();
        $p = course_view::build_payload($course, $student->id);

        $this->assertNotNull($p['next']);
        $this->assertSame($ctx['expectedNextCmid'], $p['next']['cmid']);
    }

    public function test_locked_activity_has_friendly_reason(): void {
        [$course, $student, $ctx] = $this->make_course_with_locked();
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('locked', $p['cms'][(string)$ctx['lockedCmid']]['status']);
        $this->assertStringContainsString($ctx['depName'], $p['cms'][(string)$ctx['lockedCmid']]['lockWhy']);
    }

    /**
     * mod_resource с главным файлом заданного mimetype - создает курс с одной секцией
     * и одной активностью resource, у которой дефолтный файл генератора (текстовый)
     * заменен на файл нужного mime (сохраняем sortorder=1 - тот же принцип, что
     * mod_resource\locallib.php::resource_set_mainfile использует для единственного файла).
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} курс, ресурс, студент
     */
    private function make_course_with_resource_file(string $filename, string $mimetype): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics'],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');

        // Генератору resource нужен текущий пользователь для дефолтного файла (свой draft-контекст).
        $this->setAdminUser();
        $resource = $gen->create_module('resource', ['course' => $course->id, 'section' => 1]);

        $context = \context_module::instance($resource->cmid);
        $fs = get_file_storage();
        // Дефолтный текстовый файл генератора убираем - оставляем ровно один главный файл
        // нужного mimetype (sortorder=1, как у единственного файла ресурса в проде).
        $fs->delete_area_files($context->id, 'mod_resource', 'content', 0);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_resource',
            'filearea'  => 'content',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
            'mimetype'  => $mimetype,
            'sortorder' => 1,
        ], 'test content');
        rebuild_course_cache($course->id, true);

        return [$course, $resource, $student];
    }

    public function test_resource_with_audio_file_detected_as_audio(): void {
        [$course, $resource, $student] = $this->make_course_with_resource_file('zvuk.wav', 'audio/wav');
        $p = course_view::build_payload($course, $student->id);

        $cm = $p['cms'][(string)$resource->cmid];
        $this->assertSame('audio', $cm['type']);
        $this->assertSame('Аудиоматериал', $cm['typeLabel']);
    }

    /** Регресс: сравнение mimetype не должно зависеть от регистра (AUDIO/WAV тоже audio). */
    public function test_resource_with_uppercase_mimetype_detected_as_audio(): void {
        [$course, $resource, $student] = $this->make_course_with_resource_file('zvuk2.wav', 'AUDIO/WAV');
        $p = course_view::build_payload($course, $student->id);

        $cm = $p['cms'][(string)$resource->cmid];
        $this->assertSame('audio', $cm['type']);
        $this->assertSame('Аудиоматериал', $cm['typeLabel']);
    }

    public function test_resource_with_video_file_detected_as_video(): void {
        [$course, $resource, $student] = $this->make_course_with_resource_file('video.mp4', 'video/mp4');
        $p = course_view::build_payload($course, $student->id);

        $cm = $p['cms'][(string)$resource->cmid];
        $this->assertSame('video', $cm['type']);
        $this->assertSame('Видео', $cm['typeLabel']);
    }

    public function test_resource_with_pdf_file_stays_material(): void {
        [$course, $resource, $student] = $this->make_course_with_resource_file('doc.pdf', 'application/pdf');
        $p = course_view::build_payload($course, $student->id);

        $cm = $p['cms'][(string)$resource->cmid];
        $this->assertSame('material', $cm['type']);
        $this->assertSame('Материал для чтения', $cm['typeLabel']);
    }

    /** Регресс: правка детекции resource не должна задеть mod_page (остается material). */
    public function test_page_module_stays_material(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $page = $gen->create_module('page', ['course' => $course->id, 'section' => 1]);

        $p = course_view::build_payload($course, $student->id);

        $cm = $p['cms'][(string)$page->cmid];
        $this->assertSame('material', $cm['type']);
        $this->assertSame('Материал для чтения', $cm['typeLabel']);
    }

    /**
     * Активность БЕЗ отслеживания выполнения и доступная - статус 'open' («Открыть»).
     * Самая частая ветка на реальных курсах: completion у активности выключен, значит
     * ни 'done', ни 'todo' быть не может (честно: не «выполнено», а «можно открыть»).
     */
    public function test_activity_without_completion_is_open(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $page = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_NONE]);

        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('open', $p['cms'][(string)$page->cmid]['status']);
    }

    /**
     * Курс, где выполнение не отслеживается ни у одной активности: тем для прогресса нет
     * (course.total === 0) и next-step тоже нет. Это состояние, в котором AMD не рисует
     * шапку курса вообще (ни бара, ни подписи, ни ободряющей строки).
     */
    public function test_course_without_tracked_activities_has_zero_total(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_NONE]);

        $p = course_view::build_payload($course, $student->id);

        $this->assertSame(0, $p['course']['total']);
        $this->assertSame(0, $p['course']['done']);
        $this->assertSame([], $p['sections']);
        $this->assertNull($p['next']);
    }

    /** Все отслеживаемые активности выполнены - next-step нет (состояние «Курс пройден!»). */
    public function test_next_step_is_null_when_everything_is_done(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $one = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);
        $two = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC]);

        $ci = new \completion_info($course);
        $this->mark_done($ci, 'page', $one->cmid, $student->id);
        $this->mark_done($ci, 'page', $two->cmid, $student->id);

        $p = course_view::build_payload($course, $student->id);

        $this->assertNull($p['next']);
        $this->assertSame(1, $p['course']['done']);
        $this->assertSame(1, $p['course']['total']);
    }

    /**
     * Курс с одним тестом на $numquestions вопросов.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} курс, тест (модуль), студент
     */
    private function make_course_with_quiz_questions(int $numquestions): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'section' => 1]);

        $qgen = $gen->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        for ($i = 0; $i < $numquestions; $i++) {
            $question = $qgen->create_question('truefalse', null, ['category' => $cat->id]);
            quiz_add_quiz_question($question->id, $quiz);
        }

        return [$course, $quiz, $student];
    }

    /** Тест с одним вопросом - единственное число («1 вопрос»). */
    public function test_quiz_sub_uses_singular_form(): void {
        [$course, $quiz, $student] = $this->make_course_with_quiz_questions(1);
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('Тест', $p['cms'][(string)$quiz->cmid]['typeLabel']);
        $this->assertSame('1 вопрос', $p['cms'][(string)$quiz->cmid]['sub']);
    }

    /** Тест с двумя вопросами - форма few («2 вопроса»). */
    public function test_quiz_sub_uses_few_form(): void {
        [$course, $quiz, $student] = $this->make_course_with_quiz_questions(2);
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('2 вопроса', $p['cms'][(string)$quiz->cmid]['sub']);
    }

    /** Тест с пятью вопросами - форма many («5 вопросов»). */
    public function test_quiz_sub_uses_many_form(): void {
        [$course, $quiz, $student] = $this->make_course_with_quiz_questions(5);
        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('5 вопросов', $p['cms'][(string)$quiz->cmid]['sub']);
    }

    /** Тест без вопросов - подписи нет (число не показываем, а не пишем «0 вопросов»). */
    public function test_quiz_without_questions_has_no_sub(): void {
        [$course, $quiz, $student] = $this->make_course_with_quiz_questions(0);
        $p = course_view::build_payload($course, $student->id);

        $this->assertNull($p['cms'][(string)$quiz->cmid]['sub']);
    }

    /** Задание - фиксированная подпись «с проверкой» (педагог проверит работу). */
    public function test_assign_sub_is_checked_by_teacher(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $assign = $gen->create_module('assign', ['course' => $course->id, 'section' => 1]);

        $p = course_view::build_payload($course, $student->id);

        $this->assertSame('Задание', $p['cms'][(string)$assign->cmid]['typeLabel']);
        $this->assertSame('с проверкой', $p['cms'][(string)$assign->cmid]['sub']);
    }

    /** У материалов уточнять нечего - метка типа уже говорящая, sub остается null. */
    public function test_material_modules_have_no_sub(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $page = $gen->create_module('page', ['course' => $course->id, 'section' => 1]);
        $this->setAdminUser();
        $resource = $gen->create_module('resource', ['course' => $course->id, 'section' => 1]);

        $p = course_view::build_payload($course, $student->id);

        $this->assertNull($p['cms'][(string)$page->cmid]['sub']);
        $this->assertNull($p['cms'][(string)$resource->cmid]['sub']);
    }

    /**
     * Модуль без собственной страницы (mod_label) в payload не попадает: открывать нечего,
     * чип «Открыть» был бы обманом. Соседняя обычная активность при этом на месте.
     */
    public function test_module_without_view_page_is_skipped(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 1, 'format' => 'topics'], ['createsections' => true]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $label = $gen->create_module('label', ['course' => $course->id, 'section' => 1]);
        $page = $gen->create_module('page', ['course' => $course->id, 'section' => 1]);

        $p = course_view::build_payload($course, $student->id);

        $this->assertArrayNotHasKey((string)$label->cmid, $p['cms']);
        $this->assertArrayHasKey((string)$page->cmid, $p['cms']);
    }

    /**
     * Имя активности приходит в payload БЕЗ html-экранирования: AMD вставляет его через
     * textContent, и «&» должен доехать до ребенка амперсандом, а не «&amp;».
     */
    public function test_activity_names_are_not_html_escaped(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(
            ['numsections' => 1, 'format' => 'topics', 'enablecompletion' => 1],
            ['createsections' => true]
        );
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'name' => 'Ноты & ритм', 'completion' => COMPLETION_TRACKING_AUTOMATIC]);

        $p = course_view::build_payload($course, $student->id);

        $this->assertNotNull($p['next']);
        $this->assertStringContainsString('Ноты & ритм', $p['next']['label']);
        $this->assertStringNotContainsString('&amp;', $p['next']['label']);
    }

    /**
     * Гейт «ученический вид»: true только для пользователя с записью unics_students.
     * Два разных пользователя (а не один и тот же до/после insert) - у
     * access::student_record() есть запросный кеш, отрицательный результат тоже
     * кешируется, поэтому один и тот же userid до/после insert внутри теста дал бы
     * ложный негатив.
     */
    public function test_is_child_view_gate(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics']);

        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->assertFalse(course_view::is_child_view($course));

        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $student->id]);
        $this->setUser($student);
        $this->assertTrue(course_view::is_child_view($course));
    }
}
