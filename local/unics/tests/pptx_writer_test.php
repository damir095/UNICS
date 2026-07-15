<?php
namespace local_unics;

use local_unics\ai\pptx_writer;

/**
 * Тесты OOXML-генератора PPTX ([[umk-pptx-export-design]]).
 *
 * Проверяем структуру пакета: валидный zip, обязательные части, well-formed XML,
 * титульный + рабочие слайды, media-часть картинки, XML-экранирование текста.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pptx_writer::class)]
final class pptx_writer_test extends \basic_testcase {

    private const IMG_BIN = "\xFF\xD8\xFF\xE0fake-jpeg-bytes";

    /** Слайды в формате slide_parser::parse(). */
    private function slides(): array {
        return [
            [
                'title'        => 'Вода & <жизнь>',
                'content_html' => 'Первый абзац.<br>Второй &lt;абзац&gt;.',
                'kp_html'      => '<strong>Ключевые понятия:</strong><ul><li>круговорот</li><li>испарение</li></ul>',
                'image'        => self::IMG_BIN,
                'image_mime'   => 'image/jpeg',
            ],
            [
                'title'        => 'Свойства воды',
                'content_html' => 'Просто текст.',
                'kp_html'      => '',
                'image'        => null,
                'image_mime'   => null,
            ],
        ];
    }

    /** Собрать pptx во временный файл и вернуть открытый ZipArchive. */
    private function build_zip(): \ZipArchive {
        $bin = pptx_writer::build('Тема: вода', 'География. 7 класс', $this->slides());
        $this->assertNotSame('', $bin);

        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        file_put_contents($tmp, $bin);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp));
        return $zip;
    }

    public function test_package_contains_required_parts(): void {
        $zip = $this->build_zip();

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'docProps/core.xml',
            'docProps/app.xml',
            'ppt/presentation.xml',
            'ppt/_rels/presentation.xml.rels',
            'ppt/theme/theme1.xml',
            'ppt/slideMasters/slideMaster1.xml',
            'ppt/slideMasters/_rels/slideMaster1.xml.rels',
            'ppt/slideLayouts/slideLayout1.xml',
            'ppt/slideLayouts/_rels/slideLayout1.xml.rels',
        ] as $part) {
            $this->assertNotFalse($zip->locateName($part), "нет части {$part}");
        }
    }

    public function test_title_slide_plus_content_slides(): void {
        $zip = $this->build_zip();

        // Титул + 2 слайда контента.
        $this->assertNotFalse($zip->locateName('ppt/slides/slide1.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide2.xml'));
        $this->assertNotFalse($zip->locateName('ppt/slides/slide3.xml'));
        $this->assertFalse($zip->locateName('ppt/slides/slide4.xml'));

        // Титул: название презентации и курс.
        $title = $zip->getFromName('ppt/slides/slide1.xml');
        $this->assertStringContainsString('Тема: вода', $title);
        $this->assertStringContainsString('География. 7 класс', $title);
    }

    public function test_all_xml_parts_are_well_formed(): void {
        $zip = $this->build_zip();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -4) !== '.xml' && substr($name, -5) !== '.rels') {
                continue;
            }
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($zip->getFromIndex($i));
            $this->assertTrue($ok, "битый XML в {$name}");
        }
    }

    public function test_presentation_is_16x9(): void {
        $zip = $this->build_zip();

        $pres = $zip->getFromName('ppt/presentation.xml');
        $this->assertStringContainsString('cx="12192000"', $pres);
        $this->assertStringContainsString('cy="6858000"', $pres);
    }

    public function test_special_chars_escaped_but_text_preserved(): void {
        $zip = $this->build_zip();

        $slide2 = $zip->getFromName('ppt/slides/slide2.xml');
        // Сырой & или < в тексте сделал бы XML битым - парсим и ищем текст.
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($slide2));
        $this->assertStringContainsString('Вода & <жизнь>', $doc->textContent);
        $this->assertStringContainsString('Второй <абзац>.', $doc->textContent);
        $this->assertStringContainsString('круговорот', $doc->textContent);
    }

    public function test_image_lands_in_media_and_rels(): void {
        $zip = $this->build_zip();

        $this->assertSame(self::IMG_BIN, $zip->getFromName('ppt/media/image1.jpeg'));
        $rels = $zip->getFromName('ppt/slides/_rels/slide2.xml.rels');
        $this->assertStringContainsString('../media/image1.jpeg', $rels);
        // У слайда без картинки media-ссылки нет.
        $rels3 = $zip->getFromName('ppt/slides/_rels/slide3.xml.rels');
        $this->assertStringNotContainsString('media/', $rels3);
    }
}
