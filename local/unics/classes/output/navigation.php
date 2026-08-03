<?php
namespace local_unics\output;

use local_unics\access;

defined('MOODLE_INTERNAL') || die();

/**
 * Тела навигационных колбеков плагина (этап 2.1 разгрузки lib.php).
 *
 * Сами колбеки (`local_unics_extend_navigation` и т.д.) остаются в lib.php -
 * Moodle ищет их по имени функции - и делегируют сюда одной строкой.
 */
class navigation {

    /**
     * Убирает пункты редактирования профиля из настроек навигации для учащихся.
     * Также редиректит учащегося со страниц редактирования профиля Moodle.
     * Персоналу добавляет во «Ещё» модуля пункты УНИКС (PDF-экспорт, ИИ-проверка, кодификатор).
     */
    public static function extend_settings_navigation(\settings_navigation $settingsnav, \context $context): void {
        global $DB, $USER, $PAGE;

        // На странице (mod_page) - пункт «Скачать PDF» во «Ещё». Для ВСЕХ, кто видит
        // модуль (педагог/методист/админ и ученик: офлайн-чтение, печать крупным
        // шрифтом - линия доступности). Ставим до студенческой ветки (та делает return).
        // cmid берём из контекста модуля - $PAGE->cm на этом этапе ещё не заполнен.
        if ($context instanceof \context_module && has_capability('mod/page:view', $context)) {
            $cmid = (int)$context->instanceid;
            $modrec = $DB->get_record_sql(
                "SELECT m.name, cm.instance FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?",
                [$cmid]
            );
            if ($modrec && $modrec->name === 'page') {
                $target = $settingsnav->find('modulesettings', null) ?: $settingsnav;
                $target->add(
                    'УНИКС: Скачать PDF',
                    new \moodle_url('/local/unics/pages/umk_export.php', ['cmid' => $cmid]),
                    \navigation_node::TYPE_SETTING,
                    null,
                    'local_unics_umk_export',
                    new \pix_icon('t/download', '')
                );

                // PPTX - только персоналу и только на УМК-презентациях (слайды
                // .unics-slide в контенте); ученику остается PDF ([[umk-pptx-export-design]]).
                if (access::student_record() === null) {
                    $content = (string)$DB->get_field('page', 'content', ['id' => $modrec->instance]);
                    if (\local_unics\ai\slide_parser::is_presentation($content)) {
                        $target->add(
                            'УНИКС: Скачать PPTX',
                            new \moodle_url('/local/unics/pages/umk_export_pptx.php', ['cmid' => $cmid]),
                            \navigation_node::TYPE_SETTING,
                            null,
                            'local_unics_umk_export_pptx',
                            new \pix_icon('t/download', '')
                        );
                    }
                }
            }
        }

        // Ветка «учащийся»: локдаун профиля (скрываем настройки, редиректим с edit).
        if (access::student_record() !== null) {
            // Редиректим со страниц редактирования профиля (совместимо с PHP 7.x)
            $path = $PAGE->url->get_path();
            if (strpos($path, '/user/edit.php') !== false || strpos($path, '/user/editadvanced.php') !== false) {
                redirect(new \moodle_url('/local/unics/pages/dashboard.php'));
            }

            // Скрываем настройки профиля в навигации (null = искать по ключу без ограничения по типу)
            $usersettings = $settingsnav->find('usersettings', null);
            if ($usersettings) {
                foreach (['editprofile', 'useraccount', 'usermessaging', 'userpreferences',
                          'security', 'contactable', 'blog', 'mnet_loginas', 'myprofile'] as $key) {
                    $node = $usersettings->find($key, null);
                    if ($node) {
                        $node->remove();
                    }
                }
            }
            return;
        }

        // Ветка «персонал»: на странице задания (mod_assign) добавляем во «Ещё»
        // пункт ИИ-проверки развёрнутых ответов. Гейт - mod/assign:grade в контексте
        // модуля (педагог видит инструмент только в своих заданиях). Открывает
        // essay_check.php в режиме «по заданию» (?cmid=).
        //
        // cmid берём из КОНТЕКСТА модуля, а не из $PAGE->cm: на момент построения
        // settings-навигации $PAGE->cm ещё не заполнен (проверено), тогда как
        // контекст модуля уже доступен.
        if ($context instanceof \context_module && has_capability('mod/assign:grade', $context)) {
            $cmid = (int)$context->instanceid;
            $modname = $DB->get_field_sql(
                "SELECT m.name
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = ?",
                [$cmid]
            );
            if ($modname === 'assign') {
                $target = $settingsnav->find('modulesettings', null) ?: $settingsnav;
                $target->add(
                    'УНИКС: Проверить ответы ИИ',
                    new \moodle_url('/local/unics/pages/essay_check.php', ['cmid' => $cmid]),
                    \navigation_node::TYPE_SETTING,
                    null,
                    'local_unics_essay_check',
                    new \pix_icon('i/cohort', '')
                );
            }
        }

        // Тегирование кодификатором - во «Ещё» любой активности. Гейт -
        // course:manageactivities в контексте модуля (педагог-владелец курса/методист/админ).
        // cmid берём из контекста модуля (как и выше: $PAGE->cm ещё не заполнен).
        if ($context instanceof \context_module && has_capability('moodle/course:manageactivities', $context)) {
            $cmid = (int)$context->instanceid;
            $target = $settingsnav->find('modulesettings', null) ?: $settingsnav;
            $target->add(
                'УНИКС: Кодификатор',
                new \moodle_url('/local/unics/pages/codifier_tag.php', ['cmid' => $cmid]),
                \navigation_node::TYPE_SETTING,
                null,
                'local_unics_tag_codifier',
                new \pix_icon('i/grades', '')
            );
        }
    }

