<?php
namespace local_unics;

use local_unics\health\check_result;
use local_unics\health\checks\cron_freshness;

/**
 * Дешевые проверки здоровья: запросы к своей БД, считаются в том числе для полосы тревоги.
 * Пороги и уровни выведены из реальных инцидентов, см. [[health-page-design]].
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cron_freshness::class)]
final class health_checks_test extends \advanced_testcase {

    /** Проставить всем плановым задачам время последнего прогона. */
    private function set_last_cron(int $when): void {
        global $DB;
        $DB->execute('UPDATE {task_scheduled} SET lastruntime = ?', [$when]);
    }

    public function test_fresh_cron_is_ok(): void {
        $this->resetAfterTest();
        $this->set_last_cron(time() - 60);

        $r = (new cron_freshness())->run();

        $this->assertSame(check_result::OK, $r->level);
    }

    public function test_stale_cron_is_alarm_and_says_what_to_do(): void {
        $this->resetAfterTest();
        $this->set_last_cron(time() - 40 * DAYSECS);

        $r = (new cron_freshness())->run();

        $this->assertSame(check_result::ALARM, $r->level);
        // Человеку без PHP нужно ДЕЙСТВИЕ, а не код ошибки.
        $this->assertNotSame('', $r->action);
        $this->assertStringContainsString('Планировщик', $r->action);
    }

    public function test_never_run_cron_is_alarm(): void {
        $this->resetAfterTest();
        $this->set_last_cron(0);

        $this->assertSame(check_result::ALARM, (new cron_freshness())->run()->level);
    }

    /** Граница: ровно на пороге еще порядок, за порогом уже авария. */
    public function test_threshold_boundary(): void {
        $this->resetAfterTest();
        $this->set_last_cron(time() - cron_freshness::STALE_AFTER + 5);
        $this->assertSame(check_result::OK, (new cron_freshness())->run()->level);

        $this->set_last_cron(time() - cron_freshness::STALE_AFTER - 5);
        $this->assertSame(check_result::ALARM, (new cron_freshness())->run()->level);
    }

    public function test_check_is_cheap(): void {
        $this->resetAfterTest();
        // Дешевая = считается на каждой странице для полосы, значит только свои таблицы.
        $this->assertTrue((new cron_freshness())->is_cheap());
    }

    /** Строка очереди УМК в нужном статусе и возрасте. */
    private function make_queue_row(int $status, int $agesec): int {
        global $DB;
        return (int)$DB->insert_record('unics_ai_queue', (object)[
            'umk_id'              => 0,
            'student_ids'         => json_encode([]),
            'generate_text'       => 1,
            'generate_audio'      => 0,
            'generate_quiz'       => 0,
            'generate_assignment' => 0,
            'generate_video'      => 0,
            'generate_images'     => 0,
            'status'              => $status,
            'created_at'          => time() - $agesec,
        ]);
    }

    public function test_empty_queue_is_ok(): void {
        $this->resetAfterTest();
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_backlog())->run()->level);
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_stuck())->run()->level);
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_failures())->run()->level);
    }

    public function test_fresh_pending_is_ok_but_old_pending_is_alarm(): void {
        $this->resetAfterTest();
        // Свежая заявка - норма: воркер стартует не мгновенно.
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_PENDING, 60);
        $this->assertSame(check_result::OK, (new \local_unics\health\checks\ai_queue_backlog())->run()->level);

        // Старая ждущая - значит дренаж не идет.
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_PENDING, 3600);
        $r = (new \local_unics\health\checks\ai_queue_backlog())->run();
        $this->assertSame(check_result::ALARM, $r->level);
        $this->assertNotSame('', $r->action);
    }

    public function test_long_processing_is_alarm(): void {
        $this->resetAfterTest();
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_PROCESSING, 7 * 3600);
        $this->assertSame(check_result::ALARM, (new \local_unics\health\checks\ai_queue_stuck())->run()->level);
    }

    /** Ошибки в истории - это «внимание», а не «авария»: полоса не должна гореть всегда. */
    public function test_failures_are_attention_not_alarm(): void {
        $this->resetAfterTest();
        $this->make_queue_row(\local_unics\ai\ai_queue::STATUS_FAILED, 10 * DAYSECS);
        $r = (new \local_unics\health\checks\ai_queue_failures())->run();
        $this->assertSame(check_result::ATTENTION, $r->level);
        $this->assertStringContainsString('1', $r->summary);
    }

    public function test_old_adhoc_task_is_alarm(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('task_adhoc', (object)[
            'component'     => 'local_unics',
            'classname'     => '\local_unics\task\send_new_assign_notification',
            'nextruntime'   => time() - 2 * DAYSECS,
            'faildelay'     => 0,
            'customdata'    => json_encode(['cmid' => 1]),
            'timecreated'   => time() - 2 * DAYSECS,
        ]);
        $this->assertSame(check_result::ALARM, (new \local_unics\health\checks\adhoc_backlog())->run()->level);
    }
}
