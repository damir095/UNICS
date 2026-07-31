<?php
namespace local_unics;

use local_unics\output\course_staff_view;

defined('MOODLE_INTERNAL') || die();

/**
 * Тесты хелпера данных педагогского вида страницы курса ({@see course_staff_view}):
 * гейт is_staff_view и состав класса смотрящего (class_members) - педагог видит только
 * привязанных к нему учеников (unics_teacher_student), привязка без записи на курс
 * в класс не попадает; методист/скоупный админ видит по скоупу, а не по привязкам
 * (регресс: у методиста тоже есть строка в unics_teachers - см. class_members()); системный
 * админ видит всех записанных на курс.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_staff_view::class)]
final class course_staff_view_test extends \advanced_testcase {

    /**
     * Создать (если такой роли еще нет) Moodle-роль по shortname и назначить ее пользователю
     * на системном контексте - {@see \local_unics\access::user_has_role()} (и is_methodist())
     * ищут роль без учета контекста, поэтому системного контекста достаточно.
     * В окружении PHPUnit роль 'methodist' не создается ни install.xml (там только схема),
     * ни db/upgrade.php (там создаются только region_admin/district_methodist/region_methodist) -
     * на живом сайте ее заводят вручную через «Определить роли» (см. pages/setup_roles.php),
     * поэтому тест обязан завести ее сам.
     */
    private function assign_role(string $shortname, string $archetype, int $userid): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $roleid = create_role(ucfirst($shortname), $shortname, '', $archetype);
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        role_assign((int)$roleid, $userid, \context_system::instance()->id);
    }

    /**
     * Иерархия скоупа регион -> округ -> 2 организации (для проверки, что скоуп-фильтр
     * ДЕЙСТВИТЕЛЬНО ограничивает список чужой организацией, а не просто "не пустой").
     * @return array{0:int,1:int} [id организации А, id организации Б]
     */
    private function make_two_organizations(): array {
        global $DB;
        $rid = $DB->insert_record('unics_regions', (object)['name' => 'Тест-регион']);
        $did = $DB->insert_record('unics_districts', (object)['region_id' => $rid, 'name' => 'Тест-округ']);
        $orga = $DB->insert_record('unics_organizations', (object)['district_id' => $did, 'name' => 'Организация А']);
        $orgb = $DB->insert_record('unics_organizations', (object)['district_id' => $did, 'name' => 'Организация Б']);
        return [(int)$orga, (int)$orgb];
    }

    /**
     * Записать ученика на курс + unics_students + unics_user_org со скоупом организации
     * (scope_checker::user_list_filter_sql фильтрует именно по unics_user_org записываемого,
     * а не по unics_students.organization_id).
     */
    private function enrol_student_in_org(\stdClass $course, int $orgid): \stdClass {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $student->id]);
        $DB->insert_record('unics_user_org',
            (object)['mdl_user_id' => $student->id, 'organization_id' => $orgid, 'unics_role' => 7]);
        return $student;
    }

    /**
     * Курс с двумя учениками (оба записаны) и педагогом, привязанным к ПЕРВОМУ.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass} [курс, ученик1, ученик2, педагог]
     */
    private function make_course_with_class(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 1, 'numsections' => 2]);

        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $t  = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($s2->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');

        $sid1 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $DB->insert_record('unics_students', (object)['mdl_user_id' => $s2->id]);
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid1]);

        return [$course, $s1, $s2, $t];
    }

    public function test_class_members_are_limited_to_teacher_bindings(): void {
        $this->resetAfterTest();
        [$course, $s1, $s2, $t] = $this->make_course_with_class();

        $members = course_staff_view::class_members($course, $t->id);

        $this->assertSame([(int)$s1->id], $members);
        $this->assertNotContains((int)$s2->id, $members);
    }

    public function test_bound_student_not_enrolled_is_excluded(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t] = $this->make_course_with_class();

        $outsider = $this->getDataGenerator()->create_user();
        $oid = $DB->insert_record('unics_students', (object)['mdl_user_id' => $outsider->id]);
        $tid = $DB->get_field('unics_teachers', 'id', ['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $oid]);

        $members = course_staff_view::class_members($course, $t->id);

        $this->assertSame([(int)$s1->id], $members, 'привязанный, но не записанный на курс - не в классе');
    }

    public function test_gate_is_false_for_child(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1] = $this->make_course_with_class();
        $this->setUser($s1);

        $this->assertFalse(course_staff_view::is_staff_view($course));
    }

    public function test_gate_is_true_for_teacher(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course_with_class();
        $this->setUser($t);

        $this->assertTrue(course_staff_view::is_staff_view($course));
    }

    /**
     * Регресс: методист организации ошибочно уходил в ветку привязок педагога, потому что
     * unics_user_manager::create_user() пишет строку в unics_teachers и для методиста (роль 4) -
     * см. докблок class_members(). Заводим методисту строку в unics_teachers НАРОЧНО (как в
     * проде) и НЕ заводим ни одной unics_teacher_student - если ветка выбирается по этой
     * таблице, класс будет пуст; должно быть ограничено скоупом (своя организация - виден,
     * чужая - нет).
     */
    public function test_methodist_sees_only_own_organization(): void {
        $this->resetAfterTest();
        global $DB;
        [$orga, $orgb] = $this->make_two_organizations();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);

        $own = $this->enrol_student_in_org($course, $orga);
        $this->enrol_student_in_org($course, $orgb);

        $methodist = $this->getDataGenerator()->create_user();
        $DB->insert_record('unics_user_org',
            (object)['mdl_user_id' => $methodist->id, 'organization_id' => $orga, 'unics_role' => 4]);
        $DB->insert_record('unics_teachers',
            (object)['mdl_user_id' => $methodist->id, 'organization_id' => $orga]);
        $this->assign_role('methodist', 'editingteacher', (int)$methodist->id);

        $members = course_staff_view::class_members($course, (int)$methodist->id);

        $this->assertSame([(int)$own->id], $members,
            'методист должен видеть только своих учеников по скоупу, а не пустой класс по unics_teachers');
    }

    /** Системный админ (local/unics:manage) - видит всех записанных на курс, без учета скоупа/привязок. */
    public function test_system_admin_sees_all_enrolled_students(): void {
        $this->resetAfterTest();
        [$course, $s1, $s2, ] = $this->make_course_with_class();

        $expected = [(int)$s1->id, (int)$s2->id];
        sort($expected);

        $members = course_staff_view::class_members($course, (int)get_admin()->id);

        $this->assertSame($expected, $members);
    }

    /**
     * Фикстура агрегатов: page (completion automatic) выполнена учеником s1, у s2 - нет;
     * открытый повтор темы у s1 на этой же page; оба ученика привязаны к педагогу.
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass,4:\cm_info}
     */
    private function make_course_with_signals(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 1, 'numsections' => 2]);

        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $t  = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($s2->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');

        $sid1 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $sid2 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s2->id]);
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid1]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid2]);

        $page = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);

        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $completion = new \completion_info($course);
        // update_state($cm, COMPLETION_COMPLETE, ...) НЕ годится для автоматического
        // completionview-условия: internal_get_state() пересчитывает состояние заново и
        // игнорирует переданный $possibleresult, пока «просмотр» не зафиксирован отдельно -
        // канонический способ отметить его в тестах (см. mod/page/tests/lib_test.php) -
        // set_module_viewed().
        $completion->set_module_viewed($cm, $s1->id);

        $DB->insert_record('unics_topic_retries', (object)[
            'mdl_user_id' => $s1->id, 'mdl_course_id' => $course->id, 'cmid' => (int)$page->cmid,
            'status' => 0, 'timecreated' => time(),
        ]);

        $modinfo = get_fast_modinfo($course);
        return [$course, $s1, $s2, $t, $modinfo->get_cm((int)$page->cmid)];
    }

    public function test_done_counts_only_class_members(): void {
        $this->resetAfterTest();
        [$course, , , $t, $cm] = $this->make_course_with_signals();

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame(2, $p['classSize']);
        $this->assertSame('сделали 1 из 2', $p['cms'][(string)$cm->id]['doneLabel']);
    }

    public function test_section_progress_counts_students(): void {
        $this->resetAfterTest();
        [$course, , , $t] = $this->make_course_with_signals();

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame(1, $p['sections']['1']['done']);
        $this->assertSame(2, $p['sections']['1']['total']);
        $this->assertSame('прошли тему: 1 из 2', $p['sections']['1']['label']);
    }

    public function test_open_topic_retry_counts_as_stuck(): void {
        $this->resetAfterTest();
        [$course, , , $t, $cm] = $this->make_course_with_signals();

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('застряли: 1', $p['cms'][(string)$cm->id]['stuckLabel']);
        $this->assertSame(1, $p['attention']['stuck']['count']);
        $this->assertSame('1 ученик застрял', $p['attention']['stuck']['label']);
    }

    public function test_resolved_retry_is_not_stuck(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, , , $t, $cm] = $this->make_course_with_signals();
        $DB->set_field('unics_topic_retries', 'status', 1, ['mdl_course_id' => $course->id]);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertNull($p['cms'][(string)$cm->id]['stuckLabel']);
        $this->assertNull($p['attention']['stuck']);
        $this->assertSame(\get_string('staff_all_clear', 'local_unics'), $p['strings']['allClear']);
    }

    public function test_empty_class_gives_empty_payload(): void {
        $this->resetAfterTest();
        [$course] = $this->make_course_with_signals();
        $stranger = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($stranger->id, $course->id, 'editingteacher');

        $p = \local_unics\output\course_staff_view::build_payload($course, $stranger->id);

        $this->assertSame(0, $p['classSize']);
        $this->assertSame([], $p['cms']);
        $this->assertSame([], $p['sections']);
    }

    /**
     * Дополнительные тесты сверх брифа: агрегат по mod_assign (grading_counts) и дедуп
     * «застрял в нескольких активностях» не покрыты фикстурой брифа (assign там не заводится,
     * а get_string топика открыт только на одной activity) - оба явно упомянуты в
     * требованиях самопроверки задачи, поэтому покрываю отдельно.
     */
    private function insert_assign_submission(int $assignid, int $userid, int $timemodified,
            int $attemptnumber = 0, string $status = 'submitted'): void {
        global $DB;
        $DB->insert_record('assign_submission', (object)[
            'assignment' => $assignid, 'userid' => $userid, 'timecreated' => $timemodified,
            'timemodified' => $timemodified, 'status' => $status, 'groupid' => 0,
            'attemptnumber' => $attemptnumber, 'latest' => 1,
        ]);
    }

    private function insert_assign_grade(int $assignid, int $userid, float $grade, int $timemodified,
            int $attemptnumber = 0): void {
        global $DB;
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assignid, 'userid' => $userid, 'timecreated' => $timemodified,
            'timemodified' => $timemodified, 'grader' => 0, 'grade' => $grade,
            'attemptnumber' => $attemptnumber,
        ]);
    }

    /**
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass,4:\stdClass} [курс, s1, s2, педагог, assign]
     */
    private function make_course_with_assign(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 1, 'numsections' => 1]);

        $s1 = $gen->create_user();
        $s2 = $gen->create_user();
        $t  = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($s2->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');

        $sid1 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $sid2 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s2->id]);
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid1]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid2]);

        $assign = $gen->create_module('assign', ['course' => $course->id, 'section' => 1]);

        return [$course, $s1, $s2, $t, $assign];
    }

    public function test_grading_counts_pending_submission_without_grade(): void {
        $this->resetAfterTest();
        [$course, $s1, , $t, $assign] = $this->make_course_with_assign();
        $this->insert_assign_submission((int)$assign->id, $s1->id, 1000);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('на проверке: 1', $p['cms'][(string)$assign->cmid]['gradingLabel']);
        $this->assertNotNull($p['cms'][(string)$assign->cmid]['gradingUrl']);
        $this->assertSame(1, $p['attention']['grading']['count']);
        $this->assertSame('1 работа ждет проверки', $p['attention']['grading']['label']);
    }

    public function test_grading_counts_excludes_submission_with_current_grade(): void {
        $this->resetAfterTest();
        [$course, $s1, , $t, $assign] = $this->make_course_with_assign();
        $this->insert_assign_submission((int)$assign->id, $s1->id, 1000);
        $this->insert_assign_grade((int)$assign->id, $s1->id, 80.0, 1010);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertNull($p['cms'][(string)$assign->cmid]['gradingLabel']);
        $this->assertNull($p['attention']['grading']);
    }

    public function test_grading_counts_ungraded_marker_still_pending(): void {
        // В mod_assign «нет оценки» хранится как grade = -1, а не отсутствие строки.
        $this->resetAfterTest();
        [$course, $s1, , $t, $assign] = $this->make_course_with_assign();
        $this->insert_assign_submission((int)$assign->id, $s1->id, 1000);
        $this->insert_assign_grade((int)$assign->id, $s1->id, -1.0, 1010);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('на проверке: 1', $p['cms'][(string)$assign->cmid]['gradingLabel']);
    }

    public function test_grading_counts_resubmission_after_grading_is_pending_again(): void {
        // Ученик пересдал работу (та же попытка) ПОСЛЕ того, как ее уже оценили -
        // timemodified отправки обогнал timemodified оценки, значит снова ждет проверки.
        $this->resetAfterTest();
        [$course, $s1, , $t, $assign] = $this->make_course_with_assign();
        $this->insert_assign_grade((int)$assign->id, $s1->id, 60.0, 1000);
        $this->insert_assign_submission((int)$assign->id, $s1->id, 2000);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('на проверке: 1', $p['cms'][(string)$assign->cmid]['gradingLabel']);
    }

    public function test_grading_counts_only_class_members(): void {
        // s2 не привязан к $t в этой фикстуре (только s1) - его отправка не должна считаться.
        $this->resetAfterTest();
        [$course, $s1, $s2, $t, $assign] = $this->make_course_with_assign();
        global $DB;
        $tid = $DB->get_field('unics_teachers', 'id', ['mdl_user_id' => $t->id]);
        $sid2 = $DB->get_field('unics_students', 'id', ['mdl_user_id' => $s2->id]);
        $DB->delete_records('unics_teacher_student', ['teacher_id' => $tid, 'student_id' => $sid2]);
        $this->insert_assign_submission((int)$assign->id, $s1->id, 1000);
        $this->insert_assign_submission((int)$assign->id, $s2->id, 1000);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame(1, $p['classSize']);
        $this->assertSame('на проверке: 1', $p['cms'][(string)$assign->cmid]['gradingLabel']);
    }

    /**
     * Регресс по ревью: непроверенная работа на СКРЫТОЙ от учеников активности не должна
     * попадать ни в cms (ее там вообще нет - активность не в $visible), ни в шапку. Контрольная
     * часть теста (submission на ВИДИМОМ задании) нужна, чтобы тест не проходил просто потому,
     * что весь агрегат обнулился - visible-задание обязано по-прежнему считаться.
     */
    public function test_grading_counts_excludes_hidden_activity(): void {
        $this->resetAfterTest();
        [$course, $s1, , $t, $visibleassign] = $this->make_course_with_assign();
        $hiddenassign = $this->getDataGenerator()->create_module('assign',
            ['course' => $course->id, 'section' => 1, 'visible' => 0]);
        // Непроверенная работа ТОЛЬКО на скрытом задании.
        $this->insert_assign_submission((int)$hiddenassign->id, $s1->id, 1000);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertArrayNotHasKey((string)$hiddenassign->cmid, $p['cms'],
            'скрытая активность не должна попадать на страницу вообще');
        $this->assertNull($p['attention']['grading'],
            'единственная непроверенная работа - на скрытом задании, в шапке ее быть не должно');

        // Контроль: та же непроверенная работа, но на ВИДИМОМ задании - обязана считаться.
        $this->insert_assign_submission((int)$visibleassign->id, $s1->id, 1000);
        $p2 = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('на проверке: 1', $p2['cms'][(string)$visibleassign->cmid]['gradingLabel']);
        $this->assertSame(1, $p2['attention']['grading']['count'],
            'скрытое задание по-прежнему не должно учитываться в общем счетчике');
        $this->assertNotNull($p2['attention']['grading']['url']);
    }

    /** Один ученик, застрявший СРАЗУ в двух активностях, в шапке считается один раз. */
    public function test_stuck_in_two_activities_counted_once_in_header(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t, $cm] = $this->make_course_with_signals();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'section' => 1]);
        $DB->insert_record('unics_retakes', (object)[
            'mdl_user_id' => $s1->id, 'mdl_course_id' => $course->id, 'cmid' => (int)$assign->cmid,
            'status' => 0, 'timecreated' => time(),
        ]);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('застряли: 1', $p['cms'][(string)$cm->id]['stuckLabel'], 'застрял на page (topic_retry)');
        $this->assertSame('застряли: 1', $p['cms'][(string)$assign->cmid]['stuckLabel'], 'застрял на assign (retake)');
        $this->assertSame(1, $p['attention']['stuck']['count'], 'один и тот же ученик - в шапке один раз');
    }

    /** Открытая пересдача И открытый повтор темы на ОДНОЙ активности у одного ученика - не задваивают счетчик. */
    public function test_stuck_dedup_same_activity_both_sources(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, $s1, , $t, $cm] = $this->make_course_with_signals();
        $DB->insert_record('unics_retakes', (object)[
            'mdl_user_id' => $s1->id, 'mdl_course_id' => $course->id, 'cmid' => (int)$cm->id,
            'status' => 0, 'timecreated' => time(),
        ]);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('застряли: 1', $p['cms'][(string)$cm->id]['stuckLabel']);
        $this->assertSame(1, $p['attention']['stuck']['count']);
    }

    /**
     * Регресс по ревью: открытый повтор темы на СКРЫТОЙ от учеников активности не должен
     * попадать ни в cms, ни в шапку. Застрявший на скрытой активности - ДРУГОЙ ученик (s2), а не
     * тот, что уже застрял на видимой (s1 - через фикстуру make_course_with_signals): дедуп шапки
     * по ученику иначе замаскировал бы баг - если бы фильтр по cmid не работал, header все равно
     * показал бы 1 (тот же s1), и тест был бы нечувствителен к регрессии. С разными учениками
     * "утекший" скрытый застрявший поднял бы count до 2, если фильтр сломан.
     */
    public function test_stuck_excludes_hidden_activity(): void {
        $this->resetAfterTest();
        global $DB;
        [$course, , $s2, $t, $cm] = $this->make_course_with_signals();
        $hidden = $this->getDataGenerator()->create_module('page',
            ['course' => $course->id, 'section' => 1, 'visible' => 0]);
        $DB->insert_record('unics_retakes', (object)[
            'mdl_user_id' => $s2->id, 'mdl_course_id' => $course->id, 'cmid' => (int)$hidden->cmid,
            'status' => 0, 'timecreated' => time(),
        ]);
        // Фикстура make_course_with_signals уже заводит открытый topic_retry у s1 на видимой $cm -
        // это и есть контрольная (видимая) часть, оба условия проверяются одним прогоном.

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertArrayNotHasKey((string)$hidden->cmid, $p['cms'],
            'скрытая активность не должна попадать на страницу вообще');
        $this->assertSame('застряли: 1', $p['cms'][(string)$cm->id]['stuckLabel'],
            'видимая активность по-прежнему должна учитываться');
        $this->assertSame(1, $p['attention']['stuck']['count'],
            'застрявший на скрытой активности (другой ученик) не должен попадать в счетчик шапки');
    }

    /** Курс без отслеживания выполнения (enablecompletion = 0) - без "прошли тему", без doneLabel. */
    public function test_course_without_completion_tracking(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 0, 'numsections' => 1]);
        $s1 = $gen->create_user();
        $t = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $sid1 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid1]);
        $page = $gen->create_module('page', ['course' => $course->id, 'section' => 1]);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame(1, $p['classSize']);
        $this->assertNull($p['cms'][(string)$page->cmid]['doneLabel']);
        $this->assertSame([], $p['sections'], 'без отслеживания курса тем "пройдена" не бывает');
    }

    /**
     * Секция с ДВУМЯ активностями: одна отслеживается, другая - нет (completion выключен на
     * уровне активности). "Прошли тему" должно опираться только на отслеживаемую активность,
     * а не требовать выполнения обеих (у второй completion в принципе невозможен).
     */
    public function test_untracked_activity_excluded_from_section_progress(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['format' => 'topics', 'enablecompletion' => 1, 'numsections' => 1]);
        $s1 = $gen->create_user();
        $t = $gen->create_user();
        $gen->enrol_user($s1->id, $course->id, 'student');
        $gen->enrol_user($t->id, $course->id, 'editingteacher');
        $sid1 = $DB->insert_record('unics_students', (object)['mdl_user_id' => $s1->id]);
        $tid = $DB->insert_record('unics_teachers', (object)['mdl_user_id' => $t->id]);
        $DB->insert_record('unics_teacher_student', (object)['teacher_id' => $tid, 'student_id' => $sid1]);

        $tracked = $gen->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $untracked = $gen->create_module('page', ['course' => $course->id, 'section' => 1]);

        $cm = get_coursemodule_from_id('page', $tracked->cmid, 0, false, MUST_EXIST);
        $ci = new \completion_info($course);
        $ci->set_module_viewed($cm, $s1->id);

        $p = \local_unics\output\course_staff_view::build_payload($course, $t->id);

        $this->assertSame('сделали 1 из 1', $p['cms'][(string)$tracked->cmid]['doneLabel']);
        $this->assertNull($p['cms'][(string)$untracked->cmid]['doneLabel']);
        $this->assertSame(1, $p['sections']['1']['done'], 'непроверяемая активность не должна блокировать "прошли тему"');
        $this->assertSame(1, $p['sections']['1']['total']);
    }
}
