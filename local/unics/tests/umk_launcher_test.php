<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\profile_fingerprint;
use local_unics\ai\umk_launcher;

/**
 * Постановка комплектов в очередь ([[umk-per-student-design]], разделы 6 и 8).
 * Группа доступа заводится ЗДЕСЬ, а не в воркере: очередь дренится параллельно, и нумерация
 * «Вариант N» в воркере была бы гонкой двух воркеров за один номер.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(umk_launcher::class)]
final class umk_launcher_test extends \advanced_testcase {

    private function gen(): ai_generator {
        return new class extends ai_generator {
            public function get_avg_score(int $mdl_user_id): float {
                return 70.0;
            }
        };
    }

    /**
     * Ученик, ЗАПИСАННЫЙ на курс. Запись обязательна: groups_add_member() молча возвращает
     * false для незаписанного (group/lib.php, is_enrolled), и без нее тесты не заметили бы
     * регресс «в группу никто не попал» - найдено ревью 2026-08-07.
     */
    private function make_student(array $fields = [], ?\stdClass $course = null): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        if ($course !== null) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        return (int)$DB->insert_record('unics_students', (object)(array_merge([
            'mdl_user_id'      => $user->id,
            'difficulty_level' => 2,
            'class_number'     => 7,
        ], $fields)));
    }

    private function params(array $over = []): array {
        return array_merge([
            'title'          => 'Дроби',
            'topic'          => 'Обыкновенные дроби',
            'target_section' => 1,
            'extra_prompt'   => '',
            'individual'     => false,
            'flags'          => ['generate_audio' => 0, 'generate_quiz' => 1,
                                 'generate_assignment' => 0, 'generate_video' => 0],
        ], $over);
    }

    public function test_one_umk_per_profile_with_group_and_queue(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->make_student([], $course);
        $b = $this->make_student([], $course);                          // тот же профиль
        $c = $this->make_student(['difficulty_level' => 3], $course);   // другой
        $groups = profile_fingerprint::group_students([$a, $b, $c], false, $this->gen());

        $created = umk_launcher::launch((int)$course->id, $groups, $this->params());

        $this->assertSame(2, $created);
        $umks = $DB->get_records('unics_umk', ['mdl_course_id' => $course->id]);
        $this->assertCount(2, $umks);
        foreach ($umks as $umk) {
            $this->assertSame(40, strlen((string)$umk->profile_key), 'Регламент профильный');
            $this->assertNotEmpty($umk->mdl_group_id, 'Группа заводится на постановке');
            $this->assertSame(1, $DB->count_records('unics_ai_queue', ['umk_id' => $umk->id]));
        }
        // Ученики одного профиля лежат в одной строке очереди.
        $sizes = [];
        foreach ($umks as $umk) {
            $q = $DB->get_record('unics_ai_queue', ['umk_id' => $umk->id]);
            $sizes[] = count(json_decode($q->student_ids, true));
        }
        sort($sizes);
        $this->assertSame([1, 2], $sizes);

        // Главное, ради чего лаунчер существует: дети ЛЕЖАТ в группе доступа. Без этой
        // проверки регресс «в группу никто не попал» проходил бы сьют зеленым.
        foreach ($umks as $umk) {
            $sids = json_decode(
                $DB->get_field('unics_ai_queue', 'student_ids', ['umk_id' => $umk->id]), true);
            foreach ($sids as $sid) {
                $uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => $sid]);
                $this->assertTrue(groups_is_member((int)$umk->mdl_group_id, $uid),
                    "Ученик {$sid} не попал в группу доступа комплекта");
            }
        }
    }

    public function test_over_limit_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('umk_max_per_run', 1, 'local_unics');
        $course = $this->getDataGenerator()->create_course();
        $groups = profile_fingerprint::group_students(
            [$this->make_student(), $this->make_student(['difficulty_level' => 3])], false, $this->gen());

        try {
            umk_launcher::launch((int)$course->id, $groups, $this->params());
            $this->fail('Превышение потолка обязано бросать исключение');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('отолок', $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('unics_umk', ['mdl_course_id' => $course->id]));
        $this->assertSame(0, $DB->count_records('unics_ai_queue'));
    }

    /**
     * Найдено живой проверкой: настройка добавлена в settings.php ПОСЛЕ того, как апгрейд
     * плагина уже прошел, поэтому на стенде строки конфига не было вовсе. get_config() отдает
     * false, (int)false = 0, а ноль означает «без ограничения» - потолок молча снимался бы.
     */
    public function test_unset_limit_falls_back_to_default_not_unlimited(): void {
        $this->resetAfterTest();
        unset_config('umk_max_per_run', 'local_unics');

        $this->assertSame(umk_launcher::DEFAULT_LIMIT, umk_launcher::limit());
        $this->assertGreaterThan(0, umk_launcher::limit(), 'Отсутствие настройки - не «без потолка»');
    }

    public function test_zero_limit_means_unlimited(): void {
        $this->resetAfterTest();
        set_config('umk_max_per_run', 0, 'local_unics');
        $course = $this->getDataGenerator()->create_course();
        $groups = profile_fingerprint::group_students(
            [$this->make_student(), $this->make_student(['difficulty_level' => 3])], false, $this->gen());

        $this->assertSame(2, umk_launcher::launch((int)$course->id, $groups, $this->params()));
    }

    public function test_individual_mode_uses_personal_group(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sid = $this->make_student();
        $uid = (int)$DB->get_field('unics_students', 'mdl_user_id', ['id' => $sid]);
        $groups = profile_fingerprint::group_students([$sid], true, $this->gen());

        umk_launcher::launch((int)$course->id, $groups, $this->params(['individual' => true]));

        $umk = $DB->get_record('unics_umk', ['mdl_course_id' => $course->id], '*', MUST_EXIST);
        $this->assertSame('umk_s' . $uid . '_c' . $course->id,
            $DB->get_field('groups', 'idnumber', ['id' => $umk->mdl_group_id]));
    }
}
