<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Обработчики событий ядра для local_unics.
 */
class observer {

    /**
     * При удалении активности — каскадная зачистка cm-привязанных данных
     * (метаданные, привязки кодификатора, материалы УМК, пересдачи/возвраты).
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        cleanup::course_module_deleted((int)$event->objectid);
    }

    /**
     * При удалении курса — каскадная зачистка course-привязанных данных
     * (цепочка УМК, статистика, отчеты, делегирование, шаги маршрутов) и
     * орфан-свипы cm-таблиц: delete_course удаляет модули bulk-путём,
     * НЕ вызывая course_module_deleted на каждый. См. local_unics\cleanup.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        cleanup::course_deleted((int)$event->objectid);
    }

    /**
     * При удалении пользователя ядром — зачистка его данных в unics_*
     * (та же семантика, что у privacy-провайдера: субъектные строки удаляются,
     * авторские поля и журнал аудита анонимизируются).
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        cleanup::purge_user_data((int)$event->objectid);
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

        // Мотивация: +10 баллов за сданный тест (один раз на тест) + пересчет
        // значков. Ошибки начисления не должны ломать обработку оценки.
        try {
            self::award_quiz_points($cmid, $userid);
        } catch (\Throwable $e) {
            // Нефатально.
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }

        // Адаптивный цикл: реакция на оценку теста сразу (стык «анализ результата
        // -> адаптация»), не дожидаясь ночной задачи evaluate_adaptive_levels.
        //  - входной диагностический тест -> разовая установка стартового уровня;
        //  - обычный тест -> пересчет уровня (движок сам проверит минимум тестов
        //    и сменит уровень только при необходимости).
        // Диагностику дальше по циклу (B2/B7) не гоняем - это вход, не тема/итог.
        $is_diagnostic = \local_unics\learning\activity_meta::is_diagnostic($cmid);
        try {
            global $DB;
            $student = $DB->get_record('unics_students', ['mdl_user_id' => $userid], 'id');
            if ($student) {
                if ($is_diagnostic) {
                    \local_unics\learning\adaptive_engine::diagnose_student((int)$student->id, $cmid);
                } else {
                    // S1: per-skill пересчет владения + глобальный rollup внутри on_attempt
                    // (заменяет прямой evaluate_student; диагностику по-прежнему ведет diagnose).
                    \local_unics\learning\mastery_manager::on_attempt($cmid, $userid, (int)$event->objectid);
                }
            }
        } catch (\Throwable $e) {
            // Нефатально - адаптация не должна валить обработку оценки.
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }
        if ($is_diagnostic) {
            return;
        }

        if (\local_unics\learning\activity_meta::is_final($cmid)) {
            \local_unics\learning\retake_manager::evaluate_cm_for_user($cmid, $userid); // B7
            return;
        }
        // B2: не итоговый. evaluate_cm_for_user сам проверит, что это тест темы
        // (B1-гейт), и тихо выйдет для прочих тестов.
        \local_unics\learning\topic_retry_manager::evaluate_cm_for_user($cmid, $userid);
    }

    /**
     * +10 баллов за сданный тест (>= порога \local_unics\social\points_manager::QUIZ_PASS_PCT).
     * Выплата одна на тест: маркер "[cm{id}]" в reason_text лога баллов
     * (страница магазина прячет маркер при выводе истории). Повторная оценка
     * или пересдача того же теста баллов не добавляет.
     */
    private static function award_quiz_points(int $cmid, int $userid): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $student = $DB->get_record('unics_students', ['mdl_user_id' => $userid]);
        if (!$student) {
            return;
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, instance, module');
        if (!$cm || $DB->get_field('modules', 'name', ['id' => $cm->module]) !== 'quiz') {
            return;
        }

        $marker = '[cm' . $cmid . ']';
        $already = $DB->record_exists_select(
            'unics_points_log',
            "student_id = :sid AND reason_type = :rt AND " . $DB->sql_like('reason_text', ':marker'),
            [
                'sid'    => (int)$student->id,
                'rt'     => \local_unics\social\points_manager::REASON_QUIZ_PASS,
                'marker' => '%' . $marker,
            ]
        );
        if ($already) {
            return;
        }

        $grades = grade_get_grades($cm->course, 'mod', 'quiz', $cm->instance, $userid);
        if (empty($grades->items[0]) || empty($grades->items[0]->grades[$userid])) {
            return;
        }
        $item = $grades->items[0];
        $g    = $item->grades[$userid];
        if ($g->grade === null || $g->grade === '' || $g->grade === false || (float)$item->grademax <= 0) {
            return;
        }
        $pct = (float)$g->grade / (float)$item->grademax * 100;
        if ($pct < \local_unics\social\points_manager::QUIZ_PASS_PCT) {
            return;
        }

        $quizname = $DB->get_field('quiz', 'name', ['id' => $cm->instance]) ?: 'Тест';
        \local_unics\social\points_manager::award(
            (int)$student->id,
            \local_unics\social\points_manager::POINTS_QUIZ_PASS,
            \local_unics\social\points_manager::REASON_QUIZ_PASS,
            'Сдан тест «' . mb_substr($quizname, 0, 120) . '» ' . $marker
        );

        // Тест мог открыть значок (Завершитель, Отличник и т.п.) - пересчитать
        // сразу, чтобы уведомление пришло по горячим следам.
        \local_unics\social\achievement_manager::evaluate_student((int)$student->id, $userid);
    }
}
