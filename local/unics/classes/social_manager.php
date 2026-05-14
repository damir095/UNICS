<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Управляет контактами Moodle Messaging.
 *
 * При назначении педагога учащемуся - оба автоматически добавляются в контакты.
 * При записи учащегося на курс - все сокурсники добавляются в контакты.
 */
class social_manager {

    /**
     * Добавить взаимный контакт между двумя пользователями Moodle.
     * Нефатально: исключения игнорируются.
     */
    public static function add_contact(int $user_id_a, int $user_id_b): void {
        if ($user_id_a === $user_id_b) {
            return;
        }
        try {
            \core_message\api::add_contact($user_id_a, $user_id_b);
        } catch (\Throwable $e) {
            // Нефатально - возможно, уже существует или API недоступен
        }
        try {
            \core_message\api::add_contact($user_id_b, $user_id_a);
        } catch (\Throwable $e) {
            // Нефатально
        }
    }

    /**
     * Синхронизировать контакты при назначении педагога к учащемуся.
     *
     * Добавляет в контакты:
     *  - педагог ↔ учащийся
     *  - все другие педагоги этого учащегося ↔ новый педагог (для коллаборации)
     */
    public static function sync_on_teacher_assign(int $teacher_mdl_user_id, int $student_mdl_user_id, int $student_unics_id): void {
        global $DB;

        // Педагог ↔ учащийся
        self::add_contact($teacher_mdl_user_id, $student_mdl_user_id);

        // Родители учащегося ↔ педагог
        $parents = $DB->get_records('unics_parent_student', ['student_id' => $student_unics_id], '', 'parent_mdl_user_id');
        foreach ($parents as $p) {
            self::add_contact($teacher_mdl_user_id, (int)$p->parent_mdl_user_id);
        }
    }

    /**
     * Синхронизировать контакты при записи учащегося на курс.
     *
     * Добавляет в контакты всех учащихся-одноклассников того же класса
     * из той же организации.
     */
    public static function sync_classmates(int $student_mdl_user_id, int $organization_id, int $class_number): void {
        global $DB;

        if ($class_number <= 0) {
            return;
        }

        $classmates = $DB->get_records_sql(
            "SELECT s.mdl_user_id
               FROM {unics_students} s
              WHERE s.organization_id = :org
                AND s.class_number   = :cls
                AND s.mdl_user_id   != :uid
                AND EXISTS (SELECT 1 FROM {user} u WHERE u.id = s.mdl_user_id AND u.deleted = 0)",
            ['org' => $organization_id, 'cls' => $class_number, 'uid' => $student_mdl_user_id]
        );

        foreach ($classmates as $cm) {
            self::add_contact($student_mdl_user_id, (int)$cm->mdl_user_id);
        }
    }

    /**
     * Полная синхронизация контактов учащегося:
     *   педагоги + тьюторы + одноклассники + родители (их) → учащийся.
     * Можно вызывать при создании пользователя или по запросу.
     */
    public static function full_sync_student(int $student_unics_id): void {
        global $DB;

        $student = $DB->get_record('unics_students', ['id' => $student_unics_id]);
        if (!$student) {
            return;
        }

        $mdl_uid = (int)$student->mdl_user_id;

        // Педагоги и тьюторы учащегося
        $teachers = $DB->get_records_sql(
            "SELECT t.mdl_user_id
               FROM {unics_teacher_student} ts
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.student_id = :sid",
            ['sid' => $student_unics_id]
        );
        foreach ($teachers as $t) {
            self::add_contact($mdl_uid, (int)$t->mdl_user_id);
        }

        // Родители
        $parents = $DB->get_records('unics_parent_student', ['student_id' => $student_unics_id], '', 'parent_mdl_user_id');
        foreach ($parents as $p) {
            self::add_contact($mdl_uid, (int)$p->parent_mdl_user_id);
        }

        // Одноклассники
        if ($student->organization_id && $student->class_number) {
            self::sync_classmates($mdl_uid, (int)$student->organization_id, (int)$student->class_number);
        }
    }
}
