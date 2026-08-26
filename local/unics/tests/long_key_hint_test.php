<?php
namespace local_unics;

use local_unics\ai\question_sanity;

/**
 * Длина верного ответа как подсказка ([[long-key-hint-design]]).
 *
 * Зонд 2026-08-25 (комплект целиком, ученица с ЗПР): в двух вопросах из пяти ключ был заметно
 * длиннее прочих вариантов - 56 против 26/32/45 и 63 против 14/27/16. Первый случай прежняя
 * проверка не видела вовсе: она мерила только против самого длинного дистрактора, а он и прятал
 * перекос.
 *
 * Отбраковывать такие вопросы нельзя: развёрнутый верный ответ бывает законной приметой хорошего
 * задания, и отличить его от подсказки ПО ДЛИНЕ невозможно - это одно и то же измерение. Поэтому
 * признак остаётся подозрением, а профилактика ушла в промт.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_sanity::class)]
final class long_key_hint_test extends \advanced_testcase {

    /** Вердикт по вопросу с ключом и дистракторами заданной длины. */
    private function verdict(int $keylen, array $distlens, int $correct = 0): array {
        $texts = [];
        foreach ($distlens as $len) {
            $texts[] = str_repeat('я', $len);
        }
        array_splice($texts, $correct, 0, [str_repeat('к', $keylen)]);
        return question_sanity::verdict('Какой ответ верный?', $texts, $correct);
    }

    private function noted(array $v): bool {
        return in_array('ключ заметно длиннее прочих вариантов', $v['notes'], true);
    }

    public function test_skew_hidden_by_a_long_distractor_is_now_seen(): void {
        // Главная проверка задачи: случай из зонда, 56 против 26/32/45. По самому длинному не
        // видно (45*2 = 90), по среднему (34.3) - видно сразу.
        $v = $this->verdict(56, [26, 32, 45]);

        $this->assertTrue($this->noted($v), 'перекос по среднему обязан быть замечен');
    }

    public function test_gross_skew_is_noted_too(): void {
        // Второй случай из зонда: 63 против 14/27/16. Ловится и по максимуму, и по среднему.
        $v = $this->verdict(63, [14, 27, 16]);

        $this->assertTrue($this->noted($v));
    }

    public function test_long_key_is_not_dropped(): void {
        // Развёрнутый верный ответ - законная примета хорошего задания. Отличить его от
        // подсказки по длине нельзя, поэтому вопрос принимается: решает педагог, увидев пометку.
        $v = $this->verdict(72, [10, 11, 13]);

        $this->assertNotSame('drop', $v['verdict']);
        $this->assertTrue($this->noted($v));
    }

    public function test_short_variants_do_not_trigger_on_ratio_alone(): void {
        // «Крокодил» (8) против «Кит»/«Еж»/«Лиса» - отношение 2.0, а разница четыре символа.
        // Прочесть четыре коротких слова легко, подсказки нет. Найдено падением чужого теста.
        $v = $this->verdict(8, [3, 2, 4]);

        $this->assertFalse($this->noted($v), 'отношение без абсолютной разницы ничего не значит');
    }

    public function test_two_word_answer_against_one_word(): void {
        // «часть целого» (12) против «сумма» (5): то же самое на паре вариантов.
        $v = $this->verdict(12, [5]);

        $this->assertFalse($this->noted($v));
    }

    public function test_equal_lengths_are_clean(): void {
        $v = $this->verdict(30, [28, 32, 29]);

        $this->assertSame('ok', $v['verdict']);
        $this->assertFalse($this->noted($v));
    }

    public function test_short_key_is_clean(): void {
        // Обратный перекос подсказкой не считаем: привычки «выбираю самый короткий» не
        // наблюдали.
        $v = $this->verdict(8, [40, 45, 38]);

        $this->assertFalse($this->noted($v));
    }

    public function test_key_position_does_not_matter(): void {
        foreach ([0, 1, 2, 3] as $pos) {
            $this->assertTrue($this->noted($this->verdict(63, [14, 27, 16], $pos)),
                "ключ на позиции $pos");
        }
    }
}
