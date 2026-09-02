<?php
/**
 * Артефакт «Запомни:» в учебном тексте для ЗПР ([[ovz-adaptation-measured]]).
 *
 * Зачем это вообще есть. Замер 2026-09-02 на четырех плечах по пять генераций показал, что
 * указания по виду ОВЗ выход НЕ меняют: тексты для ребенка с ЗПР и без ОВЗ различались не больше,
 * чем два запуска подряд для одного ребенка (p = 0.13-0.79). Прибор при этом рабочий - на плече
 * «одаренный» он видел сдвиг (p = 0.048). Причина: требования вроде «очень короткие абзацы»
 * контроль выполнял и без указания, а единственное невыполненное - «повторяй ключевые понятия» -
 * было непроверяемым пожеланием.
 *
 * Артефакт эту дыру закрывает: он либо есть в тексте, либо его нет, и это видно и тесту, и замеру.
 *
 * @package local_unics
 */

namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\tests\fake_ai_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_ai_generator.php');

#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class memo_artifact_test extends \advanced_testcase {

    /** Профиль ребенка с ЗПР (вид ОВЗ 4). */
    private function zpr(): array {
        return ['class_number' => 5, 'difficulty_level' => 2, 'avg_score' => 70.0,
                'categories' => [1], 'ovz_types' => [4]];
    }

    /** Тот же ребенок, но без ОВЗ: контроль. */
    private function plain(): array {
        return ['class_number' => 5, 'difficulty_level' => 2, 'avg_score' => 70.0,
                'categories' => [2], 'ovz_types' => []];
    }

    /**
     * Заглушка, отдающая заранее заданные ответы по порядку.
     */
    private function gen(array $replies): fake_ai_generator {
        return new class($replies) extends fake_ai_generator {
            public function __construct(private array $replies) {
                parent::__construct();
            }
            protected function quiz_reply(string $prompt): string {
                return (string)(array_shift($this->replies) ?? 'пусто');
            }
        };
    }

    public function test_prompt_for_zpr_demands_the_artifact(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);
        $prompt = $gen->build_prompt($this->zpr(), 'Дроби');

        $this->assertStringContainsString(ai_generator::MEMO_MARKER, $prompt,
            'Промт для ЗПР не требует артефакта - тогда и проверять в тексте нечего.');
        $this->assertStringNotContainsString(ai_generator::MEMO_MARKER,
            $gen->build_prompt($this->plain(), 'Дроби'),
            'Артефакт требуется от ВСЕХ - тогда он не признак адаптации, а общий шум.');
    }

    public function test_text_with_artifact_is_taken_as_is(): void {
        $this->resetAfterTest();
        $gen = $this->gen(["Раздел.\n\nЗапомни: дробь - это часть целого."]);

        $text = $gen->generate_lesson_text($this->zpr(), 'промт');

        $this->assertStringContainsString('Запомни:', $text);
        $this->assertCount(1, $gen->prompts, 'Переспрос при годном тексте - лишние токены.');
    }

    public function test_text_without_artifact_is_asked_again(): void {
        $this->resetAfterTest();
        $gen = $this->gen(['Раздел без повтора.', "Раздел.\n\nЗапомни: главное."]);

        $text = $gen->generate_lesson_text($this->zpr(), 'промт');

        $this->assertStringContainsString('Запомни:', $text,
            'Текст без артефакта принят молча - адаптация снова держится на одном указании.');
        $this->assertCount(2, $gen->prompts);
        $this->assertStringContainsString('ОБЯЗАТЕЛЬНО', $gen->last_prompt(),
            'Переспрос повторяет тот же промт слово в слово - у модели нет причины ответить иначе.');
    }

    /**
     * Второй заход ровно один: третьего нет даже когда и он вернул текст без артефакта.
     *
     * Урок ребенку важнее артефакта в нем, поэтому первый текст сохраняется, а не выбрасывается.
     */
    public function test_only_one_retry_and_text_is_never_lost(): void {
        $this->resetAfterTest();
        $gen = $this->gen(['Первый без повтора.', 'Второй тоже без повтора.', 'Запомни: третий.']);

        $text = $gen->generate_lesson_text($this->zpr(), 'промт');

        $this->assertSame('Первый без повтора.', $text,
            'При двух неудачах вернуть надо ПЕРВЫЙ текст, а не пустоту и не третий заход.');
        $this->assertCount(2, $gen->prompts, 'Переспросов больше одного - неограниченный расход токенов.');
    }

    /**
     * Отказ переспроса не должен стоить ребенку урока.
     */
    public function test_failed_retry_keeps_the_first_text(): void {
        $this->resetAfterTest();
        $gen = new class extends fake_ai_generator {
            protected function quiz_reply(string $prompt): string {
                if (str_contains($prompt, 'ОБЯЗАТЕЛЬНО')) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '', 'сеть');
                }
                return 'Текст без повтора.';
            }
        };

        $this->assertSame('Текст без повтора.', $gen->generate_lesson_text($this->zpr(), 'промт'));
    }

    /**
     * И то, что сработать НЕ должно: ребенок без ЗПР переспроса не вызывает.
     *
     * Без этой проверки предохранитель выглядел бы рабочим, переспрашивая всех подряд и удваивая
     * расход токенов на каждом учащемся.
     */
    public function test_other_children_are_not_asked_twice(): void {
        $this->resetAfterTest();
        foreach ([$this->plain(),
                  ['class_number' => 5, 'categories' => [1], 'ovz_types' => [5]],   // РАС
                  ['class_number' => 5, 'categories' => [4], 'ovz_types' => []]] as $profile) {
            $gen = $this->gen(['Текст без артефакта.', 'Запомни: не должно понадобиться.']);

            $text = $gen->generate_lesson_text($profile, 'промт');

            $this->assertSame('Текст без артефакта.', $text);
            $this->assertCount(1, $gen->prompts,
                'Переспрос ушел ребенку, которому артефакт не нужен.');
        }
    }
}
