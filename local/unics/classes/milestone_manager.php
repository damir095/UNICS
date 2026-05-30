<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * B4: контрольные точки (промежуточная аттестация).
 *
 * Тесты курса, помеченные флагом is_milestone (activity_meta), — это
 * промежуточные контрольные точки образовательного цикла (узел 18 диаграммы).
 * Этот класс собирает результаты milestone-тестов по учащимся: пройдена /
 * не пройдена / нет попытки, балл, дата.
 *
 * Порог «пройдена» — тот же, что у автопересдачи итогового (B7, retake_manager):
 * grade_items.gradepass, если задан (>0), иначе 50% от максимума. Это держит
 * понятие «сдано» единым во всём цикле.
 *
 * Сам флаг is_milestone хранится в unics_activity_meta (см. activity_meta);
 * контрольных точек в курсе может быть несколько (в отличие от единственного
 * итогового экзамена).
 */
class milestone_manager {

    /** Доля от максимума как порог по умолчанию, если gradepass не задан (как в B7). */
    const DEFAULT_PASS_FRACTION = 0.5;

    /** Статусы прохождения контрольной точки. */
    const STATUS_NOT_ATTEMPTED = 0; // оценки ещё нет
    const STATUS_PASSED        = 1; // балл >= порога
    const STATUS_FAILED        = 2; // балл < порога

    /**
     * Контрольные точки курса (помеченные is_milestone тесты).
     *
     * @return array<int,object> ключ = cmid; поля: cmid, instance, name
     */
    public static function get_course_milestones(int $course_id): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.instance, q.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               JOIN {quiz} q ON q.id = cm.instance
               JOIN {unics_activity_meta} am ON am.cmid = cm.id AND am.is_milestone = 1
              WHERE cm.course = :course AND cm.deletioninprogress = 0
              ORDER BY cm.section, q.name",
            ['course' => $course_id]
        );
    }

    /**
     * Пустой результат (нет попытки).
     */
    private static function blank_result(): object {
        return (object)[
            'status'       => self::STATUS_NOT_ATTEMPTED,
            'grade'        => null,
            'grademax'     => null,
            'gradepass'    => null,
            'timemodified' => null,
        ];
    }

    /**
     * Результаты одной контрольной точки сразу по нескольким учащимся
     * (одним вызовом grade_get_grades — экономит запросы для матрицы).
     *
     * @param int   $course_id курс контрольной точки
     * @param int   $instance  instance теста (quiz.id)
     * @param int[] $userids   id пользователей (mdl_user)
     * @return array<int,object> ключ = userid; поле .status/.grade/.grademax/.gradepass/.timemodified
     */
    public static function evaluate_many(int $course_id, int $instance, array $userids): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $out = [];
        foreach ($userids as $uid) {
            $out[(int)$uid] = self::blank_result();
        }
        if (empty($userids)) {
            return $out;
        }

        $grades = grade_get_grades($course_id, 'mod', 'quiz', $instance, array_map('intval', $userids));
        if (empty($grades->items[0])) {
            return $out;
        }
        $item     = $grades->items[0];
        $grademax = (float)$item->grademax;
        // gradepass на уровне grade_item; если не задан — 50% от максимума (как B7).
        $threshold = ((float)$item->gradepass > 0)
            ? (float)$item->gradepass
            : ($grademax * self::DEFAULT_PASS_FRACTION);

        foreach ($userids as $uid) {
            $uid = (int)$uid;
            if (empty($item->grades[$uid])) {
                continue;
            }
            $g = $item->grades[$uid];
            if ($g->grade === null || $g->grade === '' || $g->grade === false) {
                continue; // не оценено — оставляем blank
            }
            $grade = (float)$g->grade;
            $res = (object)[
                'status'       => ($grade >= $threshold) ? self::STATUS_PASSED : self::STATUS_FAILED,
                'grade'        => $grade,
                'grademax'     => $grademax,
                'gradepass'    => $threshold,
                'timemodified' => !empty($g->dategraded) ? (int)$g->dategraded : null,
            ];
            $out[$uid] = $res;
        }
        return $out;
    }

    /**
     * Результат одной контрольной точки одного учащегося.
     */
    public static function evaluate(int $course_id, int $instance, int $userid): object {
        return self::evaluate_many($course_id, $instance, [$userid])[$userid];
    }

    /**
     * Контрольные точки во всех курсах, где записан учащийся, с результатом каждой.
     * Используется в карточке учащегося (student_report.php).
     *
     * @return array<int,object> ключ = cmid; поля: cmid, course_id, course_name,
     *                           quiz_name, result(object)
     */
    public static function student_milestones(int $userid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.instance, c.id AS course_id,
                    c.fullname AS course_name, q.name AS quiz_name
               FROM {unics_activity_meta} am
               JOIN {course_modules} cm ON cm.id = am.cmid AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               JOIN {quiz} q ON q.id = cm.instance
               JOIN {course} c ON c.id = cm.course
              WHERE am.is_milestone = 1
                AND EXISTS (SELECT 1
                              FROM {user_enrolments} ue
                              JOIN {enrol} e ON e.id = ue.enrolid
                             WHERE e.courseid = c.id
                               AND ue.userid = :uid
                               AND ue.status = 0)
              ORDER BY c.fullname, q.name",
            ['uid' => $userid]
        );
        $out = [];
        foreach ($rows as $r) {
            $r->result = self::evaluate((int)$r->course_id, (int)$r->instance, $userid);
            $out[(int)$r->cmid] = $r;
        }
        return $out;
    }

    /**
     * HTML-бейдж статуса контрольной точки (единый вид на всех страницах).
     */
    public static function status_html(object $r): string {
        switch ((int)$r->status) {
            case self::STATUS_PASSED:
                return '<span class="badge badge-success">пройдена</span>';
            case self::STATUS_FAILED:
                return '<span class="badge badge-danger">не пройдена</span>';
            default:
                return '<span class="badge badge-secondary">нет попытки</span>';
        }
    }

    /**
     * Текст балла «X / Y» (+ порог, если не пройдена), либо «—» если нет попытки.
     */
    public static function grade_text(object $r): string {
        if ($r->grade === null) {
            return '—';
        }
        $txt = format_float($r->grade, 1) . ' / ' . format_float($r->grademax, 1);
        if ((int)$r->status === self::STATUS_FAILED) {
            $txt .= ' <span class="text-muted small">(порог ' . format_float($r->gradepass, 1) . ')</span>';
        }
        return $txt;
    }
}
