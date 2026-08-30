<?php
namespace local_unics;

/**
 * Уборка после удаления курса ([[project_status_2026_08_30_orphan_links]]).
 *
 * Живёт в обработчике события, поэтому зелёный сьют сам по себе про неё ничего не доказывает -
 * нужен прямой тест.
 *
 * @package local_unics
 */
final class cleanup_question_links_test extends \advanced_testcase {

    /** Запись банка вопросов; возвращает id. */
    private function make_bankentry(): int {
        global $DB;
        $qcat = (int)$DB->insert_record('question_categories', (object)[
            'name' => 'т', 'contextid' => \context_system::instance()->id, 'info' => '',
            'infoformat' => FORMAT_HTML, 'stamp' => make_unique_id_code(), 'parent' => 0,
            'sortorder' => 0,
        ]);
        return (int)$DB->insert_record('question_bank_entries', (object)[
            'questioncategoryid' => $qcat, 'ownerid' => null,
        ]);
    }

    private function link(int $element, int $target, int $type): int {
        global $DB, $USER;
        return (int)$DB->insert_record('unics_codifier_link', (object)[
            'element_id' => $element, 'target_type' => $type, 'target_id' => $target,
            'weight' => null, 'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
    }

    public function test_orphan_question_links_are_swept(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $cid = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'к',
            'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
        $eid = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $cid, 'parent_id' => null, 'code' => '1', 'title' => 'Тема',
            'ordinal' => 0, 'path' => '/' . $cid . '/', 'timecreated' => time(),
        ]);

        $alive = $this->make_bankentry();
        $dead  = $this->make_bankentry();
        $alivelink = $this->link($eid, $alive, codifier_link_manager::TYPE_QUESTION);
        $deadlink  = $this->link($eid, $dead, codifier_link_manager::TYPE_QUESTION);

        // Вопрос снесён вместе с курсом; привязка осталась.
        $DB->delete_records('question_bank_entries', ['id' => $dead]);

        cleanup::course_deleted((int)$course->id);

        $this->assertFalse($DB->record_exists('unics_codifier_link', ['id' => $deadlink]),
            'привязка к удалённому вопросу обязана уйти');
        $this->assertTrue($DB->record_exists('unics_codifier_link', ['id' => $alivelink]),
            'привязка к живому вопросу обязана остаться');
    }

    public function test_activity_links_are_not_touched_by_the_question_sweep(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cid = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'к',
            'created_by_mdl_user_id' => (int)$USER->id, 'timecreated' => time(),
        ]);
        $eid = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $cid, 'parent_id' => null, 'code' => '1', 'title' => 'Тема',
            'ordinal' => 0, 'path' => '/' . $cid . '/', 'timecreated' => time(),
        ]);

        // Привязка АКТИВНОСТИ, чей target_id совпадает с несуществующей записью банка:
        // подметание вопросов не имеет права её задеть.
        $link = $this->link($eid, (int)$page->cmid, codifier_link_manager::TYPE_ACTIVITY);

        cleanup::course_deleted((int)$course->id);

        $this->assertTrue($DB->record_exists('unics_codifier_link', ['id' => $link]),
            'живая привязка активности не должна попасть под подметание вопросов');
    }
}
