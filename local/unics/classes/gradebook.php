<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Журнал курса: read-модель «ученики x задания» ([[gradebook-export-design]];
 * этап 2.1 разгрузки lib.php).
 *
 * Тело перенесено из lib.php; там осталась тонкая обёртка
 * `local_unics_gradebook_matrix()` - gradebook.php и экспорт вызывают её как раньше.
 */
class gradebook {

    /**
     * Построение данных журнала (ученики x задания курса) БЕЗ вывода - для экрана и экспорта.
     * Инкапсулирует групповую изоляцию, фильтр учеников, оценки и матрицу.
     *
     * @return array{notice: ?array{text:string,level:string}, students: array, by_user: array,
     *               item_meta: array, item_class_avg: array}
     */
    public static function matrix(int $course_id, int $filter_class, string $filter_letter): array {
        global $DB, $USER;
        $result = ['notice' => null, 'students' => [], 'by_user' => [],
                   'item_meta' => [], 'item_class_avg' => []];

        $course     = get_course($course_id);
        $course_ctx = \context_course::instance($course_id);

        // Групповая изоляция: раздельные группы без accessallgroups -> только свои группы.
        $restrict_uids = null;
        if (groups_get_course_groupmode($course) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $course_ctx)) {
            $my_groups = groups_get_user_groups($course_id, $USER->id);
            $gids = !empty($my_groups[0]) ? $my_groups[0] : [];
            $restrict_uids = [];
            foreach ($gids as $gid) {
                foreach (groups_get_members($gid, 'u.id') as $m) {
                    $restrict_uids[(int)$m->id] = true;
                }
            }
            $restrict_uids = array_keys($restrict_uids);
            if (empty($restrict_uids)) {
                $result['notice'] = ['text' =>
                    'Вы не состоите ни в одной группе этого курса, а курс использует раздельные группы. '
                    . 'Обратитесь к методисту для добавления в группу.', 'level' => 'warning'];
                return $result;
            }
        }

        // Ученики (активные, записанные на курс, + фильтр класса/буквы).
        $where  = 'ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid';
        $params = ['cid' => $course_id];
        if ($filter_class > 0)     { $where .= ' AND s.class_number = :fclass';  $params['fclass']  = $filter_class; }
        if ($filter_letter !== '') { $where .= ' AND s.class_letter = :fletter'; $params['fletter'] = $filter_letter; }
        if ($restrict_uids !== null) {
            [$ruin, $ruparams] = $DB->get_in_or_equal($restrict_uids, SQL_PARAMS_NAMED, 'ru');
            $where .= " AND s.mdl_user_id {$ruin}";
            $params += $ruparams;
        }
        $students = $DB->get_records_sql(
            "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
                    u.lastname, u.firstname, u.middlename,
                    s.class_number, s.class_letter
               FROM {unics_students} s
               JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
               JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE {$where}
              ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
            $params);
        if (empty($students)) {
            $result['notice'] = ['text' =>
                'На этот курс не записано активных учащихся по выбранным условиям.', 'level' => 'info'];
            return $result;
        }

        // Оценки + матрица.
        $user_ids = array_map('intval', array_column((array)$students, 'mdl_user_id'));
        [$uin, $uparams] = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NAMED, 'u');
        $grade_rows = $DB->get_records_sql(
            "SELECT g.id, g.userid, g.itemid, g.finalgrade, gi.grademax, gi.itemname, gi.itemmodule,
                    gi.sortorder, COALESCE(g.timemodified, g.timecreated, 0) AS gtime
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid {$uin}
                AND gi.courseid   = :cid
                AND gi.itemtype   = 'mod'
                AND gi.grademax   > 0
                AND g.finalgrade IS NOT NULL
              ORDER BY g.userid, gtime, g.id",
            $uparams + ['cid' => $course_id]);

        $by_user   = [];
        $item_meta = [];
        $item_sum  = [];
        $item_cnt  = [];
        foreach ($grade_rows as $gr) {
            $uid = (int)$gr->userid;
            $iid = (int)$gr->itemid;
            $pct = $gr->finalgrade / (float)$gr->grademax * 100;
            $by_user[$uid][] = [
                'itemid' => $iid,
                'pct'    => $pct,
                'val'    => \local_unics\learning\grade_scale::from_percent($pct),
                'name'   => $gr->itemname ?: $gr->itemmodule,
                'time'   => (int)$gr->gtime,
            ];
            if (!isset($item_meta[$iid])) {
                $item_meta[$iid] = ['name' => $gr->itemname ?: $gr->itemmodule, 'sortorder' => (int)$gr->sortorder];
                $item_sum[$iid]  = 0.0;
                $item_cnt[$iid]  = 0;
            }
            $item_sum[$iid] += $pct;
            $item_cnt[$iid]++;
        }
        if (empty($grade_rows)) {
            $result['notice'] = ['text' => 'Оценок по этому курсу ещё нет.', 'level' => 'info'];
            return $result;
        }

        $item_class_avg = [];
        foreach ($item_sum as $iid => $sum) {
            $item_class_avg[$iid] = \local_unics\learning\grade_scale::from_percent($sum / $item_cnt[$iid]);
        }

        $result['students']       = $students;
        $result['by_user']        = $by_user;
        $result['item_meta']      = $item_meta;
        $result['item_class_avg'] = $item_class_avg;
        return $result;
    }
}
