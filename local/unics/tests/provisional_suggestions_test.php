<?php
namespace local_unics;

use local_unics\adaptive\theta_scale;
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
#[\PHPUnit\Framework\Attributes\CoversClass(mastery_manager::class)]
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

    /**
     * Запись владения. По умолчанию - снятая IRT: балл есть проекция theta, иначе предикат
     * справедливо считает оценку не относящейся к CAT.
     */
    private function mastery(int $student, int $element, float $theta = 0.5,
                            ?float $se = 0.8, ?float $score = null): void {
        global $DB;
        $DB->insert_record('unics_skill_mastery', (object)[
            'student_id' => $student, 'element_id' => $element,
            'score' => $score ?? theta_scale::project($theta),
            'band' => 3, 'attempts_n' => 4, 'last_score' => 50,
            'updated_at' => time(), 'theta' => $theta, 'theta_se' => $se,
        ]);
    }

    /** Завершенная сессия CAT с заданной причиной остановки и меткой времени. */
    private function session(int $student, int $element, ?string $reason, float $se,
                             ?int $finished = null): int {
        global $DB;
        return (int)$DB->insert_record('unics_cat_session', (object)[
            'student_id' => $student, 'element_id' => $element, 'qubaid' => null,
            'status' => \local_unics\learning\cat_session_manager::STATUS_FINISHED,
            'theta' => 0.5, 'theta_se' => $se, 'items_administered' => 4,
            'stop_reason' => $reason, 'se_threshold' => 0.3,
            'started_at' => time(), 'finished_at' => $finished ?? time(),
        ]);
    }

    public function test_interrupted_check_marks_element_provisional(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e);
        $this->session($s, $e, 'bank_exhausted', 0.8);

        $this->assertTrue(mastery_manager::element_estimate_is_provisional($s, $e));
    }

    public function test_complete_check_is_not_provisional(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e, 0.5, 0.2);
        $this->session($s, $e, 'se_reached', 0.2);

        $this->assertFalse(mastery_manager::element_estimate_is_provisional($s, $e));
    }

    public function test_score_recomputed_by_quizzes_is_not_provisional(): void {
        // Балл давно пересчитан обычными тестами, а theta осталась от давней оборванной
        // проверки. Без гейта «балл снят IRT» такой элемент блокировал бы продвижение
        // НАВСЕГДА (найдено ревью).
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e, 0.5, 0.8, 91.0);
        $this->session($s, $e, 'bank_exhausted', 0.8);

        $this->assertFalse(mastery_manager::element_estimate_is_provisional($s, $e));
    }

    public function test_element_without_mastery_is_not_provisional(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse(mastery_manager::element_estimate_is_provisional(
            $this->student(), $this->element()));
    }

    public function test_latest_session_decides_on_equal_timestamps(): void {
        // Ребенок прошел тему заново в ту же секунду - на маленьком банке это обычное дело.
        // Метку времени задаем ЯВНО: иначе тест проходил бы и без разрешения ничьих, стоило
        // вставкам разойтись через границу секунды.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e, 0.5, 0.2);
        $moment = time();
        $this->session($s, $e, 'bank_exhausted', 0.8, $moment);
        $this->session($s, $e, 'se_reached', 0.2, $moment);

        $this->assertFalse(mastery_manager::element_estimate_is_provisional($s, $e),
            'решает последняя вставленная проверка');
    }

    public function test_newer_timestamp_outranks_bigger_id(): void {
        // Порядок по времени важнее порядка вставки: строка с БОЛЬШИМ id, но старее по
        // времени, решать не должна.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e);
        $now = time();
        $this->session($s, $e, 'se_reached', 0.2, $now);
        $this->session($s, $e, 'bank_exhausted', 0.8, $now - 600);

        $this->assertFalse(mastery_manager::element_estimate_is_provisional($s, $e));
    }

    public function test_advancement_is_dropped_for_provisional_estimate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e);
        $this->session($s, $e, 'bank_exhausted', 0.8);

        $kept = mastery_manager::drop_unsupported_suggestions($s, [
            ['kind' => suggestion_service::KIND_ADVANCEMENT, 'element_id' => $e,
             'target_level' => 3, 'reason' => 'Навык освоен'],
        ]);

        $this->assertSame([], $kept, 'продвигать по неизмеренному нельзя');
    }

    public function test_remediation_survives_with_a_staff_only_note(): void {
        // Оговорка идет в rationale - его видит педагог; reason уезжает в маршрут ребенка.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e);
        $this->session($s, $e, 'max_items', 0.7);

        $kept = mastery_manager::drop_unsupported_suggestions($s, [
            ['kind' => suggestion_service::KIND_REMEDIATION, 'element_id' => $e,
             'target_level' => 2, 'reason' => 'Пробел по навыку (балл 30%)'],
        ]);

        $this->assertCount(1, $kept);
        $this->assertStringContainsString('предварительная', $kept[0]['rationale']);
        $this->assertStringNotContainsString('предварительная', $kept[0]['reason'],
            'ребенок читает reason - оценочному жаргону там не место');
    }

    public function test_advancement_survives_a_complete_check(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e, 0.5, 0.2);
        $this->session($s, $e, 'se_reached', 0.2);

        $kept = mastery_manager::drop_unsupported_suggestions($s, [
            ['kind' => suggestion_service::KIND_ADVANCEMENT, 'element_id' => $e,
             'target_level' => 3, 'reason' => 'Навык освоен'],
        ]);

        $this->assertCount(1, $kept);
        $this->assertArrayNotHasKey('rationale', $kept[0],
            'законченная проверка не требует оговорок');
    }

    public function test_advancement_without_element_is_dropped(): void {
        // Применить такое предложение все равно нечем, а мимо фильтра оно проходило целиком.
        $this->resetAfterTest();
        $this->setAdminUser();

        $kept = mastery_manager::drop_unsupported_suggestions($this->student(), [
            ['kind' => suggestion_service::KIND_ADVANCEMENT, 'element_id' => null,
             'target_level' => 3, 'reason' => 'общее'],
        ]);

        $this->assertSame([], $kept);
    }

    public function test_remediation_without_element_is_kept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $kept = mastery_manager::drop_unsupported_suggestions($this->student(), [
            ['kind' => suggestion_service::KIND_REMEDIATION, 'element_id' => null,
             'target_level' => 2, 'reason' => 'общее'],
        ]);

        $this->assertCount(1, $kept);
    }
    public function test_pipeline_creates_no_advancement_for_provisional_estimate(): void {
        // Проводка, а не только предикат: без этого теста фильтр можно было выкинуть из
        // рабочего пути, и весь сьют остался бы зеленым - проект уже обжигался так на
        // потерянном element_id.
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_recommender_ml', 0, 'local_unics');
        $s = $this->student();
        $e = $this->element();
        // Полоса «освоено» с оборванной проверкой: правило предложит продвижение.
        $this->mastery($s, $e);
        $this->session($s, $e, 'bank_exhausted', 0.8);

        mastery_manager::regenerate_suggestions($s);

        $this->assertFalse($DB->record_exists('unics_adaptive_suggestion',
            ['student_id' => $s, 'element_id' => $e,
             'kind' => suggestion_service::KIND_ADVANCEMENT]),
            'продвижение по оборванной проверке не должно доехать до предложений');
    }

    public function test_pipeline_creates_advancement_after_a_complete_check(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('adaptive_recommender_ml', 0, 'local_unics');
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e, 0.5, 0.2);
        $this->session($s, $e, 'se_reached', 0.2);

        mastery_manager::regenerate_suggestions($s);

        $this->assertTrue($DB->record_exists('unics_adaptive_suggestion',
            ['student_id' => $s, 'element_id' => $e,
             'kind' => suggestion_service::KIND_ADVANCEMENT]));
    }

    public function test_apply_rechecks_at_the_moment_of_use(): void {
        // Предложение создано, когда оценка была полной; проверку ребенок прошел заново, и она
        // оборвалась. Отложенное автоприменение обходило фильтр на входе (найдено ревью).
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $this->mastery($s, $e);
        $id = (int)suggestion_service::create($s, suggestion_service::KIND_ADVANCEMENT, $e,
            json_encode(['target_level' => 3, 'reason' => 'Навык освоен']));
        $this->session($s, $e, 'bank_exhausted', 0.8);

        $ok = suggestion_service::apply($id, 0, true);

        $this->assertFalse($ok);
        $this->assertSame((string)suggestion_service::STATUS_REJECTED,
            (string)$DB->get_field('unics_adaptive_suggestion', 'status', ['id' => $id]));
        $this->assertFalse($DB->record_exists('unics_path_step', ['element_id' => $e]),
            'шаг маршрута по неизмеренному не создается');
    }
}
