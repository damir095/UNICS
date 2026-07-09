<?php
namespace local_unics;

/**
 * Тесты dual-write нормализованных таблиц категорий/ОВЗ (этап 2.6, инкремент A).
 * sync_student_taxonomies - единственная точка записи junction-таблиц;
 * create_user/update_user зовут её после записи CSV-полей.
 *
 * @package local_unics
 */
final class user_manager_taxonomy_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
    }

    /** Значения junction-таблицы для ученика, отсортированные. */
    private function junction(string $table, int $sid, string $col): array {
        global $DB;
        $vals = array_map('intval', $DB->get_fieldset_select($table, $col, 'student_id = ?', [$sid]));
        sort($vals);
        return $vals;
    }

    public function test_sync_writes_and_replaces(): void {
        global $DB;
        $u = $this->getDataGenerator()->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $u->id, 'category' => '1,3', 'ovz_type' => '4',
        ]);

        \unics_user_manager::sync_student_taxonomies($sid, '1,3', '4');
        $this->assertSame([1, 3], $this->junction('unics_student_category', $sid, 'category'));
        $this->assertSame([4], $this->junction('unics_student_ovz', $sid, 'ovz_type'));

        // Повторная синхронизация - полная замена набора, пустой CSV чистит таблицу.
        \unics_user_manager::sync_student_taxonomies($sid, '2', '');
        $this->assertSame([2], $this->junction('unics_student_category', $sid, 'category'));
        $this->assertSame([], $this->junction('unics_student_ovz', $sid, 'ovz_type'));

        // Дубли/пробелы в CSV не рождают лишних строк (parse_csv дедуплицирует),
        // null допустим (колонка ovz_type nullable).
        \unics_user_manager::sync_student_taxonomies($sid, '4,4, 1', null);
        $this->assertSame([1, 4], $this->junction('unics_student_category', $sid, 'category'));
        $this->assertSame([], $this->junction('unics_student_ovz', $sid, 'ovz_type'));
    }

    public function test_sync_isolated_per_student(): void {
        global $DB;
        $mk = function () use ($DB): int {
            $u = $this->getDataGenerator()->create_user();
            return (int)$DB->insert_record('unics_students',
                (object)['mdl_user_id' => $u->id]);
        };
        $a = $mk();
        $b = $mk();
        \unics_user_manager::sync_student_taxonomies($a, '1', '2');
        \unics_user_manager::sync_student_taxonomies($b, '4', '');
        // Пересинхронизация A не задевает B.
        \unics_user_manager::sync_student_taxonomies($a, '', '');
        $this->assertSame([], $this->junction('unics_student_category', $a, 'category'));
        $this->assertSame([4], $this->junction('unics_student_category', $b, 'category'));
    }
}
