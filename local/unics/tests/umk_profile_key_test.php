<?php
namespace local_unics;

/**
 * unics_umk.profile_key: NULL - старый уровневый регламент, заполнен - профильный
 * ([[umk-per-student-design]], раздел 7). Отдельного флага регламента нет.
 *
 * @package local_unics
 */
final class umk_profile_key_test extends \advanced_testcase {

    public function test_profile_key_column_exists_and_defaults_to_null(): void {
        global $DB;
        $this->resetAfterTest();

        $id = (int)$DB->insert_record('unics_umk', (object)[
            'difficulty_level' => 2,
            'title'            => 'Старый УМК',
            'status'           => 3,
        ]);
        $this->assertNull($DB->get_field('unics_umk', 'profile_key', ['id' => $id]));

        $DB->set_field('unics_umk', 'profile_key', str_repeat('a', 40), ['id' => $id]);
        $this->assertSame(str_repeat('a', 40),
            $DB->get_field('unics_umk', 'profile_key', ['id' => $id]));
    }
}
