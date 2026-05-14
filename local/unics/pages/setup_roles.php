<?php
/**
 * Настройка прав ролей УНИКС.
 * Применяет матрицу прав для каждой роли системы.
 */
require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/setup_roles.php'));
$PAGE->set_title('Настройка прав ролей - УНИКС');
$PAGE->set_heading('Настройка прав ролей УНИКС');
$PAGE->set_pagelayout('admin');

// ================================================================
// Матрица прав по ролям
// Ключи: shortname роли Moodle
// Значения:
//   'allow'   => [...] - явно выдать CAP_ALLOW
//   'prevent' => [...] - явно запретить CAP_PREVENT (можно переопределить выше)
//   'prohibit'=> [...] - жёсткий запрет CAP_PROHIBIT
//   'inherit' => [...] - снять явное переопределение (вернуть к архетипу)
// ================================================================

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
    'moodle/course:manageactivities',
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

// ================================================================
// МАТРИЦА ПРАВ ПО РОЛЯМ
// ================================================================
$role_matrix = [

    // ------------------------------------------------------------
    // Педагог (editingteacher)
    // Создаёт контент, оценивает, управляет группой, ведёт курс
    // ------------------------------------------------------------
    'editingteacher' => [
        'allow' => array_merge(
            $caps_common,
            $caps_course_edit,
            $caps_grading,
            $caps_view_grades,
            $caps_add_activities,
            $caps_reports_view,
            $caps_forum_participate,
            $caps_messaging,
            $caps_notes,
            $caps_view_participants,
            $caps_content_tools,
            [
                'local/unics:viewstudents',
                'moodle/course:enrolconfig',
                'moodle/course:enrolreview',
                'moodle/course:markcomplete',
                'moodle/course:renameroles',
                'moodle/course:viewhiddenuserfields',
                'moodle/course:viewsuspendedusers',
                'moodle/course:viewhiddencourses',
                'moodle/course:creategroupconversations',
                'moodle/grade:managegradingforms',
                'moodle/role:assign',
                'moodle/role:review',
                'moodle/role:safeoverride',
                'moodle/badges:awardbadge',
                'moodle/badges:configurecriteria',
                'moodle/badges:configuredetails',
                'moodle/badges:configuremessages',
                'moodle/badges:createbadge',
                'moodle/badges:viewbadges',
                'moodle/badges:viewawarded',
                'moodle/question:editall',
                'moodle/question:viewall',
                'moodle/question:useall',
                'moodle/question:tagall',
                'moodle/question:moveall',
                'moodle/question:commentall',
                'mod/forum:addnews',
                'mod/forum:allowforcesubscribe',
                'mod/forum:deleteanypost',
                'mod/forum:editanypost',
                'mod/forum:managesubscriptions',
                'mod/forum:movediscussions',
                'mod/forum:pindiscussions',
                'mod/forum:postwithoutthrottling',
                'mod/forum:splitdiscussions',
                'mod/forum:viewhiddentimedposts',
                'mod/forum:viewsubscribers',
                'mod/forum:postprivatereply',
                'mod/forum:readprivatereplies',
                'mod/wiki:managewiki',
                'mod/lesson:viewreports',
                'mod/lesson:edit',
                'mod/lesson:manage',
                'mod/lesson:manageoverrides',
                'mod/quiz:manage',
                'mod/quiz:manageoverrides',
                'mod/quiz:preview',
                'mod/quiz:view',
                'mod/quiz:viewreports',
                'mod/assign:receivegradernotifications',
                'mod/assign:manageallocations',
                'mod/assign:manageoverrides',
                'mod/assign:view',
                'mod/assign:viewgrades',
                'report/loglive:view',
                'report/stats:view',
                'enrol/manual:enrol',
                'enrol/manual:manage',
                'enrol/manual:unenrol',
                'tool/recyclebin:viewitems',
                'tool/recyclebin:restoreitems',
                'tool/recyclebin:deleteitems',
                'moodle/filter:manage',
                'moodle/rating:rate',
                'moodle/rating:view',
                'moodle/rating:viewall',
                'moodle/rating:viewany',
                'gradereport/history:view',
                'gradeexport/ods:view',
                'gradeexport/xls:view',
                'gradeexport/txt:view',
                'gradeimport/csv:view',
                'gradeimport/direct:view',
                'moodle/blog:create',
                'moodle/blog:view',
                'moodle/blog:manageentries',
                'moodle/cohort:view',
                'block/activity_modules:addinstance',
                'block/completionstatus:addinstance',
                'block/feedback:addinstance',
                'block/recent_activity:addinstance',
                'block/recent_activity:viewaddupdatemodule',
                'block/recent_activity:viewdeletemodule',
                'moodle/site:manageblocks',
                'moodle/block:edit',
                'moodle/block:view',
            ]
        ),
        // accessallgroups снимается - управляется переопределением на уровне курса
        'inherit' => [
            'moodle/site:accessallgroups',
        ],
    ],

    // ------------------------------------------------------------
    // Тьютор (teacher - non-editing)
    // Сопровождает, наблюдает, общается, но не оценивает и не редактирует
    // ------------------------------------------------------------
    'teacher' => [
        'allow' => array_merge(
            $caps_common,
            $caps_view_grades,       // видит оценки, но не выставляет
            $caps_reports_view,
            $caps_forum_participate,
            $caps_messaging,
            $caps_notes,
            $caps_view_participants,
            [
                'local/unics:viewstudents',
                'moodle/course:viewhiddenactivities',
                'moodle/course:viewhiddensections',
                'moodle/course:viewhiddenuserfields',
                'moodle/course:viewsuspendedusers',
                'moodle/course:isincompletionreports',
                'moodle/competency:coursecompetencyview',
                'moodle/competency:usercompetencyview',
                'mod/assign:view',
                'mod/assign:viewgrades',          // видит оценки, не выставляет
                'mod/assign:viewownsubmissionsummary',
                'mod/quiz:view',
                'mod/quiz:reviewmyattempts',
                'mod/forum:viewdiscussion',
                'mod/forum:viewsubscribers',
                'mod/forum:viewhiddentimedposts',
                'mod/forum:readprivatereplies',
                'mod/lesson:view',
                'mod/lesson:viewreports',         // просматривает прогресс
                'mod/resource:view',
                'mod/page:view',
                'mod/url:view',
                'mod/book:read',
                'mod/folder:view',
                'mod/label:view',
                'mod/scorm:savetrack',
                'mod/scorm:viewscores',
                'mod/wiki:viewpage',
                'mod/wiki:viewcomment',
                'mod/wiki:createpage',
                'mod/wiki:editpage',
                'mod/h5pactivity:view',
                'mod/h5pactivity:submit',
                'mod/imscp:view',
                'moodle/rating:view',
                'moodle/rating:viewall',
                'moodle/rating:viewany',
                'moodle/badges:viewbadges',
                'moodle/blog:create',
                'moodle/blog:view',
                'moodle/site:viewfullnames',
                'moodle/site:viewuseridentity',
                'moodle/block:view',
            ]
        ),
        // Явно запрещаем оценивание - не должен иметь возможности даже случайно
        'prevent' => array_merge(
            $caps_grading,
            $caps_course_edit,
            $caps_add_activities,
            [
                'moodle/course:managegroups',
                'moodle/course:reset',
                'moodle/course:update',
                'moodle/site:accessallgroups',
                'moodle/course:create',
                'moodle/role:assign',
                'enrol/manual:enrol',
                'enrol/manual:unenrol',
            ]
        ),
    ],

    // ------------------------------------------------------------
    // Методист (methodist)
    // Создаёт и настраивает курсы, шаблоны; не ведёт занятия, не оценивает
    // ------------------------------------------------------------
    'methodist' => [
        'allow' => array_merge(
            $caps_common,
            $caps_course_edit,
            $caps_add_activities,
            $caps_content_tools,
            $caps_view_participants,
            $caps_messaging,
            [
                // УНИКС: методист видит учащихся (для генерации УМК), но не управляет
                // пользователями/орг (это manage). Cross-1, см. [[ux-review-by-role]].
                'local/unics:viewstudents',
                'moodle/course:create',
                'moodle/course:delete',
                'moodle/course:viewhiddenactivities',
                'moodle/course:viewhiddensections',
                'moodle/course:viewhiddencourses',
                'moodle/course:viewhiddenuserfields',
                'moodle/course:viewsuspendedusers',
                'moodle/course:viewscales',
                'moodle/course:enrolconfig',
                'moodle/course:enrolreview',
                'moodle/course:visibility',
                'moodle/course:changefullname',
                'moodle/course:changeshortname',
                'moodle/course:changesummary',
                'moodle/course:tag',
                'moodle/course:renameroles',
                'moodle/course:creategroupconversations',
                'moodle/course:managegroups',
                'moodle/category:viewcourselist',
                'moodle/category:viewhiddencategories',
                'moodle/backup:backupcourse',
                'moodle/backup:backupsection',
                'moodle/backup:backupactivity',
                'moodle/backup:configure',
                'moodle/backup:downloadfile',
                'moodle/restore:configure',
                'moodle/restore:restorecourse',
                'moodle/restore:restoresection',
                'moodle/restore:restoreactivity',
                'moodle/restore:uploadfile',
                'moodle/question:add',
                'moodle/question:editall',
                'moodle/question:viewall',
                'moodle/question:useall',
                'moodle/question:managecategory',
                'moodle/grade:viewall',
                'moodle/grade:view',
                'gradereport/grader:view',
                'gradereport/user:view',
                'gradereport/overview:view',
                'mod/forum:addnews',
                'mod/forum:addinstance',
                'mod/wiki:managewiki',
                'tool/recyclebin:viewitems',
                'tool/recyclebin:restoreitems',
                'tool/recyclebin:deleteitems',
                'moodle/filter:manage',
                'moodle/site:manageblocks',
                'moodle/block:edit',
                'moodle/block:view',
                'moodle/site:viewreports',
                'report/outline:view',
                'report/progress:view',
                'report/completion:view',
            ]
        ),
        'prevent' => array_merge(
            $caps_grading,
            [
                'moodle/site:accessallgroups',
                'moodle/site:config',
                'moodle/user:create',
                'moodle/user:delete',
                'moodle/user:update',
                'moodle/role:manage',
            ]
        ),
    ],

    // ------------------------------------------------------------
    // Учащийся (student)
    // Участвует в курсах, проходит задания, видит только свои оценки
    // ------------------------------------------------------------
    'student' => [
        'allow' => array_merge(
            $caps_common,
            $caps_forum_participate,
            $caps_messaging,
            [
                'moodle/grade:view',                // только свои оценки
                'gradereport/overview:view',
                'gradereport/user:view',
                'mod/assign:submit',
                'mod/assign:view',
                'mod/assign:exportownsubmission',
                'mod/assign:viewownsubmissionsummary',
                'mod/quiz:attempt',
                'mod/quiz:view',
                'mod/quiz:reviewmyattempts',
                'mod/choice:choose',
                'mod/choice:view',
                'mod/choice:readresponses',
                'mod/forum:viewdiscussion',
                'mod/forum:replypost',
                'mod/forum:startdiscussion',
                'mod/forum:createattachment',
                'mod/forum:deleteownpost',
                'mod/forum:exportownpost',
                'mod/lesson:view',
                'mod/resource:view',
                'mod/page:view',
                'mod/url:view',
                'mod/book:read',
                'mod/folder:view',
                'mod/label:view',
                'mod/scorm:savetrack',
                'mod/scorm:skipview',
                'mod/scorm:viewscores',
                'mod/wiki:viewpage',
                'mod/wiki:viewcomment',
                'mod/wiki:createpage',
                'mod/wiki:editpage',
                'mod/h5pactivity:view',
                'mod/h5pactivity:submit',
                'mod/imscp:view',
                'mod/feedback:complete',
                'mod/feedback:view',
                'mod/data:view',
                'mod/data:writeentry',
                'mod/data:viewentry',
                'mod/glossary:view',
                'mod/glossary:write',
                'mod/glossary:comment',
                'mod/workshop:submit',
                'mod/workshop:peerassess',
                'mod/workshop:view',
                'moodle/rating:view',
                'moodle/rating:rate',
                'moodle/badges:earnbadge',
                'moodle/badges:manageownbadges',
                'moodle/badges:viewbadges',
                'moodle/blog:create',
                'moodle/blog:view',
                'moodle/competency:planviewown',
                'moodle/competency:usercompetencyview',
                'moodle/portfolio:export',
                'tiny/autosave:use',
                'tiny/link:use',
                'tiny/media:use',
                'moodle/comment:view',
                'moodle/comment:post',
                'moodle/course:isincompletionreports',
            ]
        ),
        'prevent' => [
            'moodle/course:viewparticipants',
            'moodle/course:manageactivities',
            'moodle/course:update',
            'moodle/course:managegroups',
            'moodle/grade:viewall',
            'moodle/site:accessallgroups',
            'moodle/site:viewreports',
        ],
        // Жёсткий запрет - учащийся никогда не должен видеть панель управления УНИКС
        'prohibit' => [
            'local/unics:manage',
            'local/unics:viewstudents',
        ],
    ],

    // ------------------------------------------------------------
    // Родитель (parent)
    // Наблюдает за прогрессом ребёнка: оценки, активность, отчёты
    // ------------------------------------------------------------
    'parent' => [
        'allow' => array_merge(
            $caps_messaging,
            $caps_view_grades,
            $caps_reports_view,
            [
                'moodle/course:view',
                'moodle/course:viewoverview',
                'moodle/site:viewfullnames',
                'moodle/user:viewdetails',
                'moodle/user:viewalldetails',
                'moodle/user:viewuseractivitiesreport',
                'moodle/user:readuserblogs',
                'moodle/notes:view',
                'mod/assign:view',
                'mod/assign:viewgrades',
                'mod/quiz:view',
                'mod/quiz:reviewmyattempts',
                'mod/resource:view',
                'mod/page:view',
                'mod/url:view',
                'mod/book:read',
                'mod/folder:view',
                'mod/lesson:view',
                'moodle/calendar:manageownentries',
                'moodle/user:changeownpassword',
                'moodle/user:editownprofile',
                'moodle/blog:view',
                'moodle/badges:viewbadges',
            ]
        ),
        'prevent' => array_merge(
            $caps_grading,
            $caps_course_edit,
            $caps_add_activities,
            [
                'moodle/course:managegroups',
                'moodle/course:viewparticipants',
                'moodle/site:accessallgroups',
                'moodle/course:create',
                'moodle/role:assign',
                'enrol/manual:enrol',
                'mod/forum:startdiscussion',
                'mod/forum:replypost',
            ]
        ),
        // Жёсткий запрет - родитель не управляет системой УНИКС
        'prohibit' => [
            'local/unics:manage',
            'local/unics:viewstudents',
        ],
    ],

    // ------------------------------------------------------------
    // Администратор организации (org_admin)
    // Управляет пользователями своей организации, записывает на курсы
    // ------------------------------------------------------------
    'org_admin' => [
        'allow' => array_merge(
            $caps_common,
            $caps_view_participants,
            $caps_messaging,
            $caps_reports_view,
            $caps_view_grades,
            [
                'local/unics:manage',
                'moodle/course:view',
                'moodle/course:viewhiddencourses',
                'moodle/course:viewparticipants',
                'moodle/course:enrolconfig',
                'moodle/course:enrolreview',
                'moodle/course:viewoverview',
                'moodle/course:viewscales',
                'moodle/course:viewsuspendedusers',
                'moodle/category:viewcourselist',
                'moodle/cohort:view',
                'moodle/cohort:assign',
                'enrol/manual:enrol',
                'enrol/manual:manage',
                'enrol/manual:unenrol',
                'moodle/user:viewdetails',
                'moodle/user:viewalldetails',
                'moodle/user:viewuseractivitiesreport',
                'moodle/site:viewfullnames',
                'moodle/site:viewuseridentity',
                'moodle/notes:view',
                'moodle/notes:manage',
                'moodle/badges:viewbadges',
                'moodle/blog:view',
                'moodle/site:accessallgroups',  // должен видеть все группы своей орг.
            ]
        ),
        'prevent' => array_merge(
            $caps_grading,
            $caps_course_edit,
            $caps_add_activities,
            [
                'moodle/course:create',
                'moodle/course:delete',
                'moodle/site:config',
                'moodle/user:create',
                'moodle/user:delete',
                'moodle/role:manage',
            ]
        ),
    ],
];