    /**
     * Пункт УНИКС во вкладке «Еще» курса (вторичная навигация Moodle) - ОДИН, на хаб курса.
     *
     * Учащемуся не показываем (проверка по unics_students в БД, не по Moodle-роли - как и во всем
     * плагине: ошибочно назначенная роль не должна открыть меню).
     *
     * Прежде здесь плоско добавлялись девять пунктов с префиксом «УНИКС:» - меню «Еще» в Boost
     * одноуровневое, вложенный flyout тема не делает. Теперь группировка живет на хаб-странице, а
     * в меню остается одна ссылка: тот же прием, которым ядро показывает «Отчеты» и «Банки
     * вопросов» ([[course-hub-design]]).
     *
     * Гейт - непустой {@see course_hub::tiles()}, ТОТ ЖЕ вызов, что делает сама страница.
     * Дублировать предикаты здесь нельзя: меню и хаб разъедутся, и пункт поведет в отказ.
     *
     * @param \navigation_node $navigation вторичная навигация курса
     * @param \stdClass $course текущий курс
     * @param \context_course $context контекст курса
     */
    public static function extend_navigation_course(\navigation_node $navigation, \stdClass $course,
                                                    \context_course $context): void {
        // Учащийся - меню курса не показываем.
        if (access::student_record() !== null) {
            return;
        }

        // Ни одной доступной плитки - пункта нет (прежде так же не добавлялся ни один из девяти).
        if (!course_hub::tiles($course, $context)) {
            return;
        }

        $navigation->add(
            get_string('hub_nav', 'local_unics'),
            new \moodle_url('/local/unics/pages/course_hub.php', ['course_id' => $course->id]),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_course_hub',
            new \pix_icon('i/dashboard', '')
        );
    }

