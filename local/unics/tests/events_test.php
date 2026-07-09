<?php
namespace local_unics;

use local_unics\event\points_awarded;
use local_unics\event\points_spent;
use local_unics\event\level_changed;
use local_unics\event\umk_published;

/**
 * Тесты событий local_unics (этап 2.4 аудита): классы и эмиссия из менеджеров.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(points_awarded::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(points_spent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(level_changed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(umk_published::class)]
final class events_test extends \advanced_testcase {

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

    public function test_event_classes_create_and_describe(): void {
        $this->resetAfterTest();
        [$sid, $uid] = $this->make_student();
        $course = $this->getDataGenerator()->create_course();
        $sink = $this->redirectEvents();

        points_awarded::create([
            'context'       => \context_system::instance(),
            'objectid'      => 1,
            'relateduserid' => $uid,
            'other'         => ['student_id' => $sid, 'points' => 10, 'reason_type' => 4],
        ])->trigger();
        points_spent::create([
            'context'       => \context_system::instance(),
            'objectid'      => 2,
            'relateduserid' => $uid,
            'other'         => ['student_id' => $sid, 'points' => 30],
        ])->trigger();
        level_changed::create([
            'context'       => \context_system::instance(),
            'objectid'      => $sid,
            'relateduserid' => $uid,
            'other'         => ['old_level' => 2, 'new_level' => 1, 'source' => 'apply'],
        ])->trigger();
        umk_published::create([
            'context'  => \context_course::instance($course->id),
            'objectid' => 123,
            'other'    => ['title' => 'Тестовый УМК', 'topic' => 'Тема'],
        ])->trigger();

        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(4, $events);
        foreach ($events as $e) {
            $this->assertNotSame('', (string)$e->get_name());
            $this->assertNotSame('', (string)$e->get_description());
        }
        $this->assertStringContainsString("#{$sid}", $events[2]->get_description());
        $this->assertStringContainsString('apply', $events[2]->get_description());
        $this->assertStringContainsString('Тестовый УМК', $events[3]->get_description());
    }

    public function test_missing_other_keys_rejected(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        points_awarded::create([
            'context'       => \context_system::instance(),
            'objectid'      => 1,
            'relateduserid' => 2,
            'other'         => ['points' => 5], // нет student_id/reason_type
        ]);
    }
}
