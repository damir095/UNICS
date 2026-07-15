<?php
namespace local_unics;

use local_unics\ai\slide_parser;

/**
 * Тесты общего парсера слайдов УМК-презентации ([[umk-pptx-export-design]]).
 *
 * Фикстура повторяет разметку course_builder: div.unics-slide с заголовком
 * (возможен значок динамика при озвучке), опциональной картинкой data:jpeg,
 * контентом (nl2br от плоского текста) и блоком «Ключевые понятия».
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(slide_parser::class)]
final class slide_parser_test extends \basic_testcase {

    /** Бинарник «картинки» для data:-src (парсер не валидирует jpeg-магию). */
    private const IMG_BIN = "\xFF\xD8\xFF\xE0fake-jpeg-bytes";

    private function fixture(): string {
        $img64 = base64_encode(self::IMG_BIN);
        return '<div id="unics-pres">'
            // Слайд 0: значок динамика в заголовке, картинка, контент с переносами, КП.
            . '<div class="unics-slide" data-idx="0" style="display:block">'
            . '<h3 class="unics-slide-title">Вода в природе'
            . '<span id="unics-audio-icon-0" title="Озвучка активна">' . "\u{1F50A}" . '</span></h3>'
            . '<div class="unics-slide-img"><img src="data:image/jpeg;base64,' . $img64
            . '" alt="Вода в природе"></div>'
            . '<div class="unics-slide-content">Первый абзац.<br>Второй абзац.</div>'
            . '<div class="unics-kp"><strong>Ключевые понятия:</strong>'
            . '<ul><li>круговорот</li><li>испарение</li></ul></div>'
            . '</div>'
            // Слайд 1: без картинки и без КП.
            . '<div class="unics-slide" data-idx="1" style="display:none">'
            . '<h3 class="unics-slide-title">Свойства воды</h3>'
            . '<div class="unics-slide-content">Просто текст.</div>'
            . '</div>'
            // Слайд 2: экранированные s() спецсимволы в заголовке и контенте.
            . '<div class="unics-slide" data-idx="2" style="display:none">'
            . '<h3 class="unics-slide-title">Кислоты &amp; &lt;основания&gt;</h3>'
            . '<div class="unics-slide-content">A &lt; B &amp;&amp; B &gt; C</div>'
            . '</div>'
            . '</div>';
    }

    public function test_is_presentation(): void {
        $this->assertTrue(slide_parser::is_presentation($this->fixture()));
        $this->assertFalse(slide_parser::is_presentation('<p>обычный материал</p>'));
        $this->assertFalse(slide_parser::is_presentation(''));
    }

    public function test_parse_returns_all_slides_with_clean_titles(): void {
        $slides = slide_parser::parse($this->fixture());

        $this->assertCount(3, $slides);
        $this->assertSame('Вода в природе', $slides[0]['title']); // без значка динамика
        $this->assertSame('Свойства воды', $slides[1]['title']);
        $this->assertSame('Кислоты & <основания>', $slides[2]['title']); // entity-декод
    }

    public function test_parse_extracts_image_binary_and_mime(): void {
        $slides = slide_parser::parse($this->fixture());

        $this->assertSame(self::IMG_BIN, $slides[0]['image']);
        $this->assertSame('image/jpeg', $slides[0]['image_mime']);
        $this->assertNull($slides[1]['image']);
        $this->assertNull($slides[1]['image_mime']);
    }

    public function test_parse_keeps_content_and_kp_html(): void {
        $slides = slide_parser::parse($this->fixture());

        $this->assertStringContainsString('Первый абзац.', $slides[0]['content_html']);
        $this->assertStringContainsString('<br', $slides[0]['content_html']);
        $this->assertStringContainsString('Ключевые понятия', $slides[0]['kp_html']);
        $this->assertStringContainsString('<li>круговорот</li>', $slides[0]['kp_html']);
        $this->assertSame('', $slides[1]['kp_html']);
    }

    public function test_parse_on_non_presentation_returns_empty(): void {
        $this->assertSame([], slide_parser::parse('<p>обычный материал</p>'));
    }

    public function test_text_lines_splits_br_and_decodes(): void {
        $slides = slide_parser::parse($this->fixture());

        $this->assertSame(['Первый абзац.', 'Второй абзац.'],
            slide_parser::text_lines($slides[0]['content_html']));
        $this->assertSame(['A < B && B > C'],
            slide_parser::text_lines($slides[2]['content_html']));
        $this->assertSame([], slide_parser::text_lines(''));
    }

    public function test_kp_items_extracts_li_texts(): void {
        $slides = slide_parser::parse($this->fixture());

        $this->assertSame(['круговорот', 'испарение'],
            slide_parser::kp_items($slides[0]['kp_html']));
        $this->assertSame([], slide_parser::kp_items(''));
    }
}
