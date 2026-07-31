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
}
