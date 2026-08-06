<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Адаптационный блок промта ([[adaptation-full-kit-design]]).
 *
 * До этой задачи профиль ребенка (эффективный уровень, категория, инструкции по типам ОВЗ,
 * особенности) доходил ТОЛЬКО до учебного текста. Тест, задание и видео читали из профиля два
 * поля - класс и сырой difficulty_level, - поэтому ребенок с ЗПР получал адаптированный текст и
 * неадаптированную проверку знаний по нему же.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class adaptation_block_test extends \advanced_testcase {

    public function test_block_contains_profile_lines(): void {
        $gen = new ai_generator();
        $criteria = $gen->build_criteria(['categories' => [1], 'ovz_types' => [4],
            'difficulty_level' => 3, 'class_number' => 7, 'avg_score' => 40.0]);

        $block = $gen->adaptation_block($criteria);

        $this->assertStringContainsString('Профиль учащегося:', $block);
        $this->assertStringContainsString('- Категория: ОВЗ', $block);
        $this->assertStringContainsString('- Уровень подготовки: стандартный', $block);
        $this->assertStringContainsString('- Средний балл за последние 5 тестов: менее 50%', $block);
        $this->assertStringContainsString('Особые указания:', $block);
        $this->assertStringContainsString('Очень короткие абзацы', $block);
    }

    /** Пустой список особых указаний не должен оставлять висящий заголовок. */
    public function test_block_without_special_parts_has_no_heading(): void {
        $gen = new ai_generator();

        $block = $gen->adaptation_block([
            'category_label' => 'стандартный',
            'level_label'    => 'базовый',
            'avg_band'       => '50-85%',
            'special_parts'  => [],
        ]);

        $this->assertStringNotContainsString('Особые указания', $block);
        $this->assertStringContainsString('Профиль учащегося:', $block);
    }
}
