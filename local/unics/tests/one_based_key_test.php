<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Модель считает варианты от единицы ([[one-based-key-design]]).
 *
 * Проба 2026-08-27 на сорока двух вопросах: пять индексов оказались вне диапазона, и ВСЕ пять
 * одного вида - `correct = 4` при четырех вариантах. То есть модель нумерует варианты с единицы, а
 * не с нуля, и мы выбрасывали каждый восьмой годный вопрос.
 *
 * Сдвигается ровно этот однозначный случай: индекс, равный ЧИСЛУ вариантов. Все, что выходит за
 * диапазон сильнее, по-прежнему отбраковывается - это не возврат к августовскому зажиму, который
 * прятал ошибку («correct = 7 при четырех вариантах молча объявлял верным последний»).
 *
 * Сдвиг - ДОГАДКА, и держится она на проверке: ревью показало, что при отказе судьи вердиктов нет
 * вовсе, и сдвинутый ключ уходил бы ребенку никем не проверенным. Поэтому непроверенный сдвиг
 * выбывает - ровно так, как выбывал до всей этой правки.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class one_based_key_test extends \advanced_testcase {

    /**
     * Генератор с одним вопросом и заданным значением correct.
     *
     * @param int  $correct что модель кладет в поле correct
     * @param int  $variants сколько вариантов ответа
     * @param bool $judgeworks отвечает ли слепой судья (иначе - отказ сети)
     */
    private function generator(int $correct, int $variants = 4,
                               bool $judgeworks = true): ai_generator {
        return new class($correct, $variants, $judgeworks) extends ai_generator {
            private int $correct;
            private int $variants;
            private bool $judgeworks;
            public function __construct(int $correct, int $variants, bool $judgeworks) {
                $this->correct = $correct;
                $this->variants = $variants;
                $this->judgeworks = $judgeworks;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    if (!$this->judgeworks) {
                        return '';
                    }
                    // Судья решает задание сам и называет ТЕКСТ варианта. Он выбирает тот,
                    // который модель и имела в виду: при счете от единицы это вариант с номером
                    // correct, иначе - следующий за индексом.
                    $intended = $this->correct === $this->variants
                        ? $this->correct
                        : $this->correct + 1;
                    return json_encode(['answers' => [['n' => 1,
                        'choice' => 'Вариант номер ' . $intended]]], JSON_UNESCAPED_UNICODE);
                }
                $answers = [];
                for ($i = 1; $i <= $this->variants; $i++) {
                    $answers[] = 'Вариант номер ' . $i;
                }
                return json_encode(['questions' => [[
                    'text' => 'Какой вариант верный?',
                    'answers' => $answers,
                    'correct' => $this->correct,
                ]]], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    private function ask(ai_generator $gen): array {
        ob_start();
        try {
            return $gen->generate_quiz([], 'Дроби', '', 1);
        } finally {
            ob_end_clean();
        }
    }

    /** @return array{0:array,1:string} результат и след; при отбраковке всего результат пуст. */
    private function ask_traced(ai_generator $gen): array {
        ob_start();
        try {
            $out = $gen->generate_quiz([], 'Дроби', '', 1);
        } catch (\moodle_exception $e) {
            $out = [];
        } finally {
            $trace = ob_get_clean();
        }
        return [$out, $trace];
    }

    public function test_index_equal_to_count_is_shifted(): void {
        // Главный случай пробы: correct = 4 при четырех вариантах означает «четвертый вариант».
        $out = $this->ask($this->generator(4, 4));

        $this->assertCount(1, $out, 'вопрос обязан уцелеть, а не выбыть');
        $this->assertSame(3, (int)$out[0]['correct'], 'ключ переезжает на последний вариант');
        $this->assertSame('Вариант номер 4', $out[0]['answers'][3]);
    }

    public function test_shift_works_for_three_variants_too(): void {
        // Правило про счет с единицы, а не про число четыре.
        $out = $this->ask($this->generator(3, 3));

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_index_far_out_of_range_is_still_dropped(): void {
        // Августовский урок: correct = 7 при четырех вариантах означает, что модель ПОТЕРЯЛА
        // соответствие ключа вариантам. Зажимать такое нельзя - ребенок получил бы «неверно»
        // за верный ответ.
        $this->expectException(\moodle_exception::class);
        $this->ask($this->generator(7, 4));
    }

    public function test_normal_index_is_untouched(): void {
        $out = $this->ask($this->generator(2, 4));

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }

    public function test_zero_index_is_untouched(): void {
        // Ноль - законный первый вариант, и сдвигать его было бы порчей.
        $out = $this->ask($this->generator(0, 4));

        $this->assertCount(1, $out);
        $this->assertSame(0, (int)$out[0]['correct']);
    }

    public function test_negative_index_is_dropped(): void {
        $this->expectException(\moodle_exception::class);
        $this->ask($this->generator(-1, 4));
    }

    public function test_shift_is_visible_in_the_trace(): void {
        // Молчаливая правка ключа - это то, чего проект избегает: педагог должен видеть, что
        // задание пришло со сбитым счетом. Счетчик СВОЙ, а не общий с арифметикой: иначе по логу
        // не отличить «модель исправилась» от «сдвиг вывозит каждый восьмой вопрос».
        [$out, $trace] = $this->ask_traced($this->generator(4, 4));

        $this->assertCount(1, $out);
        $this->assertStringContainsString('Ключей, посчитанных моделью с единицы: 1', $trace);
    }

    public function test_shift_is_dropped_when_the_judge_failed(): void {
        // Ключевое ограничение: без проверки сдвиг не выпускаем. Задание неарифметическое, так
        // что расчет молчит, а судья не ответил - значит подвинутый нами ключ не подтвердил
        // никто, и вопрос выбывает, как выбывал до правки.
        [$out, $trace] = $this->ask_traced($this->generator(4, 4, false));

        $this->assertSame([], $out, 'непроверенный сдвиг не имеет права дойти до ребенка');
        $this->assertStringContainsString('сдвигом без проверки 1', $trace);
    }

    public function test_unshifted_question_survives_judge_failure(): void {
        // Предохранитель обязан быть узким: отказ судьи сам по себе комплект не убивает, иначе
        // мы поменяли бы одну беду на другую, куда более частую.
        $out = $this->ask($this->generator(2, 4, false));

        $this->assertCount(1, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
    }
}
