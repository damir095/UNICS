<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\ai_queue;
use local_unics\ai\umk_processor;

/**
 * Тесты обработчика одного элемента очереди ИИ-генерации
 * ([[ai-queue-parallel-design]]): полный happy-path с фейковым генератором
 * (без сети/заморозка) и путь ошибки. Логика перенесена из монолита
 * process_ai_queue - тест фиксирует поведение после переноса.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(umk_processor::class)]
final class umk_processor_test extends \advanced_testcase {

    /** Фейковый генератор: фикстуры вместо сети. */
    private function fake_generator(bool $fail = false): ai_generator {
        return new class($fail) extends ai_generator {
            public function __construct(private bool $fail) {
                parent::__construct();
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                if ($this->fail) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '', 'GigaChat недоступен (фейк)');
                }
                return "Учебный текст про воду.\nВторой абзац.";
            }
            public function get_avg_score(int $mdl_user_id): float {
                return 75.0; // Без смены уровня и без уведомления о низком балле.
            }
        };
    }

    /** Курс + ученик + УМК + строка очереди (только текст). */
    private function make_fixture(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $user = $this->getDataGenerator()->create_user();
        $studentid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id'      => $user->id,
            'difficulty_level' => 1,
            'class_number'     => 5,
        ]);
        $umkid = (int)$DB->insert_record('unics_umk', (object)[
            'difficulty_level' => 1,
            'mdl_course_id'    => $course->id,
            'title'            => 'Вода',
            'topic'            => 'Вода в природе',
            'target_section'   => 1,
            'status'           => 1,
            'generated_at'     => time(),
        ]);
        $queueid = (int)$DB->insert_record('unics_ai_queue', (object)[
            'umk_id'          => $umkid,
            'student_ids'     => json_encode([$studentid]),
            'generate_audio'  => 0,
            'generate_quiz'   => 0,
            'generate_assignment' => 0,
            'generate_video'  => 0,
            'status'          => ai_queue::STATUS_PENDING,
            'created_at'      => time(),
        ]);
        return [$course, $user, $studentid, $umkid, $queueid];
    }

    /**
     * Группа доступа заводится на постановке ([[umk-per-student-design]], раздел 7), поэтому
     * воркер обязан взять готовую, а не создавать уровневую. Иначе материал ляжет в чужую
     * группу, а нумерация «Вариант N» стала бы гонкой параллельных воркеров.
     */
    public function test_process_uses_group_prepared_at_enqueue(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user, $studentid, $umkid, $queueid] = $this->make_fixture();

        $prepared = (int)$this->getDataGenerator()->create_group([
            'courseid' => $course->id, 'name' => 'Вариант 1',
            'idnumber' => 'umk_fpdeadbeef_c' . $course->id,
        ])->id;
        $DB->set_field('unics_umk', 'mdl_group_id', $prepared, ['id' => $umkid]);
        $before = $DB->count_records('groups', ['courseid' => $course->id]);

        $this->expectOutputRegex('~готов~');
        (new umk_processor($this->fake_generator()))->process(ai_queue::claim($queueid));

        $this->assertSame($prepared,
            (int)$DB->get_field('unics_umk', 'mdl_group_id', ['id' => $umkid]));
        $this->assertSame($before, $DB->count_records('groups', ['courseid' => $course->id]),
            'Новых групп воркер заводить не должен');
    }

    public function test_process_happy_path_creates_hidden_material(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $user, $studentid, $umkid, $queueid] = $this->make_fixture();

        $this->expectOutputRegex('~готов~'); // mtrace-протокол обработчика.
        $row = ai_queue::claim($queueid);
        (new umk_processor($this->fake_generator()))->process($row);

        // Статусы: очередь и УМК готовы.
        $q = $DB->get_record('unics_ai_queue', ['id' => $queueid], '*', MUST_EXIST);
        $this->assertSame(ai_queue::STATUS_DONE, (int)$q->status);
        $this->assertNotEmpty($q->processed_at);
        $umk = $DB->get_record('unics_umk', ['id' => $umkid], '*', MUST_EXIST);
        $this->assertSame(3, (int)$umk->status);
        $this->assertNotEmpty($umk->mdl_group_id);

        // Материал: одна страница, СКРЫТАЯ (review-гейт), запись в unics_umk_materials.
        $materials = $DB->get_records('unics_umk_materials', ['umk_id' => $umkid]);
        $this->assertCount(1, $materials);
        $mat = reset($materials);
        $this->assertSame(1, (int)$mat->material_type);
        $cm = $DB->get_record('course_modules', ['id' => $mat->mdl_course_module_id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$cm->visible);

        // Ученик: запись на курс, членство в группе уровня, привязка к УМК.
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user->id));
        $this->assertTrue(groups_is_member((int)$umk->mdl_group_id, (int)$user->id));
        $this->assertTrue($DB->record_exists('unics_umk_students', ['umk_id' => $umkid, 'student_id' => $studentid]));
    }

    public function test_process_failure_marks_failed_with_message(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , $umkid, $queueid] = $this->make_fixture();

        $this->expectOutputRegex('~ошибка~'); // mtrace-протокол обработчика.
        $row = ai_queue::claim($queueid);
        (new umk_processor($this->fake_generator(true)))->process($row);

        $q = $DB->get_record('unics_ai_queue', ['id' => $queueid], '*', MUST_EXIST);
        $this->assertSame(ai_queue::STATUS_FAILED, (int)$q->status);
        $this->assertStringContainsString('GigaChat недоступен', (string)$q->error_message);
        $this->assertSame(4, (int)$DB->get_field('unics_umk', 'status', ['id' => $umkid]));
    }

    /** Генератор с размеченным на разделы текстом и управляемым исходом рисования. */
    private function illustrating_generator(bool $image_fails): ai_generator {
        return new class($image_fails) extends ai_generator {
            public function __construct(private bool $image_fails) {
                parent::__construct();
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                return "#### Круговорот\n\nВода испаряется.\n\n#### Осадки\n\nВода выпадает.";
            }
            public function generate_image(string $prompt): string {
                if ($this->image_fails) {
                    throw new \moodle_exception('generalexceptionmessage', 'error', '',
                        'GigaChat image: UUID изображения не найден в ответе');
                }
                return "\xFF\xD8\xFF\xE0fake-jpeg-bytes";
            }
            public function get_avg_score(int $mdl_user_id): float {
                return 75.0;
            }
        };
    }

    /**
     * Иллюстрации лекции: картинки уходят в файловое хранилище, ссылки - в текст,
     * счетчики - в УМК ([[ai-lecture-images-design]]).
     */
    public function test_process_illustrates_lecture_and_counts_images(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , $umkid, $queueid] = $this->make_fixture();
        $DB->set_field('unics_ai_queue', 'generate_images', 1, ['id' => $queueid]);
        set_config('ai_api_key', 'fake-key-for-test', 'local_unics');

        $this->expectOutputRegex('~готов~');
        (new umk_processor($this->illustrating_generator(false)))->process(ai_queue::claim($queueid));

        $umk = $DB->get_record('unics_umk', ['id' => $umkid], '*', MUST_EXIST);
        $this->assertSame(2, (int)$umk->images_total);
        $this->assertSame(2, (int)$umk->images_made);

        $cmid = (int)$DB->get_field('unics_umk_materials', 'mdl_course_module_id',
            ['umk_id' => $umkid, 'material_type' => 1]);
        $instance = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $content  = (string)$DB->get_field('page', 'content', ['id' => $instance]);

        $this->assertStringContainsString('@@PLUGINFILE@@/lecture-1.jpg', $content);
        $this->assertStringContainsString('@@PLUGINFILE@@/lecture-2.jpg', $content);

        $ctx = \context_module::instance($cmid);
        $this->assertNotFalse(get_file_storage()
            ->get_file($ctx->id, 'mod_page', 'content', 0, '/', 'lecture-1.jpg'));
    }

    /**
     * Отказ картинки не валит генерацию: лекция создается, счетчик честно показывает
     * недобор. Именно глушение этой ошибки скрывало поломку годами.
     */
    public function test_image_failure_keeps_lecture_and_reports_shortfall(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , $umkid, $queueid] = $this->make_fixture();
        $DB->set_field('unics_ai_queue', 'generate_images', 1, ['id' => $queueid]);
        set_config('ai_api_key', 'fake-key-for-test', 'local_unics');

        $this->expectOutputRegex('~готов~');
        (new umk_processor($this->illustrating_generator(true)))->process(ai_queue::claim($queueid));

        $umk = $DB->get_record('unics_umk', ['id' => $umkid], '*', MUST_EXIST);
        $this->assertSame(2, (int)$umk->images_total);
        $this->assertSame(0, (int)$umk->images_made);
        // Материал вышел несмотря на отказ картинок.
        $this->assertSame(3, (int)$umk->status);
        $this->assertTrue($DB->record_exists('unics_umk_materials',
            ['umk_id' => $umkid, 'material_type' => 1]));
    }

    /**
     * Разметка картинок НЕ должна утекать во вторичные генераторы.
     *
     * strip_for_tts() чистит markdown и LaTeX, но HTML не трогает вообще
     * (ai_generator.php:360), поэтому тег <img> ушел бы в синтез речи и ребенок
     * услышал бы его зачитанным вслух. Те же 400 символов разметки вытесняли бы
     * настоящий текст из промтов теста, задания и видео - там источник режется
     * по 2000 символов.
     */
    public function test_illustration_markup_does_not_leak_into_secondary_generators(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , , $queueid] = $this->make_fixture();
        $DB->set_field('unics_ai_queue', 'generate_images', 1, ['id' => $queueid]);
        $DB->set_field('unics_ai_queue', 'generate_audio', 1, ['id' => $queueid]);
        set_config('ai_api_key', 'fake-key-for-test', 'local_unics');

        $seen = new \stdClass();
        $seen->tts = null;
        $generator = new class($seen) extends ai_generator {
            public function __construct(private \stdClass $seen) {
                parent::__construct();
            }
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                return "#### Круговорот\n\nВода испаряется.\n\n#### Осадки\n\nВода выпадает.";
            }
            public function generate_image(string $prompt): string {
                return "\xFF\xD8\xFF\xE0fake-jpeg-bytes";
            }
            public function generate_audio(string $text): string {
                $this->seen->tts = $text;
                return 'fake-wav';
            }
            public function get_avg_score(int $mdl_user_id): float {
                return 75.0;
            }
        };

        $this->expectOutputRegex('~готов~');
        (new umk_processor($generator))->process(ai_queue::claim($queueid));

        $this->assertNotNull($seen->tts);
        $this->assertStringNotContainsString('unics-lecture-img', $seen->tts);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $seen->tts);
        $this->assertStringNotContainsString('<img', $seen->tts);
        // Настоящий текст при этом на месте.
        $this->assertStringContainsString('Вода испаряется', $seen->tts);
    }

    /**
     * Отказ озвучки не должен валить комплект.
     *
     * Блок аудио был ЕДИНСТВЕННЫМ вторичным материалом без try/catch: у теста, задания,
     * видео и озвучки слайдов защита есть. Из-за этого галочка «Аудиоматериал» при
     * неоплаченном SmartSpeech гарантированно убивала весь УМК вместе с уже созданным
     * учебным текстом ([[tts-honest-availability-design]], раздел 2).
     */
    public function test_audio_failure_does_not_kill_the_kit(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , $umkid, $queueid] = $this->make_fixture();
        $DB->set_field('unics_ai_queue', 'generate_audio', 1, ['id' => $queueid]);

        $generator = new class extends ai_generator {
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                return 'Учебный текст про воду длиной более пятидесяти символов для проверки.';
            }
            public function generate_audio(string $text): string {
                throw new \moodle_exception('generalexceptionmessage', 'error', '',
                    'SaluteSpeech HTTP 402: Payment Required');
            }
            public function get_avg_score(int $mdl_user_id): float {
                return 75.0;
            }
        };

        $this->expectOutputRegex('~готов~');
        (new umk_processor($generator))->process(ai_queue::claim($queueid));

        // Комплект вышел, а не умер.
        $this->assertSame(3, (int)$DB->get_field('unics_umk', 'status', ['id' => $umkid]));
        // Учебный текст на месте.
        $this->assertTrue($DB->record_exists('unics_umk_materials',
            ['umk_id' => $umkid, 'material_type' => 1]));
        // Аудиоматериала нет.
        $this->assertFalse($DB->record_exists('unics_umk_materials',
            ['umk_id' => $umkid, 'material_type' => 3]));
    }

    /**
     * Метка недоступности гасит и озвучку СЛАЙДОВ, а не только галочку аудиоматериала.
     *
     * Найдено ревью: слайды гейтились одним лишь наличием ключа, поэтому при известной
     * недоступности видеопрезентация все равно делала обреченный запрос на каждый слайд -
     * до сотни бесполезных обращений за запуск при потолке комплектов.
     */
    public function test_known_unavailable_tts_skips_slide_narration(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , , $queueid] = $this->make_fixture();
        $DB->set_field('unics_ai_queue', 'generate_video', 1, ['id' => $queueid]);
        set_config('ai_api_key', 'fake-key-for-test', 'local_unics');
        set_config('salute_speech_api_key', 'FAKE_KEY', 'local_unics');
        \local_unics\ai\tts_status::mark_unavailable('Payment Required');

        $generator = new class extends ai_generator {
            public int $audio_calls = 0;
            public function generate_text(string $prompt, int $max_tokens = 1024,
                                          int $minlen = self::MIN_REPLY_LEN): string {
                return 'Учебный текст про воду длиной более пятидесяти символов для проверки.';
            }
            public function generate_video_script(array $profile, string $topic,
                    string $source_text = '', string $extra_context = ''): array {
                return [
                    ['title' => 'Слайд 1', 'content' => 'Текст 1', 'key_points' => []],
                    ['title' => 'Слайд 2', 'content' => 'Текст 2', 'key_points' => []],
                ];
            }
            public function generate_audio(string $text): string {
                $this->audio_calls++;
                return 'fake-wav';
            }
            public function generate_image(string $prompt): string {
                return "\xFF\xD8\xFF\xE0fake";
            }
            public function get_avg_score(int $mdl_user_id): float {
                return 75.0;
            }
        };

        $this->expectOutputRegex('~готов~');
        (new umk_processor($generator))->process(ai_queue::claim($queueid));

        $this->assertSame(0, $generator->audio_calls);
    }

    /** Флаг снят - ни одного обращения за картинкой, счетчики остаются NULL. */
    public function test_without_flag_no_images_and_counters_stay_null(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , $umkid, $queueid] = $this->make_fixture();
        set_config('ai_api_key', 'fake-key-for-test', 'local_unics');

        $this->expectOutputRegex('~готов~');
        // Генератор бросает на любой запрос картинки: если воркер его дернет, тест упадет.
        (new umk_processor($this->illustrating_generator(true)))->process(ai_queue::claim($queueid));

        $umk = $DB->get_record('unics_umk', ['id' => $umkid], '*', MUST_EXIST);
        $this->assertNull($umk->images_total);
        $this->assertNull($umk->images_made);
    }
}
