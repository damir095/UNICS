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
            public function generate_text(string $prompt, int $max_tokens = 1024): string {
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
}
