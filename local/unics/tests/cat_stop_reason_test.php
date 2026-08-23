<?php
namespace local_unics;

use local_unics\adaptive\estimate_precision;

/**
 * Причина остановки проверки хранится, а не выводится сравнением ([[cat-honest-precision]]).
 *
 * Сервис возвращает reason (se_reached / max_items / bank_exhausted), и он доезжал до PHP, но
 * терялся. Признак «оценка предварительная» считался сравнением с ТЕКУЩЕЙ настройкой, поэтому
 * смена порога задним числом переклассифицировала прошлые проверки.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(estimate_precision::class)]
final class cat_stop_reason_test extends \advanced_testcase {

    /** Завершенная сессия CAT с заданной причиной остановки. */
    private function session(?string $reason, ?float $threshold, float $se): object {
        global $DB;
        $id = (int)$DB->insert_record('unics_cat_session', (object)[
            'student_id' => 1, 'element_id' => 1, 'qubaid' => null,
            'status' => \local_unics\learning\cat_session_manager::STATUS_FINISHED,
            'theta' => 0.5, 'theta_se' => $se, 'items_administered' => 4,
            'stop_reason' => $reason, 'se_threshold' => $threshold,
            'started_at' => time(), 'finished_at' => time(),
        ]);
        return $DB->get_record('unics_cat_session', ['id' => $id]);
    }

    public function test_precision_reached_is_final(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');
        // se ХУЖЕ нынешнего порога, но сервис сказал «точность достигнута» - при том пороге,
        // что действовал тогда. Верим сохраненному.
        $s = $this->session('se_reached', 0.9, 0.85);

        $this->assertFalse(estimate_precision::session_is_provisional($s));
    }

    public function test_bank_exhausted_is_provisional(): void {
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.9, 'local_unics');
        // se ЛУЧШЕ нынешнего порога, но проверка оборвалась на исчерпании банка.
        $s = $this->session('bank_exhausted', 0.3, 0.5);

        $this->assertTrue(estimate_precision::session_is_provisional($s));
    }

    public function test_item_limit_is_provisional(): void {
        // Лимит вопросов - тоже «не дошли до точности», как бы ни выглядела полоса.
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.9, 'local_unics');
        $s = $this->session('max_items', 0.3, 0.5);

        $this->assertTrue(estimate_precision::session_is_provisional($s));
    }

    public function test_saved_reason_survives_a_setting_change(): void {
        // Главное свойство: вердикт по сохраненной причине не зависит от нынешней настройки.
        $this->resetAfterTest();
        $s = $this->session('se_reached', 0.3, 0.28);

        set_config('cat_se_threshold', 0.05, 'local_unics');
        $this->assertFalse(estimate_precision::session_is_provisional($s),
            'ужесточение порога не переписывает прошлую проверку');

        set_config('cat_se_threshold', 0.9, 'local_unics');
        $s2 = $this->session('bank_exhausted', 0.3, 0.78);
        $this->assertTrue(estimate_precision::session_is_provisional($s2),
            'смягчение порога не превращает оборванную проверку в законченную');
    }

    public function test_old_session_falls_back_to_comparison(): void {
        // Сессии до появления поля: причины нет, судим по-старому.
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');

        $this->assertTrue(estimate_precision::session_is_provisional(
            $this->session(null, null, 0.78)));
        $this->assertFalse(estimate_precision::session_is_provisional(
            $this->session(null, null, 0.2)));
    }

    public function test_unknown_reason_falls_back_to_comparison(): void {
        // Сервис может добавить новую причину: неизвестное значение не должно молча
        // объявлять оценку законченной.
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.3, 'local_unics');

        $this->assertTrue(estimate_precision::session_is_provisional(
            $this->session('zzz_new_reason', 0.3, 0.78)));
    }
    public function test_finish_saves_reason_and_threshold(): void {
        // Причину называет сервис, и она должна ЛЕЧЬ В ЗАПИСЬ: без этого весь остальной
        // разбор опирался бы на пустое поле и вечно шел запасным путем.
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $session = $this->session(null, null, 0.9);

        \local_unics\learning\cat_session_manager::finish(
            $session, 0.42, 0.77, 4, 'bank_exhausted', 0.3);

        $saved = $DB->get_record('unics_cat_session', ['id' => $session->id]);
        $this->assertSame('bank_exhausted', $saved->stop_reason);
        $this->assertEqualsWithDelta(0.3, (float)$saved->se_threshold, 0.0001);
        $this->assertSame((string)\local_unics\learning\cat_session_manager::STATUS_FINISHED,
            (string)$saved->status);
    }

    public function test_finish_without_reason_leaves_field_empty(): void {
        // Сервис может не назвать причину: пустая строка не должна лечь в поле как значение.
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $session = $this->session(null, null, 0.9);

        \local_unics\learning\cat_session_manager::finish($session, 0.42, 0.77, 4);

        $this->assertNull($DB->get_field('unics_cat_session', 'stop_reason',
            ['id' => $session->id]));
    }
    public function test_session_without_error_is_provisional(): void {
        // Ни причины, ни стандартной ошибки: про такую проверку не известно ничего.
        // У записи владения null означает «оценка не из IRT», но у СЕССИИ - «мерить нечем».
        $this->resetAfterTest();
        $s = $this->session(null, null, 0.0);
        $s->theta_se = null;

        $this->assertTrue(estimate_precision::session_is_provisional($s));
    }

    public function test_child_note_matches_the_reason(): void {
        // «Вопросов пока мало» - неправда, когда проверка кончилась НА ЛИМИТЕ вопросов.
        $this->resetAfterTest();
        $exhausted = estimate_precision::child_note_for_session(
            $this->session('bank_exhausted', 0.3, 0.7));
        $limit = estimate_precision::child_note_for_session(
            $this->session('max_items', 0.3, 0.7));

        $this->assertStringContainsString('мало', $exhausted);
        $this->assertStringNotContainsString('мало', $limit);
        $this->assertNotSame($exhausted, $limit);
    }

    public function test_child_note_is_empty_for_a_complete_check(): void {
        $this->resetAfterTest();
        $this->assertSame('', estimate_precision::child_note_for_session(
            $this->session('se_reached', 0.3, 0.2)));
    }

    public function test_staff_note_uses_the_saved_threshold(): void {
        // Подсказка обязана говорить о пороге, действовавшем ТОГДА, иначе она спорит с
        // вердиктом: при мягком нынешнем пороге прежняя версия вовсе пустела.
        $this->resetAfterTest();
        set_config('cat_se_threshold', 0.9, 'local_unics');
        $note = estimate_precision::staff_note_for_session(
            $this->session('bank_exhausted', 0.3, 0.5));

        $this->assertStringContainsString('0,30', $note, 'порог из записи, а не нынешний');
        $this->assertStringContainsString('0,50', $note);
        $this->assertStringContainsString('кончились задания', $note);
    }

    public function test_staff_note_names_the_item_limit(): void {
        $this->resetAfterTest();
        $note = estimate_precision::staff_note_for_session(
            $this->session('max_items', 0.3, 0.5));

        $this->assertStringContainsString('лимит вопросов', $note);
    }
}
