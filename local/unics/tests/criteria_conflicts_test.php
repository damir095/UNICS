<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Особые указания не должны противоречить друг другу ([[criteria-conflicts-design]]).
 *
 * Найдено живым заходом в превью генерации 2026-08-24: у одаренного ученика с баллом ниже 50%
 * рядом стояли «Добавь углублённые факты, нестандартный угол зрения» и «материал должен быть
 * проще базового». Модель получала взаимоисключающие требования по ОДНОЙ оси - сложности - и
 * мирила их наугад.
 *
 * Корень: сложностью командует уровень (промт учебного текста прямо говорит «Сложность строго
 * соответствует уровню»), а категория «одаренный» лезла в ту же ось. Категория должна задавать
 * ТИП познавательной задачи, а не ее сложность.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class criteria_conflicts_test extends \advanced_testcase {

    /**
     * Пары формулировок, которые не могут стоять в одном наборе: они требуют
     * противоположного по одной и той же оси.
     */
    private const CONFLICTS = [
        ['углублённые факты', 'проще базового'],
        ['углублённые факты', 'проще базового уровня'],
        ['сложнее стандартного', 'проще базового'],
    ];

    /** Все сочетания категорий, видов ОВЗ и полос балла, которые дает модель профиля. */
    private function all_profiles(): \Generator {
        $catsets = [[1], [2], [3], [4], [1, 4], [3, 4], [1, 2, 3, 4], [2, 3]];
        $ovzsets = [[], [4], [5], [1, 4]];
        $bands   = [40.0, 70.0, 90.0];
        foreach ($catsets as $cats) {
            foreach ($ovzsets as $ovz) {
                foreach ($bands as $score) {
                    foreach ([1, 2, 3] as $level) {
                        yield "cats=[" . implode(',', $cats) . "] ovz=[" . implode(',', $ovz)
                            . "] балл=$score уровень=$level"
                            => ['categories' => $cats, 'ovz_types' => $ovz, 'avg_score' => $score,
                                'difficulty_level' => $level, 'class_number' => 7];
                    }
                }
            }
        }
    }

    public function test_no_contradicting_pair_in_any_combination(): void {
        // Перебор, а не отдельный случай: конфликт нашелся живым заходом на одном профиле, и
        // ровно так же незамеченным мог сидеть в любом другом сочетании.
        $gen = new ai_generator();

        foreach ($this->all_profiles() as $label => $profile) {
            $c = $gen->build_criteria($profile);
            foreach (['special_parts', 'special_parts_items'] as $key) {
                $joined = mb_strtolower(implode("\n", $c[$key]));
                foreach (self::CONFLICTS as [$a, $b]) {
                    $both = str_contains($joined, mb_strtolower($a))
                         && str_contains($joined, mb_strtolower($b));
                    $this->assertFalse($both,
                        "«{$a}» и «{$b}» стоят в одном наборе ($key) при $label");
                }
            }
        }
    }

    public function test_gifted_instruction_does_not_command_difficulty(): void {
        // Сложностью командует уровень: промт учебного текста говорит «Сложность строго
        // соответствует уровню». Категория задает тип познавательной задачи.
        $gen = new ai_generator();

        $c = $gen->build_criteria(['categories' => [4], 'difficulty_level' => 3,
            'avg_score' => 40.0, 'class_number' => 7]);

        $text = implode("\n", $c['special_parts']);
        $this->assertStringContainsString('одарённый', $text);
        $this->assertStringNotContainsString('углублённые факты', $text,
            'глубина - это сложность, а ею командует уровень');
        $this->assertStringContainsString('исследовательск', $text,
            'тип задачи остается: он не спорит ни с каким уровнем');
    }

    public function test_gifted_wording_is_same_for_raised_and_lowered_level(): void {
        // Указание категории не должно зависеть от уровня - иначе оно снова начнет спорить
        // с ним при следующей правке.
        $gen = new ai_generator();

        $low = $gen->build_criteria(['categories' => [4], 'difficulty_level' => 3,
            'avg_score' => 40.0, 'class_number' => 7]);
        $high = $gen->build_criteria(['categories' => [4], 'difficulty_level' => 2,
            'avg_score' => 90.0, 'class_number' => 7]);

        $gifted = static function (array $parts): string {
            foreach ($parts as $p) {
                if (str_contains($p, 'одарённый')) {
                    return $p;
                }
            }
            return '';
        };
        $this->assertNotSame('', $gifted($low['special_parts']));
        $this->assertSame($gifted($low['special_parts']), $gifted($high['special_parts']));
    }

    // ---------------------------------------------------------------
    // Объем
    // ---------------------------------------------------------------

    public function test_zpr_never_gets_the_largest_volume(): void {
        // Ребенок с ЗПР и высоким баллом получал 600-800 слов при указании «очень короткие
        // абзацы, повторяй ключевые понятия»: объем ограничивался только категорией
        // «длительное лечение», вид ОВЗ в минимуме не участвовал.
        $gen = new ai_generator();

        $c = $gen->build_criteria(['categories' => [1], 'ovz_types' => [4],
            'difficulty_level' => 3, 'avg_score' => 90.0, 'class_number' => 7]);

        $this->assertSame('продвинутый', $c['level_label'], 'уровень тут ни при чем, он высокий');
        $this->assertNotSame('600–800', $c['word_count'],
            'вид ОВЗ обязан участвовать в минимуме объема');
    }

    public function test_volume_takes_the_smallest_applicable_limit(): void {
        // Правило заявлено комментарием в коде: берем НАИБОЛЕЕ ограничивающее.
        $gen = new ai_generator();

        $both = $gen->build_criteria(['categories' => [1, 3], 'ovz_types' => [4],
            'difficulty_level' => 3, 'avg_score' => 90.0, 'class_number' => 7]);

        $this->assertSame('250–350', $both['word_count'],
            'длительное лечение - самое жесткое ограничение, оно и должно победить');
    }

    public function test_plain_profile_volume_follows_the_level(): void {
        // Обратная сторона: без ОВЗ и без категорий объем по-прежнему задает уровень.
        $gen = new ai_generator();

        foreach ([1 => '300–400', 2 => '400–600', 3 => '600–800'] as $level => $expected) {
            $c = $gen->build_criteria(['categories' => [9], 'ovz_types' => [],
                'difficulty_level' => $level, 'avg_score' => 70.0, 'class_number' => 7]);
            $this->assertSame($expected, $c['word_count'], "уровень $level");
        }
    }

    // ---------------------------------------------------------------
    // Согласованность с объемом
    // ---------------------------------------------------------------

    public function test_treatment_instruction_has_no_reading_time(): void {
        // «Модуль должен читаться за 10-15 минут» при 250-350 словах - это 2-3 минуты чтения.
        // Два разных числа про одно и то же модель мирит наугад, а объем и так задан
        // отдельной строкой промта.
        $gen = new ai_generator();

        $c = $gen->build_criteria(['categories' => [3], 'difficulty_level' => 2,
            'avg_score' => 70.0, 'class_number' => 7]);

        $text = implode("\n", $c['special_parts']);
        $this->assertStringContainsString('длительном лечении', $text);
        $this->assertDoesNotMatchRegularExpression('~[0-9]+\s*[-–]\s*[0-9]+\s*минут~u', $text,
            'время чтения спорит с заданным объемом');
    }
}
