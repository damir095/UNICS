<?php
namespace local_unics;

use local_unics\learning\item_pool;

/**
 * Индикатор готовности элемента к CAT не должен обещать больше, чем измерено.
 *
 * Найдено живым зондом: после калибровки по шести ответам сервис пометил задания моделью «2pl»,
 * отдав дискриминацию a = 1.000 у всех, то есть 2PL выродился в модель Раша. Индикатор при этом
 * показывал методисту «готово к CAT» ([[umk-item-pool-design]]).
 *
 * @package local_unics
 */
final class cat_readiness_test extends \advanced_testcase {

    /** Кодификатор с одним элементом; возвращает [codifier_id, element_id]. */
    private function make_codifier(): array {
        global $DB, $USER;
        $cid = (int)$DB->insert_record('unics_codifier', (object)[
            'mdl_category_id' => 1, 'name' => 'к', 'created_by_mdl_user_id' => (int)$USER->id,
            'timecreated' => time(),
        ]);
        $eid = (int)$DB->insert_record('unics_codifier_element', (object)[
            'codifier_id' => $cid, 'parent_id' => null, 'code' => '1', 'title' => 'Тема',
            'ordinal' => 0, 'path' => '/' . $cid . '/', 'timecreated' => time(),
        ]);
        return [$cid, $eid];
    }

    /** Задание, привязанное к элементу, с заданной калибровкой. */
    private function make_calibrated_item(int $element_id, float $a, int $n): int {
        global $DB, $USER;
        $qcat = (int)$DB->insert_record('question_categories', (object)[
            'name' => 'т', 'contextid' => \context_system::instance()->id, 'info' => '',
            'infoformat' => FORMAT_HTML, 'stamp' => make_unique_id_code(), 'parent' => 0,
            'sortorder' => 0,
        ]);
        $qbe = (int)$DB->insert_record('question_bank_entries', (object)[
            'questioncategoryid' => $qcat, 'ownerid' => null,
        ]);
        $qid = (int)$DB->insert_record('question', (object)[
            'category' => $qcat, 'parent' => 0, 'name' => 'в', 'questiontext' => 'в',
            'questiontextformat' => FORMAT_HTML, 'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML, 'defaultmark' => 1, 'penalty' => 0,
            'qtype' => 'multichoice', 'length' => 1, 'stamp' => make_unique_id_code(),
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => 0, 'modifiedby' => 0,
        ]);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $qbe, 'version' => 1, 'questionid' => $qid, 'status' => 'ready',
        ]);
        codifier_link_manager::link_question($element_id, $qbe, (int)$USER->id);
        $DB->insert_record('unics_item_irt', (object)[
            'item_ref' => $qbe, 'element_id' => $element_id, 'model' => '2pl',
            'a' => $a, 'b' => 0.0, 'c' => 0, 'calibrated_n' => $n, 'updated_at' => time(),
        ]);
        return $qbe;
    }

    /** Дискриминация не оценена (a = 1) - задание к 2PL не готово, как бы ни звалась модель. */
    public function test_flat_discrimination_is_not_counted_as_2pl(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cid, $eid] = $this->make_codifier();
        $this->make_calibrated_item($eid, 1.0, 30);

        $rows = codifier_analytics::element_bank_readiness($cid);

        $this->assertSame(1, (int)$rows[0]->calibrated_n, 'калиброванным задание все же считается');
        $this->assertSame(0, (int)$rows[0]->ready_2pl_n,
            'a = 1 означает, что дискриминация не оценена');
    }

    /** Наблюдений мало - доверия нет, сколько бы ни отличалась дискриминация. */
    public function test_low_observation_count_is_not_counted_as_2pl(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cid, $eid] = $this->make_codifier();
        $this->make_calibrated_item($eid, 1.7, item_pool::MIN_CALIBRATED_N - 1);

        $rows = codifier_analytics::element_bank_readiness($cid);

        $this->assertSame(0, (int)$rows[0]->ready_2pl_n);
    }

    /** Оценена дискриминация и хватает наблюдений - вот это 2PL. */
    public function test_real_2pl_is_counted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cid, $eid] = $this->make_codifier();
        $this->make_calibrated_item($eid, 1.7, item_pool::MIN_CALIBRATED_N);

        $rows = codifier_analytics::element_bank_readiness($cid);

        $this->assertSame(1, (int)$rows[0]->ready_2pl_n);
    }

    /**
     * Калиброванным считается задание с ДОСТОВЕРНОЙ калибровкой, а не с любой строкой параметров.
     *
     * Иначе методист видит «5 калиброванных» и вердикт «готово», хотя пул этим же трудностям уже
     * не верит: мерки достоверности расходились в трех местах (пул, CAT, индикатор).
     */
    public function test_untrusted_calibration_is_not_counted_as_calibrated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$cid, $eid] = $this->make_codifier();
        $this->make_calibrated_item($eid, 1.0, item_irt_manager::MIN_CALIBRATED_N - 1);

        $rows = codifier_analytics::element_bank_readiness($cid);

        $this->assertSame(1, (int)$rows[0]->tagged_n, 'привязка к элементу никуда не девается');
        $this->assertSame(0, (int)$rows[0]->calibrated_n,
            'калибровка по нескольким ответам не считается калибровкой');
    }

    /** Порог достоверности живет в ОДНОМ месте, а не копируется по классам. */
    public function test_threshold_has_single_source(): void {
        $this->resetAfterTest();

        $this->assertSame(item_irt_manager::MIN_CALIBRATED_N, item_pool::MIN_CALIBRATED_N);
    }
}
