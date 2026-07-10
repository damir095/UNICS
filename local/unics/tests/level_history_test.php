<?php
namespace local_unics;

use local_unics\learning\adaptive_engine;

/**
 * Тесты записи истории уровней (фикс пробела process_ai_queue + DRY).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(adaptive_engine::class)]
final class level_history_test extends \advanced_testcase {

    /** Временный ученик: [unics_students.id, mdl_user_id]. */
    private function make_student(): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $sid  = $DB->insert_record('unics_students', (object)[
            'mdl_user_id'      => (int)$user->id,
            'difficulty_level' => 2,
        ]);
        return [(int)$sid, (int)$user->id];
    }

    public function test_record_level_history_writes_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();

        adaptive_engine::record_level_history($sid, $uid, 2, 3, 87.456);

        $row = $DB->get_record('unics_level_history', ['student_id' => $sid], '*', MUST_EXIST);
        $this->assertSame($uid, (int)$row->mdl_user_id);
        $this->assertSame(2, (int)$row->old_level);
        $this->assertSame(3, (int)$row->new_level);
        $this->assertEqualsWithDelta(87.46, (float)$row->avg_score, 0.001);
        $this->assertGreaterThan(0, (int)$row->changed_at);
    }

    public function test_record_level_history_null_avg(): void {
        global $DB;
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();

        adaptive_engine::record_level_history($sid, $uid, 3, 2, null);

        $row = $DB->get_record('unics_level_history', ['student_id' => $sid], '*', MUST_EXIST);
        $this->assertNull($row->avg_score);
    }

    public function test_apply_level_still_writes_history(): void {
        global $CFG, $DB;
        // Легаси-глобальный класс без неймспейса - автолоадер его не видит.
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();

        $this->redirectMessages();
        $this->redirectEvents();
        adaptive_engine::apply_level($sid, 1, 45.0); // понижение 2->1

        $row = $DB->get_record('unics_level_history', ['student_id' => $sid], '*', MUST_EXIST);
        $this->assertSame(2, (int)$row->old_level);
        $this->assertSame(1, (int)$row->new_level);
        $this->assertEqualsWithDelta(45.0, (float)$row->avg_score, 0.001);
    }
}
