<?php
namespace local_unics;

use local_unics\learning\cat_session_manager;

/**
 * Адаптивная проверка не должна вести ребенка по недостоверным трудностям.
 *
 * Найдено живым заходом 2026-08-17: CAT выдал ученице задание с b = -3.892, полученной по шести
 * ответам. Пул такое задание отфильтровал бы порогом достоверности, а `cat_session_manager::bank()`
 * брал все строки `unics_item_irt` подряд. На недостоверных параметрах адаптация теряет смысл:
 * задание выбирается под оценку, которой нет.
 *
 * @package local_unics
 */
final class cat_bank_trust_test extends \advanced_testcase {

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

    /** Задание, привязанное к элементу, с калибровкой на указанном числе наблюдений. */
    private function make_item(int $element_id, float $b, int $n): int {
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
            'item_ref' => $qbe, 'element_id' => $element_id, 'model' => 'rasch',
            'a' => 1, 'b' => $b, 'c' => 0, 'calibrated_n' => $n, 'updated_at' => time(),
        ]);
        return $qbe;
    }

    /** Тема с недостоверными трудностями ребенку не предлагается вовсе. */
    public function test_element_with_untrusted_items_is_not_offered(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $eid] = $this->make_codifier();
        $this->make_item($eid, -3.892, item_irt_manager::MIN_CALIBRATED_N - 1);

        $els = cat_session_manager::eligible_elements();

        $ids = array_column($els, 'element_id');
        $this->assertNotContains($eid, $ids,
            'адаптивная проверка на калибровке по нескольким ответам не имеет смысла');
    }

    /** Достоверные задания тему открывают, и счетчик показывает именно их. */
    public function test_element_with_trusted_items_is_offered(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $eid] = $this->make_codifier();
        $this->make_item($eid, 0.0, item_irt_manager::MIN_CALIBRATED_N);
        $this->make_item($eid, 0.5, item_irt_manager::MIN_CALIBRATED_N + 5);
        // Третье задание недостоверно: в счетчик темы попасть не должно.
        $this->make_item($eid, -3.892, 1);

        $els = cat_session_manager::eligible_elements();

        $found = null;
        foreach ($els as $e) {
            if ((int)$e['element_id'] === $eid) {
                $found = $e;
            }
        }
        $this->assertNotNull($found, 'тема с достоверными заданиями обязана предлагаться');
        $this->assertSame(2, (int)$found['n'], 'счетчик считает только достоверные задания');
    }
}
