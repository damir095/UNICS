<?php
namespace local_unics;

use local_unics\health\check_result;
use local_unics\health\health_report;

/**
 * Сборка отчета здоровья: сводный уровень и кеш полосы тревоги.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(health_report::class)]
final class health_report_test extends \advanced_testcase {

    public function test_worst_picks_highest_level(): void {
        $this->resetAfterTest();
        $results = [
            'a' => check_result::ok('норма'),
            'b' => check_result::attention('есть ошибки', 'посмотрите историю'),
            'c' => check_result::ok('норма'),
        ];
        $this->assertSame(check_result::ATTENTION, health_report::worst($results));

        $results['d'] = check_result::alarm('все встало', 'включите cron');
        $this->assertSame(check_result::ALARM, health_report::worst($results));
    }

    public function test_worst_of_empty_is_ok(): void {
        $this->resetAfterTest();
        $this->assertSame(check_result::OK, health_report::worst([]));
    }

    public function test_cheap_runs_only_cheap_checks(): void {
        $this->resetAfterTest();
        foreach (health_report::checks() as $check) {
            if (!$check->is_cheap()) {
                $this->assertArrayNotHasKey($check->name(), health_report::cheap(),
                    'дорогая проверка не должна попадать в дешевый прогон: ' . $check->name());
            }
        }
        $this->assertArrayHasKey('cron_freshness', health_report::cheap());
    }

    /** Полосу поднимает только авария: «внимание» в нее не попадает. */
    public function test_alarms_exclude_attention(): void {
        global $DB;
        $this->resetAfterTest();
        // Свежий cron - аварии нет; одна ошибка генерации - это «внимание».
        $DB->execute('UPDATE {task_scheduled} SET lastruntime = ?', [time() - 60]);
        $DB->insert_record('unics_ai_queue', (object)[
            'umk_id' => 0, 'student_ids' => json_encode([]), 'generate_text' => 1,
            'generate_audio' => 0, 'generate_quiz' => 0, 'generate_assignment' => 0,
            'generate_video' => 0, 'generate_images' => 0,
            'status' => \local_unics\ai\ai_queue::STATUS_FAILED, 'created_at' => time(),
        ]);

        $this->assertSame([], health_report::alarms(),
            'ошибки в истории не должны поднимать полосу тревоги');
    }

    public function test_alarms_catch_dead_cron(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->execute('UPDATE {task_scheduled} SET lastruntime = ?', [time() - 40 * DAYSECS]);

        $alarms = health_report::alarms();

        $this->assertArrayHasKey('cron_freshness', $alarms);
        $this->assertSame(check_result::ALARM, $alarms['cron_freshness']->level);
    }

    public function test_selected_but_missing_estimator_is_alarm(): void {
        $this->resetAfterTest();
        set_config('mastery_estimator', 'unicsest_такогонет', 'local_unics');

        $r = (new \local_unics\health\checks\estimator_sanity())->run();

        $this->assertSame(check_result::ALARM, $r->level);
    }

    public function test_builtin_estimator_is_ok(): void {
        $this->resetAfterTest();
        set_config('mastery_estimator', '', 'local_unics');

        $this->assertSame(check_result::OK,
            (new \local_unics\health\checks\estimator_sanity())->run()->level);
    }
}
