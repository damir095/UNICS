<?php
namespace local_unics;

use local_unics\ai\ai_queue;

/**
 * Тесты жизненного цикла очереди ИИ-генерации ([[ai-queue-parallel-design]], 3.4 аудита):
 * постановка с adhoc-задачей, атомарный клейм, подметание и протухшие элементы.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_queue::class)]
final class ai_queue_test extends \advanced_testcase {

    /** Строка unics_umk + строка очереди (status по умолчанию PENDING). */
    private function make_queue_row(array $override = []): int {
        global $DB;
        $umkid = $DB->insert_record('unics_umk', (object)[
            'difficulty_level' => 1,
            'mdl_course_id'    => 1,
            'title'            => 'Тест-УМК',
            'topic'            => 'Тема',
            'status'           => 1,
            'generated_at'     => time(),
        ]);
        return (int)$DB->insert_record('unics_ai_queue', (object)array_merge([
            'umk_id'      => $umkid,
            'student_ids' => json_encode([1]),
            'status'      => ai_queue::STATUS_PENDING,
            'created_at'  => time(),
        ], $override));
    }

    public function test_enqueue_creates_row_and_adhoc_task(): void {
        global $DB;
        $this->resetAfterTest();

        $umkid = $DB->insert_record('unics_umk', (object)[
            'difficulty_level' => 2, 'mdl_course_id' => 1, 'title' => 'T', 'topic' => 'T',
            'status' => 1, 'generated_at' => time(),
        ]);

        $queueid = ai_queue::enqueue($umkid, [5, 7], [
            'generate_audio' => 1, 'generate_quiz' => 0, 'generate_assignment' => 0, 'generate_video' => 1,
        ]);

        $row = $DB->get_record('unics_ai_queue', ['id' => $queueid], '*', MUST_EXIST);
        $this->assertSame(ai_queue::STATUS_PENDING, (int)$row->status);
        $this->assertSame([5, 7], json_decode($row->student_ids, true));
        $this->assertSame(1, (int)$row->generate_audio);
        $this->assertSame(0, (int)$row->generate_quiz);
        $this->assertSame(1, (int)$row->generate_video);

        // Поставлена ровно одна adhoc-задача с нашим queueid.
        $adhoc = $DB->get_records('task_adhoc', ['classname' => '\\local_unics\\task\\process_umk_item']);
        $this->assertCount(1, $adhoc);
        $data = json_decode(reset($adhoc)->customdata);
        $this->assertSame($queueid, (int)$data->queueid);
    }

    public function test_claim_is_exclusive(): void {
        $this->resetAfterTest();
        $queueid = $this->make_queue_row();

        $first = ai_queue::claim($queueid);
        $this->assertNotNull($first);
        $this->assertSame(ai_queue::STATUS_PROCESSING, (int)$first->status);

        // Повторный клейм того же элемента - занято.
        $this->assertNull(ai_queue::claim($queueid));
    }

    public function test_claim_refuses_non_pending_and_missing(): void {
        $this->resetAfterTest();
        $done = $this->make_queue_row(['status' => ai_queue::STATUS_DONE]);

        $this->assertNull(ai_queue::claim($done));
        $this->assertNull(ai_queue::claim(999999));
    }

    public function test_sweep_pending_skips_fresh_rows(): void {
        $this->resetAfterTest();
        $fresh = $this->make_queue_row(['created_at' => time()]);
        $old   = $this->make_queue_row(['created_at' => time() - 600]);
        $done  = $this->make_queue_row(['created_at' => time() - 600, 'status' => ai_queue::STATUS_DONE]);

        $ids = ai_queue::sweep_pending(300, 15);

        $this->assertContains($old, $ids);
        $this->assertNotContains($fresh, $ids);
        $this->assertNotContains($done, $ids);
    }

    public function test_fail_stale_processing(): void {
        global $DB;
        $this->resetAfterTest();
        // «Протухший» в работе (клеймнут 7 часов назад) и свежий в работе.
        $stale = $this->make_queue_row(['status' => ai_queue::STATUS_PROCESSING, 'created_at' => time() - 7 * 3600]);
        $live  = $this->make_queue_row(['status' => ai_queue::STATUS_PROCESSING, 'created_at' => time() - 600]);

        $count = ai_queue::fail_stale_processing(6 * 3600);

        $this->assertSame(1, $count);
        $this->assertSame(ai_queue::STATUS_FAILED, (int)$DB->get_field('unics_ai_queue', 'status', ['id' => $stale]));
        $this->assertSame(ai_queue::STATUS_PROCESSING, (int)$DB->get_field('unics_ai_queue', 'status', ['id' => $live]));
        $this->assertStringContainsString('прервана', (string)$DB->get_field('unics_ai_queue', 'error_message', ['id' => $stale]));
    }
}
