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

    public function test_text_without_headings_is_untouched(): void {
        $src = "Просто абзац.\n\nИ еще один: 5 # 3 не заголовок.\n";

        $this->assertSame($src, output_style::shift_headings($src));
    }

    public function test_headings_already_at_h4_are_untouched(): void {
        $src = "#### Раздел\n\n##### Подраздел\n";

        $this->assertSame($src, output_style::shift_headings($src));
    }
}
