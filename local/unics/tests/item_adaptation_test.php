<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\tests\fake_raw_generator;

require_once(__DIR__ . '/fixtures/fake_ai_generator.php');

/**
 * Особые указания для ЗАДАНИЙ теста, а не для учебного текста ([[item-adaptation-design]]).
 *
 * До этой задачи набор особых указаний был один на все выходы, и написан он был про учебный
 * текст: «очень короткие абзацы», «модуль должен читаться за 10-15 минут», «завершай текст
 * коротким мотивирующим выводом». В промт теста это уходило дословно - модель получала указания
 * про абзацы там, где нужны требования к формулировке вопроса.
 *
 * Разница не косметическая. Для ЗПР «короткий абзац» и «один вопрос - одна мысль, без двойных
 * условий» - разные требования; для РАС «предсказуемая структура текста» ничего не говорит про
 * запрет вопросов вида «выбери самый подходящий», хотя именно они для ребенка с РАС
 * неразрешимы.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class item_adaptation_test extends \advanced_testcase {

    /** Слова, написанные ПРО УЧЕБНЫЙ ТЕКСТ: в промте заданий их быть не должно. */
    private const TEXT_ONLY = ['абзац', 'Модуль должен читаться', 'Завершай текст',
                               'структура текста', 'исследовательский вопрос в конце'];

    private function profile(array $over = []): array {
        return $over + ['categories' => [1], 'ovz_types' => [4],
                        'difficulty_level' => 2, 'class_number' => 7, 'avg_score' => 70.0];
    }

    // ---------------------------------------------------------------
    // Уровень критериев: два набора вместо одного
    // ---------------------------------------------------------------

    public function test_criteria_carry_a_separate_set_for_items(): void {
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile());

        $this->assertNotEmpty($c['special_parts'], 'текстовый набор остается на месте');
        $this->assertNotEmpty($c['special_parts_items'], 'у заданий обязан быть свой набор');
        $this->assertNotSame($c['special_parts'], $c['special_parts_items'],
            'если наборы совпали, задача не сделана: в тест уходят указания про текст');
    }

    public function test_item_set_is_free_of_text_wording(): void {
        // Разом по всем видам ОВЗ и всем категориям: любая забытая формулировка про текст
        // всплывет здесь, а не в живой генерации.
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile([
            'categories' => [1, 2, 3, 4], 'ovz_types' => [1, 2, 3, 4, 5, 6],
        ]));

        $items = implode("\n", $c['special_parts_items']);
        foreach (self::TEXT_ONLY as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $items,
                "«{$word}» написано про учебный текст и в требованиях к заданию бессмысленно");
        }
    }

    public function test_every_ovz_type_has_its_own_item_instruction(): void {
        // Пустой набор для какого-то вида означал бы, что ребенок с ним получает тест без
        // единого указания по своему нарушению.
        $gen = new ai_generator();

        $seen = [];
        foreach ([1, 2, 3, 4, 5, 6] as $type) {
            $c = $gen->build_criteria($this->profile(['ovz_types' => [$type]]));
            // Первая строка - перечень типов, дальше идут инструкции.
            $own = array_slice($c['special_parts_items'], 1);
            $this->assertNotEmpty($own, "вид ОВЗ $type остался без указаний для заданий");
            $joined = implode(' ', $own);
            $this->assertNotContains($joined, $seen,
                "вид ОВЗ $type получил ту же инструкцию, что и другой вид");
            $seen[] = $joined;
        }
    }

    public function test_every_category_has_its_own_item_instruction(): void {
        // Дыра, найденная мутацией: удалить вопросное указание для «длительного лечения» -
        // и все прочие тесты оставались зелеными. Проверка отсутствия слов про текст такой
        // пропуск не видит по построению, поэтому наличие спрашиваем отдельно.
        $gen = new ai_generator();

        foreach ([1 => 'ОВЗ', 2 => 'семейное обучение', 3 => 'длительное лечение',
                  4 => 'одарённый'] as $cat => $label) {
            $c = $gen->build_criteria($this->profile(['categories' => [$cat], 'ovz_types' => []]));

            $this->assertNotEmpty($c['special_parts_items'],
                "категория «{$label}» осталась без указаний для заданий");
            $this->assertSame(count($c['special_parts']), count($c['special_parts_items']),
                "у категории «{$label}» наборы разошлись по числу указаний: "
                . 'какое-то из них потеряно');
            $this->assertNotSame($c['special_parts'], $c['special_parts_items'],
                "категория «{$label}» получила в задания дословный текстовый набор");
        }
    }

    public function test_zpr_item_instruction_is_about_the_question(): void {
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile(['ovz_types' => [4]]));
        $items = implode("\n", $c['special_parts_items']);
        $text  = implode("\n", $c['special_parts']);

        $this->assertStringContainsString('Один вопрос - одна мысль', $items);
        $this->assertStringNotContainsString('Один вопрос - одна мысль', $text,
            'требование к вопросу не должно уезжать в промт учебного текста');
        $this->assertStringContainsString('Очень короткие абзацы', $text,
            'текстовый набор ломать не собирались');
    }

    public function test_ras_item_instruction_forbids_best_answer_questions(): void {
        // Для ребенка с РАС вопрос «какой ответ самый подходящий» неразрешим: он требует
        // ранжировать одинаково верные варианты по неявному признаку.
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile(['ovz_types' => [5]]));

        $items = implode("\n", $c['special_parts_items']);
        $this->assertStringContainsString('самый подходящий', $items);
        $this->assertStringContainsString('буквальн', $items);
    }

    public function test_ovz_without_types_still_gets_an_item_instruction(): void {
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile(['ovz_types' => []]));

        $this->assertNotEmpty($c['special_parts_items']);
        $this->assertStringNotContainsStringIgnoringCase('абзац',
            implode("\n", $c['special_parts_items']));
    }

    public function test_teacher_note_about_the_child_reaches_both_sets(): void {
        // Свободное поле педагога - про самого ребенка, а не про формат выхода.
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile(['special_needs' => 'путает лево и право']));

        $this->assertStringContainsString('путает лево и право', implode("\n", $c['special_parts']));
        $this->assertStringContainsString('путает лево и право',
            implode("\n", $c['special_parts_items']));
    }

    public function test_level_change_reason_is_worded_per_output(): void {
        $gen = new ai_generator();

        $c = $gen->build_criteria($this->profile(['difficulty_level' => 3, 'avg_score' => 40.0]));

        $this->assertStringContainsString('материал должен быть проще',
            implode("\n", $c['special_parts']));
        $this->assertStringContainsString('задания должны быть проще',
            implode("\n", $c['special_parts_items']),
            'понижение уровня для теста - про сложность заданий, а не про материал');
    }

    public function test_profile_without_known_categories_gets_no_special_parts(): void {
        // Пустой список категорий сюда не годится: по бэк-компату он превращается в [2] -
        // семейное обучение (найдено этим тестом). Ребенка «вообще без особенностей» в модели
        // нет, поэтому берем категорию вне справочника - так проверяется ровно то, ради чего
        // тест и написан: без указаний ни один из наборов не должен ничего выдумывать.
        $gen = new ai_generator();

        $c = $gen->build_criteria(['categories' => [9], 'ovz_types' => [],
            'difficulty_level' => 2, 'class_number' => 7, 'avg_score' => 70.0]);

        $this->assertSame([], $c['special_parts']);
        $this->assertSame([], $c['special_parts_items']);
    }

    // ---------------------------------------------------------------
    // Уровень блока промта
    // ---------------------------------------------------------------

    public function test_block_kind_switches_the_set(): void {
        $gen = new ai_generator();
        $criteria = [
            'category_label' => 'ОВЗ', 'level_label' => 'базовый', 'avg_band' => '50-85%',
            'special_parts' => ['ПРО ТЕКСТ'], 'special_parts_items' => ['ПРО ЗАДАНИЕ'],
        ];

        $text = $gen->adaptation_block($criteria);
        $items = $gen->adaptation_block($criteria, ai_generator::BLOCK_ITEMS);

        $this->assertStringContainsString('ПРО ТЕКСТ', $text);
        $this->assertStringNotContainsString('ПРО ЗАДАНИЕ', $text);
        $this->assertStringContainsString('ПРО ЗАДАНИЕ', $items);
        $this->assertStringNotContainsString('ПРО ТЕКСТ', $items);
    }

    public function test_item_block_heading_names_the_questions(): void {
        // Заголовок «Особые указания» ниоткуда не говорит, к чему они относятся, а в промте
        // теста рядом стоит собственный список требований к вопросам.
        $gen = new ai_generator();

        $block = $gen->adaptation_block([
            'category_label' => 'ОВЗ', 'level_label' => 'базовый', 'avg_band' => '50-85%',
            'special_parts' => ['ПРО ТЕКСТ'], 'special_parts_items' => ['ПРО ЗАДАНИЕ'],
        ], ai_generator::BLOCK_ITEMS);

        $this->assertStringContainsString('Особые указания к формулировке заданий:', $block);
    }

    public function test_unknown_block_kind_fails_loudly(): void {
        // Прежний код молча откатывался на текстовый набор при любом $kind кроме точного
        // BLOCK_ITEMS - то есть возвращал исходный дефект, не роняя ни одного теста
        // (найдено ревью 2026-08-25).
        $gen = new ai_generator();

        $this->expectException(\coding_exception::class);
        $gen->adaptation_block([
            'category_label' => 'ОВЗ', 'level_label' => 'базовый', 'avg_band' => '50-85%',
            'special_parts' => ['Т'], 'special_parts_items' => ['З'],
        ], 'ITEMS');
    }

    public function test_missing_item_set_fails_loudly(): void {
        // Второй молчаливый путь: критерии, собранные где-то в обход build_criteria, давали
        // промт заданий вообще без адаптации - и тоже молча.
        $gen = new ai_generator();

        $this->expectException(\coding_exception::class);
        $gen->adaptation_block([
            'category_label' => 'ОВЗ', 'level_label' => 'базовый', 'avg_band' => '50-85%',
            'special_parts' => ['Т'],
        ], ai_generator::BLOCK_ITEMS);
    }

    public function test_ovz_type_without_category_still_adapts(): void {
        // Категории и виды ОВЗ пишутся независимо (sync_student_taxonomies), и через импорт
        // достижимо «вид записан, категории нет». В этом состоянии превью печатало диагноз, а
        // адаптации не было вовсе (найдено ревью 2026-08-25).
        $gen = new ai_generator();

        $c = $gen->build_criteria(['categories' => [3], 'ovz_types' => [4],
            'difficulty_level' => 3, 'avg_score' => 90.0, 'class_number' => 7]);

        $this->assertStringContainsString('Очень короткие абзацы', implode("\n", $c['special_parts']),
            'вид ОВЗ записан - адаптация обязана применяться');
        $this->assertStringContainsString('Один вопрос - одна мысль',
            implode("\n", $c['special_parts_items']));
        $this->assertNotSame('600–800', $c['word_count'],
            'потолок объема тоже не должен зависеть от категории');
    }

    public function test_item_block_without_parts_has_no_heading(): void {
        $gen = new ai_generator();

        $block = $gen->adaptation_block([
            'category_label' => 'стандартный', 'level_label' => 'базовый', 'avg_band' => '50-85%',
            'special_parts' => [], 'special_parts_items' => [],
        ], ai_generator::BLOCK_ITEMS);

        $this->assertStringNotContainsString('Особые указания', $block);
        $this->assertStringContainsString('Профиль учащегося:', $block);
    }

    // ---------------------------------------------------------------
    // Проводка до живого промта
    // ---------------------------------------------------------------

    private function capturing(string $canned): ai_generator {
        return new class($canned) extends fake_raw_generator {
            public function __construct(private string $canned) {
                parent::__construct();
            }
            protected function reply(string $prompt): string {
                return $this->canned;
            }
        };
    }

    public function test_quiz_prompt_gets_item_wording_not_text_wording(): void {
        // Главная проверка задачи: именно этот промт раньше нес указания про абзацы.
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->capturing('{"questions":[{"text":"Вопрос?","answers":["А","Б"],"correct":0}]}');

        ob_start();
        $gen->generate_quiz($this->profile(['categories' => [1, 3, 4]]), 'Дроби');
        ob_end_clean();

        $p = $gen->last_prompt();
        $this->assertStringContainsString('Один вопрос - одна мысль', $p);
        foreach (self::TEXT_ONLY as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $p,
                "«{$word}» в промте теста - это и есть исходный дефект");
        }
    }

    public function test_lesson_prompt_keeps_text_wording(): void {
        // Обратная сторона: правка не должна была обеднить промт учебного текста.
        $gen = new ai_generator();

        $p = $gen->build_prompt($this->profile(), 'Дроби');

        $this->assertStringContainsString('Очень короткие абзацы', $p);
        $this->assertStringNotContainsString('Один вопрос - одна мысль', $p);
    }

    public function test_assignment_and_video_keep_text_wording(): void {
        // Дизайн менял ровно один выход. Задание mod_assign - связный текст задания, а не
        // варианты ответа, поэтому оно остается на текстовом наборе.
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');

        $gen = $this->capturing('Текст задания.');
        $gen->generate_assignment_description($this->profile(), 'Дроби');
        $this->assertStringContainsString('Очень короткие абзацы', $gen->last_prompt());

        $gen = $this->capturing('{"slides":[{"title":"Т","content":"С","key_points":["а"]}]}');
        $gen->generate_video_script($this->profile(), 'Дроби');
        $this->assertStringContainsString('Очень короткие абзацы', $gen->last_prompt());
    }
}
