<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Golden-тесты промта УМК (A3): рефакторинг build_criteria/build_prompt
 * не должен менять замороженную строку промта ни на байт.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class ai_generator_prompt_test extends \advanced_testcase {

    public function test_build_prompt_matches_golden_fixtures(): void {
        $fixtures = require(__DIR__ . '/fixtures/umk_prompt_fixtures.php');
        $this->assertNotEmpty($fixtures);
        $gen = new ai_generator();
        foreach ($fixtures as $name => $f) {
            $this->assertSame($f['expected'],
                $gen->build_prompt($f['profile'], $f['topic'], $f['extra']),
                "Промт изменился для кейса: {$name}");
        }
    }

    public function test_build_criteria_structure_and_semantics(): void {
        $gen = new ai_generator();

        // Понижение уровня при низком среднем + ОВЗ-инструкции.
        $c = $gen->build_criteria(['categories' => [1], 'ovz_types' => [4],
            'difficulty_level' => 2, 'class_number' => 6, 'avg_score' => 40.0]);
        foreach (['base_level', 'eff_level', 'level_label', 'level_change_reason',
                  'avg_score', 'class_str', 'category_ids', 'category_label',
                  'ovz_type_ids', 'ovz_labels', 'word_count', 'special_parts'] as $key) {
            $this->assertArrayHasKey($key, $c);
        }
        $this->assertSame(2, $c['base_level']);
        $this->assertSame(1, $c['eff_level']);
        $this->assertNotNull($c['level_change_reason']);
        $this->assertSame('300–400', $c['word_count']);
        $this->assertContains('задержка психического развития (ЗПР)', $c['ovz_labels']);

        // Лечение ограничивает объем сильнее уровня; без смены уровня причина null.
        $c2 = $gen->build_criteria(['categories' => [3], 'difficulty_level' => 3,
            'class_number' => 9, 'avg_score' => 75.0]);
        $this->assertSame('250–350', $c2['word_count']);
        $this->assertNull($c2['level_change_reason']);
    }

    /**
     * Балл огрубляется до полосы: без этого отпечаток профиля не схлопывал бы никого -
     * совпадение среднего балла до процента редкость ([[umk-per-student-design]], раздел 5).
     * Границы берутся те же, по которым уже работает adapt_level().
     */
    public function test_avg_band_replaces_exact_score(): void {
        $gen  = new ai_generator();
        $base = ['categories' => [2], 'difficulty_level' => 2, 'class_number' => 7];

        $this->assertSame('менее 50%', $gen->build_criteria($base + ['avg_score' => 42.0])['avg_band']);
        $this->assertSame('50-85%',    $gen->build_criteria($base + ['avg_score' => 71.0])['avg_band']);
        $this->assertSame('более 85%', $gen->build_criteria($base + ['avg_score' => 90.0])['avg_band']);

        // Сами границы 50 и 85 принадлежат средней полосе - ровно как в adapt_level().
        $this->assertSame('50-85%', $gen->build_criteria($base + ['avg_score' => 50.0])['avg_band']);
        $this->assertSame('50-85%', $gen->build_criteria($base + ['avg_score' => 85.0])['avg_band']);

        // Точного числа в промте больше нет.
        $prompt = $gen->build_prompt($base + ['avg_score' => 71.0], 'Дроби');
        $this->assertStringContainsString('Средний балл за последние 5 тестов: 50-85%', $prompt);
        $this->assertStringNotContainsString('71%', $prompt);

        // Причина смены уровня тоже без точного числа.
        $low = $gen->build_criteria($base + ['avg_score' => 42.0]);
        $this->assertStringContainsString('менее 50%', $low['level_change_reason']);
        $this->assertStringNotContainsString('42', $low['level_change_reason']);
    }
}
