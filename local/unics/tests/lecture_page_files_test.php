<?php
namespace local_unics;

use local_unics\ai\course_builder;

/**
 * Картинки лекции ложатся в файловое хранилище Moodle, а не в base64
 * ([[ai-lecture-images-design]], раздел 4.3).
 *
 * itemid ровно 0 - mod_page_pluginfile() отбрасывает revision из пути
 * (mod/page/lib.php:338) и читает область жестко с нулевым itemid (:366).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_builder::class)]
final class lecture_page_files_test extends \advanced_testcase {

    private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg-bytes";

    public function test_add_text_page_stores_images_in_page_content_area(): void {
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $builder = new course_builder();

        $cmid = $builder->add_text_page($course->id, 1, 'Вода',
            "#### Круговорот\n\n<p class=\"unics-lecture-img\">"
            . "<img src=\"@@PLUGINFILE@@/lecture-1.jpg\" alt=\"Круговорот\"></p>\n\nТекст.",
            ['lecture-1.jpg' => self::JPEG]);

        $ctx  = \context_module::instance($cmid);
        $file = get_file_storage()->get_file($ctx->id, 'mod_page', 'content', 0, '/', 'lecture-1.jpg');

        $this->assertNotFalse($file);
        $this->assertSame(self::JPEG, $file->get_content());
    }

    public function test_page_content_keeps_pluginfile_placeholder(): void {
        global $DB;
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $builder = new course_builder();

        $cmid = $builder->add_text_page($course->id, 1, 'Вода',
            '<img src="@@PLUGINFILE@@/lecture-1.jpg" alt="a">',
            ['lecture-1.jpg' => self::JPEG]);

        $instance = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $content  = (string)$DB->get_field('page', 'content', ['id' => $instance]);

        // Подстановку реального URL делает mod_page на выводе - в БД остается плейсхолдер.
        $this->assertStringContainsString('@@PLUGINFILE@@/lecture-1.jpg', $content);
        $this->assertStringNotContainsString('data:image', $content);
    }

    /** Пустой бинарник (картинка не создалась) файла не порождает. */
    public function test_empty_binary_creates_no_file(): void {
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $builder = new course_builder();

        $cmid = $builder->add_text_page($course->id, 1, 'Вода', 'Текст.',
            ['lecture-1.jpg' => '']);

        $ctx = \context_module::instance($cmid);
        $this->assertFalse(get_file_storage()
            ->get_file($ctx->id, 'mod_page', 'content', 0, '/', 'lecture-1.jpg'));
    }

    /** Обратная совместимость: старый вызов без картинок работает как раньше. */
    public function test_add_text_page_without_images_still_works(): void {
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $builder = new course_builder();

        $cmid = $builder->add_text_page($course->id, 1, 'Вода', 'Просто текст.');

        $this->assertGreaterThan(0, $cmid);
        $this->assertSame([], get_file_storage()->get_area_files(
            \context_module::instance($cmid)->id, 'mod_page', 'content', 0, 'id', false));
    }
}
