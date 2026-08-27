<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\tests\fake_ai_generator;

require_once(__DIR__ . '/fixtures/fake_ai_generator.php');

/**
 * Ключ приходит ТЕКСТОМ верного варианта, а индекс мы вычисляем сами ([[key-as-text-design]]).
 *
 * У номера нет способа проверки: сбитый на единицу, он остается совершенно законным числом. Обе
 * беды 27 августа выросли отсюда - `correct = 4` при четырех вариантах ([[one-based-key-design]])
 * и сбитый ключ ВНУТРИ диапазона, который виден только по расхождению со слепым судьей
 * ([[judge-key-shift-design]]).
 *
 * Текст сбить нельзя: он либо совпадает с вариантом, либо нет. Индекс при этом ВЫЧИСЛЯЕТСЯ - то
 * есть ключ ставит то, что считает, а не то, что мнит. Ровно эту границу проект и держит.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class key_as_text_test extends \advanced_testcase {

    /**
     * @param array $reply что модель кладет в вопрос помимо text и answers
     * @param array $answers варианты (по умолчанию четыре разных)
     */
    private function generator(array $reply, ?array $answers = null): ai_generator {
        $answers ??= ['Синий кит', 'Слон', 'Жираф', 'Морж'];
        // Судья по умолчанию молчит: этот тест про разбор, а не про третий ярус.
        return new class($reply, $answers) extends fake_ai_generator {
            public function __construct(private array $reply, private array $answers) {
                parent::__construct();
            }
            protected function quiz_reply(string $prompt): string {
                return json_encode(['questions' => [array_merge([
                    'text' => 'Какое животное самое крупное?',
                    'answers' => $this->answers,
                ], $this->reply)]], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    /**
     * Комплект и след.
     *
     * Исключение «ни одного вопроса» глотаем ТОЛЬКО его: любое другое пусть падает. Помощник,
     * который ловит все подряд, делает `assertSame([], $out)` тавтологией - пустой результат
     * означал бы и отбраковку, и сломанный разбор (найдено ревью).
     *
     * @return array{0:array,1:string}
     */
    private function ask(ai_generator $gen): array {
        ob_start();
        try {
            $out = $gen->generate_quiz([], 'Животные', '', 1);
        } catch (\moodle_exception $e) {
            if (!str_contains($e->getMessage(), 'Все вопросы отбракованы проверками')) {
                ob_end_clean();
                throw $e;
            }
            $out = [];
        } finally {
            $trace = ob_get_clean();
        }
        return [$out, $trace];
    }

    public function test_key_is_found_by_text(): void {
        $out = $this->ask($this->generator(['answer' => 'Жираф']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_text_wins_over_a_contradicting_index(): void {
        // Главное свойство: если модель прислала и текст, и номер, и они спорят - верим ТЕКСТУ.
        // Номер и есть тот канал, который ломается; текст мы проверяем сопоставлением.
        $out = $this->ask($this->generator(['answer' => 'Жираф', 'correct' => 0]))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct'], 'ключ ставит сопоставление, а не номер');
    }

    public function test_numbered_prefix_is_ignored(): void {
        // Модель нередко отвечает «3) Жираф» - номер тут ее собственный, из показанного списка.
        $out = $this->ask($this->generator(['answer' => '3) Жираф']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_case_and_spacing_do_not_matter(): void {
        $out = $this->ask($this->generator(['answer' => '  ЖИРАФ. ']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_index_is_the_fallback_when_text_is_missing(): void {
        // Обратная совместимость: модель может не прислать поле вовсе, и тогда путь прежний.
        [$out, $trace] = $this->ask($this->generator(['correct' => 1]));

        $this->assertCount(1, $out);
        $this->assertSame(1, (int)$out[0]['correct']);
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_index_is_the_fallback_when_text_does_not_match(): void {
        // Пересказ своими словами сопоставить нельзя - откатываемся на номер и говорим об этом.
        [$out, $trace] = $this->ask($this->generator([
            'answer' => 'самое крупное животное на планете', 'correct' => 0,
        ]));

        $this->assertCount(1, $out);
        $this->assertSame(0, (int)$out[0]['correct']);
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_duplicate_options_fall_back_to_the_index(): void {
        // Два одинаковых варианта делают сопоставление неоднозначным. Гадать нельзя: такой
        // вопрос все равно выбьет question_sanity, но ключ до нее должен остаться честным.
        [$out, $trace] = $this->ask($this->generator(
            ['answer' => 'Слон', 'correct' => 0],
            ['Слон', 'Слон', 'Жираф', 'Морж']
        ));

        // Оба яруса теперь нормализуют варианты ОДИНАКОВО, поэтому «неоднозначно для нас» и
        // «дубль для question_sanity» - одно множество, и вопрос гарантированно выбывает.
        // Пока генератор срезал приставку, а question_sanity нет, варианты «1) Кит» и «Кит»
        // были дублем для одного и разными для другой (найдено ревью).
        $this->assertSame([], $out, 'дубль вариантов выбивает вопрос признаками брака');
        $this->assertStringContainsString('два варианта одинаковы', $trace);
    }

    public function test_missing_key_drops_the_question(): void {
        // Достижимая форма: текст не совпал, номера нет. Первая редакция правки объявляла верным
        // вариант НОМЕР НОЛЬ и отправляла вопрос ребенку и в общий пул (найдено ревью).
        [$out, $trace] = $this->ask($this->generator(['answer' => 'самое крупное животное']));

        $this->assertSame([], $out, 'ключ выдумывать нельзя - вопрос выбывает');
        $this->assertStringContainsString('Ключа нет', $trace);
    }

    public function test_no_key_fields_at_all_drops_the_question(): void {
        [$out, $trace] = $this->ask($this->generator([]));

        $this->assertSame([], $out);
        $this->assertStringContainsString('Ключа нет', $trace);
    }

    public function test_numeric_answer_is_not_treated_as_text(): void {
        // Ловушка: варианты сами числа, а модель сбилась на старый контракт и прислала НОМЕР.
        // Дословное совпадение «2» с вариантом «2» выглядело бы проверенным ключом.
        [$out, $trace] = $this->ask($this->generator(
            ['answer' => 2, 'correct' => 2],
            ['1', '2', '3', '4']
        ));

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct'], 'число - это номер, а не текст варианта');
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_non_scalar_answer_falls_back_to_the_index(): void {
        // Модель нередко заворачивает значение в массив. Без гейта (string) на массиве дает
        // предупреждение и литерал «Array» - прием донорский, из answer_judge.
        [$out, $trace] = $this->ask($this->generator(
            ['answer' => ['Жираф'], 'correct' => 1]
        ));

        $this->assertCount(1, $out);
        $this->assertSame(1, (int)$out[0]['correct']);
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_prefix_without_space_is_stripped(): void {
        $out = $this->ask($this->generator(['answer' => '3.Жираф']))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_decimal_answer_is_not_mistaken_for_a_prefix(): void {
        // «3.14» не приставка с номером: срежь ее - и ключ уехал бы на вариант «14».
        $out = $this->ask($this->generator(
            ['answer' => '3.14'],
            ['2.71', '1.41', '3.14', '1.62']
        ))[0];

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_options_are_normalised_the_same_way_as_question_sanity(): void {
        // Приставку с номером срезаем у ОТВЕТА модели, но не у самих вариантов: вариант, внутри
        // которого сидит «3) », - это порча данных, и сопоставлять по нему ключ нельзя.
        //
        // Важнее другое: наше понятие «одинаковые варианты» обязано совпадать с тем, которым
        // пользуется question_sanity. Разъехавшись, они дают вопрос, который мы сочли
        // неоднозначным, а она пропустила дальше (найдено ревью).
        [$out, $trace] = $this->ask($this->generator(
            ['answer' => 'Жираф', 'correct' => 1],
            ['3) Жираф', 'Слон', 'Кит', 'Морж']
        ));

        $this->assertCount(1, $out);
        $this->assertSame(1, (int)$out[0]['correct'],
            'вариант с приставкой внутри не считаем совпадением - откатываемся на номер');
        $this->assertStringContainsString('Ключ взят номером', $trace);
    }

    public function test_text_key_is_not_shifted_as_one_based(): void {
        // Сдвиг «счет с единицы» относится ТОЛЬКО к номеру. Ключ, найденный сопоставлением,
        // трогать нельзя: он и так указывает на нужный вариант, и сдвиг был бы порчей.
        [$out, $trace] = $this->ask($this->generator(['answer' => 'Морж', 'correct' => 4]));

        $this->assertCount(1, $out);
        $this->assertSame(3, (int)$out[0]['correct']);
        $this->assertStringNotContainsString('посчитанных моделью с единицы', $trace);
    }
}