    /**
     * Пункты УНИКС в боковой навигации Moodle - ветвление по роли
     * (учащийся / родитель / региональный админ / методист / педагог / админ).
     */
    public static function extend_navigation(\global_navigation $nav): void {
        global $DB, $USER, $PAGE;

        // NEW-5: «Сообщения» - наша страница со списком контактов под роль
        // (обёртка над штатным Moodle messaging). Доступна всем ролям, поэтому
        // добавляется ДО ветвления по ролям, одним пунктом.
        if (isloggedin() && !isguestuser()) {
            $nav->add(
                'Сообщения',
                new \moodle_url('/local/unics/pages/messenger.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_messenger',
                new \pix_icon('t/message', '')
            );
            // A1: Доступность - персональные темы/контраст/шрифт/акцент. Для всех ролей.
            $nav->add(
                'Доступность',
                new \moodle_url('/local/unics/pages/accessibility.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_accessibility',
                new \pix_icon('t/preferences', '')
            );
        }

        // Учащийся - проверяем по БД в первую очередь, до любых проверок возможностей.
        // Это гарантирует, что неправильно назначенная Moodle-роль не откроет педагогическое меню.
        $student_rec = access::student_record();
        if ($student_rec) {
            $branch = $nav->add(
                'УНИКС - Мой портал',
                new \moodle_url('/local/unics/pages/dashboard.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_student_root',
                new \pix_icon('i/cohort', '')
            );
            $branch->add(
                'Мои результаты',
                new \moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_rec->id]),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_my_report'
            );
            $branch->add(
                'Мои достижения',
                new \moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student_rec->id]),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_achievements'
            );
            $branch->add(
                'Мой маршрут',
                new \moodle_url('/local/unics/pages/my_path.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_my_path'
            );
            if ((int)get_config('local_unics', 'adaptive_cat_enabled') === 1) {
                $branch->add(
                    'Адаптивная проверка',
                    new \moodle_url('/local/unics/pages/cat.php'),
                    \navigation_node::TYPE_CUSTOM,
                    null,
                    'local_unics_cat_page'
                );
            }

            // Ссылки «Заметки педагога» для активного курса
            if ($PAGE->context instanceof \context_course) {
                $courseid  = $PAGE->context->instanceid;
                $has_notes = $DB->record_exists_sql(
                    "SELECT 1
                       FROM {unics_comments} c
                       JOIN {course_modules} cm ON cm.id = c.cmid
                      WHERE c.student_id = :sid AND cm.course = :cid",
                    ['sid' => $student_rec->id, 'cid' => $courseid]
                );
                if ($has_notes) {
                    $branch->add(
                        'Заметки педагога (этот курс)',
                        new \moodle_url('/local/unics/pages/course_notes.php', [
                            'student_id' => $student_rec->id,
                            'courseid'   => $courseid,
                        ]),
                        \navigation_node::TYPE_CUSTOM,
                        null,
                        'local_unics_course_notes'
                    );
                }
            }
            return;
        }

        // Родитель
        if (access::is_parent()) {
            $nav->add(
                'УНИКС - Мои дети',
                new \moodle_url('/local/unics/pages/dashboard.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_parent_root',
                new \pix_icon('i/cohort', '')
            );
            return;
        }

        // Педагог / администратор / методист (переработка ролей 2026-05-23).
        $ctx          = \context_system::instance();
        $is_admin     = has_capability('local/unics:manage', $ctx);       // системный Moodle-админ
        $is_manageorg = has_capability('local/unics:manageorg', $ctx);    // региональн./районный админ ИЛИ методист
        $is_teacher   = has_capability('local/unics:viewstudents', $ctx); // педагоги + всё выше

        if (!$is_admin && !$is_manageorg && !$is_teacher) {
            return;
        }

        // Региональный / районный администратор - управленческое меню со scope-доступом.
        $is_scoped_admin = !$is_admin && access::is_scoped_admin();
        // Методист организации / районный методист - методическое меню.
        $is_methodist    = !$is_admin && !$is_scoped_admin && access::is_methodist();

        if (!$is_admin && !$is_scoped_admin && !$is_methodist
            && !$DB->record_exists('unics_teachers', ['mdl_user_id' => $USER->id])) {
            // viewstudents/manageorg есть, но это не админ/методист и не реальный педагог УНИКС.
            return;
        }

        // Региональный / районный администратор: меню управленца (без создания курсов и УМК).
        // Закрывает прежний пробел - region_admin раньше не получал никакого меню.
        if ($is_scoped_admin) {
            // Заголовок ветки зависит от роли: региональный методист (v3 фаза 2) делит
            // меню и права с региональным администратором, но это методическая роль.
            $scoped_title = access::user_has_role((int)$USER->id, ['region_methodist'])
                ? 'УНИКС - Портал регионального методиста'
                : 'УНИКС - Портал администратора';
            $branch = $nav->add(
                $scoped_title,
                new \moodle_url('/local/unics/pages/dashboard.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_scoped_admin_root',
                new \pix_icon('i/cohort', '')
            );
            $branch->add('Все учащиеся',
                new \moodle_url('/local/unics/pages/my_students.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_students');
            $branch->add('Создать пользователя',
                new \moodle_url('/local/unics/pages/create_user.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_create_user');
            $branch->add('Привязки',
                new \moodle_url('/local/unics/pages/assign.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_assign');
            $branch->add(get_string('organizations', 'local_unics'),
                new \moodle_url('/local/unics/pages/organizations.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_orgs');
            $branch->add('Курсы (архив)',
                new \moodle_url('/local/unics/pages/courses.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_courses');
            $branch->add('Делегирование курсов',
                new \moodle_url('/local/unics/pages/course_delegation.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_delegation');
            $branch->add('Запись учащихся на курс',
                new \moodle_url('/local/unics/pages/enrol_students.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_enrol_students');
            $branch->add('Запись педагогов на курс',
                new \moodle_url('/local/unics/pages/enrol_teachers.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_enrol_teachers');
            $branch->add('Отчёт по организации',
                new \moodle_url('/local/unics/pages/org_report.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_org_report');
            $branch->add('Статистика',
                new \moodle_url('/local/unics/pages/statistics.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_statistics');
            $branch->add('Кодификатор',
                new \moodle_url('/local/unics/pages/codifier.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_codifier');
            $branch->add('Журнал',
                new \moodle_url('/local/unics/pages/gradebook.php'),
                \navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_gradebook');
            return;
        }

        // Меню методиста - короткое, без «Мои учащиеся» (нет своих, но видит всех).
        if ($is_methodist) {
            $branch = $nav->add(
                'УНИКС - Портал методиста',
                new \moodle_url('/local/unics/pages/dashboard.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_root',
                new \pix_icon('i/cohort', '')
            );
            $branch->add(
                'Все учащиеся',
                new \moodle_url('/local/unics/pages/my_students.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_all_students'
            );
            $branch->add(
                'Пользователи',
                new \moodle_url('/local/unics/pages/users.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_users'
            );
            $branch->add(
                'Привязки',
                new \moodle_url('/local/unics/pages/assign.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_assign'
            );
            // «Организации» - для муниципального методиста (district_methodist): в v3
            // [[role-model-v3-2026-06-11]] он принял функции удалённого муниципального
            // администратора, включая управление организациями своего муниципалитета.
            // Методисту организации (methodist) пункт не показываем - его скоуп = одна орг.
            if (access::user_has_role((int)$USER->id, ['district_methodist'])) {
                $branch->add(
                    get_string('organizations', 'local_unics'),
                    new \moodle_url('/local/unics/pages/organizations.php'),
                    \navigation_node::TYPE_CUSTOM,
                    null,
                    'local_unics_methodist_orgs'
                );
            }
            // «Шаблоны курсов» убраны у методиста: курсы создаёт только педагог-создатель
            // и админ (G5 [[role-capability-audit-2026-06-17]], встречи 10-11.06). Методист
            // распределяет детей по уже готовым (делегированным) курсам.
            $branch->add(
                'Курсы (архив)',
                new \moodle_url('/local/unics/pages/courses.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_courses'
            );
            $branch->add(
                'Запись учащихся на курс',
                new \moodle_url('/local/unics/pages/enrol_students.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_enrol_students'
            );
            $branch->add(
                'Запись педагогов на курс',
                new \moodle_url('/local/unics/pages/enrol_teachers.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_enrol_teachers'
            );
            $branch->add(
                'Генерация УМК (ИИ)',
                new \moodle_url('/local/unics/pages/generate_umk.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_umk'
            );
            $branch->add(
                'История генерации УМК',
                new \moodle_url('/local/unics/pages/umk_status.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_umk_status_methodist'
            );
            $branch->add(
                'Отчёт по организации',
                new \moodle_url('/local/unics/pages/org_report.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_org_report'
            );
            $branch->add(
                'Статистика',
                new \moodle_url('/local/unics/pages/statistics.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_statistics'
            );
            $branch->add(
                'Журнал',
                new \moodle_url('/local/unics/pages/gradebook.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_gradebook'
            );
            return;
        }

        $root_url = new \moodle_url('/local/unics/pages/dashboard.php');

        $branch = $nav->add(
            'УНИКС - Портал',
            $root_url,
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_root',
            new \pix_icon('i/cohort', '')
        );

        // Дашборд - для педагогов и администраторов
        $branch->add(
            'Портал (дашборд)',
            new \moodle_url('/local/unics/pages/dashboard.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_dashboard'
        );

        // Страница «Мои учащиеся» - для всех (педагог видит только своих)
        $branch->add(
            'Мои учащиеся',
            new \moodle_url('/local/unics/pages/my_students.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_my_students'
        );

        // Журнал - для педагогов и админа (видит курсы, где есть grade:viewall).
        $branch->add(
            'Журнал',
            new \moodle_url('/local/unics/pages/gradebook.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_gradebook'
        );

        // Генерация УМК + Шаблоны курсов - только для педагогов, создающих курсы
        // (editingteacher, роль 5) и администраторов. Педагог без редактирования
        // (роль 6) контент НЕ создаёт - у него меню read-only (только дашборд + «Мои учащиеся»).
        if (!access::is_nonediting_teacher()) {
            $branch->add(
                'Шаблоны курсов',
                new \moodle_url('/local/unics/pages/course_templates.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_course_templates_teacher'
            );
            $branch->add(
                'Генерация УМК (ИИ)',
                new \moodle_url('/local/unics/pages/generate_umk.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_umk'
            );
        }

        if ($is_admin) {
            $branch->add(
                get_string('users', 'local_unics'),
                new \moodle_url('/local/unics/pages/users.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_users'
            );

            $branch->add(
                'Импорт из CSV',
                new \moodle_url('/local/unics/pages/import_users.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_import'
            );

            $branch->add(
                get_string('organizations', 'local_unics'),
                new \moodle_url('/local/unics/pages/organizations.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_orgs'
            );

            $branch->add(
                'Курсы (архив)',
                new \moodle_url('/local/unics/pages/courses.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_courses'
            );

            $branch->add(
                get_string('assignments', 'local_unics'),
                new \moodle_url('/local/unics/pages/assign.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_assign'
            );

            $branch->add(
                'Запись учащихся на курс',
                new \moodle_url('/local/unics/pages/enrol_students.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_enrol'
            );

            $branch->add(
                'Запись педагогов на курс',
                new \moodle_url('/local/unics/pages/enrol_teachers.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_enrol_teachers'
            );

            $branch->add(
                'Перевод в следующий класс',
                new \moodle_url('/local/unics/pages/promote_students.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_promote'
            );

            $branch->add(
                'История генерации УМК',
                new \moodle_url('/local/unics/pages/umk_status.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_umk_status'
            );

            // Подраздел «Отчёты»
            $reports = $branch->add(
                'Отчёты',
                new \moodle_url('/local/unics/pages/org_report.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_reports'
            );
            $reports->add(
                'Отчёт по организации',
                new \moodle_url('/local/unics/pages/org_report.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_org_report'
            );
            $reports->add(
                'Статистика',
                new \moodle_url('/local/unics/pages/statistics.php'),
                \navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_statistics'
            );

        }
    }
}
