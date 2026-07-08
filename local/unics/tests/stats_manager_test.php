<?php
namespace local_unics;

use local_unics\analytics\stats_manager;

/**
 * Тесты чистой агрегатной логики статистики (этап 5.2 аудита).
 * aggregate()/totals()/format_minutes() не ходят в БД - строки собираются руками.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(stats_manager::class)]
final class stats_manager_test extends \basic_testcase {

    /** Строка статистики с дефолтами (форма stats_manager::get_student_rows). */
    private function row(array $over = []): \stdClass {
        return (object)array_merge([
            'views'          => 0,
            'time_est_min'   => 0,
            'completed'      => 0,
            'total'          => 0,
            'attempts'       => 0,
            'ai_uses'        => 0,
            'level_changes'  => 0,
            'avg_score_pct'  => null,
            'last_active_at' => null,
        ], $over);
    }

    public function test_totals_counts_and_averages(): void {
        $rows = [
            $this->row(['avg_score_pct' => 80, 'completed' => 2, 'total' => 4,
                        'views' => 10, 'last_active_at' => time()]),
            $this->row(['avg_score_pct' => null, 'completed' => 1, 'total' => 1,
                        'views' => 5, 'last_active_at' => time() - 30 * 86400]),
        ];
        $t = stats_manager::totals($rows);
        $this->assertSame(2, $t->n_students);
        // Средний балл - только по строкам с баллом (null не размывает среднее).
        $this->assertSame(80.0, $t->avg_score);
        // Завершаемость - по суммам: (2+1)/(4+1).
        $this->assertSame(60.0, $t->completion_pct);
        $this->assertSame(15, $t->sum_views);
        // Активен за 14 дней - только первый.
        $this->assertSame(1, $t->n_active);
    }

    public function test_totals_empty(): void {
        $t = stats_manager::totals([]);
        $this->assertSame(0, $t->n_students);
        $this->assertNull($t->avg_score);
        $this->assertNull($t->completion_pct);
    }

    public function test_aggregate_grouping_and_multikeys(): void {
        $rows = [
            $this->row(['avg_score_pct' => 100]),
            $this->row(['avg_score_pct' => 50]),
            $this->row(['avg_score_pct' => 10]),
        ];
        // Классификатор: 1-я строка в A, 2-я в обе группы (мультиключ), 3-я пропущена.
        $keys = [['A'], ['A', 'B'], null];
        $i = 0;
        $aggs = stats_manager::aggregate($rows, function ($r) use (&$i, $keys) {
            return $keys[$i++];
        });
        $this->assertSame(['A', 'B'], array_keys($aggs));
        $this->assertSame(2, $aggs['A']->n_students);
        $this->assertSame(1, $aggs['B']->n_students);
        $this->assertSame(75.0, $aggs['A']->avg_score);
        $this->assertSame(50.0, $aggs['B']->avg_score);
    }

    public function test_aggregate_skips_empty_keys(): void {
        $rows = [$this->row(), $this->row()];
        $aggs = stats_manager::aggregate($rows, fn($r) => '');
        $this->assertSame([], $aggs);
    }

    public function test_format_minutes(): void {
        $this->assertSame('0 мин', stats_manager::format_minutes(0));
        $this->assertSame('0 мин', stats_manager::format_minutes(-5));
        $this->assertSame('45 мин', stats_manager::format_minutes(45));
        $this->assertSame('2 ч', stats_manager::format_minutes(120));
        $this->assertSame('2 ч 15 мин', stats_manager::format_minutes(135));
    }
}
