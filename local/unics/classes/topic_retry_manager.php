<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * B2: возврат к теме при провале (узлы 14/16/17 диаграммы).
 *
 * Когда учащийся получает оценку за тест ТЕМЫ (quiz с B1-гейтом на материалы,
 * не итоговый) и балл ниже порога сдачи, фиксируем «тему для повторения» в
 * unics_topic_retries и уведомляем учащегося (повтори материалы и пройди тест
 * заново) и его педагогов. Если позже сдал - открытая запись закрывается.
 *
 * Доступность материалов НЕ трогаем: в модели B1 материалы и так видны учащемуся
 * (гейтится тест, а не материал), поэтому «возврат» = запись + уведомление, без
 * изменения availability.
 *
 * Порог сдачи: grade_items.gradepass, если задан (>0); иначе 50% от максимума.
 * Единый с B4 (milestone_manager) и B7 (retake_manager).
 *
 * «Тест темы» определяется по B1-гейту: в course_modules.availability теста есть
 * хотя бы одно условие типа completion (зависимость от просмотра материала). Это
 * именно те тесты, для которых имеет смысл «повторить тему».
 */
class topic_retry_manager {

    /** Доля от максимума как порог по умолчанию, если gradepass не задан (как B7). */
    const DEFAULT_PASS_FRACTION = 0.5;

    /**
     * Это тест темы с B1-гейтом? (quiz + в availability есть completion-условие).
     */
    public static function is_topic_quiz(int $cmid): bool {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, module, availability');
        if (!$cm) {
            return false;
        }
        if ($DB->get_field('modules', 'name', ['id' => $cm->module]) !== 'quiz') {
            return false;
        }
        if (empty($cm->availability)) {
            return false;
        }
        $tree = json_decode($cm->availability, true);
        return is_array($tree) && self::has_completion_condition($tree);
    }

    /** Рекурсивный поиск условия type=completion в дереве availability. */
    private static function has_completion_condition(array $node): bool {
        if (isset($node['type']) && $node['type'] === 'completion') {
            return true;
        }
        if (!empty($node['c']) && is_array($node['c'])) {
            foreach ($node['c'] as $child) {
                if (is_array($child) && self::has_completion_condition($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Оценить попытку теста темы. Возвращает id новой записи «тема для повторения»,
     * либо null (сдал / не тест темы / нет оценки / уже есть открытая запись).
     */
    public static function evaluate_cm_for_user(int $cmid, int $userid): ?int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        // Итоговый обрабатывает B7, а не B2.
        if (activity_meta::is_final($cmid)) {
            return null;
        }
        if (!self::is_topic_quiz($cmid)) {
            return null;
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, instance, module');
        if (!$cm) {
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
            self::close_open($cmid, $userid); // сдал - закрыть запись, если была открыта
            return null;
        }

        // Провал. Не дублируем открытую запись.
        if ($DB->record_exists('unics_topic_retries', ['mdl_user_id' => $userid, 'cmid' => $cmid, 'status' => 0])) {
            return null;
        }

        $id = (int)$DB->insert_record('unics_topic_retries', (object)[
            'mdl_user_id'   => $userid,
            'mdl_course_id' => (int)$cm->course,
            'cmid'          => $cmid,
            'grade'         => $grade,
            'grademax'      => $grademax,
            'gradepass'     => $threshold,
            'status'        => 0,
            'timecreated'   => time(),
        ]);

        self::notify($userid, (int)$cm->course, $cmid, $grade, $grademax);

        // Адаптация маршрута (стадия цикла «Адаптация маршрута»): авто-шаг ИОМ
        // «вернуться к теме» (source=adaptive) для УНИКС-учащегося. Дедуп - внутри
        // path_manager. Тема выводится из имени теста («<Тема> - тест» -> «<Тема>»).
        // Нефатально: маршрут не должен валить обработку B2.
        try {
            $student = $DB->get_record('unics_students', ['mdl_user_id' => $userid], 'id, difficulty_level');
            if ($student) {
                $topic = preg_replace('/\s*-\s*тест\s*$/ui', '', self::quiz_name($cmid));
                path_manager::add_adaptive_topic_step(
                    (int)$student->id, $userid, (int)$cm->course, (string)$topic,
                    (int)$student->difficulty_level,
                    'Добавлено автоматически: возврат к теме после провала контрольной точки'
                );
            }
        } catch (\Throwable $e) {
            debugging('local_unics: авто-шаг ИОМ из B2 не удался: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $id;
    }

    /** Закрыть открытые записи «тема для повторения» для пары (cmid, user). */
    public static function close_open(int $cmid, int $userid): void {
        global $DB;
        $open = $DB->get_records('unics_topic_retries', ['mdl_user_id' => $userid, 'cmid' => $cmid, 'status' => 0]);
        foreach ($open as $r) {
            $DB->update_record('unics_topic_retries', (object)[
                'id'           => $r->id,
                'status'       => 1,
                'timeresolved' => time(),
            ]);
        }
    }

    /** Уведомить учащегося и его педагогов о необходимости повторить тему. */
    private static function notify(int $userid, int $course_id, int $cmid, float $grade, float $grademax): void {
        global $DB;

        $suser = $DB->get_record('user', ['id' => $userid]);
        $sname = $suser ? fullname($suser) : ('Учащийся #' . $userid);
        $cname = (string)$DB->get_field('course', 'fullname', ['id' => $course_id]);
        $qname = self::quiz_name($cmid);

        // Учащемуся - подсказка повторить тему (Q3: педагог + учащийся).
        notification_manager::notify_topic_retry_student($userid, $cname, $qname, $grade, $grademax);

        // Педагогам учащегося (если это УНИКС-учащийся с назначенными педагогами).
        $student = $DB->get_record('unics_students', ['mdl_user_id' => $userid]);
        if (!$student) {
            return;
        }
        $teachers = $DB->get_records_sql(
            "SELECT DISTINCT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student->id]
        );
        foreach ($teachers as $tl) {
            notification_manager::notify_topic_retry_teacher(
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
        return $name ?: 'тест темы';
    }

    /**
     * Открытые «темы для повторения» учащегося (для карточки в student_report).
     *
     * @return array<int,object> ключ = id; поля: cmid, grade, grademax, gradepass,
     *                           timecreated, course_name, quiz_name
     */
    public static function student_open_retries(int $userid): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT tr.id, tr.cmid, tr.grade, tr.grademax, tr.gradepass, tr.timecreated,
                    c.fullname AS course_name, q.name AS quiz_name
               FROM {unics_topic_retries} tr
               JOIN {course} c ON c.id = tr.mdl_course_id
               LEFT JOIN {course_modules} cm ON cm.id = tr.cmid AND cm.deletioninprogress = 0
               LEFT JOIN {quiz} q ON q.id = cm.instance
              WHERE tr.mdl_user_id = :uid AND tr.status = 0
              ORDER BY tr.timecreated DESC",
            ['uid' => $userid]
        );
    }

    /** Текст балла «X / Y (порог Z)». */
    public static function grade_text(object $r): string {
        if ($r->grade === null) {
            return '-';
        }
        return format_float($r->grade, 1) . ' / ' . format_float($r->grademax, 1)
            . ' <span class="text-muted small">(порог ' . format_float($r->gradepass, 1) . ')</span>';
    }
}
