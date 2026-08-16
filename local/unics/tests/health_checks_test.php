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
}
