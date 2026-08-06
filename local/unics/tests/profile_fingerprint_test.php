<?php
namespace local_unics;

use local_unics\ai\ai_generator;
use local_unics\ai\profile_fingerprint;

/**
 * Отпечаток профиля - ключ, по которому схлопываются УМК ([[umk-per-student-design]], раздел 4).
 * Ключ снимается с build_criteria(): это чистая функция профиля, а build_prompt =
 * f(критерии, тема, доп. указания). Поэтому равные ключи обязаны давать равный промт при ЛЮБОЙ
 * теме - это и есть инвариант, ради которого ключ снимается именно с критериев.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(profile_fingerprint::class)]
final class profile_fingerprint_test extends \advanced_testcase {

    /** Генератор с фиксированным баллом: без него балл считался бы из оценок. */
    private function gen(float $avg = 70.0): ai_generator {
        return new class($avg) extends ai_generator {
            public function __construct(private float $avg) {
                parent::__construct();
            }
            public function get_avg_score(int $mdl_user_id): float {
                return $this->avg;
            }
        };
    }

    /** Ученик с категориями и видами ОВЗ. Возвращает unics_students.id. */
    private function make_student(array $fields = [], array $cats = [2], array $ovz = []): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)(array_merge([
            'mdl_user_id'      => $user->id,
            'difficulty_level' => 2,
            'class_number'     => 7,
            'class_letter'     => 'А',
        ], $fields)));
        foreach ($cats as $c) {
            $DB->insert_record('unics_student_category', (object)['student_id' => $sid, 'category' => $c]);
        }
        foreach ($ovz as $o) {
            $DB->insert_record('unics_student_ovz', (object)['student_id' => $sid, 'ovz_type' => $o]);
        }
        return $sid;
    }

    public function test_identical_profiles_share_key(): void {
        $this->resetAfterTest();
        $gen = $this->gen();
        $a = profile_fingerprint::profile_of($this->make_student(), $gen);
        $b = profile_fingerprint::profile_of($this->make_student(), $gen);

        $this->assertSame(profile_fingerprint::key($a, $gen), profile_fingerprint::key($b, $gen));
    }

    public function test_each_profile_input_splits_key(): void {
        $this->resetAfterTest();
        $gen  = $this->gen();
        $base = profile_fingerprint::profile_of($this->make_student(), $gen);
        $key  = profile_fingerprint::key($base, $gen);

        $cases = [
            'категория'      => profile_fingerprint::profile_of($this->make_student([], [4]), $gen),
            'вид ОВЗ'        => profile_fingerprint::profile_of($this->make_student([], [1], [5]), $gen),
            'уровень'        => profile_fingerprint::profile_of($this->make_student(['difficulty_level' => 3]), $gen),
            'класс'          => profile_fingerprint::profile_of($this->make_student(['class_number' => 9]), $gen),
            'буква класса'   => profile_fingerprint::profile_of($this->make_student(['class_letter' => 'Б']), $gen),
            'особенности'    => profile_fingerprint::profile_of($this->make_student(['special_needs' => 'нужен перерыв']), $gen),
        ];
        foreach ($cases as $what => $profile) {
            $this->assertNotSame($key, profile_fingerprint::key($profile, $gen),
                "Расхождение по входу «{$what}» обязано менять ключ");
        }
    }

    public function test_category_order_does_not_split_key(): void {
        $this->resetAfterTest();
        $gen = $this->gen();
        // Порядок из get_fieldset_select не гарантирован - несортированный вход дал бы
        // два разных ключа одному профилю.
        $a = profile_fingerprint::profile_of($this->make_student([], [1, 4], [2, 5]), $gen);
        $b = profile_fingerprint::profile_of($this->make_student([], [4, 1], [5, 2]), $gen);

        $this->assertSame(profile_fingerprint::key($a, $gen), profile_fingerprint::key($b, $gen));
    }

    public function test_score_splits_key_only_across_band_border(): void {
        $this->resetAfterTest();
        $base = ['categories' => [2], 'ovz_types' => [], 'difficulty_level' => 2,
                 'class_number' => 7, 'class_letter' => 'А', 'special_needs' => ''];
        $gen = $this->gen();
        $key = fn(float $s) => profile_fingerprint::key($base + ['avg_score' => $s], $gen);

        $this->assertSame($key(55.0), $key(80.0), 'Внутри полосы ключ обязан совпадать');
        $this->assertNotSame($key(49.0), $key(51.0), 'Через границу 50 ключ обязан разойтись');
        $this->assertNotSame($key(85.0), $key(86.0), 'Через границу 85 ключ обязан разойтись');
    }

    public function test_equal_keys_imply_equal_prompt_for_any_topic(): void {
        $this->resetAfterTest();
        $gen = $this->gen();
        // Балл разный, но внутри одной полосы - ключ обязан совпасть, а значит и промт.
        $a = profile_fingerprint::profile_of($this->make_student(), $this->gen(55.0));
        $b = profile_fingerprint::profile_of($this->make_student(), $this->gen(80.0));
        $this->assertSame(profile_fingerprint::key($a, $gen), profile_fingerprint::key($b, $gen));

        foreach ([['Дроби', ''], ['Клеточное строение', 'акцент на схемах']] as [$topic, $extra]) {
            $this->assertSame($gen->build_prompt($a, $topic, $extra),
                $gen->build_prompt($b, $topic, $extra),
                "Равные ключи обязаны давать равный промт для темы «{$topic}»");
        }
    }

    public function test_missing_student_gives_null(): void {
        $this->resetAfterTest();
        $this->assertNull(profile_fingerprint::profile_of(999999, $this->gen()));
    }
}
