<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\course_builder;

/**
 * Излишки запаса уходят в пул заданий, а не в мусор ([[surplus-to-pool-design]]).
 *
 * С запасом ([[quiz-buffer-design]]) каждая генерация оплачивает и проверяет $num + 2 вопроса, а
 * два полностью проверенных выбрасывает. Пустой пул при этом - известное горлышко проекта:
 * калибровка IRT была невозможна, потому что у семнадцати заданий из двадцати двух был один
 * ответ.
 *
 * Ребёнок по-прежнему видит ровно свои $num вопросов; излишки лежат в банке и достаются
 * следующим ученикам через item_pool::take().
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_builder::class)]
final class surplus_to_pool_test extends \advanced_testcase {

    /** Заглушка генератора: отвечает столько вопросов, сколько просит промт. */
    private function generator(): ai_generator {
        return new class extends ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    return '';
                }
                preg_match('~ровно ([0-9]+) вопрос~u', $prompt, $m);
                $n = (int)($m[1] ?? 0);
                $questions = [];
                for ($i = 0; $i < $n; $i++) {
                    $questions[] = [
                        'text' => "Вопрос номер {$i}?",
                        'answers' => ["Верный {$i}", "Первый неверный {$i}",
                                      "Второй неверный {$i}", "Третий неверный {$i}"],
                        'correct' => 0,
                    ];
                }
                return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
            }
        };
    }

    private function quiz_questions(int $cmid): int {
        global $DB;
        $quizid = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        return $DB->count_records('quiz_slots', ['quizid' => $quizid]);
    }

    public function test_generator_hands_back_the_surplus(): void {
        // Излишки уходят через параметр по ссылке: контракт возврата не меняется, и оба
        // существующих вызывающих продолжают работать без правок.
        $gen = $this->generator();
        $surplus = [];

        ob_start();
        $out = $gen->generate_quiz([], 'Дроби', '', 5, '', $surplus);
        ob_end_clean();

        $this->assertCount(5, $out, 'наружу - ровно заказанное');
        $this->assertCount(2, $surplus, 'излишки запаса обязаны вернуться отдельно');
        $this->assertArrayHasKey('text', $surplus[0]);
    }

    public function test_surplus_is_empty_when_model_is_stingy(): void {
        // Модель прислала меньше просимого - излишков нет, и выдумывать их неоткуда.
        $gen = new class extends ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if (str_contains($prompt, \local_unics\ai\answer_judge::PROMPT_MARKER)) {
                    return '';
                }
                $questions = [];
                for ($i = 0; $i < 3; $i++) {
                    $questions[] = [
                        'text' => "Вопрос {$i}?",
                        'answers' => ["Верный {$i}", "Нет {$i}", "Ни {$i}", "Не {$i}"],
                        'correct' => 0,
                    ];
                }
                return json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE);
            }
        };
        $surplus = ['мусор'];

        ob_start();
        $out = $gen->generate_quiz([], 'Дроби', '', 5, '', $surplus);
        ob_end_clean();

        $this->assertCount(3, $out);
        $this->assertSame([], $surplus, 'параметр обязан обнуляться, а не копить прошлое');
    }

    public function test_bank_only_questions_stay_out_of_the_quiz(): void {
        // Ребёнок видит ровно свои вопросы. Излишки живут в банке и в слоты не попадают.
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();

        $main = [[
            'text' => 'Основной вопрос?',
            'answers' => ['Верный', 'Первый', 'Второй', 'Третий'], 'correct' => 0,
        ]];
        $extra = [[
            'text' => 'Запасной вопрос?',
            'answers' => ['Верный', 'Первый', 'Второй', 'Третий'], 'correct' => 0,
        ]];
        $refs = [];

        $cmid = $builder->add_quiz_with_questions((int)$course->id, 0, 'Тест', $main, [],
            $extra, $refs);

        $this->assertSame(1, $this->quiz_questions($cmid), 'в тесте только основной вопрос');
        $this->assertCount(1, $refs, 'запасной вопрос создан и его ссылка возвращена');
    }

    public function test_bank_only_question_exists_in_the_bank(): void {
        // Не просто «ссылка вернулась»: вопрос обязан быть настоящим, иначе пул получит
        // указатель в пустоту.
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();
        $refs = [];

        $builder->add_quiz_with_questions((int)$course->id, 0, 'Тест', [[
            'text' => 'Основной?', 'answers' => ['А', 'Б', 'В', 'Г'], 'correct' => 0,
        ]], [], [[
            'text' => 'Запасной вопрос про дроби?',
            'answers' => ['Верный', 'Первый', 'Второй', 'Третий'], 'correct' => 0,
        ]], $refs);

        $qid = $DB->get_field_sql(
            'SELECT qv.questionid FROM {question_versions} qv WHERE qv.questionbankentryid = ?',
            [reset($refs)], IGNORE_MULTIPLE);
        $this->assertNotEmpty($qid);
        $text = (string)$DB->get_field('question', 'questiontext', ['id' => $qid]);
        $this->assertStringContainsString('Запасной вопрос про дроби', $text);
        $this->assertSame(4, $DB->count_records('question_answers', ['question' => $qid]));
    }

    public function test_no_surplus_means_no_change(): void {
        // Пустой список излишков ничего не ломает: это обычный путь, когда пула нет.
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();
        $refs = [];

        $cmid = $builder->add_quiz_with_questions((int)$course->id, 0, 'Тест', [[
            'text' => 'Один?', 'answers' => ['А', 'Б', 'В', 'Г'], 'correct' => 0,
        ]], [], [], $refs);

        $this->assertSame(1, $this->quiz_questions($cmid));
        $this->assertSame([], $refs);
    }
}
