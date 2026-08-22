<?php
namespace local_unics;

use local_unics\ai\answer_judge;

/**
 * Слепой судья: повторный ответ модели, не видящей ключа ([[answer-judge-design]], раздел 2.2).
 *
 * Зонд 2026-08-22 показал, ради чего судья спрашивается ОТДЕЛЬНЫМ вызовом: поле solution в том
 * же ответе модель заполнила ошибкой, согласованной с ключом, и подтвердила саму себя.
 *
 * @package local_unics
 */
final class answer_judge_test extends \advanced_testcase {

    private function q(string $text, array $answers, int $correct): array {
        return ['text' => $text, 'answers' => $answers, 'correct' => $correct];
    }

    public function test_parses_reply(): void {
        $raw = '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"1861"}]}';
        $out = answer_judge::parse($raw, 2);
        $this->assertSame(['Москва', '1861'], [$out[0], $out[1]]);
    }

    public function test_missing_answer_is_null(): void {
        $out = answer_judge::parse('{"answers":[{"n":2,"choice":"1861"}]}', 2);
        $this->assertNull($out[0], 'пропуск судьи не должен смещать нумерацию');
        $this->assertSame('1861', $out[1]);
    }

    public function test_empty_choice_is_null(): void {
        // Судье разрешено не знать ответа: пустой choice означает «не уверен», а не «расхождение».
        $out = answer_judge::parse('{"answers":[{"n":1,"choice":""}]}', 1);
        $this->assertNull($out[0]);
    }

    public function test_agreement_keeps_question(): void {
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 0)];
        $this->assertSame(['ok'], answer_judge::verdicts($qs, ['москва']));
    }

    public function test_disagreement_drops_question(): void {
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)];
        $this->assertSame(['drop'], answer_judge::verdicts($qs, ['Москва']));
    }

    public function test_silent_judge_keeps_question(): void {
        // Отказ проверки не может стоить ребенку теста.
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)];
        $this->assertSame(['ok'], answer_judge::verdicts($qs, [null]));
    }

    public function test_unknown_choice_keeps_question(): void {
        // Судья назвал то, чего нет среди вариантов - это его сбой, а не брак задания.
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 0)];
        $this->assertSame(['ok'], answer_judge::verdicts($qs, ['Петербург']));
    }

    public function test_majority_disagreement_trusts_nobody(): void {
        // Судья, расходящийся чаще чем в половине случаев, скорее сбился сам.
        $qs = [];
        for ($i = 0; $i < 4; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $out = answer_judge::verdicts($qs, ['Б', 'Б', 'Б', 'А']);
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $out, 'предохранитель обязан спасти комплект');
    }

    public function test_half_disagreement_is_trusted(): void {
        // Ровно половина - еще доверяем: порог именно «больше половины».
        $qs = [];
        for ($i = 0; $i < 4; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $out = answer_judge::verdicts($qs, ['Б', 'Б', 'А', 'А']);
        $this->assertSame(['drop', 'drop', 'ok', 'ok'], $out);
    }

    public function test_silence_is_not_counted_as_disagreement(): void {
        // Молчание судьи не должно копиться в счетчик расхождений: три молчания и одно
        // расхождение - это не «сбился на большинстве», а одно найденное спорное задание.
        $qs = [];
        for ($i = 0; $i < 4; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $out = answer_judge::verdicts($qs, [null, null, null, 'Б']);
        $this->assertSame(['ok', 'ok', 'ok', 'drop'], $out);
    }

    public function test_distrust_needs_enough_verdicts(): void {
        // На одном-двух высказываниях доли нет: одно расхождение из одного - сразу «сто
        // процентов», и предохранитель глушил бы судью всегда, обессмысливая ярус.
        $qs = [];
        for ($i = 0; $i < 3; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $out = answer_judge::verdicts($qs, ['Б', 'Б', 'Б']);
        $this->assertSame(['drop', 'drop', 'drop'], $out,
            'предохранитель не должен включаться, пока судья высказался слишком мало');
    }

    public function test_prompt_shuffles_answers(): void {
        // Позиционная привычка модели при ответе не должна совпадать с ее же привычкой
        // при генерации: иначе согласие двух свидетелей оказывается согласием одного.
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $prompt = answer_judge::build_prompt($items);
        preg_match_all('~^\s*1\)\s*(.+)$~mu', $prompt, $m);
        $this->assertCount(12, $m[1], 'у каждого вопроса ровно один первый вариант');
        $this->assertNotSame(array_fill(0, 12, 'А'), array_map('trim', $m[1]),
            'варианты обязаны перемешиваться');
    }

    public function test_prompt_keeps_every_answer(): void {
        // Перемешивание не имеет права терять варианты.
        $prompt = answer_judge::build_prompt([
            $this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 0)]);
        foreach (['Москва', 'Тверь', 'Казань', 'Самара'] as $a) {
            $this->assertStringContainsString($a, $prompt);
        }
    }

    public function test_review_returns_ok_when_model_fails(): void {
        $gen = new class extends \local_unics\ai\ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                throw new \moodle_exception('generalexceptionmessage', 'error', '', 'сеть недоступна');
            }
        };
        $judge = new answer_judge($gen);
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)];
        $this->assertSame(['ok'], $judge->review($qs));
    }

    public function test_review_returns_ok_when_reply_is_garbage(): void {
        $gen = new class extends \local_unics\ai\ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                return 'извините, не могу помочь';
            }
        };
        $judge = new answer_judge($gen);
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)];
        $this->assertSame(['ok'], $judge->review($qs));
    }

    public function test_review_drops_disagreed_question(): void {
        $gen = new class extends \local_unics\ai\ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                return '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"Париж"}]}';
            }
        };
        $judge = new answer_judge($gen);
        $out = $judge->review([
            $this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 2),
            $this->q('Столица Франции?', ['Париж', 'Лион', 'Ницца', 'Тур'], 0),
        ]);
        $this->assertSame(['drop', 'ok'], $out);
    }

    public function test_review_of_empty_list_makes_no_call(): void {
        $gen = new class extends \local_unics\ai\ai_generator {
            public int $calls = 0;
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
                $this->calls++;
                return '{"answers":[]}';
            }
        };
        $judge = new answer_judge($gen);
        $this->assertSame([], $judge->review([]));
        $this->assertSame(0, $gen->calls, 'пустой комплект не стоит обращения к сети');
    }
}
