<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Сбитый счет вариантов ВНУТРИ диапазона: отбраковка превращается в починку.
 *
 * Ключ, равный числу вариантов, виден по самому значению ([[one-based-key-design]]). Ключ, сбитый
 * на единицу внутри диапазона, не виден ничем: `correct = 2` при четырех вариантах законен. Выдает
 * его только независимое мнение слепого судьи, и выдает характерно - его выбор ложится ровно на
 * вариант ПЕРЕД ключом.
 *
 * Существенно, что судья слеп к нашему порядку: варианты ему перемешиваются, отвечает он текстом.
 * Поэтому совпадение с `correct - 1` не может быть артефактом его нумерации.
 *
 * Порог - главное в этой правке. Ошибиться тут дороже, чем выбросить вопрос: неверный ключ у
 * ребенка означает «неверно» за верный ответ, а задание еще и уедет в общий пул. Поэтому одному
 * совпадению не верим: случайная ошибка судьи попадает на соседний вариант примерно в трети
 * случаев, а два и три разом - уже привычка модели в этом комплекте.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class judge_key_shift_test extends \advanced_testcase {

    /**
     * Генератор, у которого ключи заданы явно, а судья отвечает текстом нужного варианта.
     *
     * @param int[] $keys что модель кладет в correct по каждому вопросу
     * @param int[] $judgepicks индекс варианта, который выберет судья (по нашему порядку)
     */
    private function generator(array $keys, array $judgepicks): ai_generator {
        return new class($keys, $judgepicks) extends ai_generator {
            private array $keys;
            private array $picks;
            public function __construct(array $keys, array $picks) {
                $this->keys = $keys;
                $this->picks = $picks;
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
                    $rows = [];
                    foreach ($this->picks as $q => $at) {
                        if ($at === null) {
                            continue;
                        }
                        $rows[] = ['n' => $q + 1, 'choice' => $this->answers($q)[$at]];
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

    public function test_two_shifted_keys_are_fixed_instead_of_dropped(): void {
        // Судья спорит с двумя ключами, и оба раза выбирает вариант ПЕРЕД ключом. Раньше оба
        // вопроса выбывали, и комплект укорачивался вдвое.
        $gen = $this->generator([2, 3, 1], [1, 2, 1]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(3, $out, 'годные вопросы не должны выбывать из-за сбитого счета');
        $this->assertSame(1, (int)$out[0]['correct']);
        $this->assertSame(2, (int)$out[1]['correct']);
        $this->assertSame(1, (int)$out[2]['correct'], 'согласованный ключ не трогаем');
        $this->assertStringContainsString('Ключ переставлен по выбору судьи: 2', $trace);
    }

    public function test_single_match_is_not_trusted(): void {
        // Одно совпадение - в пределах случайной ошибки судьи. Двигать ключ по нему нельзя:
        // цена ошибки тут выше цены потерянного вопроса.
        $gen = $this->generator([2, 1, 3], [1, 1, 3]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(2, $out, 'спорный вопрос выбывает, как и раньше');
        $this->assertStringContainsString('одиночному совпадению не верим', $trace);
        $this->assertStringNotContainsString('Ключ переставлен', $trace);
    }

    public function test_disagreement_elsewhere_is_still_a_drop(): void {
        // Судья спорит, но выбирает НЕ соседний вариант: это обычный неверный ключ, и признака
        // сбитого счета тут нет. Такой вопрос выбывает даже рядом с настоящими сдвигами.
        $gen = $this->generator([1, 1, 1], [0, 0, 3]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(2, $out);
        $this->assertStringContainsString('Ключ переставлен по выбору судьи: 2', $trace);
        $this->assertSame(0, (int)$out[0]['correct']);
        $this->assertSame(0, (int)$out[1]['correct']);
    }

    public function test_agreement_is_never_touched(): void {
        // Судья со всеми согласен - двигать нечего. Проверка на то, что правило не срабатывает
        // от одного лишь наличия вердиктов.
        $gen = $this->generator([2, 1, 3], [2, 1, 3]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(3, $out);
        $this->assertSame(2, (int)$out[0]['correct']);
        $this->assertStringNotContainsString('Ключ переставлен', $trace);
    }

    public function test_key_shifted_at_parse_time_is_not_shifted_again(): void {
        // Вопросу уже двигали ключ при разборе (correct = 4 при четырех вариантах). Если судья
        // после этого все равно спорит, второй сдвиг в ту же сторону был бы разгоном догадки.
        // Порог берут ДРУГИЕ два вопроса, так что дело не в нем: уже сдвинутый вопрос обязан
        // выбыть даже тогда, когда починка в комплекте разрешена.
        $gen = $this->generator([4, 2, 3], [2, 1, 2]);

        [$out, $trace] = $this->ask($gen, 3);

        $this->assertCount(2, $out, 'уже сдвинутый вопрос выбывает, двое остальных чинятся');
        $this->assertStringContainsString('Ключ переставлен по выбору судьи: 2', $trace);
        foreach ($out as $q) {
            $this->assertStringNotContainsString('Вопрос номер 0', (string)$q['text'],
                'вопрос, которому ключ уже двигали, второй раз двигать нельзя');
        }
    }
}
