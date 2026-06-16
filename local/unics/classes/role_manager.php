<?php
namespace local_unics;

defined('MOODLE_INTERNAL') || die();

/**
 * Применение матрицы прав ролей УНИКС.
 *
 * Содержит каноническую матрицу capability × роль и метод её применения.
 * Используется из:
 *   - pages/setup_roles.php (ручной запуск админом);
 *   - db/upgrade.php (автоприменение при апгрейде плагина — решение встречи 2026-05-20, Q6).
 *
 * Матрица — единый источник истины. Менять её только здесь.
 */
class role_manager {

    /**
     * Применяет матрицу прав ко всем ролям из get_matrix().
     * Безопасно вызывать многократно — assign_capability перезаписывает существующие назначения.
     *
     * @return array Результаты по ролям: [shortname => ['status' => 'ok'|'skip', 'msg' => ...]]
     */
    public static function apply_matrix(): array {
        global $DB;
        $ctx_sys = \context_system::instance();
        $results = [];

        foreach (self::get_matrix() as $shortname => $matrix) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id');
            if (!$role) {
                $results[$shortname] = ['status' => 'skip', 'msg' => 'роль не найдена в системе'];
                continue;
            }

            $cnt_allow   = 0;
            $cnt_prevent = 0;
            $cnt_inherit = 0;
            $cnt_miss    = 0;

            foreach ($matrix['allow'] ?? [] as $cap) {
                if (!get_capability_info($cap)) { $cnt_miss++; continue; }
                assign_capability($cap, CAP_ALLOW, $role->id, $ctx_sys->id, true);
                $cnt_allow++;
            }
            foreach ($matrix['prevent'] ?? [] as $cap) {
                if (!get_capability_info($cap)) { $cnt_miss++; continue; }
                assign_capability($cap, CAP_PREVENT, $role->id, $ctx_sys->id, true);
                $cnt_prevent++;
            }
            foreach ($matrix['prohibit'] ?? [] as $cap) {
                if (!get_capability_info($cap)) { $cnt_miss++; continue; }
                assign_capability($cap, CAP_PROHIBIT, $role->id, $ctx_sys->id, true);
            }
            foreach ($matrix['inherit'] ?? [] as $cap) {
                if (!get_capability_info($cap)) { $cnt_miss++; continue; }
                unassign_capability($cap, $role->id, $ctx_sys->id);
                $cnt_inherit++;
            }

            $results[$shortname] = [
                'status' => 'ok',
                'msg'    => "разрешено: {$cnt_allow}, запрещено: {$cnt_prevent}, сброшено: {$cnt_inherit}"
                          . ($cnt_miss ? ", не найдено capability: {$cnt_miss}" : ''),
            ];
        }

