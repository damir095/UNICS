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
        $this->assertStringContainsString('не набраны и после переспроса', $trace,
            'Урок ушел без артефакта молча - в production это неотличимо от удачи.');
    }

    /**
     * Два ОДИНАКОВЫХ ответа модели: след обязан сказать «с первым».
     *
     * Признак выбора сравнивал СОДЕРЖИМОЕ строк, и при одинаковых ответах - обычное дело при
     * низкой температуре и у любой заглушки - след врал, будто отдан второй текст (найдено ревью).
     * Врал он ровно в том случае, который оператор и пошел бы разбирать.
     */
    public function test_identical_answers_are_reported_as_the_first_text(): void {
        $this->resetAfterTest();
        $gen = $this->gen(['Один и тот же ответ.', 'Один и тот же ответ.']);

        [$text, $trace] = $this->lesson($gen, $this->zpr());

        $this->assertSame('Один и тот же ответ.', $text);
        $this->assertStringContainsString('с первым', $trace);
        $this->assertStringNotContainsString('со вторым', $trace);
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
        // РАС здесь БОЛЬШЕ НЕТ: с 2026-09-02 он требует своего артефакта («Пример»), и держать
        // его в списке «кому не нужно» значило бы сторожить уже неверное правило.
        foreach ([$this->plain(),
                  ['class_number' => 5, 'categories' => [1], 'ovz_types' => [1]],   // слабовидящий
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

    /**
     * РАС: три «Пример:» - и текст годен.
     *
     * Замер показал, что прежнее указание для РАС не выполняло НИ ОДНОГО из двух своих обещаний:
     * неровность структуры та же, что в контроле (p = 0.857), метафоры 0.4 против 1.0 (p = 0.365).
     * Предсказуемость теперь задается конструкцией - одинаковая схема раздела, повторенная трижды.
     */
    public function test_ras_needs_three_examples(): void {
        $this->resetAfterTest();
        $ras = ['class_number' => 5, 'categories' => [1], 'ovz_types' => [5]];
        $good = "Раздел.

Пример: лужа высыхает.

Второй.

Пример: пар над чайником."
              . "

Третий.

Пример: роса на траве.";

        $gen = $this->gen([$good]);
        [$text] = $this->lesson($gen, $ras);
        $this->assertSame($good, $text);
        $this->assertCount(1, $gen->prompts, 'Переспрос за годный текст РАС.');

        // Двух примеров мало: три раздела - три примера.
        $gen = $this->gen(["Пример: раз.

Пример: два.", $good]);
        [$text] = $this->lesson($gen, $ras);
        $this->assertSame($good, $text);
        $this->assertCount(2, $gen->prompts, 'Двух примеров хватило - структура уже не трехчастная.');
    }

    /**
     * ЗПР и РАС разом: нужны ОБА артефакта, и проверка не смолкает на первом выполненном.
     */
    public function test_combined_profile_needs_both_artifacts(): void {
        $this->resetAfterTest();
        $both = ['class_number' => 5, 'categories' => [1], 'ovz_types' => [4, 5]];
        $gen  = $this->gen([]);

        $this->assertSame([ai_generator::MEMO_MARKER => ai_generator::MEMO_MIN,
                           ai_generator::EXAMPLE_MARKER => ai_generator::EXAMPLE_MIN],
            $gen->required_artifacts($gen->build_criteria($both)));

        // Повторы есть, примеров нет - текст все равно негоден.
        $memo_only = "Раздел.

Запомни: раз.

Второй.

Запомни: два.";
        $this->assertSame([ai_generator::EXAMPLE_MARKER],
            array_keys($gen->missing_artifacts($memo_only, $gen->build_criteria($both))),
            'Проверка смолкла на первом выполненном артефакте.');
    }


    /**
     * Сочетание ЗПР+РАС на ЖИВОМ пути: переспрос называет оба артефакта, и выбирается лучший текст.
     *
     * Проверка через required_artifacts() сама по себе мимо: весь код переспроса для сочетания
     * видов не исполнялся НИ В ОДНОМ тесте, и склейка меток «Запомни», «Пример: ...» - где нужный
     * вид был только у последней - прошла бы мимо сьюта (найдено ревью).
     */
    public function test_combined_profile_retry_names_both_and_keeps_the_better_text(): void {
        $this->resetAfterTest();
        $both = ['class_number' => 5, 'categories' => [1], 'ovz_types' => [4, 5]];
        // Первому не хватает ОБОИХ артефактов (повтор всего один при пороге два, примеров нет),
        // второму - только повтора. Значит переспрос обязан назвать оба, а вернуться должен
        // ВТОРОЙ текст: не хватает меньшему числу видов.
        $first  = "Раздел.

Запомни: один и только.";
        $second = "Раздел.

Пример: раз.

Второй.

Пример: два.

Третий.

Пример: три.";
        $gen = $this->gen([$first, $second]);

        [$text, $trace] = $this->lesson($gen, $both);

        $retry = $gen->last_prompt();
        $this->assertStringContainsString(ai_generator::MEMO_MARKER . ': ...', $retry,
            'Повтор назван в переспросе не в том виде, который засчитывает проверка.');
        $this->assertStringContainsString(ai_generator::EXAMPLE_MARKER . ': ...', $retry);
        $this->assertStringContainsString('не меньше ' . ai_generator::EXAMPLE_MIN, $retry,
            'Переспрос не называет нужное число - модель ставит по одному на раздел и не берет порог.');
        $this->assertStringContainsString('главное понятие', $retry,
            'Переспрос не говорит, ЧТО класть в строку - его выполнит наполнитель вида «Запомни: далее».');
        $this->assertSame($second, $text,
            'Вернулся текст, которому не хватает большего числа артефактов.');
        $this->assertStringContainsString('со вторым', $trace);
        $this->assertStringContainsString('Запомни: 1 и 0', $trace,
            'В следе нет счетов - нельзя отличить «чуть не хватило» от «не выполнено вовсе».');
    }

    /**
     * «Например:» - не артефакт: «пример» лишь подстрока этого слова.
     *
     * Без якоря текст с тремя «Например: ...» и НУЛЕМ строк-примеров проходил проверку, а след
     * рапортовал об успехе. На 20 текстах замера этого не случилось ни разу - дыра была скрытой.
     */
    public function test_naprimer_does_not_count_as_an_example(): void {
        $this->resetAfterTest();
        $ras = ['class_number' => 5, 'categories' => [1], 'ovz_types' => [5]];
        $gen = $this->gen([]);
        $naprimer_only = "Например: лужа высыхает. Например: пар. Например: роса.";

        $this->assertSame([ai_generator::EXAMPLE_MARKER],
            array_keys($gen->missing_artifacts($naprimer_only, $gen->build_criteria($ras))),
            'Связка «например» засчитана как строка-пример.');
        $this->assertSame(1, $gen->artifact_count('Приведем пример: роса.', ai_generator::EXAMPLE_MARKER),
            'Якорь съел законный пример в середине предложения.');
    }

    /**
     * Общее требование «2-3 примера» не должно спорить с указанием для РАС.
     */
    public function test_shared_example_cap_yields_to_the_ras_instruction(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);

        $this->assertStringNotContainsString('Включи 2–3 примера',
            $gen->build_prompt(['class_number' => 5, 'categories' => [1], 'ovz_types' => [5]], 'Дроби'),
            'Потолок «2-3» стоит рядом с требованием примера в каждом разделе - оси спорят.');
        $this->assertStringContainsString('Включи 2–3 примера',
            $gen->build_prompt($this->plain(), 'Дроби'),
            'Потолок снят у всех - требование про примеры потеряно для обычного ребенка.');
    }

    /**
     * НОДА: три пронумерованных шага - и текст годен.
     *
     * Указание обещало «чёткие пошаговые инструкции», а замер дал 0.00 нумерованных шагов при
     * 0.80 в контроле: с указанием выходило ХУЖЕ, чем без него. После замены на артефакт - 4.40
     * против 0.00, p = 0.008 ([[ovz-adaptation-measured]]).
     */
    public function test_noda_needs_numbered_steps(): void {
        $this->resetAfterTest();
        $noda = ['class_number' => 5, 'categories' => [1], 'ovz_types' => [3]];
        $good = "Шаг 1. Нагрей воду.

Шаг 2. Дождись пара.

Шаг 3. Охлади пар.";

        $gen = $this->gen([$good]);
        [$text] = $this->lesson($gen, $noda);
        $this->assertSame($good, $text);
        $this->assertCount(1, $gen->prompts, 'Переспрос за годный текст НОДА.');

        // Двух шагов мало: порядок действий назван, а не разобран.
        $gen = $this->gen(["Шаг 1. Раз.

Шаг 2. Два.", $good]);
        [$text] = $this->lesson($gen, $noda);
        $this->assertSame($good, $text);
        $this->assertCount(2, $gen->prompts);
    }

    /**
     * Шаг пишется «Шаг 1.», с номером и точкой, а не с двоеточием.
     *
     * Общий шаблон «метка плюс двоеточие» не нашёл бы его НИКОГДА, и каждый комплект для НОДА
     * платил бы лишней генерацией, не проходя проверку ни при каком ответе модели.
     */
    public function test_step_counting_accepts_number_and_dot(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);
        $M = ai_generator::STEP_MARKER;

        $this->assertSame(3, $gen->artifact_count("Шаг 1. Раз
Шаг 2. Два
Шаг 3. Три", $M));
        $this->assertSame(2, $gen->artifact_count('**Шаг 1.** Раз. **Шаг 2.** Два.', $M));
        $this->assertSame(2, $gen->artifact_count('Шаг 1: Раз. Шаг 2: Два.', $M));
        // И то, что срабатывать НЕ должно.
        $this->assertSame(0, $gen->artifact_count('Первым шагом нагреваем, шагом вторым ждём.', $M),
            'Слово «шагом» в тексте засчитано за артефакт.');
        $this->assertSame(0, $gen->artifact_count('Запомни 1. что-то', ai_generator::MEMO_MARKER),
            'Хвост с номером применён к артефакту, который пишется с двоеточием.');
    }

    /**
     * Переспрос за шагами называет ИХ формат, а не чужой.
     *
     * Прежняя развилка знала два артефакта и третьему выдала бы формулировку про примеры.
     */
    public function test_step_retry_asks_for_numbered_lines(): void {
        $this->resetAfterTest();
        $gen = $this->gen(['Текст без шагов.', "Шаг 1. Раз.

Шаг 2. Два.

Шаг 3. Три."]);

        $this->lesson($gen, ['class_number' => 5, 'categories' => [1], 'ovz_types' => [3]]);

        $retry = $gen->last_prompt();
        $this->assertStringContainsString(ai_generator::STEP_MARKER . ' 1. ...', $retry);
        $this->assertStringContainsString('порядком действий', $retry);
        $this->assertStringNotContainsString('пример из реальной жизни', $retry,
            'Шагам досталась формулировка чужого артефакта.');
    }

    /**
     * У КАЖДОГО артефакта своя формулировка переспроса.
     *
     * В match есть ветка по умолчанию - без нее забытая формулировка роняла бы генерацию урока
     * целиком (UnhandledMatchError летит ВНЕ try). Но запасная ветка не должна работать молча,
     * поэтому тест сторожит число артефактов: добавили новый - опишите его формулировку и внесите
     * сюда, иначе ребенок получит переспрос без требования к содержанию строки.
     */
    public function test_every_artifact_has_its_own_retry_wording(): void {
        $this->resetAfterTest();
        $gen  = $this->gen([]);
        $crit = $gen->build_criteria(
            ['class_number' => 5, 'categories' => [1], 'ovz_types' => [1, 2, 3, 4, 5, 6]]);

        $wordings = [
            ai_generator::MEMO_MARKER    => 'главное понятие',
            ai_generator::EXAMPLE_MARKER => 'пример из реальной жизни',
            ai_generator::STEP_MARKER    => 'порядком действий',
        ];
        $this->assertCount(count($wordings), $gen->required_artifacts($crit),
            'Появился артефакт без своей формулировки переспроса - опишите ее и внесите в тест.');

        $gen = $this->gen(['Текст без единого артефакта.', 'И второй такой же.']);
        $this->lesson($gen, ['class_number' => 5, 'categories' => [1],
                             'ovz_types' => [1, 2, 3, 4, 5, 6]]);
        $retry = $gen->last_prompt();
        foreach ($wordings as $marker => $wording) {
            $this->assertStringContainsString($wording, $retry,
                "Артефакт «{$marker}» получил чужую или пустую формулировку.");
        }
    }

    /**
     * Строки «Пример:» из источника НЕ вырезаются: пример - это содержание, а не повтор.
     */
    public function test_examples_stay_in_the_source(): void {
        $this->resetAfterTest();
        $gen = $this->gen([]);

        $source = $gen->strip_memo("Испарение.

Запомни: вода испаряется.

Пример: лужа высыхает.");

        $this->assertStringNotContainsString('Запомни', $source);
        $this->assertStringContainsString('Пример: лужа высыхает.', $source,
            'Вырезан пример - из источника ушло содержание, а не повтор.');
    }
}
