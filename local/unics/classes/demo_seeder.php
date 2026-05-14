<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

class demo_seeder {

    private course_builder $builder;

    public function __construct() {
        $this->builder = new course_builder();
    }

    /**
     * Creates ONE demo course (Математика, 5 класс).
     * Each topic section contains 3 sets of activities restricted by profile_field_unics_level.
     */
    public function seed_math_demo(int $category_id = 0): \stdClass {
        $course = course_template::create_from_template('math', 5, $category_id);
        $this->fill_course($course->id);
        return $course;
    }

    // ------------------------------------------------------------------

    private function fill_course(int $course_id): void {
        global $DB;

        $section_count = $DB->count_records('course_sections', ['course' => $course_id]);
        $topics        = $this->get_topics();

        // Section 0: intro (visible to all, no restriction)
        $this->builder->add_text_page($course_id, 0, 'Добро пожаловать в курс', $this->get_intro_text());

        foreach ($topics as $idx => $topic) {
            $sec = $idx + 1;
            if ($sec >= $section_count) {
                break;
            }

            foreach ([1, 2, 3] as $level) {
                // Theory page
                $cmid = $this->builder->add_text_page(
                    $course_id, $sec,
                    '[' . $this->level_label($level) . '] ' . $topic['title'],
                    $topic['theory'][$level]
                );
                $this->builder->set_cm_availability_level($cmid, $level);

                // Audio placeholder (levels 2 and 3)
                if ($level >= 2) {
                    $cmid = $this->add_audio_placeholder($course_id, $sec, $topic['title'], $level);
                }

                // Quiz
                $attempts = ($level === 1) ? 0 : 3;
                $cmid = $this->builder->add_quiz(
                    $course_id, $sec,
                    '[' . $this->level_label($level) . '] Тест: ' . $topic['title'],
                    $attempts
                );
                $this->builder->set_cm_availability_level($cmid, $level);

                // Assignment (level 3 only)
                if ($level === 3 && !empty($topic['task'])) {
                    $cmid = $this->builder->add_assignment(
                        $course_id, $sec,
                        '[Продвинутый] Задание: ' . $topic['title'],
                        $topic['task']
                    );
                    $this->builder->set_cm_availability_level($cmid, $level);
                }
            }
        }

        // Final section: итоговый контроль (separate quiz per level)
        $last = $section_count - 1;
        foreach ([1, 2, 3] as $level) {
            $cmid = $this->builder->add_quiz(
                $course_id, $last,
                '[' . $this->level_label($level) . '] Итоговый тест по математике (5 класс)',
                1
            );
            $this->builder->set_cm_availability_level($cmid, $level);
        }

        rebuild_course_cache($course_id, true);
    }

