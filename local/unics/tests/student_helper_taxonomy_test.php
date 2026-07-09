<?php
namespace local_unics;

use local_unics\identity\student_helper;

/**
 * Тесты SQL-хелперов нормализованных категорий/ОВЗ (этап 2.6, инкремент B):
 * подзапросы-алиасы восстанавливают CSV-форму, EXISTS-фильтры находят учеников.
 *
 * @package local_unics
 */
final class student_helper_taxonomy_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/local/unics/classes/identity/user_manager.php');
        $this->resetAfterTest();
    }

    /** Ученик с junction-строками (как dual-write из user_manager). */
    private function student(string $catcsv, string $ovzcsv): int {
        global $DB;
        $u = $this->getDataGenerator()->create_user();
        $sid = (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $u->id, 'category' => $catcsv, 'ovz_type' => $ovzcsv ?: null,
        ]);
        \unics_user_manager::sync_student_taxonomies($sid, $catcsv, $ovzcsv);
        return $sid;
    }

    public function test_taxonomy_select_sql_restores_csv_shape(): void {
        global $DB;
        $a = $this->student('1,3', '4');
        $b = $this->student('', '');

        [$catsql, $ovzsql] = student_helper::taxonomy_select_sql('s');
        $rows = $DB->get_records_sql(
            "SELECT s.id, {$catsql}, {$ovzsql} FROM {unics_students} s ORDER BY s.id");

        $this->assertSame('1,3', $rows[$a]->category);
        $this->assertSame('4', $rows[$a]->ovz_type);
        // Нет строк -> NULL; parse_csv трактует как пустой набор (эквивалент '').
        $this->assertNull($rows[$b]->category);
        $this->assertNull($rows[$b]->ovz_type);
        $this->assertSame([], student_helper::parse_csv($rows[$b]->category));

        // Кастомные алиасы (get_user_profile использует student_category).
        [$catsql2, ] = student_helper::taxonomy_select_sql('s', 'student_category');
        $row = $DB->get_record_sql(
            "SELECT s.id, {$catsql2} FROM {unics_students} s WHERE s.id = ?", [$a]);
        $this->assertSame('1,3', $row->student_category);
    }

    public function test_sql_has_filters_use_junction(): void {
        global $DB;
        $a = $this->student('1,3', '4');
        $b = $this->student('2', '');

        [$frag, $params] = student_helper::sql_has_category(1);
        $ids = $DB->get_fieldset_sql("SELECT s.id FROM {unics_students} s WHERE {$frag}", $params);
        $this->assertSame([$a], array_map('intval', $ids));

        [$frag2, $params2] = student_helper::sql_has_category(2);
        $ids2 = $DB->get_fieldset_sql("SELECT s.id FROM {unics_students} s WHERE {$frag2}", $params2);
        $this->assertSame([$b], array_map('intval', $ids2));

        [$frag3, $params3] = student_helper::sql_has_ovz_type(4);
        $ids3 = $DB->get_fieldset_sql("SELECT s.id FROM {unics_students} s WHERE {$frag3}", $params3);
        $this->assertSame([$a], array_map('intval', $ids3));

        [$frag4, $params4] = student_helper::sql_has_ovz_type(6);
        $this->assertSame([],
            $DB->get_fieldset_sql("SELECT s.id FROM {unics_students} s WHERE {$frag4}", $params4));
    }
}
