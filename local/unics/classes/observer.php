<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Обработчики событий ядра для local_unics.
 */
class observer {

    /**
     * При удалении активности — чистим её метаданные (unics_activity_meta),
     * чтобы не оставлять осиротевших строк (is_final/is_milestone).
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        // activity_meta загружается автозагрузчиком (local_unics\activity_meta).
        activity_meta::delete((int)$event->objectid);
    }

    /**
     * При удалении курса чистим связанные данные. delete_course удаляет модули
     * курса bulk-путём, НЕ вызывая course_module_deleted на каждый — поэтому здесь:
     * (1) удаляем пересдачи курса (есть mdl_course_id); (2) подметаем осиротевшие
     * метаданные активностей (модули курса уже удалены, cmid больше не существует).
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $DB->delete_records('unics_retakes', ['mdl_course_id' => (int)$event->objectid]);
        $DB->delete_records('unics_topic_retries', ['mdl_course_id' => (int)$event->objectid]);
        $DB->execute(
            "DELETE FROM {unics_activity_meta}
              WHERE cmid NOT IN (SELECT id FROM {course_modules})"
        );
    }

    /**
     * Оценка попытки теста. В Moodle 5 итоговая оценка теста вычисляется в
     * process_grade_submission, после чего летит attempt_graded (а не
     * attempt_submitted, тот раньше грейда).
     *
     * Развилка по типу теста:
     *  - итоговый (is_final): провал -> пересдача + уведомление педагогов (B7);
     *  - тест темы (B1-гейт на материалы): провал -> «тема для повторения» +
     *    уведомление учащемуся и педагогам (B2).
     */
    public static function quiz_attempt_graded(\mod_quiz\event\attempt_graded $event): void {
        $cmid   = (int)$event->contextinstanceid; // контекст модуля = cmid
        $userid = (int)$event->relateduserid;     // владелец попытки (учащийся)
        if (!$cmid || !$userid) {
            return;
        }
        if (activity_meta::is_final($cmid)) {
            retake_manager::evaluate_cm_for_user($cmid, $userid); // B7
            return;
        }
        // B2: не итоговый. evaluate_cm_for_user сам проверит, что это тест темы
        // (B1-гейт), и тихо выйдет для прочих тестов.
        topic_retry_manager::evaluate_cm_for_user($cmid, $userid);
    }
}
