<?php
namespace local_unics;

use local_unics\ai\ai_generator;

/**
 * Комплект не усыхает: у модели просим с запасом ([[quiz-buffer-design]]).
 *
 * Живая генерация 2026-08-26: из двух заказов по пять вопросов вышло четыре и три - один вопрос
 * снял судья, два не прошли по битому индексу ключа. У модели просили РОВНО нужное количество, а
 * ярусов отбраковки уже четыре (арифметика, признаки брака, судья, длина ключа), и каждый новый
 * ярус укорачивает тест.
 *
 * Запас просится ВНУТРИ generate_quiz: обрезка до нужного числа в конце разбора уже стоит, и
 * вызывающие (umk_processor, бронь пула) не меняются - наружу по-прежнему уходит не больше $num.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ai_generator::class)]
final class quiz_buffer_test extends \advanced_testcase {

    /**
     * Генератор-заглушка: отвечает СТОЛЬКО вопросов, сколько просит промт.
     *
     * Первая редакция возвращала жестко заданные семь штук независимо от просьбы, и главный тест
     * задачи оставался зеленым даже при QUIZ_BUFFER = 0 - то есть проверял не запас, а обрезку
     * (найдено ревью 2026-08-26). Модель отвечает на просьбу, значит и заглушка должна.
     *
     * @param int $badkeys сколько вопросов испортить битым индексом ключа
     * @param int|null $cap не отвечать больше этого числа - модель прислала меньше просимого
     * @param string|null $judge ответ слепого судьи; null означает «судья молчит»
     */
    private function generator(int $badkeys = 0, ?int $cap = null, ?string $judge = null): ai_generator {
        return new class($badkeys, $cap, $judge) extends ai_generator {
            private int $badkeys;
            private ?int $cap;
            private ?string $judge;
            public array $prompts = [];
            public array $limits = [];
            public array $asked = [];
            public function __construct(int $badkeys, ?int $cap, ?string $judge) {
                $this->badkeys = $badkeys;
                $this->cap = $cap;
                $this->judge = $judge;
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    return (string)$this->judge;
                }
                $this->prompts[] = $prompt;
                $this->limits[] = $max_tokens;
                preg_match('~ровно ([0-9]+) вопрос~u', $prompt, $m);
                $n = (int)($m[1] ?? 0);
                $this->asked[] = $n;
                if ($this->cap !== null) {
                    $n = min($n, $this->cap);
                }
                $questions = [];
                for ($i = 0; $i < $n; $i++) {
                    $questions[] = [
                        'text' => "Вопрос номер {$i}?",
                        'answers' => ["Верный {$i}", "Первый неверный {$i}",
                                      "Второй неверный {$i}", "Третий неверный {$i}"],
                        'correct' => $i < $this->badkeys ? 99 : 0,
                    ];
                }
                return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    private function ask(ai_generator $gen, int $num): array {
        ob_start();
        try {
            return $gen->generate_quiz([], 'Дроби', '', $num);
        } finally {
            ob_end_clean();
        }
    }

    /** То же, но с текстом следа - в нем видно работу ярусов. */
    private function ask_traced(ai_generator $gen, int $num): array {
        ob_start();
        try {
            $out = $gen->generate_quiz([], 'Дроби', '', $num);
        } finally {
            $trace = ob_get_clean();
        }
        return [$out, $trace];
    }

    public function test_prompt_asks_for_more_than_needed(): void {
        $gen = $this->generator();

        $this->ask($gen, 5);

        $this->assertSame(7, $gen->asked[0], 'у модели просим с запасом');
    }

    public function test_caller_still_gets_exactly_what_it_asked(): void {
        // Запас наружу не протекает: вызывающий рассчитывает места в пуле по своему числу.
        $gen = $this->generator();

        $out = $this->ask($gen, 5);

        $this->assertCount(5, $out);
    }

    public function test_buffer_absorbs_rejections(): void {
        // Ради чего задача и делается. Заглушка отвечает ровно на просьбу, поэтому при
        // QUIZ_BUFFER = 0 модель прислала бы пять, два выбыли бы и осталось три - тест упал бы.
        $gen = $this->generator(2);

        $out = $this->ask($gen, 5);

        $this->assertCount(5, $out, 'запас обязан покрыть отбраковку двух вопросов');
    }

    public function test_judge_rejection_is_absorbed_too(): void {
        // Первый ярус из живого случая - слепой судья. Он выбирает ответ, не видя ключа; здесь
        // судья на каждый вопрос называет чужой вариант, и один вопрос выбывает.
        $judge = json_encode(['answers' => [
            ['n' => 1, 'answer' => 'Первый неверный 0'],
        ]], JSON_UNESCAPED_UNICODE);
        $gen = $this->generator(0, null, $judge);

        $out = $this->ask($gen, 5);

        $this->assertCount(5, $out, 'вердикт судьи тоже покрывается запасом');
    }

    public function test_short_reply_is_not_padded(): void {
        // Модель прислала меньше запрошенного - выдаем сколько есть, выдумывать нечего.
        $gen = $this->generator(0, 3);

        $out = $this->ask($gen, 5);

        $this->assertCount(3, $out);
    }

    public function test_surplus_is_reported(): void {
        // Без следа нельзя понять, нужен запас или он тратится впустую.
        $gen = $this->generator();

        [$out, $trace] = $this->ask_traced($gen, 5);

        $this->assertCount(5, $out);
        // След говорит, куда запас УШЕЛ. Пока излишки выбрасывались, тут стояло «отброшено»;
        // после [[surplus-to-pool-design]] это было бы прямой неправдой в логе.
        $this->assertStringContainsString('Запас: годных вопросов ушло в пул 2', $trace);
    }

    public function test_notes_separate_delivered_questions_from_the_surplus(): void {
        // Подозрения собирались со ВСЕХ разобранных вопросов, включая заведомо обрезаемый
        // запас: педагог читал отчет про задания, которых ребенок не увидит
        // (найдено ревью 2026-08-26). Тогда запас выбрасывался, и молчать про него было верно.
        //
        // Теперь запас уходит в общий пул и достанется другим детям, так что молчание стало
        // сокрытием: пометки показываем, но помечаем «в запасе» - чтобы педагог не искал в
        // тесте своего ребенка задание, которого там нет (найдено ревью 2026-08-27).
        $gen = new class extends ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    return '';
                }
                $questions = [];
                for ($i = 0; $i < 7; $i++) {
                    // Длинный ключ - только у последних двух, то есть ровно у запаса.
                    $key = $i >= 5 ? 'Очень длинный и подробный верный ответ номер ' . $i
                                   : "Верный {$i}";
                    $questions[] = [
                        'text' => "Вопрос номер {$i}?",
                        'answers' => [$key, "Нет {$i}", "Ни {$i}", "Не {$i}"],
                        'correct' => 0,
                    ];
                }
                return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
            }
        };

        [$out, $trace] = $this->ask_traced($gen, 5);

        $this->assertCount(5, $out);
        $this->assertStringContainsString('в запасе: ключ заметно длиннее прочих вариантов',
            $trace, 'подозрение к запасу, ушедшему в пул, скрывать нельзя');
        // Главное - что пометка НЕ выдана за свойство выданного ребенку задания.
        $this->assertStringNotContainsString('Подозрения (вопросы все равно приняты): ключ',
            $trace, 'пометка запаса не должна читаться как пометка выданного вопроса');
    }

    public function test_token_limit_covers_the_larger_order(): void {
        // Потолок был жестким на любое число вопросов, а первая редакция формулы оставляла на
        // вопрос МЕНЬШЕ прежнего (700 против 819) - то есть ухудшала то, что лечила.
        $gen = $this->generator();

        $this->ask($gen, 5);

        $asked = $gen->asked[0];
        $this->assertGreaterThanOrEqual((int)ceil(4096 / 5 * $asked), $gen->limits[0],
            'бюджет на вопрос не должен стать меньше прежнего');
    }

    public function test_all_rejected_still_throws(): void {
        // Порог «все отбракованы» считает по факту, а не по заказу: запас его не отменяет.
        $gen = $this->generator(99);

        $this->expectException(\moodle_exception::class);
        $this->ask($gen, 5);
    }
}
