<?php
namespace local_unics;

use local_unics\social\achievement_manager;

/**
 * Тест прогресса значков achievement_manager::get_badge_progress + гард переписанных
 * check_* через evaluate_student ([[badge-progress-design]], мотивация этап 3, срез 4).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_unics\social\achievement_manager::class)]
final class achievement_manager_test extends \advanced_testcase {

    private $gen;

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->gen = $this->getDataGenerator();
    }

    /** Ученик (unics_students) + mdl-пользователь. Возврат [student_id, mdl_user_id]. */
    private function make_student(): array {
        global $DB;
        $u = $this->gen->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)['mdl_user_id' => $u->id]);
        return [$sid, (int)$u->id];
    }

    /** Записать ученика на новый курс. */
    private function enrol_new_course(int $uid): void {
        $c = $this->gen->create_course();
        $this->gen->enrol_user($uid, $c->id, 'student');
    }

    /** Создать тест в курсе и выставить ученику сырой балл (grademax=100). */
    private function graded_quiz(int $uid, float $raw): void {
        $c = $this->gen->create_course();
        $this->gen->enrol_user($uid, $c->id, 'student');
        $quiz = $this->gen->create_module('quiz', ['course' => $c->id, 'grade' => 100]);
        grade_update('mod/quiz', $c->id, 'mod', 'quiz', $quiz->id, 0,
            ['userid' => $uid, 'rawgrade' => $raw]);
    }

    public function test_active_count_gate(): void {
        [$sid, $uid] = $this->make_student();
        $this->enrol_new_course($uid);
        $this->enrol_new_course($uid); // 2 курса из 3

        $p = achievement_manager::get_badge_progress($sid, $uid);
        $a = $p[achievement_manager::BADGE_ACTIVE];
        $this->assertFalse($a['earned']);
        $this->assertSame('count', $a['unit']);
        $this->assertSame(2, $a['current']);
        $this->assertSame(3, $a['target']);
        $this->assertSame(67, $a['pct']); // round(2/3*100)
    }

    public function test_completer_gate_and_earned_flag(): void {
        global $DB;
        [$sid, $uid] = $this->make_student();

        // Нет сданных тестов -> прогресс 0/1.
        $p = achievement_manager::get_badge_progress($sid, $uid);
        $c = $p[achievement_manager::BADGE_COMPLETER];
        $this->assertFalse($c['earned']);
        $this->assertSame('count', $c['unit']);
        $this->assertSame(0, $c['current']);
        $this->assertSame(1, $c['target']);
        $this->assertSame(0, $c['pct']);

        // Явно проставленный значок -> earned:true, pct:100.
        $DB->insert_record('unics_achievements', (object)[
            'student_id' => $sid, 'badge_type' => achievement_manager::BADGE_COMPLETER,
            'awarded_at' => time(), 'awarded_by' => 0, 'note' => 'test',
        ]);
        $p2 = achievement_manager::get_badge_progress($sid, $uid);
        $c2 = $p2[achievement_manager::BADGE_COMPLETER];
        $this->assertTrue($c2['earned']);
        $this->assertSame(100, $c2['pct']);
        $this->assertNull($c2['unit']);
    }

    public function test_diligent_count_then_pct_gate(): void {
        [$sid, $uid] = $this->make_student();

        // 3 теста (<5) -> count-воротце к 5.
        $this->graded_quiz($uid, 90.0);
        $this->graded_quiz($uid, 90.0);
        $this->graded_quiz($uid, 90.0);
        $d = achievement_manager::get_badge_progress($sid, $uid)[achievement_manager::BADGE_DILIGENT];
        $this->assertSame('count', $d['unit']);
        $this->assertSame(3, $d['current']);
        $this->assertSame(5, $d['target']);

        // Ещё 2 теста -> всего 5, avg 90 -> pct-воротце к 85.
        $this->graded_quiz($uid, 90.0);
        $this->graded_quiz($uid, 90.0);
        $d2 = achievement_manager::get_badge_progress($sid, $uid)[achievement_manager::BADGE_DILIGENT];
        $this->assertSame('pct', $d2['unit']);
        $this->assertSame(90, $d2['current']);
        $this->assertSame(85, $d2['target']);
        $this->assertSame(100, $d2['pct']); // clamp: round(90/85*100)=106 -> 100
    }

    public function test_evaluate_student_still_awards_after_refactor(): void {
        // Гард: переписанные check_* сохранили поведение.
        [$sid, $uid] = $this->make_student();
        $this->enrol_new_course($uid);
        $this->enrol_new_course($uid);
        $this->enrol_new_course($uid); // 3 курса -> Активный
        $this->graded_quiz($uid, 70.0); // >=60% -> Завершитель

        $awarded = achievement_manager::evaluate_student($sid, $uid);
        $this->assertContains(achievement_manager::BADGE_ACTIVE, $awarded);
        $this->assertContains(achievement_manager::BADGE_COMPLETER, $awarded);
    }
}
