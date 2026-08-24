<?php
namespace local_unics;

use local_unics\adaptive\estimate_precision;
use local_unics\learning\mastery_manager;
use local_unics\learning\suggestion_service;

/**
 * Маршрут не строится по неизмеренному ([[provisional-suggestions]]).
 *
 * Честность оценки была доведена до экранов, но не до потребителя: рекомендатель предлагал
 * «навык освоен, можно продвигаться» по полосе, снятой в ОБОРВАННОЙ проверке. Лишнее повторение
 * ребенку безвредно, а продвижение по неизмеренному - нет, поэтому асимметрия намеренная.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(estimate_precision::class)]
final class provisional_suggestions_test extends \advanced_testcase {

    /** Ученик УНИКС. */
    private function student(): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        return (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'class_number' => 7, 'difficulty_level' => 2,
        ]);
    }

    /** Элемент кодификатора. */
    private function element(): int {
        global $USER;
        $cat = $this->getDataGenerator()->create_category();
        $cid = codifier_manager::create_codifier((int)$cat->id, 'к', (int)$USER->id);
        return codifier_manager::add_element($cid, null, '1', 'Тема');
    }

    /** Завершенная сессия CAT с заданной причиной остановки. */
    private function session(int $student, int $element, ?string $reason, float $se): void {
        global $DB;
        $DB->insert_record('unics_cat_session', (object)[
            'student_id' => $student, 'element_id' => $element, 'qubaid' => null,
            'status' => \local_unics\learning\cat_session_manager::STATUS_FINISHED,
            'theta' => 0.5, 'theta_se' => $se, 'items_administered' => 4,
            'stop_reason' => $reason, 'se_threshold' => 0.3,
            'started_at' => time(), 'finished_at' => time(),
        ]);
    }

    public function test_interrupted_check_marks_element_provisional(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->session($s, $e, 'bank_exhausted', 0.8);

        $this->assertTrue(estimate_precision::is_element_provisional($s, $e));
    }

    public function test_complete_check_is_not_provisional(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->session($s, $e, 'se_reached', 0.2);

        $this->assertFalse(estimate_precision::is_element_provisional($s, $e));
    }

    public function test_element_without_cat_is_not_provisional(): void {
        // Оценка получена обычным путем: о точности говорить нечего, и запрещать
        // продвижение не за что.
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(estimate_precision::is_element_provisional(
            $this->student(), $this->element()));
    }

    public function test_latest_session_decides(): void {
        // Ребенок прошел тему заново: решает ПОСЛЕДНЯЯ проверка, а не первая.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->session($s, $e, 'bank_exhausted', 0.8);
        $this->session($s, $e, 'se_reached', 0.2);

        $this->assertFalse(estimate_precision::is_element_provisional($s, $e));
    }

    public function test_advancement_is_dropped_for_provisional_estimate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->session($s, $e, 'bank_exhausted', 0.8);

        $kept = mastery_manager::drop_unsupported_suggestions($s, [
            ['kind' => suggestion_service::KIND_ADVANCEMENT, 'element_id' => $e,
             'target_level' => 3, 'reason' => 'Навык освоен'],
        ]);

        $this->assertSame([], $kept, 'продвигать по неизмеренному нельзя');
    }

    public function test_remediation_survives_a_provisional_estimate(): void {
        // Лишнее повторение безвредно - его оставляем, но с пометкой для педагога.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->session($s, $e, 'max_items', 0.7);

        $kept = mastery_manager::drop_unsupported_suggestions($s, [
            ['kind' => suggestion_service::KIND_REMEDIATION, 'element_id' => $e,
             'target_level' => 2, 'reason' => 'Пробел по навыку (балл 30%)'],
        ]);

        $this->assertCount(1, $kept);
        $this->assertStringContainsString('предварительная', $kept[0]['reason']);
    }

    public function test_advancement_survives_a_complete_check(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->session($s, $e, 'se_reached', 0.2);

        $kept = mastery_manager::drop_unsupported_suggestions($s, [
            ['kind' => suggestion_service::KIND_ADVANCEMENT, 'element_id' => $e,
             'target_level' => 3, 'reason' => 'Навык освоен'],
        ]);

        $this->assertCount(1, $kept);
        $this->assertStringNotContainsString('предварительная', $kept[0]['reason'],
            'законченная проверка не требует оговорок');
    }

    public function test_suggestion_without_element_is_untouched(): void {
        // Предложения без привязки к элементу (общие) проверять не по чему.
        $this->resetAfterTest();
        $this->setAdminUser();

        $kept = mastery_manager::drop_unsupported_suggestions($this->student(), [
            ['kind' => suggestion_service::KIND_ADVANCEMENT, 'element_id' => null,
             'target_level' => 3, 'reason' => 'общее'],
        ]);

        $this->assertCount(1, $kept);
    }
}
