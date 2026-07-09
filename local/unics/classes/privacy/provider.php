<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy API провайдер плагина УНИКС.
 *
 * Плагин хранит персональные данные особой категории (сведения о здоровье детей:
 * категория, вид ОВЗ, особые потребности), поэтому провайдер обязателен (152-ФЗ,
 * стандарт Moodle). Данные субъекта отчитываются в его пользовательском контексте.
 *
 * @package    local_unics
 * @copyright  2026 УНИКС
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unics\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /** Таблицы, привязанные к пользователю напрямую: таблица => колонка с id пользователя. */
    private const DIRECT_TABLES = [
        'unics_user_org'           => 'mdl_user_id',
        'unics_students'           => 'mdl_user_id',
        'unics_teachers'           => 'mdl_user_id',
        'unics_retakes'            => 'mdl_user_id',
        'unics_topic_retries'      => 'mdl_user_id',
        'unics_notifications'      => 'mdl_user_id',
        'unics_comment_seen'       => 'mdl_user_id',
        'unics_level_history'      => 'mdl_user_id',
        'unics_stats_student_course' => 'mdl_user_id',
        'unics_parent_student'     => 'parent_mdl_user_id',
        'unics_comments'           => 'teacher_mdl_user_id',
        'unics_audit_log'          => 'mdl_user_id',
    ];

    // Состав субъектных таблиц и анонимизируемых авторских полей - в \local_unics\cleanup
    // (единая реализация удаления для privacy-запросов и события user_deleted).

    // ----------------------------------------------------------------- metadata.

    public static function get_metadata(collection $collection): collection {
        $u = 'privacy:metadata:field:userid';
        $s = 'privacy:metadata:field:student';
        $t = 'privacy:metadata:field:teacher';
        $time = 'privacy:metadata:field:time';

        $collection->add_database_table('unics_user_org', [
            'mdl_user_id' => $u,
            'unics_role' => 'privacy:metadata:unics_user_org:unics_role',
            'organization_id' => 'privacy:metadata:unics_user_org:organization_id',
        ], 'privacy:metadata:unics_user_org');

        $collection->add_database_table('unics_students', [
            'mdl_user_id' => $u,
            'diagnosed' => 'privacy:metadata:unics_students:diagnosed',
            'special_needs' => 'privacy:metadata:unics_students:special_needs',
            'birth_date' => 'privacy:metadata:unics_students:birth_date',
            'class_number' => 'privacy:metadata:unics_students:class_number',
            'difficulty_level' => 'privacy:metadata:unics_students:difficulty_level',
            'points' => 'privacy:metadata:unics_students:points',
        ], 'privacy:metadata:unics_students');

        // Нормализованные таблицы категорий/ОВЗ (этап 2.6): та же особая категория
        // ПДн, что и CSV-поля выше - декларируем и выгружаем наравне с ними.
        $collection->add_database_table('unics_student_category', [
            'student_id' => $s,
            'category' => 'privacy:metadata:unics_students:category',
        ], 'privacy:metadata:unics_student_category');
        $collection->add_database_table('unics_student_ovz', [
            'student_id' => $s,
            'ovz_type' => 'privacy:metadata:unics_students:ovz_type',
        ], 'privacy:metadata:unics_student_ovz');

        $collection->add_database_table('unics_teachers', [
            'mdl_user_id' => $u,
            'subjects' => 'privacy:metadata:unics_teachers:subjects',
            'qualification' => 'privacy:metadata:unics_teachers:qualification',
        ], 'privacy:metadata:unics_teachers');

        $collection->add_database_table('unics_teacher_student', [
            'teacher_id' => $t, 'student_id' => $s,
            'assigned_by' => 'privacy:metadata:field:createdby',
        ], 'privacy:metadata:unics_teacher_student');

        $collection->add_database_table('unics_parent_student', [
            'parent_mdl_user_id' => $u, 'student_id' => $s,
        ], 'privacy:metadata:unics_parent_student');

        $collection->add_database_table('unics_teacher_subject', [
            'teacher_id' => $t,
        ], 'privacy:metadata:unics_teacher_subject');

        $collection->add_database_table('unics_retakes', [
            'mdl_user_id' => $u, 'grade' => 'privacy:metadata:field:grade',
            'timecreated' => $time,
        ], 'privacy:metadata:unics_retakes');

        $collection->add_database_table('unics_topic_retries', [
            'mdl_user_id' => $u, 'grade' => 'privacy:metadata:field:grade',
            'timecreated' => $time,
        ], 'privacy:metadata:unics_topic_retries');

        $collection->add_database_table('unics_level_history', [
            'student_id' => $s, 'old_level' => 'privacy:metadata:unics_level_history:old_level',
            'new_level' => 'privacy:metadata:unics_level_history:new_level',
            'avg_score' => 'privacy:metadata:field:grade', 'changed_at' => $time,
        ], 'privacy:metadata:unics_level_history');

        $collection->add_database_table('unics_learning_path', [
            'student_id' => $s, 'goal' => 'privacy:metadata:unics_learning_path:goal',
            'created_by_mdl_user_id' => 'privacy:metadata:field:createdby',
        ], 'privacy:metadata:unics_learning_path');

        $collection->add_database_table('unics_skill_mastery', [
            'student_id' => $s, 'score' => 'privacy:metadata:unics_skill_mastery:score',
            'theta' => 'privacy:metadata:unics_skill_mastery:theta',
        ], 'privacy:metadata:unics_skill_mastery');

        $collection->add_database_table('unics_mastery_history', [
            'student_id' => $s,
        ], 'privacy:metadata:unics_mastery_history');

        $collection->add_database_table('unics_adaptive_suggestion', [
            'student_id' => $s, 'kind' => 'privacy:metadata:unics_adaptive_suggestion:kind',
            'decided_by' => 'privacy:metadata:field:createdby',
        ], 'privacy:metadata:unics_adaptive_suggestion');

        $collection->add_database_table('unics_cat_session', [
            'student_id' => $s, 'theta' => 'privacy:metadata:unics_skill_mastery:theta',
        ], 'privacy:metadata:unics_cat_session');

        $collection->add_database_table('unics_points_log', [
            'student_id' => $s, 'points' => 'privacy:metadata:unics_points_log:points',
            'reason_text' => 'privacy:metadata:unics_points_log:reason_text',
            'created_at' => $time,
        ], 'privacy:metadata:unics_points_log');

        $collection->add_database_table('unics_achievements', [
            'student_id' => $s, 'badge_type' => 'privacy:metadata:unics_achievements:badge_type',
            'awarded_by' => 'privacy:metadata:field:createdby',
        ], 'privacy:metadata:unics_achievements');

        $collection->add_database_table('unics_purchases', [
            'student_id' => $s, 'item_id' => 'privacy:metadata:unics_purchases:item_id',
            'purchased_at' => $time,
        ], 'privacy:metadata:unics_purchases');

        $collection->add_database_table('unics_umk_students', [
            'student_id' => $s,
        ], 'privacy:metadata:unics_umk_students');

        $collection->add_database_table('unics_comments', [
            'student_id' => $s, 'teacher_mdl_user_id' => $u,
            'body' => 'privacy:metadata:unics_comments:body',
        ], 'privacy:metadata:unics_comments');

        $collection->add_database_table('unics_comment_seen', [
            'student_id' => $s, 'mdl_user_id' => $u, 'last_seen_at' => $time,
        ], 'privacy:metadata:unics_comment_seen');

        $collection->add_database_table('unics_notifications', [
            'mdl_user_id' => $u, 'subject' => 'privacy:metadata:unics_notifications:subject',
            'body' => 'privacy:metadata:unics_notifications:body',
        ], 'privacy:metadata:unics_notifications');

        $collection->add_database_table('unics_stats_student_course', [
            'student_id' => $s, 'avg_score_pct' => 'privacy:metadata:field:grade',
            'last_active_at' => $time,
        ], 'privacy:metadata:unics_stats_student_course');

        $collection->add_database_table('unics_reports', [
            'student_id' => $s, 'teacher_id' => $t,
        ], 'privacy:metadata:unics_reports');

        $collection->add_database_table('unics_audit_log', [
            'mdl_user_id' => $u, 'action' => 'privacy:metadata:unics_audit_log:action',
            'ip_address' => 'privacy:metadata:unics_audit_log:ip_address',
            'changed_at' => $time,
        ], 'privacy:metadata:unics_audit_log');

        $collection->add_database_table('unics_ai_queue', [
            'student_ids' => 'privacy:metadata:unics_ai_queue:student_ids',
        ], 'privacy:metadata:unics_ai_queue');

        // Внешние сервисы: что уходит наружу при генерации/озвучке.
        $collection->add_external_location_link('gigachat', [
            'profile' => 'privacy:metadata:gigachat:profile',
            'texts' => 'privacy:metadata:gigachat:texts',
        ], 'privacy:metadata:gigachat');
        $collection->add_external_location_link('salutespeech', [
            'texts' => 'privacy:metadata:salutespeech:texts',
        ], 'privacy:metadata:salutespeech');

        return $collection;
    }

    // ------------------------------------------------------------ вспомогательные.

    /** id профиля обучающегося для пользователя (или null). */
    private static function student_id(int $userid): ?int {
        global $DB;
        $id = $DB->get_field('unics_students', 'id', ['mdl_user_id' => $userid]);
        return $id ? (int)$id : null;
    }

    /** id профиля педагога для пользователя (или null). */
    private static function teacher_id(int $userid): ?int {
        global $DB;
        $id = $DB->get_field('unics_teachers', 'id', ['mdl_user_id' => $userid]);
        return $id ? (int)$id : null;
    }

    /** Есть ли у пользователя данные в плагине. */
    private static function has_data(int $userid): bool {
        global $DB;
        foreach (self::DIRECT_TABLES as $table => $col) {
            if ($DB->record_exists($table, [$col => $userid])) {
                return true;
            }
        }
        return false;
    }

    // ----------------------------------------------------------------- contexts.

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        if (self::has_data((int)$context->instanceid)) {
            $userlist->add_user((int)$context->instanceid);
        }
    }

    // ------------------------------------------------------------------- export.

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        $context = null;
        foreach ($contextlist->get_contexts() as $ctx) {
            if ($ctx instanceof \context_user && (int)$ctx->instanceid === $userid) {
                $context = $ctx;
                break;
            }
        }
        if ($context === null) {
            return;
        }

        $sid = self::student_id($userid);
        $tid = self::teacher_id($userid);
        $root = get_string('pluginname', 'local_unics');

        // Группа: [подконтекст, таблица, условия выборки].
        $sets = [
            ['profile', 'unics_user_org', ['mdl_user_id' => $userid]],
            ['profile', 'unics_students', ['mdl_user_id' => $userid]],
            ['profile', 'unics_teachers', ['mdl_user_id' => $userid]],
            ['links', 'unics_parent_student', ['parent_mdl_user_id' => $userid]],
            ['learning', 'unics_retakes', ['mdl_user_id' => $userid]],
            ['learning', 'unics_topic_retries', ['mdl_user_id' => $userid]],
            ['communication', 'unics_comments', ['teacher_mdl_user_id' => $userid]],
            ['communication', 'unics_notifications', ['mdl_user_id' => $userid]],
            ['audit', 'unics_audit_log', ['mdl_user_id' => $userid]],
        ];
        if ($sid !== null) {
            $sets = array_merge($sets, [
                ['profile', 'unics_student_category', ['student_id' => $sid]],
                ['profile', 'unics_student_ovz', ['student_id' => $sid]],
                ['links', 'unics_teacher_student', ['student_id' => $sid]],
                ['links', 'unics_parent_student', ['student_id' => $sid]],
                ['learning', 'unics_level_history', ['student_id' => $sid]],
                ['learning', 'unics_learning_path', ['student_id' => $sid]],
                ['learning', 'unics_skill_mastery', ['student_id' => $sid]],
                ['learning', 'unics_mastery_history', ['student_id' => $sid]],
                ['learning', 'unics_adaptive_suggestion', ['student_id' => $sid]],
                ['learning', 'unics_cat_session', ['student_id' => $sid]],
                ['learning', 'unics_umk_students', ['student_id' => $sid]],
                ['learning', 'unics_stats_student_course', ['student_id' => $sid]],
                ['learning', 'unics_reports', ['student_id' => $sid]],
                ['motivation', 'unics_points_log', ['student_id' => $sid]],
                ['motivation', 'unics_achievements', ['student_id' => $sid]],
                ['motivation', 'unics_purchases', ['student_id' => $sid]],
                ['communication', 'unics_comments', ['student_id' => $sid]],
            ]);
        }
        if ($tid !== null) {
            $sets = array_merge($sets, [
                ['links', 'unics_teacher_student', ['teacher_id' => $tid]],
                ['profile', 'unics_teacher_subject', ['teacher_id' => $tid]],
                ['learning', 'unics_reports', ['teacher_id' => $tid]],
            ]);
        }

        // Свод по подконтекстам: подконтекст -> таблица -> строки (дедуп по id).
        $bucket = [];
        foreach ($sets as [$group, $table, $conditions]) {
            $rows = $DB->get_records($table, $conditions);
            foreach ($rows as $row) {
                $bucket[$group][$table][(int)$row->id] = $row;
            }
        }
        // Дочерние таблицы: шаги маршрута и шаги CAT-сессий.
        if (!empty($bucket['learning']['unics_learning_path'])) {
            $pathids = array_keys($bucket['learning']['unics_learning_path']);
            [$insql, $params] = $DB->get_in_or_equal($pathids);
            foreach ($DB->get_records_select('unics_path_step', "path_id $insql", $params) as $row) {
                $bucket['learning']['unics_path_step'][(int)$row->id] = $row;
            }
        }
        if (!empty($bucket['learning']['unics_cat_session'])) {
            $sessionids = array_keys($bucket['learning']['unics_cat_session']);
            [$insql, $params] = $DB->get_in_or_equal($sessionids);
            foreach ($DB->get_records_select('unics_cat_step', "session_id $insql", $params) as $row) {
                $bucket['learning']['unics_cat_step'][(int)$row->id] = $row;
            }
        }

        foreach ($bucket as $group => $tables) {
            foreach ($tables as $table => $rows) {
                writer::with_context($context)->export_data(
                    [$root, get_string('privacy:export:' . $group, 'local_unics'), $table],
                    (object)['records' => array_values($rows)]
                );
            }
        }
    }

    // ------------------------------------------------------------------- delete.

    /**
     * Удаление данных одного пользователя. Единая реализация - в \local_unics\cleanup
     * (переиспользуется наблюдателем user_deleted): субъектные строки удаляются,
     * авторские поля и журнал аудита анонимизируются.
     */
    private static function purge_user(int $userid): void {
        \local_unics\cleanup::purge_user_data($userid);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $ctx) {
            if ($ctx instanceof \context_user && (int)$ctx->instanceid === $userid) {
                self::purge_user($userid);
                return;
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            self::purge_user((int)$context->instanceid);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ((int)$userid === (int)$context->instanceid) {
                self::purge_user((int)$userid);
            }
        }
    }
}