        return $results;
    }

    /**
     * Каноническая матрица прав. Ключ — shortname Moodle-роли.
     * Структура значения:
     *   'allow'    => [...] — явно выдать CAP_ALLOW
     *   'prevent'  => [...] — явно запретить CAP_PREVENT (можно переопределить выше)
     *   'prohibit' => [...] — жёсткий запрет CAP_PROHIBIT
     *   'inherit'  => [...] — снять явное переопределение
     */
    public static function get_matrix(): array {

        // ----------------------------------------------------------------
        // Общие наборы для повторного использования
        // ----------------------------------------------------------------
        $caps_course_edit = [
            'moodle/course:update',
            'moodle/course:manageactivities',
            'moodle/course:sectionvisibility',
            'moodle/course:activityvisibility',
            'moodle/course:movesections',
            'moodle/course:setcurrentsection',
            'moodle/course:managefiles',
            'moodle/course:managegroups',
            'moodle/course:managescales',
            'moodle/course:reset',
            'moodle/course:tag',
            'moodle/course:viewhiddenactivities',
            'moodle/course:viewhiddensections',
            'moodle/course:ignorefilesizelimits',
            'moodle/course:overridecompletion',
            'moodle/course:togglecompletion',
            'moodle/backup:backupcourse',
            'moodle/backup:backupsection',
            'moodle/backup:backupactivity',
            'moodle/backup:configure',
            'moodle/backup:downloadfile',
            'moodle/backup:userinfo',
            'moodle/restore:configure',
            'moodle/restore:restorecourse',
            'moodle/restore:restoresection',
            'moodle/restore:restoreactivity',
            'moodle/restore:uploadfile',
            'moodle/restore:userinfo',
        ];

        $caps_grading = [
            'moodle/grade:edit',
            'moodle/grade:hide',
            'moodle/grade:lock',
            'moodle/grade:unlock',
            'moodle/grade:import',
            'moodle/grade:manage',
            'moodle/grade:manageletters',
            'moodle/grade:manageoutcomes',
            'moodle/grade:viewhidden',
            'moodle/grade:export',
            'mod/assign:grade',
            'mod/assign:managegrades',
            'mod/assign:releasegrades',
            'mod/assign:reviewgrades',
            'mod/assign:grantextension',
            'mod/quiz:grade',
            'mod/quiz:regrade',
            'mod/quiz:deleteattempts',
            'mod/forum:grade',
            'mod/lesson:grade',
            'mod/workshop:overridegrades',
            'mod/data:approve',
        ];

        $caps_view_grades = [
            'moodle/grade:view',
            'moodle/grade:viewall',
            'gradereport/grader:view',
            'gradereport/user:view',
            'gradereport/overview:view',
            'gradereport/history:view',
            'gradereport/outcomes:view',
            'gradereport/singleview:view',
            'gradereport/summary:view',
        ];

        $caps_add_activities = [
            'mod/assign:addinstance',
            'mod/book:addinstance',
            'mod/choice:addinstance',
            'mod/data:addinstance',
            'mod/feedback:addinstance',
            'mod/folder:addinstance',
            'mod/forum:addinstance',
            'mod/glossary:addinstance',
            'mod/h5pactivity:addinstance',
            'mod/imscp:addinstance',
            'mod/label:addinstance',
            'mod/lesson:addinstance',
            'mod/lti:addinstance',
            'mod/page:addinstance',
            'mod/quiz:addinstance',
            'mod/resource:addinstance',
            'mod/scorm:addinstance',
            'mod/url:addinstance',
            'mod/wiki:addinstance',
            'mod/workshop:addinstance',
        ];

        $caps_reports_view = [
            'report/outline:view',
            'report/outline:viewuserreport',
            'report/participation:view',
            'report/progress:view',
            'report/completion:view',
            'report/log:view',
            'report/log:viewtoday',
            'moodle/site:viewreports',
        ];

        $caps_forum_participate = [
            'mod/forum:viewdiscussion',
            'mod/forum:replypost',
            'mod/forum:startdiscussion',
            'mod/forum:createattachment',
            'mod/forum:deleteownpost',
            'mod/forum:replynews',
            'mod/forum:exportownpost',
            'mod/forum:cantogglefavourite',
        ];

        $caps_messaging = [
            'moodle/site:sendmessage',
            'moodle/site:messageanyuser',
            'moodle/user:readuserblogs',
            'moodle/user:readuserposts',
        ];

        $caps_notes = [
            'moodle/notes:manage',
            'moodle/notes:view',
        ];

        $caps_view_participants = [
            'moodle/course:viewparticipants',
            'moodle/course:reviewotherusers',
            'moodle/course:bulkmessaging',
            'moodle/user:viewdetails',
            'moodle/user:viewalldetails',
            'moodle/user:viewlastip',
            'moodle/user:viewuseractivitiesreport',
            'moodle/site:viewfullnames',
            'moodle/site:viewuseridentity',
        ];

        $caps_common = [
            'moodle/course:view',
            'moodle/course:viewoverview',
            'moodle/course:viewscales',
            'moodle/course:downloadcoursecontent',
            'moodle/calendar:manageownentries',
            'moodle/calendar:manageentries',
            'moodle/calendar:managegroupentries',
            'moodle/comment:view',
            'moodle/comment:post',
            'moodle/comment:delete',
            'moodle/user:changeownpassword',
            'moodle/user:editownprofile',
            'moodle/user:editownmessageprofile',
            'moodle/user:manageownfiles',
            'moodle/portfolio:export',
            'moodle/search:query',
            'moodle/site:doclinks',
            'moodle/site:viewparticipants',
        ];

        $caps_content_tools = [
            'moodle/contentbank:access',
            'moodle/contentbank:upload',
            'moodle/contentbank:useeditor',
            'moodle/contentbank:manageowncontent',
            'moodle/contentbank:deleteowncontent',
            'moodle/contentbank:copycontent',
            'moodle/contentbank:downloadcontent',
            'moodle/h5p:deploy',
            'moodle/h5p:setdisplayoptions',
            'contenttype/h5p:access',
            'contenttype/h5p:upload',
            'contenttype/h5p:useeditor',
            'tiny/html:use',
            'tiny/link:use',
            'tiny/media:use',
            'tiny/equation:use',
            'tiny/h5p:use',
            'tiny/autosave:use',
            'tiny/recordrtc:use',
            'tiny/recordrtc:recordaudio',
            'tiny/recordrtc:recordvideo',
            'tiny/accessibilitychecker:use',
            'tiny/noautolink:use',
            'moodle/question:add',
            'moodle/question:editmine',
            'moodle/question:viewmine',
            'moodle/question:usemine',
            'moodle/question:tagmine',
            'moodle/question:movemine',
            'moodle/question:commentmine',
            'moodle/question:flag',
            'moodle/question:managecategory',
        ];

        $matrix = [

            // ------------------------------------------------------------
            // Региональный администратор (region_admin) — этап 3 пункта #11.
            // Скоуп = регион ИЛИ район через unics_user_org.region_id/district_id.
            // По правам аналогичен методисту: writes через local/unics:manageorg
            // + scope-check на каждой странице (target в моём регионе/районе?).
            // Создаёт/назначает методистов и педагогов в своём регионе/районе.
            // ------------------------------------------------------------
            'region_admin' => [
                'allow' => array_merge(
                    $caps_common, $caps_view_participants, $caps_messaging,
                    $caps_reports_view, $caps_view_grades,
                    [
                        // Главное: пишет в свой скоуп. Каждая страница, использующая
                        // manageorg, обязана проверять, что target входит в регион/район
                        // регион-админа (см. unics_user_org для текущего user'а).
                        'local/unics:manageorg',
                        'local/unics:viewstudents',
                        'moodle/course:view', 'moodle/course:viewhiddencourses',
                        'moodle/course:viewparticipants', 'moodle/course:enrolconfig',
                        'moodle/course:enrolreview', 'moodle/course:viewoverview',
                        'moodle/course:viewscales', 'moodle/course:viewsuspendedusers',
                        'moodle/category:viewcourselist',
                        'moodle/cohort:view', 'moodle/cohort:assign',
                        'enrol/manual:enrol', 'enrol/manual:manage', 'enrol/manual:unenrol',
                        'moodle/user:viewdetails', 'moodle/user:viewalldetails',
                        'moodle/user:viewuseractivitiesreport',
                        'moodle/site:viewfullnames', 'moodle/site:viewuseridentity',
                        'moodle/notes:view', 'moodle/notes:manage',
                        'moodle/badges:viewbadges', 'moodle/blog:view',
                        'moodle/site:accessallgroups',
                    ]
                ),
                'prevent' => array_merge(
                    $caps_grading, $caps_course_edit, $caps_add_activities,
                    [
                        'moodle/course:create', 'moodle/course:delete',
                        'moodle/site:config',
                        'moodle/user:create', 'moodle/user:delete',
                        'moodle/role:manage',
                    ]
                ),
            ],

            // ------------------------------------------------------------
            // Педагог (editingteacher) — создаёт контент, оценивает, управляет группой
            // ------------------------------------------------------------
            'editingteacher' => [
                'allow' => array_merge(
                    $caps_common, $caps_course_edit, $caps_grading, $caps_view_grades,
                    $caps_add_activities, $caps_reports_view, $caps_forum_participate,
                    $caps_messaging, $caps_notes, $caps_view_participants, $caps_content_tools,
                    [
                        // Наблюдение #8 (2026-05-28): «педагог, создающий курсы» (unics_role 5)
                        // создаёт курсы из шаблонов и при необходимости удаляет свои.
                        // Решение встречи 2026-05-20 (педагог НЕ создаёт курсы) пересмотрено.
                        'moodle/course:create', 'moodle/course:delete',
                        'local/unics:viewstudents',
                        'moodle/course:enrolconfig', 'moodle/course:enrolreview',
                        'moodle/course:markcomplete', 'moodle/course:renameroles',
                        'moodle/course:viewhiddenuserfields', 'moodle/course:viewsuspendedusers',
                        'moodle/course:viewhiddencourses', 'moodle/course:creategroupconversations',
                        'moodle/grade:managegradingforms',
                        'moodle/role:assign', 'moodle/role:review', 'moodle/role:safeoverride',
                        'moodle/badges:awardbadge', 'moodle/badges:configurecriteria',
                        'moodle/badges:configuredetails', 'moodle/badges:configuremessages',
                        'moodle/badges:createbadge', 'moodle/badges:viewbadges', 'moodle/badges:viewawarded',
                        'moodle/question:editall', 'moodle/question:viewall', 'moodle/question:useall',
                        'moodle/question:tagall', 'moodle/question:moveall', 'moodle/question:commentall',
                        'mod/forum:addnews', 'mod/forum:allowforcesubscribe', 'mod/forum:deleteanypost',
                        'mod/forum:editanypost', 'mod/forum:managesubscriptions', 'mod/forum:movediscussions',
                        'mod/forum:pindiscussions', 'mod/forum:postwithoutthrottling', 'mod/forum:splitdiscussions',
                        'mod/forum:viewhiddentimedposts', 'mod/forum:viewsubscribers',
                        'mod/forum:postprivatereply', 'mod/forum:readprivatereplies',
                        'mod/wiki:managewiki',
                        'mod/lesson:viewreports', 'mod/lesson:edit', 'mod/lesson:manage', 'mod/lesson:manageoverrides',
                        'mod/quiz:manage', 'mod/quiz:manageoverrides', 'mod/quiz:preview', 'mod/quiz:view', 'mod/quiz:viewreports',
                        'mod/assign:receivegradernotifications', 'mod/assign:manageallocations',
                        'mod/assign:manageoverrides', 'mod/assign:view', 'mod/assign:viewgrades',
                        'report/loglive:view', 'report/stats:view',
                        'enrol/manual:enrol', 'enrol/manual:manage', 'enrol/manual:unenrol',
                        'tool/recyclebin:viewitems', 'tool/recyclebin:restoreitems', 'tool/recyclebin:deleteitems',
                        'moodle/filter:manage',
                        'moodle/rating:rate', 'moodle/rating:view', 'moodle/rating:viewall', 'moodle/rating:viewany',
                        'gradereport/history:view',
                        'gradeexport/ods:view', 'gradeexport/xls:view', 'gradeexport/txt:view',
                        'gradeimport/csv:view', 'gradeimport/direct:view',
                        'moodle/blog:create', 'moodle/blog:view', 'moodle/blog:manageentries',
                        'moodle/cohort:view',
                        'block/activity_modules:addinstance', 'block/completionstatus:addinstance',
                        'block/feedback:addinstance', 'block/recent_activity:addinstance',
                        'block/recent_activity:viewaddupdatemodule', 'block/recent_activity:viewdeletemodule',
                        'moodle/site:manageblocks', 'moodle/block:edit', 'moodle/block:view',
                    ]
                ),
                'inherit' => ['moodle/site:accessallgroups'],
            ],

            // ------------------------------------------------------------
            // Методист (methodist) — создаёт курсы/шаблоны + пишет в свой скоуп (новое: manageorg)
            // ------------------------------------------------------------
            'methodist' => [
                'allow' => array_merge(
                    $caps_common, $caps_course_edit, $caps_add_activities, $caps_content_tools,
                    $caps_view_participants, $caps_messaging,
                    [
                        'local/unics:viewstudents',
                        // Новое (этап 1 пункта #11, встреча 2026-05-20):
                        // даёт право писать (создавать ученика, назначать педагога) в рамках
                        // скоупа методиста (район/орг через unics_user_org). Каждая страница,
                        // использующая manageorg, обязана проверять, что target входит в скоуп.
                        'local/unics:manageorg',
                        'moodle/course:create', 'moodle/course:delete',
                        'moodle/course:viewhiddenactivities', 'moodle/course:viewhiddensections',
                        'moodle/course:viewhiddencourses', 'moodle/course:viewhiddenuserfields',
                        'moodle/course:viewsuspendedusers', 'moodle/course:viewscales',
                        'moodle/course:enrolconfig', 'moodle/course:enrolreview',
                        'moodle/course:visibility', 'moodle/course:changefullname',
                        'moodle/course:changeshortname', 'moodle/course:changesummary',
                        'moodle/course:tag', 'moodle/course:renameroles',
                        'moodle/course:creategroupconversations', 'moodle/course:managegroups',
                        'moodle/category:viewcourselist', 'moodle/category:viewhiddencategories',
                        'moodle/backup:backupcourse', 'moodle/backup:backupsection',
                        'moodle/backup:backupactivity', 'moodle/backup:configure',
                        'moodle/backup:downloadfile', 'moodle/restore:configure',
                        'moodle/restore:restorecourse', 'moodle/restore:restoresection',
                        'moodle/restore:restoreactivity', 'moodle/restore:uploadfile',
                        'moodle/question:add', 'moodle/question:editall', 'moodle/question:viewall',
                        'moodle/question:useall', 'moodle/question:managecategory',
                        'moodle/grade:viewall', 'moodle/grade:view',
                        'gradereport/grader:view', 'gradereport/user:view', 'gradereport/overview:view',
                        'mod/forum:addnews', 'mod/forum:addinstance',
                        'mod/wiki:managewiki',
                        'tool/recyclebin:viewitems', 'tool/recyclebin:restoreitems', 'tool/recyclebin:deleteitems',
                        'moodle/filter:manage', 'moodle/site:manageblocks', 'moodle/block:edit', 'moodle/block:view',
                        'moodle/site:viewreports', 'report/outline:view', 'report/progress:view', 'report/completion:view',
                    ]
                ),
                'prevent' => array_merge(
                    $caps_grading,
                    [
                        'moodle/site:accessallgroups', 'moodle/site:config',
                        'moodle/user:create', 'moodle/user:delete', 'moodle/user:update',
                        'moodle/role:manage',
                    ]
                ),
            ],

            // ------------------------------------------------------------
            // Учащийся (student)
            // ------------------------------------------------------------
            'student' => [
                'allow' => array_merge(
                    $caps_common, $caps_forum_participate, $caps_messaging,
                    [
                        'moodle/grade:view',
                        'gradereport/overview:view', 'gradereport/user:view',
                        'mod/assign:submit', 'mod/assign:view',
                        'mod/assign:exportownsubmission', 'mod/assign:viewownsubmissionsummary',
                        'mod/quiz:attempt', 'mod/quiz:view', 'mod/quiz:reviewmyattempts',
                        'mod/choice:choose', 'mod/choice:view', 'mod/choice:readresponses',
                        'mod/forum:viewdiscussion', 'mod/forum:replypost', 'mod/forum:startdiscussion',
                        'mod/forum:createattachment', 'mod/forum:deleteownpost', 'mod/forum:exportownpost',
                        'mod/lesson:view',
                        'mod/resource:view', 'mod/page:view', 'mod/url:view',
                        'mod/book:read', 'mod/folder:view', 'mod/label:view',
                        'mod/scorm:savetrack', 'mod/scorm:skipview', 'mod/scorm:viewscores',
                        'mod/wiki:viewpage', 'mod/wiki:viewcomment', 'mod/wiki:createpage', 'mod/wiki:editpage',
                        'mod/h5pactivity:view', 'mod/h5pactivity:submit',
                        'mod/imscp:view',
                        'mod/feedback:complete', 'mod/feedback:view',
                        'mod/data:view', 'mod/data:writeentry', 'mod/data:viewentry',
                        'mod/glossary:view', 'mod/glossary:write', 'mod/glossary:comment',
                        'mod/workshop:submit', 'mod/workshop:peerassess', 'mod/workshop:view',
                        'moodle/rating:view', 'moodle/rating:rate',
                        'moodle/badges:earnbadge', 'moodle/badges:manageownbadges', 'moodle/badges:viewbadges',
                        'moodle/blog:create', 'moodle/blog:view',
                        'moodle/competency:planviewown', 'moodle/competency:usercompetencyview',
                        'moodle/portfolio:export',
                        'tiny/autosave:use', 'tiny/link:use', 'tiny/media:use',
                        'moodle/comment:view', 'moodle/comment:post',
                        'moodle/course:isincompletionreports',
                    ]
                ),
                'prevent' => [
                    'moodle/course:viewparticipants', 'moodle/course:manageactivities',
                    'moodle/course:update', 'moodle/course:managegroups',
                    'moodle/grade:viewall', 'moodle/site:accessallgroups', 'moodle/site:viewreports',
                ],
                'prohibit' => ['local/unics:manage', 'local/unics:manageorg', 'local/unics:viewstudents'],
            ],

            // ------------------------------------------------------------
            // Родитель (parent) — пока только отчёты, без доступа в курс ребёнка
            // ------------------------------------------------------------
            'parent' => [
                'allow' => array_merge(
                    $caps_messaging, $caps_view_grades, $caps_reports_view,
                    [
                        'moodle/course:view', 'moodle/course:viewoverview',
                        'moodle/site:viewfullnames',
                        'moodle/user:viewdetails', 'moodle/user:viewalldetails',
                        'moodle/user:viewuseractivitiesreport', 'moodle/user:readuserblogs',
                        'moodle/notes:view',
                        'mod/assign:view', 'mod/assign:viewgrades',
                        'mod/quiz:view', 'mod/quiz:reviewmyattempts',
                        'mod/resource:view', 'mod/page:view', 'mod/url:view',
                        'mod/book:read', 'mod/folder:view', 'mod/lesson:view',
                        'moodle/calendar:manageownentries',
                        'moodle/user:changeownpassword', 'moodle/user:editownprofile',
                        'moodle/blog:view', 'moodle/badges:viewbadges',
                    ]
                ),
                'prevent' => array_merge(
                    $caps_grading, $caps_course_edit, $caps_add_activities,
                    [
                        'moodle/course:managegroups', 'moodle/course:viewparticipants',
                        'moodle/site:accessallgroups',
                        'moodle/course:create', 'moodle/role:assign',
                        'enrol/manual:enrol',
                        'mod/forum:startdiscussion', 'mod/forum:replypost',
                    ]
                ),
                'prohibit' => ['local/unics:manage', 'local/unics:manageorg', 'local/unics:viewstudents'],
            ],

            // ------------------------------------------------------------
            // Администратор организации (org_admin)
            // ------------------------------------------------------------
            'org_admin' => [
                'allow' => array_merge(
                    $caps_common, $caps_view_participants, $caps_messaging,
                    $caps_reports_view, $caps_view_grades,
                    [
                        'local/unics:manage',
                        'moodle/course:view', 'moodle/course:viewhiddencourses',
                        'moodle/course:viewparticipants', 'moodle/course:enrolconfig',
                        'moodle/course:enrolreview', 'moodle/course:viewoverview',
                        'moodle/course:viewscales', 'moodle/course:viewsuspendedusers',
                        'moodle/category:viewcourselist',
                        'moodle/cohort:view', 'moodle/cohort:assign',
                        'enrol/manual:enrol', 'enrol/manual:manage', 'enrol/manual:unenrol',
                        'moodle/user:viewdetails', 'moodle/user:viewalldetails',
                        'moodle/user:viewuseractivitiesreport',
                        'moodle/site:viewfullnames', 'moodle/site:viewuseridentity',
                        'moodle/notes:view', 'moodle/notes:manage',
                        'moodle/badges:viewbadges', 'moodle/blog:view',
                        'moodle/site:accessallgroups',
                    ]
                ),
                'prevent' => array_merge(
                    $caps_grading, $caps_course_edit, $caps_add_activities,
                    [
                        'moodle/course:create', 'moodle/course:delete',
                        'moodle/site:config',
                        'moodle/user:create', 'moodle/user:delete',
                        'moodle/role:manage',
                    ]
                ),
            ],

            // ------------------------------------------------------------
            // Педагог (teacher, код 6) — non-editing: видит курс, оценивает,
            // НЕ редактирует контент и НЕ создаёт курсы. Стандартный архетип
            // teacher даёт базовый non-editing набор; здесь добавляем наш
            // local/unics:viewstudents (read-only на наших страницах) и явно
            // фиксируем запреты на редактирование/создание.
            // ------------------------------------------------------------
            'teacher' => [
                'allow' => array_merge(
                    $caps_common, $caps_view_grades, $caps_grading,
                    $caps_reports_view, $caps_forum_participate,
                    $caps_messaging, $caps_notes, $caps_view_participants,
                    [
                        'local/unics:viewstudents',
                        'moodle/course:view', 'moodle/course:viewparticipants',
                        'moodle/course:viewhiddencourses', 'moodle/course:viewsuspendedusers',
                        'moodle/grade:view', 'moodle/grade:viewall',
                        'mod/assign:view', 'mod/assign:viewgrades',
                        'mod/quiz:view', 'mod/quiz:viewreports',
                        'moodle/badges:viewbadges', 'moodle/badges:awardbadge',
                        'moodle/blog:view',
                    ]
                ),
                'prevent' => array_merge(
                    $caps_course_edit, $caps_add_activities,
                    [
                        'moodle/course:create', 'moodle/course:delete',
                        'moodle/course:update', 'moodle/course:manageactivities',
                        'moodle/role:assign', 'moodle/role:manage',
                        'moodle/user:create', 'moodle/user:delete',
                        'moodle/site:config',
                    ]
                ),
                'prohibit' => ['local/unics:manage', 'local/unics:manageorg'],
            ],
        ];

        // Районный методист — копия методиста организации по правам.
        // Разница только в скоупе (район), который хранится в unics_user_org,
        // а не в матрице capabilities. Поэтому переиспользуем готовый набор:
        //   district_methodist ≈ methodist (создаёт курсы/УМК + manageorg).
        // Муниципальный администратор (district_admin) удалён в v3 [[role-model-v3-2026-06-11]] —
        // его функции перешли муниципальному методисту.
        $matrix['district_methodist'] = $matrix['methodist'];

        // Региональный методист (region_methodist) — v3 фаза 2 [[role-model-v3-2026-06-11]].
        // Права идентичны региональному администратору (manageorg по региону, запись
        // учеников/педагогов на курсы через enrol, отчёты, просмотр оценок; БЕЗ создания
        // курсов и site:config). Отличие region_admin/region_methodist — организационное
        // (admin = техобслуживание, methodist = распределение курсов/сертификаты/отчётность)
        // и в меню; уникальная власть методиста (делегирование курсов) добавится в фазе 3.
        // Скоуп = регион (unics_user_org.region_id), как у region_admin.
        $matrix['region_methodist'] = $matrix['region_admin'];

        return $matrix;
    }

    /**
     * Канонические отображаемые имена ролей (mdl_role.name).
     * Без локализации — пользователь работает на русском, system-роли названы
     * по нашей терминологии. Наблюдение #13 (2026-05-28).
     *
     * @return array shortname => display name
     */
    public static function get_role_names(): array {
        return [
            'editingteacher'     => 'Педагог (создающий курсы)',
            'teacher'            => 'Педагог',
            'methodist'          => 'Методист организации',
            'district_methodist' => 'Муниципальный методист',
            'region_methodist'   => 'Региональный методист',
            'region_admin'       => 'Региональный администратор',
            'student'            => 'Учащийся',
            'parent'             => 'Родитель',
        ];
    }

    /**
     * Применяет канонические имена ролей. Безопасно вызывать многократно.
     */
    public static function apply_role_names(): void {
        global $DB;
        foreach (self::get_role_names() as $shortname => $name) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id, name');
            if ($role && $role->name !== $name) {
                $DB->set_field('role', 'name', $name, ['id' => $role->id]);
            }
        }
    }
}