// ================================================================
// Обработка POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'apply') {
        $ctx_sys = context_system::instance();
        $results = [];

        foreach ($role_matrix as $shortname => $matrix) {
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

        // Сохраняем результат в сессии для отображения
        $_SESSION['unics_setup_results'] = $results;

        redirect(
            new moodle_url('/local/unics/pages/setup_roles.php'),
            'Матрица прав применена.',
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// ================================================================
// Вывод
// ================================================================
echo $OUTPUT->header();
echo $OUTPUT->heading('Настройка прав ролей УНИКС');

echo html_writer::link(
    new moodle_url('/local/unics/pages/users.php'),
    'Назад к пользователям',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Результаты последнего применения
if (!empty($_SESSION['unics_setup_results'])) {
    $res = $_SESSION['unics_setup_results'];
    unset($_SESSION['unics_setup_results']);

    echo '<div class="alert alert-success"><strong>Результат применения:</strong><ul class="mb-0">';
    foreach ($res as $sn => $r) {
        $icon = $r['status'] === 'ok' ? '✓' : '-';
        echo "<li><code>{$sn}</code>: {$icon} {$r['msg']}</li>";
    }
    echo '</ul></div>';
}

// --- Описание матрицы ---
echo '<div class="card mb-4">';
echo '<div class="card-header"><strong>Матрица прав ролей</strong></div>';
echo '<div class="card-body p-0">';
echo '<table class="table table-sm table-bordered mb-0">';
echo '<thead class="thead-light"><tr>
    <th>Группа прав</th>
    <th>Педагог</th>
    <th>Тьютор</th>
    <th>Методист</th>
    <th>Учащийся</th>
    <th>Родитель</th>
    <th>Адм. орг.</th>
</tr></thead><tbody>';

$matrix_display = [
    ['Редактировать содержимое курса',   '✅', '❌', '✅', '❌', '❌', '❌'],
    ['Выставлять оценки',               '✅', '❌', '❌', '❌', '❌', '❌'],
    ['Просматривать оценки всех',       '✅', '✅', '✅', '❌', '✅', '✅'],
    ['Управлять группами курса',        '✅', '❌', '✅', '❌', '❌', '❌'],
    ['Просматривать участников',        '✅', '✅', '✅', '❌', '❌', '✅'],
    ['Форум: участие',                  '✅', '✅', '✅', '✅', '❌', '❌'],
    ['Заметки о студентах',             '✅', '✅', '❌', '❌', '✅¹', '✅'],
    ['Отчёты активности',               '✅', '✅', '✅', '❌', '✅', '✅'],
    ['Сообщения',                       '✅', '✅', '✅', '✅', '✅', '✅'],
    ['Создавать курсы',                 '❌', '❌', '✅', '❌', '❌', '❌'],
    ['Записывать пользователей',        '✅²', '❌', '❌', '❌', '❌', '✅'],
    ['Резервное копирование',           '✅', '❌', '✅', '❌', '❌', '❌'],
    ['accessallgroups',                 '⚙️³', '❌', '❌', '❌', '❌', '✅'],
];

foreach ($matrix_display as $row) {
    echo '<tr>';
    foreach ($row as $i => $cell) {
        $class = $i === 0 ? '' : 'text-center';
        echo "<td class=\"{$class}\">{$cell}</td>";
    }
    echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '<div class="card-footer small text-muted">';
echo '¹ Родитель видит заметки - только на просмотр. ';
echo '² Педагог может управлять записью в рамках своего курса (enrol/manual). ';
echo '³ Педагог: inherit (не установлено глобально) - переопределяется PROHIBIT на уровне курса при включении режима «Раздельные группы».';
echo '</div>';
echo '</div>';

// --- Кнопка применения ---
echo '<form method="post">';
echo '<input type="hidden" name="action" value="apply">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Проверяем какие роли существуют
$role_labels = [
    'editingteacher' => 'Педагог',
    'teacher'        => 'Тьютор',
    'methodist'      => 'Методист',
    'student'        => 'Учащийся',
    'parent'         => 'Родитель',
    'org_admin'      => 'Адм. организации',
];
$missing_roles = [];
foreach ($role_labels as $sn => $label) {
    if (!$DB->record_exists('role', ['shortname' => $sn])) {
        $missing_roles[] = "{$label} ({$sn})";
    }
}

if (!empty($missing_roles)) {
    echo '<div class="alert alert-warning">';
    echo '<strong>Роли не найдены в системе (права для них будут пропущены):</strong> ';
    echo implode(', ', $missing_roles);
    echo '<br><small>Создайте эти роли через Администрирование → Пользователи → Права → Определить роли.</small>';
    echo '</div>';
}

echo html_writer::tag('button', 'Применить матрицу прав ко всем ролям',
    ['type' => 'submit', 'class' => 'btn btn-primary',
     'onclick' => "return confirm('Применить матрицу прав? Существующие явные настройки прав для этих ролей будут перезаписаны.');"]
);
echo '</form>';

echo $OUTPUT->footer();
