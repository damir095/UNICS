<?php
namespace local_unics;

use local_unics\social\notification_manager;

/**
 * Тесты уведомления учащемуся о новом (вручную добавленном) задании (этап 6.1
 * роадмапа, [[new-assignment-notification-design]]). Покрывает фильтры триггера
 * (видимость, тип модуля) и аудиторию (активные учащиеся курса).
 *
 * @package local_unics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(notification_manager::class)]
final class notification_new_assign_test extends \advanced_testcase {

    /** Курс + один активный учащийся, записанный на курс. */
    private function course_with_student(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, 'student');
        $DB->insert_record('unics_students', (object)[
            'mdl_user_id'      => $user->id,
            'difficulty_level' => 2,
        ]);
        return [$course, $user];
    }

    private function notif_count(int $mdl_user_id): int {
        global $DB;
        return $DB->count_records('unics_notifications', [
            'mdl_user_id' => $mdl_user_id,
            'notif_type'  => notification_manager::TYPE_NEW_ASSIGN,
        ]);
    }

    public function test_visible_quiz_notifies_enrolled_student(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$course, $user] = $this->course_with_student();

        $quiz = $this->getDataGenerator()->create_module('quiz',
            ['course' => $course->id, 'name' => 'Тест по теме']);

        $this->assertSame(0, $this->notif_count((int)$user->id));
        notification_manager::notify_new_assign_for_module((int)$quiz->cmid);
        $this->assertSame(1, $this->notif_count((int)$user->id));

        $row = $GLOBALS['DB']->get_record('unics_notifications',
            ['mdl_user_id' => $user->id, 'notif_type' => notification_manager::TYPE_NEW_ASSIGN]);
        $this->assertStringContainsString('Тест по теме', $row->subject);
    }

    public function test_visible_assign_notifies_enrolled_student(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$course, $user] = $this->course_with_student();

        $assign = $this->getDataGenerator()->create_module('assign',
            ['course' => $course->id, 'name' => 'Домашняя работа']);

        notification_manager::notify_new_assign_for_module((int)$assign->cmid);
        $this->assertSame(1, $this->notif_count((int)$user->id));
    }

    public function test_hidden_module_does_not_notify(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$course, $user] = $this->course_with_student();

        $quiz = $this->getDataGenerator()->create_module('quiz',
            ['course' => $course->id, 'visible' => 0]);

        notification_manager::notify_new_assign_for_module((int)$quiz->cmid);
        $this->assertSame(0, $this->notif_count((int)$user->id));
    }

    public function test_other_module_type_does_not_notify(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$course, $user] = $this->course_with_student();

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        notification_manager::notify_new_assign_for_module((int)$page->cmid);
        $this->assertSame(0, $this->notif_count((int)$user->id));
    }

    public function test_non_student_enrolled_user_not_notified(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        // Педагог записан на курс, но НЕ строка в unics_students.
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        $quiz = $gen->create_module('quiz', ['course' => $course->id]);
        notification_manager::notify_new_assign_for_module((int)$quiz->cmid);

        $this->assertSame(0, $this->notif_count((int)$teacher->id));
    }

    public function test_archived_student_not_notified(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$course, $user] = $this->course_with_student();
        $DB->set_field('unics_students', 'archived_at', time(), ['mdl_user_id' => $user->id]);

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        notification_manager::notify_new_assign_for_module((int)$quiz->cmid);

        $this->assertSame(0, $this->notif_count((int)$user->id));
    }

    public function test_unenrolled_student_not_notified(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        // Учащийся существует, но НЕ записан на этот курс.
        $user = $gen->create_user();
        $DB->insert_record('unics_students', (object)[
            'mdl_user_id' => $user->id, 'difficulty_level' => 2,
        ]);

        $quiz = $gen->create_module('quiz', ['course' => $course->id]);
        notification_manager::notify_new_assign_for_module((int)$quiz->cmid);

        $this->assertSame(0, $this->notif_count((int)$user->id));
    }

    /**
     * Сквозная проверка: обычное создание видимого quiz через генератор Moodle
     * (тот же путь, что и добавление активности педагогом через форму) само
     * триггерит core-событие course_module_created -> observer::course_module_created,
     * который ставит adhoc-задачу send_new_assign_notification в очередь. Само
     * создание модуля НЕ должно уведомлять синхронно (см. дизайн-уточнение выше) -
     * уведомление появляется только после явного выполнения поставленной задачи.
     */
    public function test_creating_visible_quiz_via_generator_queues_and_delivers_notification(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$course, $user] = $this->course_with_student();

        $this->getDataGenerator()->create_module('quiz',
            ['course' => $course->id, 'name' => 'Автособытие']);

        // Создание модуля - чистая back-end операция: уведомление еще НЕ отправлено,
        // задача только поставлена в очередь.
        $this->assertSame(0, $this->notif_count((int)$user->id));

        $task = \core\task\manager::get_next_adhoc_task(time());
        $this->assertInstanceOf(\local_unics\task\send_new_assign_notification::class, $task);
        $task->execute();
        \core\task\manager::adhoc_task_complete($task);

        $this->assertSame(1, $this->notif_count((int)$user->id));
    }
}
