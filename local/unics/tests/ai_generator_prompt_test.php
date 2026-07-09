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
}
