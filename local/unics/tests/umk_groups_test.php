<?php
namespace local_unics;

use local_unics\ai\course_builder;

/**
 * Группы доступа к комплектам ([[umk-per-student-design]], раздел 7). Группа на отпечаток
 * переиспользуется всеми темами курса, поэтому в ее idnumber нет хеша темы - в отличие от
 * старой уровневой группы. Имя нейтральное: ни ФИО, ни диагноза на странице курса.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_builder::class)]
final class umk_groups_test extends \advanced_testcase {

    /**
     * Группа заводится на пару «отпечаток + ТЕМА». Тема в ключе обязательна: без нее состав
     * группы накапливается между запусками, и ученик прошлой темы молча получает доступ к
     * материалу следующей, для которого его не выбирали. Найдено ревью 2026-08-07; старая
     * уровневая группа тему в idnumber несла (course_builder.php, get_or_create_level_group).
     */
    public function test_profile_group_is_per_topic_and_reused_within_topic(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $builder = new course_builder();
        $key1 = str_repeat('a', 40);
        $key2 = str_repeat('b', 40);

        $g1 = $builder->get_or_create_profile_group((int)$course->id, $key1, 'Дроби');
        $g2 = $builder->get_or_create_profile_group((int)$course->id, $key1, 'Дроби');
        $g3 = $builder->get_or_create_profile_group((int)$course->id, $key2, 'Дроби');
        $g4 = $builder->get_or_create_profile_group((int)$course->id, $key1, 'Проценты');

        $this->assertSame($g1, $g2, 'Тот же отпечаток и та же тема - та же группа');
        $this->assertNotSame($g1, $g3, 'Другой отпечаток - другая группа');
        $this->assertNotSame($g1, $g4, 'ДРУГАЯ ТЕМА - другая группа, состав не накапливается');

        // Нумерация идет внутри темы, иначе номера росли бы через весь курс.
        $this->assertSame('Дроби - вариант 1', $DB->get_field('groups', 'name', ['id' => $g1]));
        $this->assertSame('Дроби - вариант 2', $DB->get_field('groups', 'name', ['id' => $g3]));
        $this->assertSame('Проценты - вариант 1', $DB->get_field('groups', 'name', ['id' => $g4]));

        $this->assertSame('umk_fp' . substr($key1, 0, 8) . '_c' . $course->id . '_' . substr(md5('Дроби'), 0, 8),
            $DB->get_field('groups', 'idnumber', ['id' => $g1]));
    }

    public function test_student_group_carries_no_name_on_reuse(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        // Запись на курс обязательна: groups_add_member() молча возвращает false для
        // незаписанного пользователя (group/lib.php, проверка is_enrolled).
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $builder = new course_builder();

        $g1 = $builder->get_or_create_student_group((int)$course->id, (int)$user->id);
        $g2 = $builder->get_or_create_student_group((int)$course->id, (int)$user->id);

        $this->assertSame($g1, $g2, 'Одна персональная группа на пару курс-ученик');
        $this->assertSame('umk_s' . $user->id . '_c' . $course->id,
            $DB->get_field('groups', 'idnumber', ['id' => $g1]));
        $this->assertTrue(groups_is_member($g1, (int)$user->id));
    }
}
