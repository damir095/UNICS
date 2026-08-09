<?php
namespace local_unics;

use local_unics\ai\lecture_illustrator;

/**
 * Иллюстрации учебного текста ([[ai-lecture-images-design]], раздел 4).
 *
 * Класс чистый - ни сети, ни БД, поэтому покрывается целиком. Фикстура повторяет
 * то, что реально приходит от модели после output_style::shift_headings(): разделы
 * размечены «####».
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lecture_illustrator::class)]
final class lecture_illustrator_test extends \basic_testcase {

    private function md(int $sections = 3): string {
        $out = "Вводный абзац про воду.\n\n";
        for ($i = 1; $i <= $sections; $i++) {
            $out .= "#### Раздел {$i}\n\nТекст раздела {$i}. Второе предложение раздела {$i}.\n\n";
        }
        return $out;
    }

    public function test_split_sections_returns_heading_and_lead(): void {
        $secs = lecture_illustrator::split_sections($this->md(3), 'Вода в природе');

        $this->assertCount(3, $secs);
        $this->assertSame('Раздел 1', $secs[0]['heading']);
        $this->assertStringContainsString('Текст раздела 1.', $secs[0]['lead']);
        $this->assertStringNotContainsString('Раздел 2', $secs[0]['lead']);
    }

    public function test_split_sections_caps_at_max_images(): void {
        $secs = lecture_illustrator::split_sections($this->md(6), 'Вода в природе');

        $this->assertCount(lecture_illustrator::MAX_IMAGES, $secs);
        $this->assertSame(4, lecture_illustrator::MAX_IMAGES);
        $this->assertSame('Раздел 4', $secs[3]['heading']);
    }

    /** Заголовков нет - одна вводная картинка, заголовок берется из темы УМК. */
    public function test_split_sections_without_headings_falls_back_to_topic(): void {
        $secs = lecture_illustrator::split_sections(
            'Сплошной текст без заголовков. Второе предложение.', 'Вода в природе');

        $this->assertCount(1, $secs);
        $this->assertSame('Вода в природе', $secs[0]['heading']);
        $this->assertStringContainsString('Сплошной текст', $secs[0]['lead']);
    }

    public function test_lead_is_trimmed_to_word_boundary(): void {
        $long = "#### Раздел\n\n" . str_repeat('словоформа ', 60);
        $secs = lecture_illustrator::split_sections($long, 'Тема');

        $this->assertLessThanOrEqual(200, \core_text::strlen($secs[0]['lead']));
        $this->assertStringEndsNotWith(' ', $secs[0]['lead']);
        $this->assertStringEndsWith('словоформа', $secs[0]['lead']);
    }

    public function test_prompt_for_zpr_asks_for_single_object(): void {
        $p = lecture_illustrator::build_image_prompt(
            ['ovz_type_ids' => [4]], 'Вода в природе', 'Круговорот', 'Вода испаряется.');

        $this->assertStringContainsString('Один узнаваемый объект в центре', $p);
        $this->assertStringContainsString('Круговорот', $p);
        $this->assertStringContainsString('Вода в природе', $p);
    }

    public function test_prompt_for_ras_forbids_metaphors(): void {
        $p = lecture_illustrator::build_image_prompt(
            ['ovz_type_ids' => [5]], 'Вода в природе', 'Круговорот', '');

        $this->assertStringContainsString('без метафор', $p);
    }

    public function test_prompt_without_ovz_has_no_nosology_block(): void {
        $p = lecture_illustrator::build_image_prompt(
            ['ovz_type_ids' => []], 'Вода в природе', 'Круговорот', '');

        $this->assertStringNotContainsString('Один узнаваемый объект', $p);
        $this->assertStringNotContainsString('без метафор', $p);
        $this->assertStringNotContainsString('Крупные контрастные объекты', $p);
        $this->assertStringContainsString('Без подписей и текста на изображении', $p);
    }

    /**
     * Указания педагога в промт картинки не идут - сознательное отступление от
     * [[teacher-extra-prompt-design]]: поле пишется словами про текст и в директиве
     * рисования дает шум. Тест - страж этого решения.
     */
    public function test_prompt_ignores_teacher_extra_prompt(): void {
        $p = lecture_illustrator::build_image_prompt(
            ['ovz_type_ids' => [4], 'extra_prompt' => 'больше примеров из биологии'],
            'Вода в природе', 'Круговорот', '');

        $this->assertStringNotContainsString('больше примеров', $p);
    }

    public function test_insert_images_places_tag_after_heading(): void {
        $md   = $this->md(2);
        $secs = lecture_illustrator::split_sections($md, 'Тема');
        $out  = lecture_illustrator::insert_images($md, $secs,
            [0 => 'lecture-1.jpg', 1 => 'lecture-2.jpg']);

        $this->assertStringContainsString(
            '#### Раздел 1' . "\n\n" . '<p class="unics-lecture-img">'
            . '<img src="@@PLUGINFILE@@/lecture-1.jpg" alt="Раздел 1"></p>', $out);
        $this->assertStringContainsString('@@PLUGINFILE@@/lecture-2.jpg', $out);
    }

    /** Отказ одной картинки не сдвигает имена остальных. */
    public function test_insert_images_skips_missing_without_shifting(): void {
        $md   = $this->md(3);
        $secs = lecture_illustrator::split_sections($md, 'Тема');
        $out  = lecture_illustrator::insert_images($md, $secs,
            [0 => 'lecture-1.jpg', 2 => 'lecture-3.jpg']);

        $this->assertStringContainsString('@@PLUGINFILE@@/lecture-1.jpg', $out);
        $this->assertStringNotContainsString('lecture-2.jpg', $out);
        $this->assertStringContainsString('@@PLUGINFILE@@/lecture-3.jpg', $out);
        $this->assertSame(2, substr_count($out, 'unics-lecture-img'));
    }

    public function test_insert_images_without_headings_prepends(): void {
        $md   = 'Сплошной текст без заголовков.';
        $secs = lecture_illustrator::split_sections($md, 'Вода в природе');
        $out  = lecture_illustrator::insert_images($md, $secs, [0 => 'lecture-1.jpg']);

        $this->assertStringStartsWith('<p class="unics-lecture-img">', $out);
        $this->assertStringContainsString('alt="Вода в природе"', $out);
        $this->assertStringEndsWith('Сплошной текст без заголовков.', $out);
    }

    public function test_insert_images_escapes_quotes_in_alt(): void {
        $md   = "#### Тема «Кислоты» и \"основания\"\n\nТекст.";
        $secs = lecture_illustrator::split_sections($md, 'Тема');
        $out  = lecture_illustrator::insert_images($md, $secs, [0 => 'lecture-1.jpg']);

        $this->assertStringNotContainsString('alt="Тема «Кислоты» и "основания""', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function test_insert_images_with_empty_filenames_returns_text_unchanged(): void {
        $md   = $this->md(2);
        $secs = lecture_illustrator::split_sections($md, 'Тема');

        $this->assertSame($md, lecture_illustrator::insert_images($md, $secs, []));
    }
}
