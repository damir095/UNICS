<?php
namespace local_unics;

use local_unics\learning\item_pool;

/**
 * Отбор заданий из общего пула элемента кодификатора.
 *
 * Пул нужен затем, чтобы ОДНО задание видели многие ученики: IRT оценивает параметры задания по
 * ответам многих на одно, а генерация «каждому свои пять вопросов» давала по одному ответу на
 * задание и делала калибровку невозможной ([[umk-item-pool-design]]).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_pool::class)]
final class item_pool_test extends \advanced_testcase {

    /** Элемент кодификатора, к которому привязываем задания. */
    private function make_element(): int {
        global $DB, $USER;
        $catid = $DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'тестовый', 'created_by_mdl_user_id' => (int)$USER->id,
            'timecreated' => time(),
        ]);
        return (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $catid, 'parent_id' => null, 'code' => '1',
            'title' => 'Тема', 'ordinal' => 0, 'path' => '/1/', 'timecreated' => time(),
        ]);
    }

    /**
     * Задание в банке, привязанное к элементу.
     *
     * Возвращает [questionbankentryid, questionid]: они разные, и это принципиально -
     * ответы лежат на questionid (версия), а привязка на questionbankentryid.
     */
    private function make_item(int $element_id, ?int $level = null): array {
        global $DB, $USER;
        $catid = (int)$DB->insert_record('question_categories', (object)[
            'name' => 'тест', 'contextid' => \context_system::instance()->id, 'info' => '',
            'infoformat' => FORMAT_HTML, 'stamp' => make_unique_id_code(), 'parent' => 0,
            'sortorder' => 0,
        ]);
        $qbeid = (int)$DB->insert_record('question_bank_entries', (object)[
            'questioncategoryid' => $catid, 'ownerid' => null,
        ]);
        $qid = (int)$DB->insert_record('question', (object)[
            'category' => $catid, 'parent' => 0, 'name' => 'в', 'questiontext' => 'в',
            'questiontextformat' => FORMAT_HTML, 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'defaultmark' => 1, 'penalty' => 0,
            'qtype' => 'multichoice', 'length' => 1, 'stamp' => make_unique_id_code(),
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => 0, 'modifiedby' => 0,
        ]);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $qbeid, 'version' => 1, 'questionid' => $qid,
            'status' => 'ready',
        ]);
        \local_unics\codifier_link_manager::link_question($element_id, $qbeid, (int)$USER->id);
        if ($level !== null) {
            item_pool::remember_level($qbeid, $level, (int)$USER->id);
        }
        return [$qbeid, $qid];
    }

    /** Своя попытка использования на каждый ответ: у question_attempts уникальны (usage, slot). */
    private int $usage = 0;

    /** Ответ ученика на задание: именно он делает калибровку возможной. */
    private function answer(int $questionid, int $times = 1): void {
        global $DB;
        for ($i = 0; $i < $times; $i++) {
            $this->usage++;
            $DB->insert_record('question_attempts', (object)[
                'questionusageid' => $this->usage, 'slot' => 1, 'behaviour' => 'deferredfeedback',
                'questionid' => $questionid, 'variant' => 1, 'maxmark' => 1,
                'minfraction' => 0, 'maxfraction' => 1, 'flagged' => 0,
                'questionsummary' => '', 'rightanswer' => '', 'responsesummary' => '',
                'timemodified' => time(),
            ]);
        }
    }

    public function test_empty_pool_reports_everything_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();

        $r = item_pool::take($element, 2, 5);

        $this->assertSame([], $r['ids']);
        $this->assertSame(5, $r['missing']);
    }

    public function test_partial_pool_reports_the_shortfall(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $this->make_item($element, 2);
        $this->make_item($element, 2);

        $r = item_pool::take($element, 2, 5);

        $this->assertCount(2, $r['ids']);
        $this->assertSame(3, $r['missing']);
    }

    /** Ради этого все и затевалось: два подряд запроса дают ОДНИ И ТЕ ЖЕ задания. */
    public function test_two_takes_return_the_same_items(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        for ($i = 0; $i < 5; $i++) {
            $this->make_item($element, 2);
        }

        $first  = item_pool::take($element, 2, 5)['ids'];
        $second = item_pool::take($element, 2, 5)['ids'];

        sort($first);
        sort($second);
        $this->assertSame($first, $second);
    }

    /** Реже отвеченные идут первыми: иначе задания сверх пятерки не откалибруются никогда. */
    public function test_least_answered_go_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$busy, $busyqid] = $this->make_item($element, 2);
        [$fresh, ] = $this->make_item($element, 2);
        $this->answer($busyqid, 4);

        $ids = item_pool::take($element, 2, 1)['ids'];

        $this->assertSame([$fresh], $ids, 'первым обязан идти тот, у кого ответов меньше');
        $this->assertSame(4, item_pool::answers_count($busy));
        $this->assertSame(0, item_pool::answers_count($fresh));
    }

    /** Удаленное из банка задание не должно попасть в слот: это прямой урок 249 сирот. */
    public function test_deleted_bank_entry_is_filtered_out(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$gone, ] = $this->make_item($element, 2);
        $this->make_item($element, 2);
        $DB->delete_records('question_bank_entries', ['id' => $gone]);

        $r = item_pool::take($element, 2, 5);

        $this->assertNotContains($gone, $r['ids']);
        $this->assertCount(1, $r['ids']);
        $this->assertSame(4, $r['missing']);
    }

    /** Задание с чужим уровнем в выдачу по своему уровню не идет. */
    public function test_other_level_is_not_taken(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        $this->make_item($element, 3);

        $r = item_pool::take($element, 1, 5);

        $this->assertSame([], $r['ids']);
        $this->assertSame(5, $r['missing']);
    }

    /** Старые ручные привязки без уровня годятся, но берутся последними. */
    public function test_items_without_level_come_last_but_are_used(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        [$nolevel, ] = $this->make_item($element, null);
        [$exact, ]   = $this->make_item($element, 2);

        $ids = item_pool::take($element, 2, 2)['ids'];

        $this->assertSame([$exact, $nolevel], $ids);
    }

    /** Появилась калибровка - трудность берется из нее, а не из заявленного уровня. */
    public function test_calibrated_b_wins_over_declared_level(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $element = $this->make_element();
        // Заявлен уровень 3, но измеренная трудность - как у базового.
        [$measured, ] = $this->make_item($element, 3);
        $DB->insert_record('unics_item_irt', (object)[
            'item_ref' => $measured, 'element_id' => $element, 'model' => 'rasch',
            'a' => 1, 'b' => -1.0, 'c' => 0, 'calibrated_n' => 30, 'updated_at' => time(),
        ]);

        $ids = item_pool::take($element, 1, 5)['ids'];

        $this->assertSame([$measured], $ids,
            'откалиброванное задание судится по b, заявленный уровень уходит в запас');
    }
}
