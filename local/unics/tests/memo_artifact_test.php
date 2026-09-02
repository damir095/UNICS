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

    /** Годный текст: артефактов не меньше порога. */
    private const GOOD = "Раздел.\n\nЗапомни: дробь - часть целого.\n\nВторой.\n\nЗапомни: знаменатель внизу.";

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

    /** Заглушка, отдающая заранее заданные ответы по порядку. */
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

    /**
     * Генерация с перехватом следа: под PHPUnit CLI_SCRIPT истинно, и trace() идет в mtrace.
     * Тот же прием, что в generate_text_refusal_test - иначе вывод ломает прогон как «лишний».
     */
    private function lesson(fake_ai_generator $gen, array $profile): array {
        ob_start();
        $text = $gen->generate_lesson_text($gen->build_criteria($profile), 'промт');
        return [$text, (string)ob_get_clean()];
    }

    public function test_prompt_for_zpr_demands_the_artifact(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);

        $this->assertStringContainsString(ai_generator::MEMO_MARKER,
            $gen->build_prompt($this->zpr(), 'Дроби'),
            'Промт для ЗПР не требует артефакта - тогда и проверять в тексте нечего.');
        $this->assertStringNotContainsString(ai_generator::MEMO_MARKER,
            $gen->build_prompt($this->plain(), 'Дроби'),
            'Артефакт требуется от ВСЕХ - тогда он не признак адаптации, а общий шум.');
    }

    public function test_text_with_artifact_is_taken_as_is(): void {
        $this->resetAfterTest();
        $gen = $this->gen([self::GOOD]);

        [$text, $trace] = $this->lesson($gen, $this->zpr());

        $this->assertStringContainsString('Запомни:', $text);
        $this->assertCount(1, $gen->prompts, 'Переспрос при годном тексте - лишние токены.');
        $this->assertSame('', $trace, 'След при удачном прогоне - шум в журнале задачи.');
    }

    public function test_text_without_artifact_is_asked_again(): void {
        $this->resetAfterTest();
        $gen = $this->gen(['Раздел без повтора.', self::GOOD]);

        [$text] = $this->lesson($gen, $this->zpr());

        $this->assertStringContainsString('Запомни:', $text,
            'Текст без артефакта принят молча - адаптация снова держится на одном указании.');
        $this->assertCount(2, $gen->prompts);
        $this->assertStringContainsString('ОБЯЗАТЕЛЬНО', $gen->last_prompt(),
            'Переспрос повторяет тот же промт слово в слово - у модели нет причины ответить иначе.');
    }

    /**
     * Один «Запомни» на весь текст - это откат адаптации, а не соблюдение.
     *
     * Указание просит артефакт после КАЖДОГО раздела, живая проба дала 4-5 штук. Проверка на
     * наличие пропустила бы сползание к одному итоговому «Запомни: подведем итог» (найдено ревью).
     */
    public function test_single_memo_is_not_enough(): void {
        $this->resetAfterTest();
        $gen = $this->gen(["Раздел.\n\nЗапомни: одно и только.", self::GOOD]);

        [$text] = $this->lesson($gen, $this->zpr());

        $this->assertSame(self::GOOD, $text);
        $this->assertCount(2, $gen->prompts, 'Одиночный артефакт принят за соблюдение.');
    }

    /**
     * Артефакт, выделенный разметкой, - тоже артефакт.
     *
     * Тот же промт велит выделять ключевые понятия жирным, так что «**Запомни**:» - вероятная
     * запись. Голый шаблон ее не видел: верный текст стоил бы лишней генерации (найдено ревью).
     */
    public function test_markdown_forms_of_the_artifact_count(): void {
        $this->resetAfterTest();
        $gen = $this->gen(["**Запомни**: раз.\n\n*Запомни*: два.\n\n### ЗАПОМНИ: три."]);

        [$text] = $this->lesson($gen, $this->zpr());

        $this->assertSame(3, $gen->memo_count($text));
        $this->assertCount(1, $gen->prompts, 'Переспрос за текст, который артефакт содержит.');
    }

    /**
     * Второй заход ровно один: третьего нет даже когда и он вернул текст без артефакта.
     *
     * Урок ребенку важнее артефакта в нем, поэтому первый текст сохраняется, а не выбрасывается.
     */
    public function test_only_one_retry_and_text_is_never_lost(): void {
        $this->resetAfterTest();
        $gen = $this->gen(['Первый без повтора.', 'Второй тоже без повтора.', self::GOOD]);

        [$text, $trace] = $this->lesson($gen, $this->zpr());

        $this->assertSame('Первый без повтора.', $text,
            'При двух неудачах вернуть надо ПЕРВЫЙ текст, а не пустоту и не третий заход.');
        $this->assertCount(2, $gen->prompts, 'Переспросов больше одного - неограниченный расход токенов.');
        $this->assertStringContainsString('не появился и после переспроса', $trace,
            'Урок ушел без артефакта молча - в production это неотличимо от удачи.');
    }

    /**
     * Отказ переспроса не должен стоить ребенку урока, но обязан оставить след.
     */
    public function test_failed_retry_keeps_the_first_text_and_traces(): void {
        $this->resetAfterTest();
        $gen = new class extends fake_ai_generator {
            protected function quiz_reply(string $prompt): string {
                if (str_contains($prompt, 'ОБЯЗАТЕЛЬНО')) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '', 'сеть');
                }
                return 'Текст без повтора.';
            }
        };

        [$text, $trace] = $this->lesson($gen, $this->zpr());

        $this->assertSame('Текст без повтора.', $text);
        $this->assertStringContainsString('не удался', $trace);
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
            $gen = $this->gen(['Текст без артефакта.', self::GOOD]);

            [$text] = $this->lesson($gen, $profile);

            $this->assertSame('Текст без артефакта.', $text);
            $this->assertCount(1, $gen->prompts,
                'Переспрос ушел ребенку, которому артефакт не нужен.');
        }
    }

    /**
     * Сочетание ЗПР+РАС артефакт СОХРАНЯЕТ - решение сознательное.
     *
     * Повтор после каждого раздела и есть предсказуемая структура, которой требует указание для
     * РАС. Отдельный тест нужен потому, что случайное «истина» тут неотличимо от продуманного.
     */
    public function test_combined_zpr_and_ras_keeps_the_artifact(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);

        $this->assertTrue($gen->memo_required($gen->build_criteria(
            ['class_number' => 5, 'categories' => [1], 'ovz_types' => [4, 5]])));
    }

    /**
     * Строковый вид ОВЗ ('4' вместо 4) не должен молча выключать проверку.
     */
    public function test_string_ovz_type_still_requires_the_artifact(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);

        $this->assertTrue($gen->memo_required($gen->build_criteria(
            ['class_number' => 5, 'categories' => [1], 'ovz_types' => ['4']])),
            'Профиль из JSON или импорта даст указание в промте, а проверку - нет.');
    }

    /**
     * Строки-повторы не должны попадать в источник для теста, задания и видео.
     */
    public function test_memo_lines_are_stripped_from_the_source(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);

        $source = $gen->strip_memo("Испарение воды.\n\n**Запомни**: вода испаряется.\n\nКонденсация.");

        $this->assertStringNotContainsString('Запомни', $source,
            'Повтор съедает окно источника: там текст режется по 1500-2000 знаков.');
        $this->assertStringContainsString('Испарение воды.', $source);
        $this->assertStringContainsString('Конденсация.', $source);
    }
}
