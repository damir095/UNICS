<?php
namespace local_unics\analytics;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/unics/classes/student_helper.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

/**
 * Сбор и агрегация учебной статистики (A5, [[statistics-collection-design]]).
 *
 * Гибрид-хранение: сырьё читаем из нативных источников Moodle (logstore, completion,
 * grade_grades, quiz_attempts) и наших таблиц (unics_umk, unics_level_history); свой
 * rollup-агрегат unics_stats_student_course (учащийся x курс, кумулятивно) пересчитывает
 * scheduled task aggregate_stats. Период агрегации в v1 не вводится (кумулятив за всё время) -
 * периодизация отложена (открытый вопрос дизайна).
 */
class stats_manager {

    /** Окно «активен» для метрики active-students (дни). */
    const ACTIVE_DAYS = 14;
    /** Максимальный разрыв между событиями лога, считающийся одной сессией (сек). */
    const SESSION_GAP = 1800;

    // ==================== ПЕРЕСЧЁТ ROLLUP ====================

    /**
     * Пересчитать rollup по всем активным учащимся.
     *
     * @return array{students:int, rows:int} сколько учащихся и строк (учащийся x курс) обновлено
     */
    public static function rebuild_all(): array {
        global $DB;
        $students = $DB->get_records('unics_students', ['archived_at' => null], '', 'id, mdl_user_id');
        $nrows = 0;
        foreach ($students as $s) {
            $nrows += self::rebuild_for_student((int)$s->id, (int)$s->mdl_user_id);
        }
        return ['students' => count($students), 'rows' => $nrows];
    }

    /**
     * Пересчитать rollup по одному учащемуся (все его курсы с записью/активностью).
     *
     * @param int $student_id  unics_students.id
     * @param int $mdl_user_id user.id
     * @return int число обновлённых строк (= число курсов)
     */
    public static function rebuild_for_student(int $student_id, int $mdl_user_id): int {
        $courses = enrol_get_users_courses($mdl_user_id, true, 'id');
        $n = 0;
        foreach ($courses as $course) {
            if ((int)$course->id <= 1) {
                continue; // пропускаем сайт-курс
            }
            self::rebuild_row($student_id, $mdl_user_id, (int)$course->id);
            $n++;
        }
        return $n;
    }

