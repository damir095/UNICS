<?php
namespace local_unics;

use local_unics\ai\lecture_illustrator;
use local_unics\ai\output_style;

/**
 * Блок кода не трогаем, а математику чистим целиком ([[code-fence-and-math-design]]).
 *
 * Два родственных долга в одном слое обработки текста:
 *
 * 1. `strip_math_markup()` знал ровно четыре команды (`\frac`, `\cdot`, `\times`, `\div`), а
 *    прочие оставлял в тексте урока. Живая генерация 2026-08-24 вернула `$$H_2O \rightarrow
 *    H_2O(пар)$$` - доллары снимались, команда оставалась.
 * 2. `lecture_illustrator::HEADING_RE` искал заголовки регуляркой по всему тексту, поэтому строка
 *    «#### считаем сумму» внутри примера на Python становилась разделом и получала иллюстрацию.
 *    В `shift_headings()` этот же дефект чинили еще в августе 2026 - построчным обходом с флагом
 *    ограждения.
 *
 * Обе правки обязаны обходить блоки кода стороной: там обратный слэш и решетка законны, и
 * «чистка» испортила бы материал урока по информатике.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(output_style::class)]
final class code_fence_safety_test extends \advanced_testcase {

    // ---------------------------------------------------------------
    // Математическая разметка
    // ---------------------------------------------------------------

    public function test_known_commands_become_symbols(): void {
        $cases = [
            'A \rightarrow B'   => '→',
            'x \le 5'           => '≤',
            'y \ge 3'           => '≥',
            'a \approx b'       => '≈',
            '\pm 2'             => '±',
            '\sqrt 9'           => '√',
            '\alpha и \beta'    => 'α',
        ];
        foreach ($cases as $in => $expected) {
            $out = output_style::strip_math_markup($in);
            $this->assertStringContainsString($expected, $out, "вход: $in");
            $this->assertStringNotContainsString('\\', $out, "обратный слэш остался: $in");
        }
    }

    public function test_unknown_command_is_removed_entirely(): void {
        // Незнакомую команду оставлять нельзя: «rightarrow» без слэша - такой же мусор, как
        // со слэшем. Удаляем целиком.
        $out = output_style::strip_math_markup('Формула \unknowncmd тут');

        $this->assertStringNotContainsString('unknowncmd', $out);
        $this->assertStringNotContainsString('\\', $out);
        $this->assertStringContainsString('Формула', $out);
        $this->assertStringContainsString('тут', $out);
    }

    public function test_the_live_failure_from_the_probe(): void {
        // Ровно то, что вернула модель 2026-08-24.
        $out = output_style::strip_math_markup('$$H_2O \rightarrow H_2O(пар)$$');

        $this->assertStringNotContainsString('\\', $out);
        $this->assertStringNotContainsString('$', $out);
        $this->assertStringContainsString('→', $out);
    }

    public function test_ordinary_fractions_are_not_split(): void {
        // Найдено проверкой чистки на реальных страницах стенда: правило для смешанных чисел
        // ломало обычные дроби с двузначным числителем, и в уроке про дроби ребенок читал
        // «5/15 + 6/15 = 1 1/15» вместо «11/15».
        $this->assertSame('11/15', output_style::strip_math_markup('11/15'));
        $this->assertSame('5/15 + 6/15 = 11/15',
            output_style::strip_math_markup('5/15 + 6/15 = 11/15'));
        $this->assertSame('25/100', output_style::strip_math_markup('25/100'));
    }

    public function test_mixed_number_is_still_split(): void {
        // Ради чего правило и заводилось: «1½» без разделителя слиплось бы в «11/2».
        $this->assertSame('1 1/2', output_style::strip_math_markup('1½'));
        $this->assertSame('2 3/4', output_style::strip_math_markup('2¾'));
        $this->assertSame('1/2', output_style::strip_math_markup('½'), 'одиночная дробь как есть');
    }

    public function test_plain_text_is_untouched(): void {
        $text = "Вода испаряется.\n\nПотом конденсируется - и выпадает дождем.";

        $this->assertSame($text, output_style::strip_math_markup($text));
    }

    public function test_code_fence_keeps_backslashes(): void {
        // Главная проверка: в примере кода обратный слэш законен, и чистка обязана обойти его
        // стороной. Иначе урок по информатике превращается в кашу.
        $md = "Пример пути:\n\n```python\npath = 'C:\\\\Users\\\\test'\nprint(\"\\n\")\n```\n\nДальше текст.";

        $out = output_style::strip_math_markup($md);

        $this->assertStringContainsString("path = 'C:\\\\Users\\\\test'", $out);
        $this->assertStringContainsString('print("\\n")', $out);
    }

    public function test_math_outside_fence_still_cleaned_when_fence_present(): void {
        // Обход блоков не должен выключать чистку во всем документе.
        $md = "Формула \\rightarrow тут.\n\n```\ncode \\n\n```\n\nИ \\le здесь.";

        $out = output_style::strip_math_markup($md);

        $this->assertStringContainsString('→', $out);
        $this->assertStringContainsString('≤', $out);
        $this->assertStringContainsString('code \\n', $out, 'внутри блока - как было');
    }

    // ---------------------------------------------------------------
    // Разбивка на разделы
    // ---------------------------------------------------------------

    public function test_heading_inside_code_fence_is_not_a_section(): void {
        // «#### считаем сумму» в примере на Python - комментарий, а не раздел урока. Иначе
        // иллюстратор рисует картинку к строке кода.
        $md = "#### Введение\n\nТекст раздела про сложение.\n\n"
            . "```python\n#### считаем сумму\ns = 1 + 2\n```\n\n"
            . "#### Вывод\n\nИтоговый текст.";

        $sections = lecture_illustrator::split_sections($md, 'Сложение');

        $headings = array_column($sections, 'heading');
        $this->assertContains('Введение', $headings);
        $this->assertContains('Вывод', $headings);
        $this->assertNotContains('считаем сумму', $headings,
            'решетка внутри блока кода - не заголовок');
    }

    public function test_sections_are_still_found_without_fences(): void {
        // Обратная сторона: обычный текст с заголовками разбирается как раньше.
        $md = "#### Первый\n\nТекст один.\n\n#### Второй\n\nТекст два.";

        $sections = lecture_illustrator::split_sections($md, 'Тема');

        $this->assertCount(2, $sections);
        $this->assertSame('Первый', $sections[0]['heading']);
        $this->assertSame('Второй', $sections[1]['heading']);
    }

    public function test_document_with_only_fenced_heading_falls_back_to_topic(): void {
        // Если ЕДИНСТВЕННАЯ решетка спрятана в блоке кода, разделов нет вовсе - и работает
        // запасной путь «одна вводная картинка по теме».
        $md = "Вступление без заголовков.\n\n```\n#### не заголовок\n```\n\nКонец.";

        $sections = lecture_illustrator::split_sections($md, 'Круговорот воды');

        $this->assertCount(1, $sections);
        $this->assertSame('Круговорот воды', $sections[0]['heading']);
    }

    public function test_section_body_still_reaches_the_prompt(): void {
        // Смещения при построчном обходе легко сбить на единицу, и тогда в промт картинки
        // уедет обрезок соседнего раздела.
        $md = "#### Испарение\n\nСолнце нагревает воду и она превращается в пар.\n\n"
            . "#### Осадки\n\nОблако тяжелеет и проливается дождем.";

        $sections = lecture_illustrator::split_sections($md, 'Тема');

        $this->assertStringContainsString('Солнце нагревает', $sections[0]['lead']);
        $this->assertStringNotContainsString('Облако', $sections[0]['lead']);
        $this->assertStringContainsString('Облако тяжелеет', $sections[1]['lead']);
    }
}
