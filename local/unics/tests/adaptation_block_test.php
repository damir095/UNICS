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

    /**
     * Свободные указания педагога ([[teacher-extra-prompt-design]]). Сборка вынесена из
     * build_prompt(), иначе повторилась бы еще трижды - в тесте, задании и видео.
     */
    public function test_extra_block_is_empty_without_instructions(): void {
        $gen = new ai_generator();

        $this->assertSame('', $gen->extra_block(''));
        $this->assertSame('', $gen->extra_block('   '));
    }

    public function test_extra_block_trims_and_labels(): void {
        $gen = new ai_generator();

        $block = $gen->extra_block('  предмет - биология, избегать латинских терминов  ');

        $this->assertStringContainsString('Дополнительные указания от педагога:', $block);
        $this->assertStringContainsString('предмет - биология, избегать латинских терминов', $block);
        $this->assertStringNotContainsString('  предмет', $block, 'Пробелы по краям обрезаются');
    }

    /**
     * Наследник, перехватывающий промт вместо обращения к сети. Шов protected появился в
     * [[ai-output-style-plan]] ради другого теста и здесь оказался единственным способом вообще
     * увидеть промт: он собирается внутри generate_* и наружу не возвращается.
     */
    private function capturing(string $canned): ai_generator {
        return new class($canned) extends ai_generator {
            public string $last_prompt = '';
            private string $canned;
            public function __construct(string $canned) {
                parent::__construct();
                $this->canned = $canned;
            }
            protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
                $this->last_prompt = $prompt;
                return $this->canned;
            }
        };
    }

    /** Ребенок с ЗПР, базовым уровнем 3 и низким баллом: уровень должен понизиться до 2. */
    private function zpr_profile(): array {
        return [
            'categories'       => [1],
            'ovz_types'        => [4],
            'difficulty_level' => 3,
            'class_number'     => 7,
            'avg_score'        => 40.0,
            'special_needs'    => 'нужен перерыв каждые 10 минут',
        ];
    }

    public function test_quiz_prompt_carries_effective_level_and_ovz(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->capturing('{"questions":[{"text":"Вопрос?","answers":["А","Б"],"correct":0}]}');

        $gen->generate_quiz($this->zpr_profile(), 'Дроби');

        $p = $gen->last_prompt;
        $this->assertStringContainsString('уровень: стандартный', $p,
            'Уровень эффективный (3 понижен до 2), а не сырой');
        $this->assertStringNotContainsString('продвинутый', $p);
        $this->assertStringContainsString('Профиль учащегося:', $p);
        $this->assertStringContainsString('Очень короткие абзацы', $p, 'Инструкция по ЗПР доехала');
        $this->assertStringContainsString('нужен перерыв каждые 10 минут', $p);
    }

    public function test_assignment_prompt_carries_adaptation_block(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->capturing('Текст задания.');

        $gen->generate_assignment_description($this->zpr_profile(), 'Дроби');

        $p = $gen->last_prompt;
        $this->assertStringContainsString('уровень: стандартный', $p);
        $this->assertStringNotContainsString('продвинутый', $p);
        $this->assertStringContainsString('Очень короткие абзацы', $p);
        $this->assertStringContainsString('нужен перерыв каждые 10 минут', $p);
    }

    public function test_video_prompt_carries_adaptation_block(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $gen = $this->capturing('{"slides":[{"title":"Т","content":"С","key_points":["а","б"]}]}');

        $gen->generate_video_script([
            'categories' => [4], 'difficulty_level' => 2, 'class_number' => 8, 'avg_score' => 90.0,
        ], 'Дроби');

        $p = $gen->last_prompt;
        $this->assertStringContainsString('уровень: продвинутый', $p,
            'Одаренный с баллом выше 85% получает повышенный уровень');
        $this->assertStringContainsString('Категория: одарённый', $p);
        $this->assertStringContainsString('исследовательский вопрос', $p);
    }

    /**
     * Указания педагога доходят до всех трех вторичных промтов
     * ([[teacher-extra-prompt-design]]). Раньше они уходили только в учебный текст, хотя
     * «избегать латинских терминов без пояснений» относится и к вопросам теста.
     */
    public function test_teacher_instructions_reach_all_secondary_prompts(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');
        $extra = 'предмет - биология, избегать латинских терминов без пояснений';

        $quiz = $this->capturing('{"questions":[{"text":"В?","answers":["А","Б"],"correct":0}]}');
        $quiz->generate_quiz($this->zpr_profile(), 'Клетка', '', 5, $extra);
        $this->assertStringContainsString('Дополнительные указания от педагога:', $quiz->last_prompt);
        $this->assertStringContainsString($extra, $quiz->last_prompt);

        $assign = $this->capturing('Текст задания.');
        $assign->generate_assignment_description($this->zpr_profile(), 'Клетка', '', $extra);
        $this->assertStringContainsString($extra, $assign->last_prompt);

        $video = $this->capturing('{"slides":[{"title":"Т","content":"С","key_points":["а"]}]}');
        $video->generate_video_script($this->zpr_profile(), 'Клетка', '', $extra);
        $this->assertStringContainsString($extra, $video->last_prompt);
    }

    /** Без указаний педагога заголовок не появляется - как и в промте учебного текста. */
    public function test_no_teacher_heading_when_instructions_empty(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');

        $quiz = $this->capturing('{"questions":[{"text":"В?","answers":["А","Б"],"correct":0}]}');
        $quiz->generate_quiz($this->zpr_profile(), 'Клетка');

        $this->assertStringNotContainsString('Дополнительные указания', $quiz->last_prompt);
    }
}
