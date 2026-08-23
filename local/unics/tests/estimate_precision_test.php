<?php
namespace local_unics;

use local_unics\adaptive\estimate_precision;

/**
 * Признак предварительной оценки ([[cat-honest-precision]]).
 *
 * Живой заход 2026-08-22: проверка кончилась на четырех заданиях с se = 0.769 при пороге 0.30,
 * а ребенку показали полосу «почти» как готовый результат.
 *
 * @package local_unics
 */
final class estimate_precision_test extends \advanced_testcase {

    public function test_high_error_is_provisional(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $this->assertTrue(estimate_precision::is_provisional(0.769));
    }

    public function test_error_within_threshold_is_final(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $this->assertFalse(estimate_precision::is_provisional(0.28));
    }

    public function test_error_exactly_at_threshold_is_provisional(): void {
        // Сервис останавливается по «se СТРОГО меньше порога» (ai-service/app/cat.py), значит
        // ровно на пороге точность НЕ достигнута и проверка кончилась по другой причине.
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $this->assertTrue(estimate_precision::is_provisional(0.3));
    }

    public function test_recomputed_score_is_not_an_irt_estimate(): void {
        // theta и theta_se переживают пересчет обычным путем: после нескольких тестов в записи
        // лежит балл, снятый процентами, и стандартная ошибка давнего прохождения CAT.
        $this->assertFalse(estimate_precision::is_irt_estimate(0.5855, 91.0),
            'балл 91 не может быть проекцией theta 0.59');
    }

    public function test_irt_score_is_recognized(): void {
        $theta = 0.5855;
        $score = \local_unics\adaptive\theta_scale::project($theta);
        $this->assertTrue(estimate_precision::is_irt_estimate($theta, $score));
    }

    public function test_missing_theta_is_not_an_irt_estimate(): void {
        $this->assertFalse(estimate_precision::is_irt_estimate(null, 64.23));
    }

    public function test_no_error_means_no_claim(): void {
        // Оценка не через IRT (обычный путь rolling_avg): о точности говорить нечего,
        // и объявлять такую оценку предварительной нельзя.
        $this->assertFalse(estimate_precision::is_provisional(null));
    }

    public function test_threshold_comes_from_settings(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.9, 'local_unics');
        $this->assertFalse(estimate_precision::is_provisional(0.769),
            'при мягком пороге та же ошибка перестает быть предварительной');
        set_config('cat_se_threshold', 0.1, 'local_unics');
        $this->assertTrue(estimate_precision::is_provisional(0.15));
    }

    public function test_unset_threshold_falls_back_to_default(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0, 'local_unics');
        $this->assertSame(estimate_precision::DEFAULT_SE_THRESHOLD, estimate_precision::threshold());
        $this->assertTrue(estimate_precision::is_provisional(0.4));
    }

    public function test_child_note_has_no_numbers(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $note = estimate_precision::child_note(0.769);
        $this->assertNotSame('', $note);
        // Класс [0-9], а не \d: под Moodle-тестами локаль подменяет таблицы PCRE, и байтовый
        // \d совпадает с байтами кириллицы - проверка «нет цифр» краснела на чистой строке.
        $this->assertDoesNotMatchRegularExpression('~[0-9]~u', $note,
            'ребенку нужна причина, а не доверительный интервал');
    }

    public function test_child_note_is_empty_for_a_final_estimate(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $this->assertSame('', estimate_precision::child_note(0.2));
    }

    public function test_staff_note_carries_both_numbers(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $note = estimate_precision::staff_note(0.769);
        $this->assertStringContainsString('0,77', $note);
        $this->assertStringContainsString('0,30', $note);
    }

    public function test_staff_note_is_empty_for_a_final_estimate(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        $this->assertSame('', estimate_precision::staff_note(0.1));
    }
}
