<?php
namespace local_unics;

use local_unics\adaptive\theta_scale;
use local_unics\learning\cat_session_manager;
use local_unics\learning\suggestion_service;
use local_unics\task\refresh_suggestions;

/**
 * Пересчет предложений уехал из запроса ребенка ([[refresh-suggestions-task-design]]).
 *
 * Найдено ревью 2026-08-25. Рекомендатель и рассылка педагогам работали синхронно в том же
 * запросе, в котором ребенок отвечал на последнее задание проверки: при включенном
 * ML-рекомендателе это лишние 5 секунд ожидания (таймаут irt_client) на завершающем шаге, плюс по
 * письму каждому привязанному педагогу. Ребенку эта работа не нужна - он ждет только свой
 * результат.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(refresh_suggestions::class)]
final class refresh_suggestions_task_test extends \advanced_testcase {

    /** theta, дающая полосу «освоено»: project(2.0) = 88.08 при пороге 85. */
    private const THETA_MASTERED = 2.0;

    private function student(): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        return (int)$DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'class_number' => 7, 'difficulty_level' => 2,
        ]);
    }

    private function element(): int {
        global $USER;
        $cat = $this->getDataGenerator()->create_category();
        $cid = codifier_manager::create_codifier((int)$cat->id, 'к', (int)$USER->id);
        return codifier_manager::add_element($cid, null, '1', 'Тема');
    }

    private function open_session(int $student, int $element): object {
        global $DB;
        $id = (int)$DB->insert_record('unics_cat_session', (object)[
            'student_id' => $student, 'element_id' => $element, 'qubaid' => null,
            'status' => cat_session_manager::STATUS_ACTIVE,
            'theta' => 0.0, 'theta_se' => 1.0, 'items_administered' => 0,
            'started_at' => time(),
        ]);
        return $DB->get_record('unics_cat_session', ['id' => $id], '*', MUST_EXIST);
    }

    /** Поставленные задачи пересчета - в порядке очереди. */
    private function queued(): array {
        global $DB;
        return array_values($DB->get_records('task_adhoc',
            ['classname' => '\\' . refresh_suggestions::class], 'id ASC'));
    }

    /** Выполнить все поставленные задачи, как это сделал бы cron. */
    private function run_queued(): void {
        foreach ($this->queued() as $rec) {
            $task = new refresh_suggestions();
            $task->set_custom_data(json_decode($rec->customdata));
            $task->execute();
        }
    }

    private function advancements(int $student, int $element): int {
        global $DB;
        return $DB->count_records('unics_adaptive_suggestion', [
            'student_id' => $student, 'element_id' => $element,
            'kind' => suggestion_service::KIND_ADVANCEMENT,
        ]);
    }

    // ---------------------------------------------------------------
    // Завершение CAT
    // ---------------------------------------------------------------

    public function test_cat_finish_queues_the_task_instead_of_running_it(): void {
        // Главная проверка: в запросе ребенка предложений НЕ появляется, появляется задача.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);

        $this->assertSame(0, $this->advancements($s, $e),
            'рекомендатель не должен работать в запросе ребенка');
        $queued = $this->queued();
        $this->assertCount(1, $queued, 'задача пересчета обязана быть поставлена');
        $this->assertSame($s, (int)json_decode($queued[0]->customdata)->student_id);
    }

    public function test_task_creates_the_advancement(): void {
        // Отложенная работа доводит дело до конца - результат тот же, что был синхронным.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();

        $this->assertSame(1, $this->advancements($s, $e));
    }

    public function test_mastery_is_written_synchronously(): void {
        // Владение ребенок видит сразу: отложить можно предложения педагогу, но не результат
        // проверки.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);

        $m = \local_unics\learning\mastery_manager::current_mastery($s, $e);
        $this->assertNotNull($m, 'владение обязано быть записано в том же запросе');
        $this->assertEqualsWithDelta(theta_scale::project(self::THETA_MASTERED),
            (float)$m->score, 0.01);
    }

    public function test_interrupted_check_still_creates_nothing(): void {
        // Правило честности переехало вместе с работой: по оборванной проверке продвижение
        // не предлагается и после выполнения задачи.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.9, 5,
            \local_unics\adaptive\estimate_precision::REASON_BANK_EXHAUSTED, 0.3);
        $this->run_queued();

        $this->assertSame(0, $this->advancements($s, $e));
    }

    public function test_repeated_runs_do_not_duplicate(): void {
        // Задача идемпотентна: повтор после сбоя не должен добавлять педагогу вторую карточку.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();
        $this->run_queued();

        $this->assertSame(1, $this->advancements($s, $e));
    }

    public function test_task_without_student_id_does_nothing(): void {
        // Битые данные задачи не должны валить cron: он выполняет ее вперемешку с чужими.
        $this->resetAfterTest();
        $this->setAdminUser();

        $task = new refresh_suggestions();
        $task->set_custom_data([]);

        // Задача пишет через mtrace: без перехвата PHPUnit метит тест рискованным.
        ob_start();
        $task->execute();
        $out = ob_get_clean();

        $this->assertStringContainsString('без student_id', $out,
            'пропуск должен оставлять след в логе задачи, а не проходить молча');
    }

    // ---------------------------------------------------------------
    // Попытка обычного теста
    // ---------------------------------------------------------------

    public function test_attempt_path_also_queues_the_task(): void {
        // Тот же случай: ребенок отправил тест, и в ЕГО запросе крутился рекомендатель со
        // всеми письмами педагогам.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        global $DB;
        $DB->insert_record('unics_skill_mastery', (object)[
            'student_id' => $s, 'element_id' => $e, 'score' => 90.0, 'band' => 3,
            'attempts_n' => 3, 'last_score' => 90.0, 'updated_at' => time(),
        ]);

        \local_unics\learning\mastery_manager::regenerate_suggestions_later($s);

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $this->assertSame($s, (int)json_decode($queued[0]->customdata)->student_id);
        $this->assertSame(0, $this->advancements($s, $e), 'синхронно ничего не создается');
    }
}