    private function add_audio_placeholder(int $course_id, int $section_num, string $topic_title, int $level): int {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'label'], 'id');
        if (!$module) {
            return 0;
        }
        $section = $DB->get_record('course_sections', ['course' => $course_id, 'section' => $section_num]);
        if (!$section) {
            return 0;
        }

        $label               = new \stdClass();
        $label->course       = $course_id;
        $label->name         = 'Аудиолекция - ' . $topic_title;
        $label->intro        = '<div class="alert alert-secondary">'
            . '<strong>Аудиолекция:</strong> запустите '
            . '<a href="/local/unics/pages/generate_umk.php">ИИ-генерацию УМК</a>, '
            . 'чтобы автоматически создать аудио по этой теме (VoiceRSS TTS).'
            . '</div>';
        $label->introformat  = FORMAT_HTML;
        $label->timemodified = time();
        $label->id = $DB->insert_record('label', $label);

        $cm               = new \stdClass();
        $cm->course       = $course_id;
        $cm->module       = $module->id;
        $cm->instance     = $label->id;
        $cm->section      = $section->id;
        $cm->visible      = 1;
        $cm->added        = time();
        $cm->availability = course_template::profile_level_availability($level);
        $cm->id = $DB->insert_record('course_modules', $cm);

        $seq   = array_filter(explode(',', $section->sequence ?? ''));
        $seq[] = $cm->id;
        $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);

        return $cm->id;
    }

    private function level_label(int $level): string {
        return course_template::get_level_labels()[$level] ?? '';
    }

    private function get_intro_text(): string {
        return implode("\n\n", [
            'Добро пожаловать в демонстрационный курс «Математика, 5 класс»!',
            'В каждой теме материалы разделены по уровням сложности. '
            . 'Вы видите только те активности, которые соответствуют вашему уровню (Базовый, Стандартный или Продвинутый).',
            "Как работать:\n1. Прочитайте теорию.\n2. Прослушайте аудиолекцию (уровни 2 и 3)."
            . "\n3. Пройдите тест.\n4. Выполните задание (уровень 3).",
            'Тесты создаются пустыми - вопросы добавляет педагог или модуль ИИ-генерации УМК.',
        ]);
    }

    private function get_topics(): array {
        return [
            [
                'title'  => 'Натуральные числа',
                'theory' => [
                    1 => "Натуральные числа\n\nНатуральные числа - это числа для счёта предметов: 1, 2, 3, 4, 5...\n\nПример: у тебя 3 яблока - число 3 натуральное.\n\nЗапомни: число 0 - не натуральное. После любого числа всегда есть следующее.",
                    2 => "Натуральные числа\n\nНатуральные числа - положительные целые числа: 1, 2, 3, ... Обозначаются символом ℕ.\n\nСвойства:\n• Наименьшее - 1.\n• У каждого числа есть следующее (n + 1).\n• Натуральных чисел бесконечно много.\n\nПримеры: количество учеников, номер страницы, номер маршрута автобуса.",
                    3 => "Натуральные числа\n\nНатуральные числа (ℕ) - фундаментальное понятие математики.\n\nАксиомы Пеано:\n1. 1 ∈ ℕ\n2. Если n ∈ ℕ, то n+1 ∈ ℕ\n3. Для n ≠ m: n+1 ≠ m+1\n4. Принцип математической индукции.\n\nПрименения: нумерация, количество, кодирование (ID, ISBN).\n\nЗамечание: сложение и умножение замкнуты в ℕ; вычитание и деление - нет.\n\nИстория: первые системы счисления появились ~5000 лет назад.",
                ],
                'task' => 'Составьте задачу с натуральными числами (не менее 5 действий). Запишите условие, решение и ответ.',
            ],
            [
                'title'  => 'Обыкновенные дроби',
                'theory' => [
                    1 => "Обыкновенные дроби\n\nДробь - это часть целого. 1/4 яблока - одна из четырёх равных частей.\n\nВ дроби 3/4:\n• 3 - числитель (сколько взяли)\n• 4 - знаменатель (на сколько поделили)\n\nЗапомни: знаменатель не может быть равен 0!",
                    2 => "Обыкновенные дроби\n\nДробь a/b: a - числитель, b - знаменатель (b ≠ 0).\n\nВиды:\n• Правильная: числитель < знаменатель (3/5)\n• Неправильная: числитель ≥ знаменатель (7/4)\n• Смешанное число: 1¾ = 1 + 3/4\n\nПримеры: 1/2 пиццы; 3/4 часа = 45 мин; скидка 25% = 1/4 цены.",
                    3 => "Обыкновенные дроби\n\nДробь a/b - число, равное решению уравнения b·x = a, где a ∈ ℤ, b ∈ ℕ.\n\nКлассификация: правильная (|a| < b), неправильная (|a| ≥ b), несократимая (НОД(a,b) = 1).\n\nАрифметика:\na/b + c/d = (ad+bc)/(bd)\na/b × c/d = (ac)/(bd)\na/b ÷ c/d = (ad)/(bc)\n\nСвязь с десятичными: 1/4 = 0.25; 1/3 = 0.(3); 1/7 = 0.(142857).",
                ],
                'task' => 'Нарисуйте схему «Виды дробей». Приведите по 3 примера каждого вида из жизни.',
            ],
            [
                'title'  => 'Десятичные дроби',
                'theory' => [
                    1 => "Десятичные дроби\n\nДесятичная дробь - дробь, где знаменатель 10, 100, 1000...\n\n0,5 = 5/10; 0,25 = 25/100\n\nЗапятая разделяет целую и дробную части.\n\nПример: цена 49,90 руб. - это 49 рублей и 90 копеек.",
                    2 => "Десятичные дроби\n\n3,14 - это 3 и 14 сотых.\n\nРазряды:\n• 0,1 = десятые\n• 0,01 = сотые\n• 0,001 = тысячные\n\nСравнение: 3,5 > 3,49.\n\nПримеры: курс доллара 92,35 руб.; рост 1,75 м; π ≈ 3,14159.",
                    3 => "Десятичные дроби\n\nОснована на позиционной системе счисления (основание 10).\n\nВиды:\n• Конечная: 0,375\n• Периодическая: 0,(3) = 1/3\n• Непериодическая: √2 = 1,41421356...\n\nКритерий конечности: b = 2ⁿ·5ᵐ.\n\nСтандартный вид: a × 10ⁿ, 1 ≤ a < 10.\n\nПрименение: физика (СИ), финансы, информатика (IEEE 754).",
                ],
                'task' => 'Найдите 3 примера применения десятичных дробей в жизни. Напишите мини-эссе (100–150 слов) с вычислениями.',
            ],
            [
                'title'  => 'Проценты',
                'theory' => [
                    1 => "Проценты\n\n1 процент (1%) - одна сотая часть числа.\n\nПримеры:\n• 50% от 100 руб. = 50 руб.\n• 10% от 200 = 20\n\nФормула: % от числа = число × процент ÷ 100.",
                    2 => "Проценты\n\nПроцент (%) = 1/100 = 0,01.\n\nФормулы:\n• a% от N = N × a / 100\n• Сколько % A от B = A / B × 100\n\nПримеры:\n• Скидка 20% на 500 руб. = 100 руб.\n• 15 из 60 = 25%\n\nПрименение: скидки, налоги, банковские ставки.",
                    3 => "Проценты\n\nТипы задач:\n1. Найти a% от N: P = N·a/100\n2. Найти % A от B: a = A/B·100\n3. Найти целое: N = P·100/a\n\nСложный процент: Aₙ = A₀·(1 + p/100)ⁿ\n\nПример: инфляция 8%/год, товар через 3 года: 1000·1,08³ ≈ 1259 руб.\n\nСкидка 30%, затем 20%: итого 44% (не 50%).",
                ],
                'task' => 'Рассчитайте: 1) скидку 15% на товар 2400 руб.; 2) сколько % составляет 45 от 180. Покажите решение.',
            ],
        ];
    }
}
