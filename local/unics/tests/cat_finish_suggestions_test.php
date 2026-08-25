<?php
namespace local_unics;

use local_unics\adaptive\theta_scale;
use local_unics\learning\cat_session_manager;
use local_unics\learning\mastery_manager;
use local_unics\learning\suggestion_service;

/**
 * После CAT-проверки предложения пересчитываются ([[cat-finish-suggestions-design]]).
 *
 * Найдено ревью 2026-08-24. Фильтр честности снимает предложение о продвижении, пока оценка по
 * элементу предварительная ([[provisional-suggestions]]). Вернуть его должна очередная проверка,
 * доведенная до точности, - но рекомендатель запускался ТОЛЬКО из mastery_manager::on_attempt(),
 * то есть по попытке обычного теста Moodle. Завершение CAT-сессии его не запускало, ночная
 * задача тоже.
 *
 * Получалось наоборот: именно та проверка, которая обязана разблокировать продвижение, его и не
 * разблокировала. Ребенок оставался ниже своего уровня до ближайшего обычного теста по этому же
 * элементу.
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cat_session_manager::class)]
final class cat_finish_suggestions_test extends \advanced_testcase {

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

    /** Активная сессия CAT - та, которую предстоит завершить. */
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

    /**
     * Выполнить поставленные задачи пересчета, как это сделал бы cron.
     *
     * С 2026-08-25 finish() не считает предложения сам, а ставит задачу
     * ([[refresh-suggestions-task-design]]): рекомендатель ходит в сеть и шлет письма
     * педагогам, а ребенок в этот момент ждет свой результат.
     */
    private function run_queued(): void {
        global $DB;
        $cls = '\\' . \local_unics\task\refresh_suggestions::class;
        foreach ($DB->get_records('task_adhoc', ['classname' => $cls], 'id ASC') as $rec) {
            $task = new \local_unics\task\refresh_suggestions();
            $task->set_custom_data(json_decode($rec->customdata));
            ob_start();
            $task->execute();
            ob_end_clean();
        }
    }

    private function advancements(int $student, int $element): int {
        global $DB;
        return $DB->count_records('unics_adaptive_suggestion', [
            'student_id' => $student, 'element_id' => $element,
            'kind' => suggestion_service::KIND_ADVANCEMENT,
        ]);
    }

    public function test_precise_check_creates_the_advancement(): void {
        // Главная проверка задачи. До правки предложение не появлялось вовсе: finish() писал
        // владение и на этом останавливался.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $session = $this->open_session($s, $e);

        cat_session_manager::finish($session, self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();

        $this->assertSame(1, $this->advancements($s, $e),
            'проверка, доведенная до точности, обязана вернуть продвижение');
    }

    public function test_interrupted_check_creates_nothing(): void {
        // Обратная сторона: правило честности должно остаться в силе. Оборванная проверка
        // владение пишет, но продвижение по неизмеренному предлагать нельзя.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();
        $session = $this->open_session($s, $e);

        cat_session_manager::finish($session, self::THETA_MASTERED, 0.9, 5,
            \local_unics\adaptive\estimate_precision::REASON_BANK_EXHAUSTED, 0.3);
        $this->run_queued();

        $this->assertSame(0, $this->advancements($s, $e),
            'по оборванной проверке продвижение предлагать нельзя');
    }

    public function test_second_precise_check_does_not_duplicate(): void {
        // Дедуп живет в suggestion_service::create (has_open). Без него каждая пройденная
        // проверка добавляла бы педагогу еще одну карточку и еще одно уведомление.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();
        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 6,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();

        $this->assertSame(1, $this->advancements($s, $e), 'дублей быть не должно');
    }

    public function test_interrupted_then_precise_returns_the_advancement(): void {
        // Весь сценарий целиком, ради которого задача и делалась: сперва проверка обрывается
        // и продвижение снимается, потом ребенок доводит тему до точности - и предложение
        // появляется, не дожидаясь обычного теста по этому элементу.
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.9, 4,
            \local_unics\adaptive\estimate_precision::REASON_BANK_EXHAUSTED, 0.3);
        $this->run_queued();
        $this->assertSame(0, $this->advancements($s, $e), 'предпосылка: продвижение снято');

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 7,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();

        $this->assertSame(1, $this->advancements($s, $e),
            'доведенная до точности проверка обязана вернуть снятое продвижение');
    }

    public function test_mastery_is_still_recorded(): void {
        // Правка добавляет шаги ПОСЛЕ записи владения и не должна ее задеть.
        //
        // Здесь же страхуется и вызов глобального гейта уровня: он обернут в catch, и первая
        // версия правки звала класс с неверным неймспейсом - ошибка была съедена молча. Ловит
        // это сам PHPUnit: advanced_testcase считает неожиданный debugging() провалом, поэтому
        // сломанный вызов роняет КАЖДЫЙ тест этого файла (проверено мутацией).
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), self::THETA_MASTERED, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();

        $m = mastery_manager::current_mastery($s, $e);
        $this->assertNotNull($m);
        $this->assertEqualsWithDelta(theta_scale::project(self::THETA_MASTERED),
            (float)$m->score, 0.01);
    }

    public function test_low_score_creates_remediation_not_advancement(): void {
        // Точная проверка с низким баллом - это пробел, а не продвижение. Раньше тест проверял
        // только вторую половину («не продвижение»), и предложение о повторении, которое он на
        // самом деле создает, оставалось непроверенным: мутация, убирающая ветку remediation
        // из рекомендателя, оставляла тест зеленым (найдено ревью 2026-08-25).
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $s = $this->student();
        $e = $this->element();

        cat_session_manager::finish($this->open_session($s, $e), -2.0, 0.2, 5,
            \local_unics\adaptive\estimate_precision::REASON_PRECISION, 0.3);
        $this->run_queued();

        $this->assertSame(0, $this->advancements($s, $e), 'продвижения по пробелу быть не может');
        $this->assertSame(1, $DB->count_records('unics_adaptive_suggestion', [
            'student_id' => $s, 'element_id' => $e,
            'kind' => suggestion_service::KIND_REMEDIATION,
        ]), 'пробел обязан дать предложение о повторении');
    }
}
