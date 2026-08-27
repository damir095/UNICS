<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Признак сбитого на единицу ключа ВНУТРИ диапазона: считаем, но ключ не переносим.
 *
 * Ключ, равный числу вариантов, виден по самому значению ([[one-based-key-design]]). Ключ, сбитый
 * на единицу внутри диапазона, не виден ничем: `correct = 2` при четырех вариантах законен. Выдает
 * его только независимое мнение слепого судьи, и выдает характерно - его выбор ложится ровно на
 * вариант ПЕРЕД ключом. Проба на сорока вопросах: расхождений восемь, семь из них такие.
 *
 * Соблазн переставлять по этому признаку ключ был реализован и ОТКАЧЕН ([[judge-key-shift-design]]).
 * Причины: выигрыш вышел два вопроса из сорока при полных комплектах; вероятность случайного
 * попадания судьи на соседний вариант около трети, то есть пара таких совпадений набирается сама
 * примерно в каждом девятом комплекте; и главное - проект держит границу «ключ правит то, что
 * ВЫЧИСЛЯЕТ ответ, а не то, что его мнит» (докблок answer_judge).
 *
 * Эти тесты сторожат откат: спорный вопрос обязан выбывать, а признак - попадать в след.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class judge_key_shift_test extends \advanced_testcase {

    /**
     * Генератор с заданными ключами и заданным выбором судьи.
     *
     * Судье вопросы приходят перенумерованными (в промт идут только те, что не решены расчетом),
     * поэтому заглушка отвечает не по своему порядку, а по номерам ИЗ ПРОМТА - иначе стоит
     * появиться арифметическому вопросу, и выборы молча разъедутся (найдено ревью).
     *
     * @param int[] $keys что модель кладет в correct по каждому вопросу
     * @param array $judgepicks индекс варианта, который выберет судья (null - промолчит)
     * @param bool  $judgeworks отвечает ли судья вообще
     */
    private function generator(array $keys, array $judgepicks,
                               bool $judgeworks = true): ai_generator {
        return new class($keys, $judgepicks, $judgeworks) extends ai_generator {
            private array $keys;
            private array $picks;
            private bool $works;
            public function __construct(array $keys, array $picks, bool $works) {
                $this->keys = $keys;
                $this->picks = $picks;
                $this->works = $works;
            }
            /** Варианты вопроса: заведомо разные тексты, чтобы сопоставление шло однозначно. */
            private function answers(int $q): array {
                $out = [];
                for ($i = 0; $i < 4; $i++) {
                    $out[] = 'Вопрос ' . $q . ' вариант ' . $i;
                }
                return $out;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    if (!$this->works) {
                        // Именно ИСКЛЮЧЕНИЕ: пустая строка дает другую ветку (ответ пришел, но
                        // не пригодился), и тест про отказ сети проверял бы не то.
                        throw new \moodle_exception('generalexceptionmessage', 'error', '',
                            'сеть недоступна');
                    }
                    // Номер в промте -> наш номер вопроса.
                    preg_match_all('~^([0-9]+)\. Вопрос номер ([0-9]+)\?~mu', $prompt, $m,
                        PREG_SET_ORDER);
                    $rows = [];
                    foreach ($m as $hit) {
                        $q = (int)$hit[2];
                        $at = $this->picks[$q] ?? null;
                        if ($at === null) {
                            continue;
                        }
                        $rows[] = ['n' => (int)$hit[1], 'choice' => $this->answers($q)[$at]];
                    }
                    return json_encode(['answers' => $rows], JSON_UNESCAPED_UNICODE);
                }
                $questions = [];
                foreach ($this->keys as $q => $key) {
                    $questions[] = [
                        'text' => 'Вопрос номер ' . $q . '?',
                        'answers' => $this->answers($q),
                        'correct' => $key,
                    ];
                }
                return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    /** @return array{0:array,1:string} результат и след. */
    private function ask(ai_generator $gen, int $num): array {
        ob_start();
        try {
            $out = $gen->generate_quiz([], 'Дроби', '', $num);
        } catch (\moodle_exception $e) {
            $out = [];
        } finally {
            $trace = ob_get_clean();
        }
        return [$out, $trace];
    }

    public function test_shift_like_disagreement_is_still_a_drop(): void {
        // Главное свойство: сколько бы признаков ни набралось, ключ остается прежним, а спорные
        // вопросы выбывают. Именно это и откатили.
        $gen = $this->generator([2, 3, 1], [1, 2, 1]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(1, $out, 'спорные вопросы выбывают, а не чинятся');
        $this->assertSame('Вопрос номер 2?', (string)$out[0]['text']);
        $this->assertSame(1, (int)$out[0]['correct'], 'согласованный ключ не трогаем');
        $this->assertStringNotContainsString('переставлен', $trace);
    }

    public function test_the_sign_is_counted_in_the_trace(): void {
        // Считать признак нужно: без счетчика нельзя узнать, сколько заданий уходит в мусор
        // из-за сбитого на единицу ключа, и стоит ли возвращаться к задаче.
        $gen = $this->generator([2, 3, 1], [1, 2, 1]);

        [, $trace] = $this->ask($gen, 3);

        $this->assertStringContainsString('Похоже на сбитый на единицу ключ: 2', $trace);
    }

    public function test_disagreement_elsewhere_is_not_counted(): void {
        // Судья спорит, но выбирает НЕ соседний вариант: это обычный неверный ключ, признака
        // сбитого счета тут нет, и в счетчик он попадать не должен.
        $gen = $this->generator([1, 1, 1], [3, 3, 1]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(1, $out);
        $this->assertStringNotContainsString('Похоже на сбитый на единицу ключ', $trace);
    }

    public function test_agreement_is_never_counted(): void {
        $gen = $this->generator([2, 1, 3], [2, 1, 3]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(3, $out);
        $this->assertStringNotContainsString('Похоже на сбитый на единицу ключ', $trace);
    }

    public function test_nothing_is_counted_when_the_judge_failed(): void {
        // Судья не ответил - вердиктов нет, спорных нет, и признаку взяться неоткуда. Проверка
        // на то, что счетчик не читает пустые вердикты как согласие или как спор (найдено ревью:
        // ветки отказа судьи не были покрыты вовсе).
        $gen = $this->generator([2, 3, 1], [1, 2, 1], false);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(3, $out, 'отказ судьи комплект не роняет');
        $this->assertStringNotContainsString('Похоже на сбитый на единицу ключ', $trace);
        $this->assertStringContainsString('Судья не ответил', $trace);
    }

    public function test_nothing_is_counted_when_the_judge_is_unusable(): void {
        // Судья ответил, но ни один его выбор не сошелся с вариантами: проверка не состоялась,
        // и признак считать не по чему.
        $gen = $this->generator([2, 3, 1], [null, null, null]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(3, $out);
        $this->assertStringNotContainsString('Похоже на сбитый на единицу ключ', $trace);
        $this->assertStringContainsString('проверка не состоялась', $trace);
    }
}
