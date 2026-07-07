<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * B7: автопересдача итогового экзамена при провале.
 *
 * Когда учащийся получает оценку за активность, помеченную итоговой (B5,
 * activity_meta::is_final), и оценка ниже порога сдачи — фиксируем пересдачу
 * в unics_retakes и уведомляем педагогов учащегося. Если сдал — закрываем
 * открытую пересдачу (если была).
 *
 * Порог сдачи: grade_items.gradepass, если задан (>0); иначе 50% от максимума.
 */
class retake_manager {

    /** Доля от максимума, используемая как порог по умолчанию, если gradepass не задан. */
    const DEFAULT_PASS_FRACTION = 0.5;

    /**
     * Оценить попытку итогового экзамена. Возвращает id новой записи пересдачи,
     * либо null (сдал / не итоговый / нет оценки / дубликат открытой пересдачи).
     */
    public static function evaluate_cm_for_user(int $cmid, int $userid): ?int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if (!activity_meta::is_final($cmid)) {
            return null;
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, instance, module');
        if (!$cm) {
            return null;
        }
        // Итоговый экзамен — только тест (quiz).
        if ($DB->get_field('modules', 'name', ['id' => $cm->module]) !== 'quiz') {
            return null;
        }

        $grades = grade_get_grades($cm->course, 'mod', 'quiz', $cm->instance, $userid);
        if (empty($grades->items[0]) || empty($grades->items[0]->grades[$userid])) {
            return null;
        }
        $item = $grades->items[0];
        $g    = $item->grades[$userid];
        if ($g->grade === null || $g->grade === '' || $g->grade === false) {
            return null; // ещё не оценено
        }

        $grade     = (float)$g->grade;
        $grademax  = (float)$item->grademax;
        $gradepass = (float)$item->gradepass;
        $threshold = $gradepass > 0 ? $gradepass : ($grademax * self::DEFAULT_PASS_FRACTION);

        if ($grade >= $threshold) {
            self::close_open($cmid, $userid); // сдал — закрыть пересдачу, если была открыта
            return null;
        }

        // Провал. Не дублируем открытую пересдачу.
        if ($DB->record_exists('unics_retakes', ['mdl_user_id' => $userid, 'cmid' => $cmid, 'status' => 0])) {
            return null;
        }

        $id = (int)$DB->insert_record('unics_retakes', (object)[
            'mdl_user_id'   => $userid,
            'mdl_course_id' => (int)$cm->course,
            'cmid'          => $cmid,
            'grade'         => $grade,
            'grademax'      => $grademax,
            'gradepass'     => $threshold,
            'status'        => 0,
            'timecreated'   => time(),
        ]);

        self::notify_teachers($userid, (int)$cm->course, $cmid, $grade, $grademax);
        return $id;
    }

    /** Закрыть открытые пересдачи для пары (cmid, user). */
    public static function close_open(int $cmid, int $userid): void {
        global $DB;
        $open = $DB->get_records('unics_retakes', ['mdl_user_id' => $userid, 'cmid' => $cmid, 'status' => 0]);
        foreach ($open as $r) {
            $DB->update_record('unics_retakes', (object)[
                'id'           => $r->id,
                'status'       => 1,
                'timeresolved' => time(),
            ]);
        }
    }

    /** Уведомить педагогов учащегося о необходимости пересдачи. */
    private static function notify_teachers(int $userid, int $course_id, int $cmid, float $grade, float $grademax): void {
        global $DB;

        $student = $DB->get_record('unics_students', ['mdl_user_id' => $userid]);
        if (!$student) {
            return; // не УНИКС-учащийся — некому слать (запись пересдачи уже создана)
        }

        $suser  = $DB->get_record('user', ['id' => $userid]);
        $sname  = $suser ? fullname($suser) : ('Учащийся #' . $student->id);
        $cname  = (string)$DB->get_field('course', 'fullname', ['id' => $course_id]);
        $qname  = self::quiz_name($cmid);

        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student->id]
        );
        foreach ($teachers as $tl) {
            \local_unics\social\notification_manager::notify_retake_needed(
                (int)$tl->mdl_user_id, $sname, $cname, $qname, $grade, $grademax
            );
        }
    }

    /** Имя теста по cmid. */
    private static function quiz_name(int $cmid): string {
        global $DB;
        $name = $DB->get_field_sql(
            "SELECT q.name
               FROM {course_modules} cm
               JOIN {quiz} q ON q.id = cm.instance
              WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );
        return $name ?: 'итоговый экзамен';
    }
}
