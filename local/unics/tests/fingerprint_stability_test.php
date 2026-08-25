<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\profile_fingerprint;

/**
 * Отпечаток профиля не зависит от формулировок промта ([[criteria-conflicts-design]], раздел 7).
 *
 * Найдено ревью 2026-08-25. В ключ шел ВЕСЬ массив критериев, включая special_parts - готовые
 * человекочитаемые указания. Любая редактура текста («добавь углублённые факты» -> «дай
 * нестандартный угол зрения») сдвигала каждый сохраненный profile_key, а из первых восьми
 * символов ключа строится idnumber группы доступа: повторная генерация той же темы не находила
 * прежнюю группу, заводила «вариант 2», и ребенок оказывался в обеих - с двумя комплектами по
 * одной теме. На стенде правка формулировок развела 6 ключей из 8.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(profile_fingerprint::class)]
final class fingerprint_stability_test extends \advanced_testcase {

    private function profile(array $over = []): array {
        return $over + ['categories' => [1], 'ovz_types' => [4], 'difficulty_level' => 2,
                        'class_number' => 7, 'class_letter' => 'А', 'avg_score' => 70.0,
                        'special_needs' => ''];
    }

    /** Генератор с подмененными формулировками указаний - как будто их отредактировали. */
    private function reworded(): ai_generator {
        return new class extends ai_generator {
            public function build_criteria(array $profile): array {
                $c = parent::build_criteria($profile);
                // Текст указаний переписан целиком, структура профиля та же.
                $c['special_parts'] = array_map(
                    static fn(string $s): string => 'ПЕРЕПИСАНО: ' . $s, $c['special_parts']);
                $c['special_parts_items'] = array_map(
                    static fn(string $s): string => 'ПЕРЕПИСАНО: ' . $s, $c['special_parts_items']);
                $c['level_label'] = 'иначе названный уровень';
                $c['category_label'] = 'иначе названная категория';
                return $c;
            }
        };
    }

    public function test_rewording_instructions_does_not_change_the_key(): void {
        // Главная проверка: правка текста промта не должна ломать группировку и плодить
        // группы доступа.
        $p = $this->profile();

        $before = profile_fingerprint::key($p, new ai_generator());
        $after  = profile_fingerprint::key($p, $this->reworded());

        $this->assertSame($before, $after,
            'редактура формулировок обязана оставлять ключ прежним');
    }

    public function test_different_ovz_types_still_differ(): void {
        // Обратная сторона: схлопывать разные профили в один комплект нельзя.
        $zpr = profile_fingerprint::key($this->profile(['ovz_types' => [4]]));
        $ras = profile_fingerprint::key($this->profile(['ovz_types' => [5]]));

        $this->assertNotSame($zpr, $ras, 'ЗПР и РАС - разные профили');
    }

    public function test_different_categories_still_differ(): void {
        $ovz    = profile_fingerprint::key($this->profile(['categories' => [1]]));
        $gifted = profile_fingerprint::key($this->profile(['categories' => [4]]));

        $this->assertNotSame($ovz, $gifted);
    }

    public function test_special_needs_still_separates_children(): void {
        // Свободное поле педагога - про самого ребенка. Двое детей с разными особенностями
        // не должны получить один комплект.
        $a = profile_fingerprint::key($this->profile(['special_needs' => 'нужен перерыв']));
        $b = profile_fingerprint::key($this->profile(['special_needs' => 'путает лево и право']));
        $none = profile_fingerprint::key($this->profile());

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $none);
    }

    public function test_effective_level_separates_children(): void {
        // Уровень, скорректированный по баллу, - структурный признак: по нему расходится
        // и объем, и сложность.
        $low  = profile_fingerprint::key($this->profile(['difficulty_level' => 3, 'avg_score' => 40.0]));
        $high = profile_fingerprint::key($this->profile(['difficulty_level' => 3, 'avg_score' => 90.0]));

        $this->assertNotSame($low, $high);
    }

    public function test_same_band_same_key(): void {
        // Полоса, а не число: два балла внутри одной полосы дают один комплект - ради этого
        // схлопывание и заведено.
        $a = profile_fingerprint::key($this->profile(['avg_score' => 60.0]));
        $b = profile_fingerprint::key($this->profile(['avg_score' => 75.0]));

        $this->assertSame($a, $b);
    }

    public function test_class_separates_children(): void {
        $seven = profile_fingerprint::key($this->profile(['class_number' => 7]));
        $eight = profile_fingerprint::key($this->profile(['class_number' => 8]));

        $this->assertNotSame($seven, $eight);
    }

    public function test_key_is_a_sha1(): void {
        $this->assertMatchesRegularExpression('~^[0-9a-f]{40}$~',
            profile_fingerprint::key($this->profile()));
    }
}
