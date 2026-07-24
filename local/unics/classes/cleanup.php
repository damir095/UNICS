<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Каскадная зачистка данных unics_* при удалении сущностей ядра (этап 1.2 аудита).
 *
 * FK в Moodle XMLDB декларативны (constraints в БД не создаются), поэтому целостность
 * при удалении курса/модуля/пользователя обеспечивается только кодом. Единая точка:
 * observer делегирует сюда, privacy-провайдер переиспользует purge_user_data().
 */
class cleanup {

    /**
     * Удаление модуля курса: метаданные активности, привязки кодификатора к активности,
     * материалы УМК, пересдачи и возвраты к теме этого модуля.
     */
    public static function course_module_deleted(int $cmid): void {
        global $DB;
        \local_unics\learning\activity_meta::delete($cmid);
        $DB->delete_records('unics_codifier_link',
            ['target_type' => codifier_link_manager::TYPE_ACTIVITY, 'target_id' => $cmid]);
        $DB->delete_records('unics_umk_materials', ['mdl_course_module_id' => $cmid]);
        $DB->delete_records('unics_retakes', ['cmid' => $cmid]);
        $DB->delete_records('unics_topic_retries', ['cmid' => $cmid]);
    }

    /**
     * Удаление курса. delete_course удаляет модули bulk-путем БЕЗ course_module_deleted
     * на каждый, поэтому cm-привязанные таблицы подметаются здесь орфан-свипом.
     */
    public static function course_deleted(int $courseid): void {
        global $DB;

        // Цепочка УМК: umk -> materials / ai_queue / umk_students / шаги маршрутов по umk_id.
        $umkids = $DB->get_fieldset_select('unics_umk', 'id', 'mdl_course_id = ?', [$courseid]);
        if ($umkids) {
            [$insql, $params] = $DB->get_in_or_equal($umkids);
            $DB->delete_records_select('unics_umk_materials', "umk_id $insql", $params);
            $DB->delete_records_select('unics_ai_queue', "umk_id $insql", $params);
            $DB->delete_records_select('unics_umk_students', "umk_id $insql", $params);
            $DB->set_field_select('unics_path_step', 'umk_id', null, "umk_id $insql", $params);
            $DB->delete_records_select('unics_umk', "id $insql", $params);
        }

        // Таблицы с прямой ссылкой на курс.
        $DB->delete_records('unics_retakes', ['mdl_course_id' => $courseid]);
        $DB->delete_records('unics_topic_retries', ['mdl_course_id' => $courseid]);
        $DB->delete_records('unics_stats_student_course', ['mdl_course_id' => $courseid]);
        $DB->delete_records('unics_reports', ['mdl_course_id' => $courseid]);
        $DB->delete_records('unics_course_delegation', ['courseid' => $courseid]);
        $DB->delete_records('unics_path_step', ['mdl_course_id' => $courseid]);

        // Орфан-свипы cm-привязанных таблиц (модули уже удалены bulk-путем).
        $DB->execute(
            "DELETE FROM {unics_activity_meta}
              WHERE cmid NOT IN (SELECT id FROM {course_modules})"
        );
        $DB->execute(
            "DELETE FROM {unics_codifier_link}
              WHERE target_type = ?
                AND target_id NOT IN (SELECT id FROM {course_modules})",
            [codifier_link_manager::TYPE_ACTIVITY]
        );
        $DB->execute(
            "DELETE FROM {unics_umk_materials}
              WHERE mdl_course_module_id NOT IN (SELECT id FROM {course_modules})"
        );
    }

    /**
     * Полная зачистка данных пользователя (субъектные строки - delete, авторские
     * поля и журнал аудита - анонимизация). Используется наблюдателем user_deleted
     * и privacy-провайдером (delete_data_for_user).
     */
    public static function purge_user_data(int $userid): void {
        global $DB;

        $sid = $DB->get_field('unics_students', 'id', ['mdl_user_id' => $userid]);
        $tid = $DB->get_field('unics_teachers', 'id', ['mdl_user_id' => $userid]);

        if ($sid) {
            $sid = (int)$sid;
            // Дочерние - раньше родителей.
            $pathids = $DB->get_fieldset_select('unics_learning_path', 'id', 'student_id = ?', [$sid]);
            if ($pathids) {
                [$insql, $params] = $DB->get_in_or_equal($pathids);
                $DB->delete_records_select('unics_path_step', "path_id $insql", $params);
            }
            $sessionids = $DB->get_fieldset_select('unics_cat_session', 'id', 'student_id = ?', [$sid]);
            if ($sessionids) {
                [$insql, $params] = $DB->get_in_or_equal($sessionids);
                $DB->delete_records_select('unics_cat_step', "session_id $insql", $params);
            }
            foreach (['unics_teacher_student', 'unics_parent_student', 'unics_umk_students',
                'unics_achievements', 'unics_points_log', 'unics_purchases', 'unics_equipped',
                'unics_learning_path', 'unics_skill_mastery', 'unics_mastery_history',
                'unics_adaptive_suggestion', 'unics_cat_session', 'unics_reports',
                'unics_comments', 'unics_comment_seen', 'unics_level_history',
                'unics_stats_student_course',
                'unics_student_category', 'unics_student_ovz'] as $table) {
                $DB->delete_records($table, ['student_id' => $sid]);
            }
        }
        if ($tid) {
            $tid = (int)$tid;
            foreach (['unics_teacher_subject', 'unics_teacher_student', 'unics_reports'] as $table) {
                $DB->delete_records($table, ['teacher_id' => $tid]);
            }
        }

        // Прямые субъектные строки.
        foreach (['unics_user_org' => 'mdl_user_id', 'unics_students' => 'mdl_user_id',
            'unics_teachers' => 'mdl_user_id', 'unics_retakes' => 'mdl_user_id',
            'unics_topic_retries' => 'mdl_user_id', 'unics_notifications' => 'mdl_user_id',
            'unics_comment_seen' => 'mdl_user_id', 'unics_level_history' => 'mdl_user_id',
            'unics_stats_student_course' => 'mdl_user_id',
            'unics_parent_student' => 'parent_mdl_user_id',
            'unics_comments' => 'teacher_mdl_user_id'] as $table => $col) {
            $DB->delete_records($table, [$col => $userid]);
        }

        // Анонимизация: журнал аудита и поля «кто сделал» - записи целы, субъект исчезает.
        $DB->set_field('unics_audit_log', 'mdl_user_id', 0, ['mdl_user_id' => $userid]);
        foreach (['unics_teacher_student' => ['assigned_by'],
            'unics_learning_path' => ['created_by_mdl_user_id'],
            'unics_achievements' => ['awarded_by'],
            'unics_adaptive_suggestion' => ['decided_by'],
            'unics_course_delegation' => ['usercreated'],
            'unics_codifier' => ['created_by_mdl_user_id'],
            'unics_codifier_link' => ['created_by_mdl_user_id']] as $table => $fields) {
            foreach ($fields as $field) {
                $DB->set_field($table, $field, 0, [$field => $userid]);
            }
        }
    }
}
