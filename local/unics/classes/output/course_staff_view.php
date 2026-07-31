<?php
namespace local_unics\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Данные педагогского вида страницы курса (course/view.php, формат topics): сигнал ПО КЛАССУ -
 * что ждет проверки, кто застрял, сколько учеников прошло каждую тему.
 * local_unics ВЫЧИСЛЯЕТ; theme_unics стилизует; AMD local_unics/course_staff дополняет DOM.
 * Зеркало {@see course_view}, но считает не одного пользователя, а агрегаты по всему классу.
 *
 * ВАЖНО про числа: чипы считают КЛАСС СМОТРЯЩЕГО (для педагога - его привязки
 * unics_teacher_student), а страница course_report.php, куда ведет ссылка «застряли», показывает
 * ВСЕХ записанных на курс. Расхождение ожидаемо и намеренно: чип отвечает «сколько МОИХ учеников
 * застряло», отчет - «что происходит на курсе целиком».
 */
class course_staff_view {

    /** Выполнено: те же константы, что в course_view::activity_status() - числа обязаны сходиться. */
    private const DONE_STATES = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];

    /**
     * Гейт «педагогский вид»: смотрящий не ребенок и не родитель, режим редактирования выключен,
     * и он вправе видеть участников курса. В режиме редактирования не показываем сознательно -
     * эта поверхность и так самая плотная на сайте.
     */
    public static function is_staff_view(\stdClass $course): bool {
        global $PAGE;
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        if ($PAGE->user_is_editing()) {
            return false;
        }
        if (\local_unics\access::student_record() !== null || \local_unics\access::is_parent()) {
            return false;
        }
        return has_capability('moodle/course:viewparticipants', \context_course::instance($course->id));
    }

    /**
     * Класс смотрящего на этом курсе - активные незаархивированные учащиеся, записанные на курс.
     *
     * Ветка выбирается по РОЛИ смотрящего, а не по наличию строки в unics_teachers - ЛОВУШКА:
     * unics_user_manager::create_user() пишет строку в unics_teachers для КАЖДОЙ «учительской»
     * роли, включая методиста организации (роль 4) и муниципального методиста (роль 9) -
     * см. {@see \local_unics\access::is_methodist()} («по таблице teacher'ов методиста не
     * отличить»). Поэтому проверка «методист/скоупный админ» ОБЯЗАНА идти РАНЬШЕ проверки
     * unics_teachers: иначе методист попадает в ветку привязок, где у него нет ни одной строки
     * unics_teacher_student, и класс ошибочно выходит пустым.
     *
     * Порядок веток:
     * - системный админ (local/unics:manage) - без фильтра, видит всех записанных на курс;
     * - методист (роли 4/9) или скоупный админ (роли 1/10, manageorg без manage) -
     *   ограничен его скоупом (scope_checker::user_list_filter_sql);
     * - иначе, если есть строка в unics_teachers - педагог, ограничен привязками
     *   (unics_teacher_student);
     * - иначе - нетипичная роль с доступом к участникам курса, класс пуст (безопасный дефолт,
     *   а не «показать всех»).
     *
     * ВНИМАНИЕ: unics_teacher_student.teacher_id ссылается на unics_teachers.id, а student_id - на
     * unics_students.id (НЕ на user.id) - отсюда join'ы через mdl_user_id.
     * @return int[] Moodle user id по возрастанию, без дублей; пустой массив - класса нет
     */
    public static function class_members(\stdClass $course, int $viewerid): array {
        global $DB;

        $params = ['cid' => (int)$course->id];
        $joins = '';
        $where = '';

        if (has_capability('local/unics:manage', \context_system::instance(), $viewerid)) {
            // Системный админ - фильтра нет, видит всех записанных на курс.
        } else if (\local_unics\access::is_methodist($viewerid) || \local_unics\access::is_scoped_admin($viewerid)) {
            // Методист/скоупный админ: курсы у нас общие, поэтому без скоуп-фильтра он увидел бы
            // учеников чужих организаций.
            [$scopewhere, $scopeparams] = \local_unics\identity\scope_checker::user_list_filter_sql(
                $viewerid, 'uo', 'o');
            $joins = ' JOIN {unics_user_org} uo ON uo.mdl_user_id = s.mdl_user_id
                       LEFT JOIN {unics_organizations} o ON o.id = uo.organization_id ';
            $where = ' AND (' . $scopewhere . ')';
            $params += $scopeparams;
        } else {
            $teacherid = $DB->get_field('unics_teachers', 'id', ['mdl_user_id' => $viewerid]);
            if ($teacherid) {
                $joins = ' JOIN {unics_teacher_student} ts
                                ON ts.student_id = s.id AND ts.teacher_id = :tid ';
                $params['tid'] = (int)$teacherid;
            } else {
                // Ни одна ветка не подошла (нетипичная роль с доступом к участникам курса) -
                // безопасный дефолт: класс пуст, а не «все ученики курса».
                $where = ' AND 1=0';
            }
        }

        $sql = "SELECT DISTINCT s.mdl_user_id
                  FROM {unics_students} s
                  JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
                  JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
                  JOIN {enrol} e ON e.id = ue.enrolid
                  {$joins}
                 WHERE ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid {$where}";

        // get_records_sql ключует по ПЕРВОМУ столбцу; он тут DISTINCT mdl_user_id, поэтому ключи -
        // это и есть искомые id пользователей.
        $ids = array_map('intval', array_keys($DB->get_records_sql($sql, $params)));
        sort($ids);
        return $ids;
    }

    /**
     * Сколько учеников класса выполнило каждую активность - ОДНИМ запросом на курс.
     * @param int[] $cmids
     * @param int[] $userids
     * @return array<int,int> cmid -> число выполнивших
     */
    private static function done_counts(array $cmids, array $userids): array {
        global $DB;
        if (!$cmids || !$userids) {
            return [];
        }
        [$incm, $pcm] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        [$inu, $pu] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        [$incs, $pcs] = $DB->get_in_or_equal(self::DONE_STATES, SQL_PARAMS_NAMED, 'cs');
        $rows = $DB->get_records_sql(
            "SELECT cmc.coursemoduleid AS cmid, COUNT(DISTINCT cmc.userid) AS cnt
               FROM {course_modules_completion} cmc
              WHERE cmc.coursemoduleid {$incm} AND cmc.userid {$inu} AND cmc.completionstate {$incs}
           GROUP BY cmc.coursemoduleid",
            $pcm + $pu + $pcs
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->cmid] = (int)$r->cnt;
        }
        return $out;
    }

    /**
     * Сколько отправленных работ ждет проверки по каждому заданию курса - ОДНИМ запросом.
     * Считаем последние отправки (latest = 1, status = submitted) учеников класса, у которых нет
     * актуальной оценки: оценки нет вовсе, она пустая (в mod_assign «нет оценки» это -1) либо
     * выставлена ДО последней отправки (ученик переотправил после проверки).
     * Объекты assign НЕ инстанцируем - метод зовется на страницу курса целиком.
     * @param int[] $userids
     * @return array<int,int> cmid -> число работ на проверке
     */
    private static function grading_counts(int $courseid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$inu, $pu] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, COUNT(DISTINCT sub.userid) AS cnt
               FROM {assign_submission} sub
               JOIN {assign} a ON a.id = sub.assignment
               JOIN {modules} m ON m.name = 'assign'
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id
                    AND cm.course = a.course AND cm.deletioninprogress = 0
          LEFT JOIN {assign_grades} g ON g.assignment = sub.assignment AND g.userid = sub.userid
                    AND g.attemptnumber = sub.attemptnumber
              WHERE a.course = :cid AND sub.latest = 1 AND sub.status = 'submitted'
                    AND sub.userid {$inu}
                    AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0 OR g.timemodified < sub.timemodified)
           GROUP BY cm.id",
            ['cid' => $courseid] + $pu
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->cmid] = (int)$r->cnt;
        }
        return $out;
    }

    /**
     * Застрявшие: у ученика по активности открыта назначенная пересдача (unics_retakes) ИЛИ
     * открыт повтор темы (unics_topic_retries) - система уже решила, что он не справился.
     * ОДИН запрос с UNION (он же дедуплицирует ученика, у которого открыто и то, и другое).
     * Возвращаем пары, а не готовые счетчики: из одних и тех же строк нужны И число по каждой
     * активности, И число РАЗНЫХ учеников по курсу целиком (в шапке ученик считается один раз,
     * даже если застрял в трех активностях).
     *
     * ВАЖНО про параметры: `get_in_or_equal` для каждой ветки UNION вызывается ОТДЕЛЬНО, с
     * разными префиксами (u1_/u2_) - Moodle DML считает КОЛИЧЕСТВО ВХОЖДЕНИЙ именованного
     * плейсхолдера в тексте SQL, а не число уникальных имен (moodle_database::fix_sql_params(),
     * $named_count = preg_match_all(...)), поэтому один и тот же :u1 не может повторно
     * использоваться во второй ветке UNION - потребует вдвое больше значений, чем передано.
     * @param int[] $userids
     * @return array{0:array<int,int>,1:int} [cmid -> число застрявших, число разных учеников]
     */
    private static function stuck_counts(int $courseid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [[], 0];
        }
        [$inu1, $pu1] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u1');
        [$inu2, $pu2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u2');
        $params = ['cid1' => $courseid, 'cid2' => $courseid] + $pu1 + $pu2;
        // get_recordset_sql, а не get_records_sql: первый столбец (cmid) НЕ уникален, записи
        // с одинаковым cmid затерли бы друг друга.
        $rs = $DB->get_recordset_sql(
            "SELECT cmid, userid FROM (
                 SELECT r.cmid AS cmid, r.mdl_user_id AS userid
                   FROM {unics_retakes} r
                  WHERE r.mdl_course_id = :cid1 AND r.status = 0 AND r.mdl_user_id {$inu1}
                 UNION
                 SELECT tr.cmid AS cmid, tr.mdl_user_id AS userid
                   FROM {unics_topic_retries} tr
                  WHERE tr.mdl_course_id = :cid2 AND tr.status = 0 AND tr.mdl_user_id {$inu2}
             ) x",
            $params
        );
        $bycm = [];
        $users = [];
        foreach ($rs as $row) {
            $cmid = (int)$row->cmid;
            $bycm[$cmid] = ($bycm[$cmid] ?? 0) + 1;
            $users[(int)$row->userid] = true;
        }
        $rs->close();
        return [$bycm, count($users)];
    }

    /**
     * Активность попадает в сигнал, если она вообще показана на странице курса и ведет на свою
     * страницу - тот же фильтр, что в ученическом виде (course_view::visible_to_child), иначе
     * числа разойдутся с числом видимых строк.
     */
    private static function visible_on_page(\cm_info $cm): bool {
        return $cm->has_view() && $cm->is_visible_on_course_page();
    }

    /**
     * Собрать payload педагогского вида страницы курса - сигнал по классу смотрящего.
     * Запросов к БД НЕЗАВИСИМО от размера класса: class_members (~5, уже посчитано в Task 1),
     * плюс ровно 3 своих агрегата - done_counts (1), grading_counts (1), stuck_counts (1);
     * get_fast_modinfo/completion_info своих запросов не делают (используют кеш модуля
     * страницы курса). Измерено PHPUnit'ом (perf_get_queries()): 8 при 2 учениках/1 активности,
     * 8 при 30 учениках/1 активности - число НЕ растет с классом. Отдельно от размера класса,
     * число запросов слегка растет с количеством активностей ТИПА assign - это цена core
     * cm_info::obtain_dynamic_data() -> mod_assign_cm_info_dynamic() (заполнение MUC-кеша
     * переопределений срока сдачи), тот же вызов, что уже делает course_view::visible_to_child()
     * для того же фильтра «показывается ли активность на странице» - не своя N+1, а
     * унаследованная от Moodle core/сестринского класса стоимость первого обращения к cm_info.
     * Никаких запросов в цикле по ученикам или по активностям в самом course_staff_view.
     */
    public static function build_payload(\stdClass $course, int $viewerid): array {
        $members = self::class_members($course, $viewerid);
        $classsize = count($members);
        $strings = [
            'sectionName' => get_string('progress_section_name', 'local_unics'),
            'allClear' => get_string('staff_all_clear', 'local_unics'),
        ];
        $empty = [
            'strings' => $strings, 'attention' => ['grading' => null, 'stuck' => null],
            'classSize' => 0, 'sections' => [], 'cms' => [],
        ];
        if (!$members) {
            return $empty;
        }

        $modinfo = get_fast_modinfo($course);
        $ci = new \completion_info($course);

        $visible = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (self::visible_on_page($cm)) {
                $visible[] = $cm;
            }
        }
        if (!$visible) {
            return array_merge($empty, ['classSize' => $classsize]);
        }

        $cmids = array_map(static fn(\cm_info $cm): int => (int)$cm->id, $visible);
        $done = self::done_counts($cmids, $members);
        $grading = self::grading_counts((int)$course->id, $members);
        [$stuck, $stuckusers] = self::stuck_counts((int)$course->id, $members);

        $reporturl = (new \moodle_url('/local/unics/pages/course_report.php',
            ['course_id' => (int)$course->id]))->out(false);

        $cms = [];
        $firstgradingurl = null;
        foreach ($visible as $cm) {
            $cmid = (int)$cm->id;
            $tracked = $ci->is_enabled($cm);
            $gradingcount = $grading[$cmid] ?? 0;
            $stuckcount = $stuck[$cmid] ?? 0;
            $gradingurl = null;
            if ($gradingcount > 0) {
                $gradingurl = (new \moodle_url('/mod/assign/view.php',
                    ['id' => $cmid, 'action' => 'grading']))->out(false);
                $firstgradingurl = $firstgradingurl ?? $gradingurl;
            }
            $cms[(string)$cmid] = [
                'doneLabel' => $tracked
                    ? get_string('staff_done_label', 'local_unics',
                        (object)['done' => $done[$cmid] ?? 0, 'total' => $classsize])
                    : null,
                'gradingLabel' => $gradingcount > 0
                    ? get_string('staff_grading_label', 'local_unics', $gradingcount) : null,
                'gradingUrl' => $gradingurl,
                'stuckLabel' => $stuckcount > 0
                    ? get_string('staff_stuck_label', 'local_unics', $stuckcount) : null,
                'stuckUrl' => $stuckcount > 0 ? $reporturl : null,
            ];
        }

        // «Прошли тему»: точное число требовало бы пофамильного пересечения выполнивших по ВСЕМ
        // активностям секции. Минимум по активностям - верхняя оценка: совпадает с точным числом
        // в типичном случае (ученики идут по теме по порядку) и никогда не завышает результат
        // больше, чем на число «перепрыгнувших» учеников. Точнее - отдельная задача, если
        // педагоги попросят.
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $tracked = [];
            foreach ($visible as $cm) {
                if ((int)$cm->sectionnum === (int)$section->section && $ci->is_enabled($cm)) {
                    $tracked[] = (int)$cm->id;
                }
            }
            if (!$tracked) {
                continue;
            }
            // Тему прошел тот, кто выполнил ВСЕ отслеживаемые активности секции. Считаем по
            // минимуму выполнивших: у кого не хватает хотя бы одной - тема не пройдена.
            $passed = $classsize;
            foreach ($tracked as $cmid) {
                $passed = min($passed, $done[$cmid] ?? 0);
            }
            $a = (object)['done' => $passed, 'total' => $classsize];
            $sections[(string)$section->section] = [
                'done' => $passed, 'total' => $classsize,
                'label' => get_string('staff_section_progress', 'local_unics', $a),
                'aria' => get_string('staff_section_progress_aria', 'local_unics', $a),
            ];
        }

        $gradingtotal = array_sum($grading);
        return [
            'strings' => $strings,
            'attention' => [
                'grading' => $gradingtotal > 0 ? [
                    'count' => $gradingtotal,
                    'label' => get_string('staff_attention_grading_' . plural::form($gradingtotal),
                        'local_unics', $gradingtotal),
                    'url' => $firstgradingurl,
                ] : null,
                'stuck' => $stuckusers > 0 ? [
                    'count' => $stuckusers,
                    'label' => get_string('staff_attention_stuck_' . plural::form($stuckusers),
                        'local_unics', $stuckusers),
                    'url' => $reporturl,
                ] : null,
            ],
            'classSize' => $classsize,
            'sections' => $sections,
            'cms' => $cms,
        ];
    }
}