    /**
     * Посчитать и записать одну строку rollup (учащийся x курс).
     */
    protected static function rebuild_row(int $student_id, int $mdl_user_id, int $course_id): void {
        global $DB;

        $now = time();

        // Просмотры активностей курса (logstore).
        $views = (int)$DB->count_records_select('logstore_standard_log',
            "userid = :uid AND courseid = :cid AND action = 'viewed' AND target = 'course_module'",
            ['uid' => $mdl_user_id, 'cid' => $course_id]);

        // Время на курсе (аппроксимация по интервалам лога) + последняя активность.
        $times = $DB->get_fieldset_sql(
            "SELECT timecreated FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid = :cid
           ORDER BY timecreated ASC",
            ['uid' => $mdl_user_id, 'cid' => $course_id]);
        $time_est_min = 0;
        $last_active  = null;
        if (!empty($times)) {
            $last_active = (int)end($times);
            $prev = null;
            $secs = 0;
            foreach ($times as $t) {
                $t = (int)$t;
                if ($prev !== null) {
                    $gap = $t - $prev;
                    if ($gap > 0 && $gap <= self::SESSION_GAP) {
                        $secs += $gap;
                    }
                }
                $prev = $t;
            }
            $time_est_min = (int)round($secs / 60);
        }

        // Завершённые активности (completion) и всего активностей с completion.
        $completed = (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cmc.userid = :uid AND cm.course = :cid AND cm.completion > 0
                AND cmc.completionstate IN (1, 2)",
            ['uid' => $mdl_user_id, 'cid' => $course_id]);
        $total = (int)$DB->count_records_select('course_modules',
            "course = :cid AND completion > 0 AND visible = 1 AND deletioninprogress = 0",
            ['cid' => $course_id]);

        // Завершённые попытки тестов.
        $attempts = (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE qa.userid = :uid AND q.course = :cid AND qa.state = 'finished'",
            ['uid' => $mdl_user_id, 'cid' => $course_id]);

        // Средний балл по тестам курса (%).
        $avg = $DB->get_field_sql(
            "SELECT AVG(g.finalgrade / gi.grademax * 100)
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid = :uid AND gi.courseid = :cid
                AND gi.itemtype = 'mod' AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL AND gi.grademax > 0",
            ['uid' => $mdl_user_id, 'cid' => $course_id]);
        $avg_score = ($avg === null || $avg === false) ? null : round((float)$avg, 2);

        // Выдано УМК (наш «ИИ-след»).
        $ai_uses = (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {unics_umk_students} us
               JOIN {unics_umk} u ON u.id = us.umk_id
              WHERE us.student_id = :sid AND u.mdl_course_id = :cid",
            ['sid' => $student_id, 'cid' => $course_id]);

        $row = (object)[
            'student_id'           => $student_id,
            'mdl_user_id'          => $mdl_user_id,
            'mdl_course_id'        => $course_id,
            'views'                => $views,
            'time_est_min'         => $time_est_min,
            'completed_activities' => $completed,
            'total_activities'     => $total,
            'attempts'             => $attempts,
            'avg_score_pct'        => $avg_score,
            'last_active_at'       => $last_active,
            'ai_uses'              => $ai_uses,
            'computed_at'          => $now,
        ];

        $existing = $DB->get_record('unics_stats_student_course',
            ['student_id' => $student_id, 'mdl_course_id' => $course_id], 'id');
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('unics_stats_student_course', $row);
        } else {
            $DB->insert_record('unics_stats_student_course', $row);
        }
    }

    // ==================== ЧТЕНИЕ / СРЕЗЫ ====================

    /**
     * Базовый набор строк «один учащийся = одна строка» с агрегатом по всем его курсам
     * и атрибутами для срезов (ОВЗ-категория, вид ОВЗ, класс, орг./район/регион).
     *
     * @param int[]|null $org_ids null = все организации (регион/сайт-админ);
     *                            [] = ничего не видно; иначе - фильтр по организациям скоупа
     * @return \stdClass[] строки с полями student_id, mdl_user_id, category, ovz_type,
     *   class_number, organization_id, organization_name, district_id, region_id,
     *   views, time_est_min, completed, total, attempts, ai_uses, avg_score_pct (nullable),
     *   last_active_at (nullable), level_changes
     */
    public static function get_student_rows(?array $org_ids): array {
        global $DB;

        $params = [];
        $orgwhere = '';
        if ($org_ids !== null) {
            if (empty($org_ids)) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($org_ids, SQL_PARAMS_NAMED, 'org');
            $orgwhere = "AND s.organization_id $insql";
            $params = $inparams;
        }

        $sql = "SELECT s.id AS student_id, s.mdl_user_id, s.category, s.ovz_type, s.class_number,
                       s.organization_id, o.name AS organization_name,
                       o.district_id, d.name AS district_name, d.region_id, r.name AS region_name,
                       COALESCE(SUM(st.views), 0)                AS views,
                       COALESCE(SUM(st.time_est_min), 0)         AS time_est_min,
                       COALESCE(SUM(st.completed_activities), 0) AS completed,
                       COALESCE(SUM(st.total_activities), 0)     AS total,
                       COALESCE(SUM(st.attempts), 0)             AS attempts,
                       COALESCE(SUM(st.ai_uses), 0)              AS ai_uses,
                       AVG(st.avg_score_pct)                     AS avg_score_pct,
                       MAX(st.last_active_at)                    AS last_active_at,
                       COUNT(st.id)                              AS n_courses
                  FROM {unics_students} s
             LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
             LEFT JOIN {unics_districts} d ON d.id = o.district_id
             LEFT JOIN {unics_regions} r ON r.id = d.region_id
             LEFT JOIN {unics_stats_student_course} st ON st.student_id = s.id
                 WHERE s.archived_at IS NULL $orgwhere
              GROUP BY s.id, s.mdl_user_id, s.category, s.ovz_type, s.class_number,
                       s.organization_id, o.name, o.district_id, d.name, d.region_id, r.name";

        $rows = array_values($DB->get_records_sql($sql, $params));
        if (empty($rows)) {
            return [];
        }

        // Число изменений уровня (из истории) - отдельно, чтобы не размножать SUM по курсам.
        $lc = $DB->get_records_sql(
            "SELECT student_id, COUNT(1) AS c FROM {unics_level_history} GROUP BY student_id");
        foreach ($rows as $r) {
            $r->student_id     = (int)$r->student_id;
            $r->avg_score_pct  = $r->avg_score_pct === null ? null : round((float)$r->avg_score_pct, 1);
            $r->last_active_at = $r->last_active_at === null ? null : (int)$r->last_active_at;
            $r->level_changes  = isset($lc[$r->student_id]) ? (int)$lc[$r->student_id]->c : 0;
        }

        return $rows;
    }

    /**
     * Сгруппировать строки учащихся по произвольному ключу (срез).
     *
     * @param \stdClass[] $rows  из get_student_rows
     * @param callable    $keyfn fn(\stdClass $row): string|string[]|null - ключ(и) среза;
     *                           null = строку в этот срез не включать (напр. не-ОВЗ в срезе по ОВЗ).
     *                           Несколько ключей (массив) = учащийся попадает в каждый (CSV-членство).
     * @return array key => \stdClass агрегат (см. finalize_agg)
     */
    public static function aggregate(array $rows, callable $keyfn): array {
        $out = [];
        foreach ($rows as $r) {
            $keys = $keyfn($r);
            if ($keys === null) {
                continue;
            }
            foreach ((array)$keys as $k) {
                if ($k === null || $k === '') {
                    continue;
                }
                if (!isset($out[$k])) {
                    $out[$k] = self::blank_agg();
                }
                self::add_to_agg($out[$k], $r);
            }
        }
        foreach ($out as $k => $a) {
            self::finalize_agg($out[$k]);
        }
        return $out;
    }

    /** Один агрегат по всем строкам (итоговая «шапка»). */
    public static function totals(array $rows): \stdClass {
        $a = self::blank_agg();
        foreach ($rows as $r) {
            self::add_to_agg($a, $r);
        }
        self::finalize_agg($a);
        return $a;
    }

    protected static function blank_agg(): \stdClass {
        return (object)[
            'n_students'    => 0,
            'n_active'      => 0,
            'n_with_score'  => 0,
            'sum_views'     => 0,
            'sum_time'      => 0,
            'sum_completed' => 0,
            'sum_total'     => 0,
            'sum_attempts'  => 0,
            'sum_ai'        => 0,
            'sum_levelchg'  => 0,
            'score_sum'     => 0.0,
            // вычисляется в finalize_agg:
            'avg_score'      => null,
            'completion_pct' => null,
        ];
    }

    protected static function add_to_agg(\stdClass $a, \stdClass $r): void {
        $a->n_students++;
        $a->sum_views     += (int)$r->views;
        $a->sum_time      += (int)$r->time_est_min;
        $a->sum_completed += (int)$r->completed;
        $a->sum_total     += (int)$r->total;
        $a->sum_attempts  += (int)$r->attempts;
        $a->sum_ai        += (int)$r->ai_uses;
        $a->sum_levelchg  += (int)$r->level_changes;
        if ($r->avg_score_pct !== null) {
            $a->score_sum += (float)$r->avg_score_pct;
            $a->n_with_score++;
        }
        if ($r->last_active_at && (time() - (int)$r->last_active_at) <= self::ACTIVE_DAYS * 86400) {
            $a->n_active++;
        }
    }

    protected static function finalize_agg(\stdClass $a): void {
        $a->avg_score = $a->n_with_score > 0 ? round($a->score_sum / $a->n_with_score, 1) : null;
        $a->completion_pct = $a->sum_total > 0 ? round($a->sum_completed / $a->sum_total * 100, 1) : null;
    }

    /**
     * Человекочитаемое время из минут: «2 ч 15 мин» / «45 мин».
     */
    public static function format_minutes(int $min): string {
        if ($min <= 0) {
            return '0 мин';
        }
        $h = intdiv($min, 60);
        $m = $min % 60;
        if ($h > 0) {
            return $m > 0 ? "{$h} ч {$m} мин" : "{$h} ч";
        }
        return "{$m} мин";
    }
}
