<?php
namespace local_unics;

use local_unics\ai\output_style;

/**
 * Нормализатор выхода ИИ ([[ai-output-style-design]]). Фикстура построена по РЕАЛЬНОМУ выходу
 * живого прогона 2026-08-06: в 2453 символах текста про вулканы было 9 эмодзи, 3 длинных тире
 * и пять заголовков «##» при нуле «####», хотя промт явно требует «####».
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(output_style::class)]
final class output_style_test extends \advanced_testcase {

    /** Кусок реального ответа модели. */
    private function dirty(): string {
        return "# 🌋 Урок о вулканах\n\n"
             . "## **Введение**\n\n"
             . "Сегодня мы поговорим о вулканах — удивительных природных явлениях, "
             . "которые одновременно пугают и завораживают людей.\n\n"
             . "## 🔥 **Что такое вулкан?**\n\n"
             . "Вулкан — это форма рельефа, через которую магма выходит наружу.\n";
    }

    /** Счетчик эмодзи по тем же диапазонам, которые вырезает clean(). */
    private function count_emoji(string $t): int {
        return preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $t);
    }

    /**
     * Диапазоны за пределами первой версии clean(): звезды и стрелки блока U+2B00,
     * закрытые буквы и флаги блока U+1F000, клавиатурный keycap U+20E3. Найдено ревью
     * 2026-08-07: инвариант «эмодзи в выходе ИИ нет» на них не выполнялся.
     */
    public function test_emoji_outside_first_ranges_are_also_cut(): void {
        $out = output_style::clean("⭐ Главное: раз 1️⃣ и 🆗 дальше ➡ конец");

        foreach (['⭐', '🆗', '➡', "\u{20E3}", "\u{FE0F}"] as $ch) {
            $this->assertStringNotContainsString($ch, $out, "Не вырезан символ {$ch}");
        }
        $this->assertStringContainsString('Главное: раз', $out);
        $this->assertStringContainsString('конец', $out);
    }

    public function test_emoji_and_long_dashes_are_gone(): void {
        $out = output_style::clean($this->dirty());

        $this->assertSame(0, $this->count_emoji($out), 'Эмодзи вырезаются полностью');
        $this->assertStringNotContainsString('—', $out);
        $this->assertStringContainsString('о вулканах - удивительных', $out,
            'Длинное тире заменяется дефисом, текст вокруг не страдает');
    }

    /**
     * Буква «ё» - осознанное исключение ([[ai-output-style-design]], раздел 3): в учебном
     * материале для детей с ЗПР и РАС она снимает двусмысленность (все/всё, небо/нёбо).
     * Тест стоит здесь, чтобы никто не добавил замену «ё» заодно с тире.
     */
    public function test_yo_is_preserved(): void {
        $src = 'Тёплый воздух поднимается, всё небо в тучах.';

        $this->assertSame($src, output_style::clean($src));
    }

    public function test_meaningful_typography_survives(): void {
        $src = 'Магма → лава → застывшая порода. Это «внешний» процесс; дефис-минус: 5-7 км.';

        $this->assertSame($src, output_style::clean($src),
            'Стрелки, кавычки-елочки и обычный дефис - не эмодзи и не длинное тире');
    }

    public function test_no_double_spaces_left_after_cutting(): void {
        $out = output_style::clean("# 🌋 Урок\n\nТекст 🔥 дальше   \n");

        $this->assertStringNotContainsString('  ', $out, 'Дыры от вырезанных эмодзи схлопываются');
        $this->assertStringContainsString('# Урок', $out);
        $this->assertStringContainsString('Текст дальше', $out);
    }

    public function test_hard_line_break_survives(): void {
        // Два пробела в конце строки - жесткий перенос markdown, а учебный текст ложится в
        // страницу как FORMAT_MARKDOWN. Замер на пяти уроках: модель ставит такой перенос
        // (5 штук на 219 строк), а чистка съедала их все - строки склеивались в один абзац.
        $out = output_style::clean("первая строка  \nвторая строка\nтретья\n");

        $this->assertStringContainsString("первая строка  \nвторая", $out);
    }

    public function test_hard_break_is_normalised_to_two_spaces(): void {
        // Больше двух markdown не требует, и лишние пробелы в тексте ни к чему.
        $out = output_style::clean("строка     \nследом\n");

        $this->assertStringContainsString("строка  \nследом", $out);
        $this->assertStringNotContainsString('   ', $out);
    }

    public function test_single_trailing_space_is_still_cut(): void {
        // ОДИН пробел переносом не является: это и есть след вырезанного эмодзи.
        $out = output_style::clean("текст 🔥\nследом\n");

        $this->assertSame("текст\nследом", $out);
    }

    public function test_hard_break_at_the_very_end_is_dropped(): void {
        // Перенос в конце текста бессмыслен: переносить нечего.
        $this->assertSame('одна строка', output_style::clean("одна строка  \n"));
    }

    public function test_one_trailing_space_is_not_a_hard_break(): void {
        // Markdown требует ДВА пробела. Один - это опечатка или след правки, и превращать его
        // в перенос значило бы менять разбивку текста там, где автор ее не менял.
        $out = output_style::clean("текст \nследом\n");

        $this->assertSame("текст\nследом", $out);
    }

    public function test_strip_math_markup_keeps_hard_line_breaks(): void {
        // Второе место, где схлопываются пробелы. Живой заход показал: починив только clean(),
        // до страницы урока не доживает НИ ОДИН перенос - этот метод съедал их следом.
        $out = \local_unics\ai\output_style::strip_math_markup("первая строка  \nвторая строка");

        $this->assertSame("первая строка  \nвторая строка", $out);
    }

    public function test_whole_lesson_path_keeps_hard_line_breaks(): void {
        // Путь текста урока целиком, как в umk_processor: clean -> strip_math_markup ->
        // shift_headings. Проверять звенья по отдельности мало - дефект жил на стыке.
        $src = "## Заголовок\n\nпервая строка  \nвторая строка\n";
        $out = \local_unics\ai\output_style::shift_headings(
            \local_unics\ai\output_style::strip_math_markup(
                \local_unics\ai\output_style::clean($src)));

        $this->assertStringContainsString("первая строка  \nвторая строка", $out);
    }

    public function test_hard_breaks_survive_next_to_a_code_fence(): void {
        // Главный случай, на котором первая редакция ЛОМАЛАСЬ. Восстановление шло по номерам
        // строк, а чистка внутри map_outside_code() подрезает пустые строки у ограждений: число
        // строк менялось, и перенос уезжал на соседнюю строку (найдено ревью).
        $src = "## Тема\n\n```python\nprint(1)\n```\n\nA  \nB\nC  \nD\n";
        $out = \local_unics\ai\output_style::shift_headings(
            \local_unics\ai\output_style::strip_math_markup(
                \local_unics\ai\output_style::clean($src)));

        $this->assertStringContainsString("A  \nB", $out, "перенос уцелел");
        $this->assertStringContainsString("C  \nD", $out, "и на своей строке");
    }

    public function test_code_fence_content_is_not_given_hard_breaks(): void {
        // Внутри блока кода пробелы значимы, и весь класс их не трогает. Приписка лезла и туда.
        $out = \local_unics\ai\output_style::clean("текст\n```\nprint(1)    \nprint(2)\n```\nхвост");

        $this->assertStringContainsString("print(1)\nprint(2)", $out);
    }

    public function test_strip_math_markup_does_not_leave_trailing_spaces(): void {
        // Через этот метод проходят ТЕКСТ ВОПРОСА и каждый вариант ответа. Первая редакция
        // дописывала им хвостовые пробелы, и те уезжали в банк вопросов (найдено ревью).
        $this->assertSame("5", \local_unics\ai\output_style::strip_math_markup("5  "));
        $this->assertSame("ответ", \local_unics\ai\output_style::strip_math_markup("  ответ  "));
    }

    public function test_non_breaking_space_tail_becomes_exactly_two_spaces(): void {
        // Неразрывный пробел ловится как \h, но побайтная обрезка его не снимала, и в хвосте
        // оказывалось четыре знака вместо двух (найдено ревью).
        $out = \local_unics\ai\output_style::clean("первая\u{00A0}\u{00A0}\nвторая");

        $this->assertSame("первая  \nвторая", $out);
    }

    public function test_emoji_hole_does_not_become_a_hard_break(): void {
        // «текст 🔥 🌋» после вырезания оставит подряд идущие пробелы В СЕРЕДИНЕ, а не перенос:
        // судим по тексту ДО вырезания, иначе дыра от эмодзи притворилась бы переносом.
        $out = output_style::clean("текст 🔥 🌋\nследом\n");

        $this->assertSame("текст\nследом", $out);
    }

    public function test_hard_break_survives_windows_line_endings(): void {
        // Переводы строк собираются обратно теми же: подмена CRLF на LF была бы правкой,
        // о которой никто не просил.
        $out = output_style::clean("первая  \r\nвторая\r\nтретья");

        $this->assertStringContainsString("первая  \r\nвторая", $out);
    }

    public function test_leading_indentation_survives(): void {
        // Отступ в начале строки трогать нельзя: это markdown-вложенность и блоки кода.
        $src = "- пункт\n    вложенная строка\n";

        $this->assertSame(trim($src), output_style::clean($src));
    }

    public function test_clean_is_idempotent(): void {
        $once = output_style::clean($this->dirty());

        $this->assertSame($once, output_style::clean($once));
    }

    public function test_json_still_parses_after_clean(): void {
        $json = '{"questions":[{"text":"Что такое вулкан 🌋 — кратко?","answers":["А","Б"],"correct":0}]}';

        $parsed = json_decode(output_style::clean($json), true);

        $this->assertIsArray($parsed);
        $this->assertSame('Что такое вулкан - кратко?', $parsed['questions'][0]['text']);
        $this->assertSame(0, $parsed['questions'][0]['correct']);
    }

    /**
     * Заголовки - не косметика. course_builder::add_text_page() пишет contentformat =
     * FORMAT_MARKDOWN, то есть markdown РЕНДЕРИТСЯ: «#» от модели стал <h1> внутри страницы
     * курса, где заголовок уже есть. На иерархию заголовков опираются программы экранного
     * доступа, а слабовидящие - целевая аудитория проекта.
     */
    public function test_headings_shift_down_so_minimum_becomes_h4(): void {
        $out = output_style::shift_headings("# Урок\n\n## Введение\n\nтекст\n\n### Итог\n");

        $this->assertStringContainsString("#### Урок", $out);
        $this->assertStringContainsString("##### Введение", $out);
        $this->assertStringContainsString("###### Итог", $out);
        $this->assertStringNotContainsString("\n# ", "\n" . $out);
    }

    /** Сдвиг работает и вверх: опускать некуда, надо поднимать. */
    public function test_headings_shift_up_when_model_went_too_deep(): void {
        $out = output_style::shift_headings("##### Раздел\n\n###### Подраздел\n");

        $this->assertStringContainsString("#### Раздел", $out);
        $this->assertStringContainsString("##### Подраздел", $out);
    }

    /** Глубже шестого уровня markdown не идет - лишнее упирается в потолок. */
    public function test_headings_deeper_than_six_are_capped(): void {
        $out = output_style::shift_headings("# А\n\n#### Б\n");

        $this->assertStringContainsString("#### А", $out);
        $this->assertStringContainsString("###### Б", $out);
    }

    /**
     * Решетка внутри блока кода - это комментарий, а не заголовок. Найдено ревью 2026-08-07:
     * на уроке информатики строка «# считаем сумму» задирала минимум до первого уровня, весь
     * сдвиг перекашивался, а сам пример кода портился.
     */
    public function test_hash_inside_code_fence_is_not_a_heading(): void {
        $src = "## Введение\n\n```python\n# считаем сумму\nprint(1)\n```\n\n## Итог\n";

        $out = output_style::shift_headings($src);

        $this->assertStringContainsString("#### Введение", $out);
        $this->assertStringContainsString("#### Итог", $out);
        $this->assertStringContainsString("# считаем сумму", $out, 'Код не тронут');
        $this->assertStringNotContainsString("#### считаем сумму", $out);
    }

    public function test_text_without_headings_is_untouched(): void {
        $src = "Просто абзац.\n\nИ еще один: 5 # 3 не заголовок.\n";

        $this->assertSame($src, output_style::shift_headings($src));
    }

    public function test_headings_already_at_h4_are_untouched(): void {
        $src = "#### Раздел\n\n##### Подраздел\n";

        $this->assertSame($src, output_style::shift_headings($src));
    }

    /**
     * Чистка обязана стоять В ГОРЛОВИНЕ generate_text(), а не на местах вызова: через нее идут
     * ВСЕ шесть выходов ИИ (учебный текст, тест, задание, слайды, пояснения адаптива, разбор
     * эссе), и ни один вызывающий не должен иметь возможности о ней забыть.
     *
     * Подменяем самый нижний слой - HTTP к GigaChat, - поэтому тест проверяет именно
     * generate_text(), а не свою же реализацию. Сети тест не касается.
     */
    public function test_generate_text_returns_cleaned_output(): void {
        $this->resetAfterTest();
        set_config('ai_api_key', 'FAKE_KEY_FOR_TEST', 'local_unics');

        $gen = new class extends \local_unics\ai\ai_generator {
            protected function generate_text_gigachat(string $prompt, int $max_tokens = 1024): string {
                return "Вулкан 🌋 — это гора.";
            }
        };

        $out = $gen->generate_text('любой промт');

        $this->assertSame('Вулкан - это гора.', $out);
    }
    public function test_strip_math_markup_turns_latex_fraction_into_text(): void {
        // Живой ответ 2026-08-20: модель прислала LaTeX, хотя промт его запрещает.
        $this->assertSame('Сложите 4/7 и 3/7',
            \local_unics\ai\output_style::strip_math_markup(
                'Сложите $ \frac{4}{7} $ и $ \frac{3}{7} $'));
    }

    public function test_strip_math_markup_handles_operations_and_delimiters(): void {
        $this->assertSame('2 × 3 и 8 : 2',
            \local_unics\ai\output_style::strip_math_markup(
                '\(2 \cdot 3\) и \[8 \div 2\]'));
    }

    public function test_strip_math_markup_leaves_plain_text_alone(): void {
        $this->assertSame('Сколько будет 2/5 + 1/5?',
            \local_unics\ai\output_style::strip_math_markup('Сколько будет 2/5 + 1/5?'));
    }
    public function test_strip_math_markup_expands_unicode_fractions(): void {
        // Живая генерация 2026-08-21: модель ушла от запрета на LaTeX в символы дробей, и
        // верификатор перестал понимать выражение. Хуже того, вариант «½» не опознавался как
        // число, поэтому ВЕРНЫЕ задания отбрасывались как безответные.
        $this->assertSame('1/3 + 1/6 = ?',
            \local_unics\ai\output_style::strip_math_markup('⅓ + ⅙ = ?'));
        $this->assertSame('1/2', \local_unics\ai\output_style::strip_math_markup('½'));
        $this->assertSame('3/4', \local_unics\ai\output_style::strip_math_markup('¾'));
    }
}
