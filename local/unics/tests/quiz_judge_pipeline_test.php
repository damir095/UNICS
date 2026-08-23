<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\answer_judge;

/**
 * Вторая фаза проверки в конвейере генерации теста ([[answer-judge-design]], раздел 4).
 *
 * Ярусы идут по порядку: арифметика, формальные признаки, слепой судья. Здесь проверяется
 * именно связка - что каждый ярус доходит до вопроса и что отказ судьи не стоит ребенку теста.
 *
 * @package local_unics
 */
final class quiz_judge_pipeline_test extends \advanced_testcase {

    /**
     * Генератор, различающий свои два вызова по промту.
     *
     * @param string $quiz ответ на просьбу составить тест
     * @param string|null $judge ответ судьи; null - отказ сети
     */
    private function generator(string $quiz, ?string $judge = null): ai_generator {
        return new class($quiz, $judge) extends ai_generator {
            private string $quiz;
            private ?string $judge;
            public int $judge_calls = 0;
            // Родительский конструктор не зову намеренно: он читает ключ API из настроек.
            public function __construct(string $quiz, ?string $judge) {
                $this->quiz = $quiz;
                $this->judge = $judge;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, answer_judge::PROMPT_MARKER)) {
                    $this->judge_calls++;
                    if ($this->judge === null) {
                        throw new \moodle_exception('generalexceptionmessage', 'error', '',
                            'сеть недоступна');
                    }
                    return $this->judge;
                }
                return $this->quiz;
            }
        };
    }

    private function quiz_reply(array $questions): string {
        return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
    }

    /** След генератора от последнего вызова quiet(). */
    private string $trace = '';

    /**
     * Прогнать генерацию, перехватив след.
     *
     * Под CLI генератор пишет через mtrace, и без перехвата PHPUnit метит каждый такой тест
     * рискованным; заодно след становится доступен для проверок.
     */
    private function quiet(ai_generator $gen, ...$args): array {
        ob_start();
        try {
            return $gen->generate_quiz(...$args);
        } finally {
            $this->trace = (string)ob_get_clean();
        }
    }

    public function test_broken_key_index_drops_question(): void {
        // Индекс ключа больше не зажимается молча: correct = 9 при четырех вариантах объявлял
        // верным последний, и ребенок получал «неверно» за верный ответ.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 0],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Лион', 'Ницца', 'Тур'],
             'correct' => 9],
        ]), '{"answers":[{"n":1,"choice":"Москва"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'География', '', 2);
        $this->assertCount(1, $out);
        $this->assertSame('Столица России?', $out[0]['text']);
    }

    public function test_duplicate_answers_drop_question(): void {
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'москва.', 'Казань', 'Самара'],
             'correct' => 0],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Лион', 'Ницца', 'Тур'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Париж"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'География', '', 2);
        $this->assertCount(1, $out);
        $this->assertSame('Столица Франции?', $out[0]['text']);
    }

    public function test_judge_drops_disputed_question(): void {
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 1],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Лион', 'Ницца', 'Тур'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"Париж"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'География', '', 2);
        $this->assertCount(1, $out);
        $this->assertSame('Столица Франции?', $out[0]['text'],
            'спорный вопрос не должен доехать до ребенка');
    }

    public function test_judge_agreement_keeps_everything(): void {
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 0],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Лион', 'Ницца', 'Тур'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"Париж"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'География', '', 2);
        $this->assertCount(2, $out, 'согласие судьи ничего не отбрасывает');
    }

    public function test_judge_failure_keeps_all_questions(): void {
        // Отказ проверки не может стоить ребенку теста.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 1],
        ]), null);

        $out = $this->quiet($gen, ['class_number' => 7], 'География', '', 1);
        $this->assertCount(1, $out);
        $this->assertSame(1, $gen->judge_calls, 'судью спросили ровно один раз на комплект');
    }

    public function test_judge_is_asked_once_per_batch(): void {
        $qs = [];
        for ($i = 0; $i < 5; $i++) {
            $qs[] = ['text' => 'Вопрос ' . $i, 'answers' => ['А', 'Б', 'В', 'Г'], 'correct' => 0];
        }
        $gen = $this->generator($this->quiz_reply($qs), '{"answers":[]}');
        $this->quiet($gen, ['class_number' => 7], 'История', '', 5);
        $this->assertSame(1, $gen->judge_calls, 'пять вопросов - один вызов судьи, не пять');
    }

    public function test_judge_is_not_asked_when_nothing_survived(): void {
        // Все вопросы выбиты ранними ярусами: спрашивать судью не о чем, а комплект пуст,
        // значит generate_quiz обязан пойти на вторую попытку и в итоге бросить исключение.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Вопрос?', 'answers' => ['А', 'А', 'В', 'Г'], 'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"А"}]}');

        try {
            $this->quiet($gen, ['class_number' => 7], 'История', '', 1);
            $this->fail('пустой комплект обязан бросать исключение');
        } catch (\moodle_exception $e) {
            $this->assertSame(0, $gen->judge_calls, 'судью не о чем спрашивать');
        }
    }

    public function test_arithmetic_tier_still_runs_first(): void {
        // Судья согласен с неверным ключом, но арифметика считает сама и правит его.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Найдите значение выражения: 2/5 + 1/5',
             'answers' => ['3/5', '3/10', '2/10', '1/5'], 'correct' => 1, 'solution' => ''],
        ]), '{"answers":[{"n":1,"choice":"3/10"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'Математика', '', 1);
        $this->assertCount(1, $out);
        $this->assertSame(0, $out[0]['correct'], 'ключ обязан переехать на верный вариант');
    }

    public function test_surplus_questions_backfill_after_judge(): void {
        // Модель регулярно присылает больше, чем просили. Если обрезать комплект ДО судьи,
        // добирать после его отбраковки будет нечем, и ребенок получит короткий тест.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 1],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Лион', 'Ницца', 'Тур'],
             'correct' => 0],
            ['text' => 'Столица Италии?', 'answers' => ['Рим', 'Милан', 'Турин', 'Генуя'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"Париж"},'
            . '{"n":3,"choice":"Рим"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'География', '', 2);
        $this->assertCount(2, $out, 'выбывшее место обязано занять запасное задание');
        $this->assertSame('Столица Италии?', $out[1]['text']);
    }

    public function test_fixed_key_is_counted_only_when_it_reaches_the_child(): void {
        // Ключ починили, но вопрос выбили признаки: в обеих колонках сразу он стоять не может.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Найдите значение выражения: 2/5 + 1/5',
             'answers' => ['3/5', '3/10', '3/5', '1/5'], 'correct' => 1, 'solution' => ''],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Лион', 'Ницца', 'Тур'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Париж"}]}');

        $out = $this->quiet($gen, ['class_number' => 7], 'Математика', '', 2);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('исправлено ключей 0', $this->trace);
        $this->assertStringContainsString('признаками 1', $this->trace);

        // Обратный случай в том же тесте: без него «ноль» проходил бы и при вовсе не
        // работающем счетчике - проверено мутацией.
        $good = $this->generator($this->quiz_reply([
            ['text' => 'Найдите значение выражения: 2/5 + 1/5',
             'answers' => ['3/5', '3/10', '2/10', '1/5'], 'correct' => 1, 'solution' => ''],
        ]), '{"answers":[]}');
        $this->quiet($good, ['class_number' => 7], 'Математика', '', 1);
        $this->assertStringContainsString('исправлено ключей 1', $this->trace,
            'дошедший до ребенка исправленный ключ обязан считаться');
    }

    public function test_trace_reports_successful_judging(): void {
        // Молчание удачного яруса неотличимо от того, что его перестали звать вовсе.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Москва"}]}');

        $this->quiet($gen, ['class_number' => 7], 'География', '', 1);
        $this->assertStringContainsString('Судья проверил вопросов: 1', $this->trace);
    }

    public function test_trace_reports_judge_failure(): void {
        // Отказ судьи, съеденный молча, неотличим от чистого прогона - ровно та ловушка,
        // на которой проект стоял с озвучкой.
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 1],
        ]), null);

        $this->quiet($gen, ['class_number' => 7], 'География', '', 1);
        $trace = $this->trace;
        $this->assertStringContainsString('судья', mb_strtolower($trace),
            'отказ судьи обязан оставить след');
    }

    public function test_trace_reports_counts(): void {
        $gen = $this->generator($this->quiz_reply([
            ['text' => 'Столица России?', 'answers' => ['Москва', 'Тверь', 'Казань', 'Самара'],
             'correct' => 1],
            ['text' => 'Столица Франции?', 'answers' => ['Париж', 'Париж', 'Ницца', 'Тур'],
             'correct' => 0],
            ['text' => 'Столица Италии?', 'answers' => ['Рим', 'Милан', 'Турин', 'Генуя'],
             'correct' => 0],
        ]), '{"answers":[{"n":1,"choice":"Москва"},{"n":2,"choice":"Рим"}]}');

        $this->quiet($gen, ['class_number' => 7], 'География', '', 3);
        $trace = $this->trace;
        $this->assertStringContainsString('признаками 1', $trace);
        $this->assertStringContainsString('судьей 1', $trace);
    }

    public function test_distrusted_judge_leaves_trace(): void {
        // Предохранитель сработал: вердикты сняты, но молчать об этом нельзя.
        $qs = [];
        for ($i = 0; $i < 4; $i++) {
            $qs[] = ['text' => 'Вопрос ' . $i, 'answers' => ['А', 'Б', 'В', 'Г'], 'correct' => 0];
        }
        $picks = [];
        foreach ([1, 2, 3] as $n) {
            $picks[] = ['n' => $n, 'choice' => 'Б'];
        }
        $picks[] = ['n' => 4, 'choice' => 'А'];
        $gen = $this->generator($this->quiz_reply($qs),
            json_encode(['answers' => $picks], JSON_UNESCAPED_UNICODE));

        $out = $this->quiet($gen, ['class_number' => 7], 'История', '', 4);
        $trace = $this->trace;
        $this->assertCount(4, $out, 'предохранитель обязан спасти комплект');
        $this->assertStringContainsString('судья', mb_strtolower($trace));
    }
}
