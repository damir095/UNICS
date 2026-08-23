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

    /** Четыре вопроса с ключом на первом варианте. */
    private function four(): array {
        $qs = [];
        for ($i = 0; $i < 4; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        return $qs;
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

    public function test_first_choice_wins_over_echoed_sample(): void {
        // Модель дописывает к ответу образец формата из промта - тогда второй выбор на тот же
        // номер затирал бы настоящий ответ.
        $raw = '{"answers":[{"n":1,"choice":"Москва"},{"n":1,"choice":"дословный текст варианта"}]}';
        $this->assertSame('Москва', answer_judge::parse($raw, 1)[0]);
    }

    public function test_non_scalar_choice_is_ignored(): void {
        // Без гейта (string) на массиве дает предупреждение и литерал «Array».
        $raw = '{"answers":[{"n":1,"choice":["Москва"]}]}';
        $this->assertNull(answer_judge::parse($raw, 1)[0]);
    }

    public function test_agreement_keeps_question(): void {
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 0)];
        $out = answer_judge::verdicts($qs, ['москва']);
        $this->assertSame(['ok'], $out['verdicts']);
        $this->assertSame(answer_judge::STATUS_JUDGED, $out['status']);
    }

    public function test_disagreement_drops_question(): void {
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)];
        $this->assertSame(['drop'], answer_judge::verdicts($qs, ['Москва'])['verdicts']);
    }

    public function test_silent_judge_keeps_question(): void {
        // Отказ проверки не может стоить ребенку теста.
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)];
        $this->assertSame(['ok'], answer_judge::verdicts($qs, [null])['verdicts']);
    }

    public function test_unknown_choice_keeps_question(): void {
        // Судья назвал то, чего нет среди вариантов - это его сбой, а не брак задания.
        // Ключ намеренно НЕ нулевой: при correct = 0 тест был тавтологией, потому что
        // (int)false === 0 и отсутствие проверки давало тот же «ok» (найдено ревью).
        $qs = [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 2)];
        $out = answer_judge::verdicts($qs, ['Петербург']);
        $this->assertSame(['ok'], $out['verdicts']);
        $this->assertSame(0, $out['judged'], 'сбой судьи не считается высказыванием');
    }

    public function test_duplicate_answer_text_is_not_a_disagreement(): void {
        // Выбранный текст встречается дважды: какой имел в виду судья - неизвестно, и
        // «расхождение» было бы выдумкой.
        $qs = [$this->q('Столица России?', ['Москва', 'Москва', 'Тверь', 'Казань'], 1)];
        $this->assertSame(['ok'], answer_judge::verdicts($qs, ['Москва'])['verdicts']);
    }

    public function test_keys_of_filtered_list_are_preserved(): void {
        // Судью спрашивают только про дожившее до него, поэтому массив приходит с дырами.
        $qs = [2 => $this->q('Столица России?', ['Москва', 'Тверь'], 0),
               5 => $this->q('Столица Франции?', ['Париж', 'Лион'], 0)];
        $out = answer_judge::verdicts($qs, ['Тверь', 'Париж']);
        $this->assertSame([2 => 'drop', 5 => 'ok'], $out['verdicts'],
            'вердикт обязан остаться при своем вопросе');
    }

    public function test_total_disagreement_trusts_nobody(): void {
        // Судья, разошедшийся со ВСЕМИ своими высказываниями, почти наверняка сбился сам.
        // Малые комплекты долевой порог не прикрывает: пул отдает воркеру и один вопрос.
        $qs = [$this->q('Вопрос 1', ['А', 'Б', 'В', 'Г'], 0),
               $this->q('Вопрос 2', ['А', 'Б', 'В', 'Г'], 0)];
        $out = answer_judge::verdicts($qs, ['Б', 'Б']);
        $this->assertSame(['ok', 'ok'], $out['verdicts']);
        $this->assertSame(answer_judge::STATUS_DISTRUST, $out['status']);
    }

    public function test_majority_disagreement_trusts_nobody(): void {
        $out = answer_judge::verdicts($this->four(), ['Б', 'Б', 'Б', 'А']);
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $out['verdicts'],
            'предохранитель обязан спасти комплект');
        $this->assertSame(answer_judge::STATUS_DISTRUST, $out['status']);
    }

    public function test_half_disagreement_is_trusted(): void {
        // Ровно половина - еще доверяем: порог именно «больше половины».
        $out = answer_judge::verdicts($this->four(), ['Б', 'Б', 'А', 'А']);
        $this->assertSame(['drop', 'drop', 'ok', 'ok'], $out['verdicts']);
        $this->assertSame(answer_judge::STATUS_JUDGED, $out['status']);
    }

    public function test_share_threshold_needs_enough_verdicts(): void {
        // Три высказывания, два расхождения - доля выше половины, но высказываний мало.
        $qs = [];
        for ($i = 0; $i < 3; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $out = answer_judge::verdicts($qs, ['Б', 'Б', 'А']);
        $this->assertSame(['drop', 'drop', 'ok'], $out['verdicts'],
            'долевой порог не должен включаться на трех высказываниях');
    }

    public function test_silence_is_not_counted_in_the_denominator(): void {
        // Восемь вопросов, судья высказался по четырем и разошелся на трех: доля 3/4 выше
        // половины, предохранитель обязан сработать. Если бы молчание попадало в знаменатель,
        // доля стала бы 3/8 и три годных вопроса были бы отброшены.
        $qs = [];
        for ($i = 0; $i < 8; $i++) {
            $qs[] = $this->q('Вопрос ' . $i, ['А', 'Б', 'В', 'Г'], 0);
        }
        $out = answer_judge::verdicts($qs, [null, null, null, null, 'Б', 'Б', 'Б', 'А']);
        $this->assertSame(answer_judge::STATUS_DISTRUST, $out['status']);
        $this->assertSame(4, $out['judged'], 'молчание не высказывание');
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

    public function test_prompt_does_not_depend_on_the_key(): void {
        // Слепота судьи - весь смысл яруса: промт для одного и того же вопроса обязан быть
        // неотличим при любом ключе. Иначе ярус тихо схлопывается в одного свидетеля.
        $lines = function (int $correct): array {
            $out = preg_split('~\R~u',
                answer_judge::build_prompt([$this->q('Столица России?',
                    ['Москва', 'Тверь', 'Казань', 'Самара'], $correct)]));
            // Номер варианта снимаем: он переставляется перемешиванием, а сравнивать надо
            // содержание промта, а не порядок строк.
            $out = array_map(static fn(string $s): string
                => preg_replace('~^\s*\d+\)\s*~u', '', $s), $out);
            sort($out);
            return $out;
        };
        $this->assertSame($lines(0), $lines(3));
    }

    /** Заглушка генератора: родительский конструктор не зовем - он читает ключ API из настроек. */
    private function stub(callable $reply): \local_unics\ai\ai_generator {
        return new class($reply) extends \local_unics\ai\ai_generator {
            private $reply;
            public int $calls = 0;
            public function __construct(callable $reply) {
                $this->reply = $reply;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                $this->calls++;
                return ($this->reply)($prompt);
            }
        };
    }

    public function test_review_reports_failure_when_model_fails(): void {
        $gen = $this->stub(function (): string {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'сеть недоступна');
        });
        $out = (new answer_judge($gen))->review(
            [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)]);
        $this->assertSame(['ok'], $out['verdicts'], 'отказ судьи не может стоить ребенку теста');
        $this->assertSame(answer_judge::STATUS_FAILED, $out['status'],
            'иначе отказ сети неотличим от чистого прогона');
    }

    public function test_review_reports_unusable_reply_apart_from_outage(): void {
        // Ответ пришел, но выбрать из него нечего. Путать это с отказом сети нельзя:
        // устойчивое расхождение форматов лечится правкой промта, а не ожиданием связи.
        $gen = $this->stub(fn(): string => 'извините, не могу помочь');
        $out = (new answer_judge($gen))->review(
            [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)]);
        $this->assertSame(['ok'], $out['verdicts']);
        $this->assertSame(answer_judge::STATUS_UNUSABLE, $out['status']);
    }

    public function test_review_survives_non_moodle_errors(): void {
        // TypeError из сетевого слоя (curl_init вернул false) прошел бы насквозь и убил бы
        // комплект, уже прошедший все три яруса.
        $gen = $this->stub(function (): string {
            throw new \TypeError('curl_setopt_array(): Argument #1 must be of type CurlHandle');
        });
        $out = (new answer_judge($gen))->review(
            [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 1)]);
        $this->assertSame(['ok'], $out['verdicts']);
        $this->assertSame(answer_judge::STATUS_FAILED, $out['status']);
    }

    public function test_review_lowers_the_empty_reply_threshold(): void {
        // Выбор по одному вопросу занимает 39 символов, а обычный порог «пустого ответа» - 50:
        // при нем ярус был мертв для малых комплектов и докладывал о себе как об отказе сети.
        $seen = null;
        $gen = new class($seen) extends \local_unics\ai\ai_generator {
            public ?int $minlen = null;
            public function __construct(&$seen) {
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                $this->minlen = $minlen;
                return '{"answers":[{"n":1,"choice":"Москва"}]}';
            }
        };
        (new answer_judge($gen))->review(
            [$this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 0)]);
        $this->assertNotNull($gen->minlen);
        $this->assertLessThan(40, $gen->minlen, 'иначе короткий ответ судьи сочтут пустым');
    }

    public function test_review_drops_disagreed_question(): void {
        $gen = $this->stub(fn(): string =>
            '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"Париж"}]}');
        $out = (new answer_judge($gen))->review([
            $this->q('Столица России?', ['Москва', 'Тверь', 'Казань', 'Самара'], 2),
            $this->q('Столица Франции?', ['Париж', 'Лион', 'Ницца', 'Тур'], 0),
        ]);
        $this->assertSame(['drop', 'ok'], $out['verdicts']);
        $this->assertSame(answer_judge::STATUS_JUDGED, $out['status']);
    }

    public function test_review_of_empty_list_makes_no_call(): void {
        $gen = $this->stub(fn(): string => '{"answers":[]}');
        $out = (new answer_judge($gen))->review([]);
        $this->assertSame([], $out['verdicts']);
        $this->assertSame(0, $gen->calls, 'пустой комплект не стоит обращения к сети');
    }
}
