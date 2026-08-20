<?php
namespace local_unics;

use local_unics\ai\codifier_proposer;

/**
 * Запись подтвержденного методистом дерева ([[codifier-ai-proposal-design]], разделы 5 и 6).
 *
 * @package local_unics
 */
final class codifier_apply_test extends \advanced_testcase {

    private int $codifier;

    protected function setUp(): void {
        parent::setUp();
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->codifier = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => (int)$course->category, 'name' => 'Математика',
            'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
    }

    private function two_sections(): array {
        return [
            ['code' => '1', 'natural' => '1', 'shifted' => false, 'title' => 'Числа',
             'description' => 'про числа', 'topics' => [
                ['code' => '1.1', 'title' => 'Дроби', 'description' => 'складывает дроби'],
                ['code' => '1.2', 'title' => 'Проценты', 'description' => 'считает проценты'],
             ]],
            ['code' => '2', 'natural' => '2', 'shifted' => false, 'title' => 'Геометрия',
             'description' => 'про фигуры', 'topics' => [
                ['code' => '2.1', 'title' => 'Углы', 'description' => 'измеряет углы'],
             ]],
        ];
    }

    public function test_apply_creates_tree_with_descriptions(): void {
        $n = codifier_proposer::apply($this->codifier, $this->two_sections());
        $this->assertSame(5, $n);

        $tree = codifier_manager::get_tree($this->codifier);
        $this->assertCount(5, $tree);

        $bycode = [];
        foreach ($tree as $e) {
            $bycode[$e->code] = $e;
        }
        $this->assertSame('Дроби', $bycode['1.1']->title);
        $this->assertSame('складывает дроби', $bycode['1.1']->description);
        $this->assertSame((int)$bycode['1']->id, (int)$bycode['1.1']->parent_id,
            'тема обязана висеть на своем разделе');
        $this->assertSame('/' . $bycode['1']->id . '/' . $bycode['1.1']->id . '/', $bycode['1.1']->path);
        $this->assertSame(0, (int)$bycode['1']->depth);
        $this->assertSame(1, (int)$bycode['1.1']->depth);
    }

    public function test_apply_refuses_taken_code(): void {
        global $DB;
        codifier_manager::add_element($this->codifier, null, '1', 'Уже занято');
        try {
            codifier_proposer::apply($this->codifier, $this->two_sections());
            $this->fail('занятый код обязан отклонять весь шаг');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('уже занят', $e->getMessage());
        }
        $this->assertSame(1, $DB->count_records('unics_codifier_element',
            ['codifier_id' => $this->codifier]), 'при отказе не должно остаться ни одной новой строки');
    }

    public function test_apply_refuses_duplicate_inside_batch(): void {
        global $DB;
        $sections = $this->two_sections();
        // Методист поправил код руками и столкнул два раздела. Коды тем правит следом, иначе
        // сработает более ранняя проверка «тема принадлежит своему разделу».
        $sections[1]['code'] = '1';
        $sections[1]['topics'][0]['code'] = '1.9';
        try {
            codifier_proposer::apply($this->codifier, $sections);
            $this->fail('дубликат внутри пачки обязан отклонять весь шаг');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('дважды', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('unics_codifier_element',
            ['codifier_id' => $this->codifier]));
    }

    public function test_apply_skips_rows_with_empty_title(): void {
        $sections = $this->two_sections();
        $sections[0]['topics'][1]['title'] = '';   // методист очистил название темы
        $sections[1]['title'] = '';                 // и целого раздела
        $n = codifier_proposer::apply($this->codifier, $sections);
        $this->assertSame(2, $n, 'раздел без названия уходит вместе со своими темами');
        $codes = codifier_proposer::existing_codes($this->codifier);
        sort($codes);
        $this->assertSame(['1', '1.1'], $codes);
    }

    public function test_apply_refuses_topic_code_that_left_its_section(): void {
        global $DB;
        $sections = $this->two_sections();
        $sections[0]['code'] = '7'; // методист сдвинул раздел, коды тем остались от прежнего
        try {
            codifier_proposer::apply($this->codifier, $sections);
            $this->fail('код темы обязан принадлежать своему разделу');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('не принадлежит разделу', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('unics_codifier_element',
            ['codifier_id' => $this->codifier]));
    }

    public function test_apply_allows_section_code_edited_together_with_topics(): void {
        $sections = $this->two_sections();
        $sections[0]['code'] = '7';
        $sections[0]['topics'][0]['code'] = '7.1';
        $sections[0]['topics'][1]['code'] = '7.2';
        $this->assertSame(5, codifier_proposer::apply($this->codifier, $sections));
        $codes = codifier_proposer::existing_codes($this->codifier);
        sort($codes);
        $this->assertSame(['2', '2.1', '7', '7.1', '7.2'], $codes);
    }

    public function test_existing_titles_feed_the_prompt(): void {
        codifier_manager::add_element($this->codifier, null, '9', 'Части света');
        $this->assertSame(['Части света'], codifier_proposer::existing_titles($this->codifier));
    }
}
