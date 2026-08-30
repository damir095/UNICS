<?php
namespace local_unics\output;

use local_unics\access;

defined('MOODLE_INTERNAL') || die();

/**
 * Оболочка под роль (G3 per-role-shell): боковой рельс, иконки навбара,
 * кнопка «На дашборд» (этап 2.1 разгрузки lib.php).
 *
 * Тела перенесены из lib.php; там остались тонкие обёртки
 * (`local_unics_get_shell_nav()` и т.д.) - тема и страницы вызывают их как раньше.
 */
class shell {

    /**
     * Единая кнопка «На дашборд» для верхней части любой страницы плагина.
     * Ставится сразу после $OUTPUT->header() - одинаковый возврат на портал с любой
     * страницы (дашборд обслуживает все роли). На самом dashboard.php не нужна.
     *
     * @return string HTML кнопки.
     */
    public static function dashboard_button(): string {
        return \html_writer::div(
            \html_writer::link(
                new \moodle_url('/local/unics/pages/dashboard.php'),
                'На дашборд',
                ['class' => 'btn btn-outline-secondary btn-sm']
            ),
            'unics-dashboard-back mb-3'
        );
    }

    /**
     * Хук навбара: иконки УНИКС рядом с колокольчиком уведомлений.
     *
     * Moodle вызывает `*_render_navbar_output` для каждого плагина из
     * `core_renderer::navbar_plugin_output()` (рядом с попап'ами сообщений/уведомлений).
     * Добавляем две иконки: «Сообщения» (наш мессенджер взамен скрытого нативного) и
     * «Доступность» (тема/контраст/шрифт - быстрый доступ для детей с ОВЗ). Те же
     * core-иконки, что и в боковом меню (`t/message`, `t/preferences`).
     *
     * @param \renderer_base $output renderer ядра
     * @return string HTML
     */
    public static function render_navbar(\renderer_base $output): string {
        global $PAGE;

        if (!isloggedin() || isguestuser()) {
            return '';
        }

        // Гамбургер мобильного off-canvas рельса (G3): только на страницах УНИКС, где
        // рельс реально отрисован; на десктопе скрыт CSS (рельс там постоянный).
        // AMD local_unics/shell_rail навешивает открытие/закрытие.
        $burger = '';
        if ($PAGE->has_set_url()
            && strpos($PAGE->url->out_omit_querystring(), '/local/unics/') !== false) {
            $burger = \html_writer::tag('button',
                $output->pix_icon('i/menu', ''),
                [
                    'type'          => 'button',
                    'class'         => 'nav-link unics-navbar-link unics-shell-rail__burger',
                    'title'         => 'Меню',
                    'aria-label'    => 'Открыть меню',
                    'aria-controls' => 'unics-shell-rail',
                    'aria-expanded' => 'false',
                ]);
        }

        $items = [
            ['/local/unics/pages/messenger.php',     't/message',             'Сообщения'],
            ['/local/unics/pages/accessibility.php', 'e/accessibility_checker', 'Доступность'],
        ];

        $links = '';
        foreach ($items as [$path, $pix, $label]) {
            $links .= \html_writer::link(
                new \moodle_url($path),
                $output->pix_icon($pix, $label),
                [
                    'class'      => 'nav-link unics-navbar-link',
                    'title'      => $label,
                    'aria-label' => $label,
                ]
            );
        }

        return \html_writer::div($burger . $links, 'd-flex align-items-center unics-navbar-actions');
    }

    /**
     * Структура бокового рельса (G3 per-role-shell) для текущего пользователя.
     *
     * Единый ИСТОЧНИК пунктов оболочки: те же ролевые правила, что в
     * local_unics_extend_navigation, но сгруппированные по смыслу
     * (канонический словарь групп - см. .design/per-role-shell/INFORMATION_ARCHITECTURE.md).
     * Тема рендерит результат в постоянный рельс
     * ({@see theme_unics_before_standard_top_of_body_html}).
     *
     * Активность пункта вычисляется по совпадению пути текущей страницы с путём пункта.
     *
     * v1 (Срез "Рельс v1"): реализована раскладка ПЕДАГОГА (editingteacher / teacher).
     * Остальные роли (ученик/родитель/методист/админ) - следующий срез "Рельс: все роли";
     * пока получают минимум (Обзор + Связь), чтобы рельс не был пустым/сломанным.
     *
     * @return array{groups: array<int, array{label: ?string, items: array}>}
     */
    public static function get_nav(): array {
        global $USER, $DB, $PAGE;

        if (!isloggedin() || isguestuser()) {
            return ['groups' => []];
        }

        $current = $PAGE->has_set_url() ? $PAGE->url->out_omit_querystring() : '';
        // Фабрика пункта: метка, путь, иконка (pix-ключ), доп. query-параметры, опц. override активности.
        $item = function (string $label, string $path, string $icon = '', array $params = [],
                          ?bool $active = null) use ($current): array {
            $url = new \moodle_url($path, $params);
            return [
                'label'  => $label,
                'url'    => $url,
                'active' => $active ?? ($current !== '' && $url->out_omit_querystring() === $current),
                'icon'   => $icon,
            ];
        };

        // Канонические постоянные группы (одинаковы у всех ролей - «выучил один раз»).
        // Обзор всегда первая, Связь всегда последняя (CSS прижимает :last-child к низу).
        $portal = ['label' => null, 'items' => [
            $item('Портал', '/local/unics/pages/dashboard.php', 'i/dashboard'),
        ]];
        $svyaz = ['label' => 'Связь', 'items' => [
            $item('Сообщения', '/local/unics/pages/messenger.php', 't/message'),
            $item('Доступность', '/local/unics/pages/accessibility.php', 't/preferences'),
        ]];

        // Финализатор: группа из 1 пункта рендерится без заголовка (правило IA).
        $finalize = function (array $groups): array {
            foreach ($groups as &$g) {
                if (count($g['items']) === 1) {
                    $g['label'] = null;
                }
            }
            unset($g);
            return ['groups' => $groups];
        };

        // Группа «Мои курсы» - быстрый прямой доступ к курсам пользователя. Одинакова у всех ролей,
        // строится один раз и кладется сразу после «Портала». Пустая (нет записей) - не добавляется.
        $base = [$portal];
        [$railcourses, $railtrunc] = self::get_rail_courses();
        if ($railcourses) {
            $citems = [];
            foreach ($railcourses as $c) {
                $citems[] = $item($c->fullname, '/course/view.php', 'i/course', ['id' => $c->id],
                    ($PAGE->course && (int)$PAGE->course->id === (int)$c->id));
            }
            if ($railtrunc) {
                $citems[] = $item('Все курсы', '/my/courses.php', 'i/course');
            }
            $base[] = ['label' => 'Мои курсы', 'items' => $citems];
        }

        // --- Учащийся: проверяем по БД первым (как extend_navigation - чтобы ошибочная
        //     Moodle-роль не открыла чужой рельс). Обучение -> Результаты. ---
        $student_rec = access::student_record();
        if ($student_rec) {
            $sid    = (int)$student_rec->id;
            $groups = $base;

            $learn = [
                $item('Мой маршрут', '/local/unics/pages/my_path.php', 'i/competencies'),
                // Помощник - в «Учебе», рядом с маршрутом: ребенок ищет его там же, где урок.
                $item('Помощник', '/local/unics/pages/assistant.php', 'i/question'),
            ];
            // «Заметки педагога» - только в контексте курса и если они есть (зеркало
            // extend_navigation; на страницах /local/unics/* контекст курса появится с
            // охватом курса в следующем срезе).
            if ($PAGE->context instanceof \context_course) {
                $courseid  = $PAGE->context->instanceid;
                $has_notes = $DB->record_exists_sql(
                    "SELECT 1
                       FROM {unics_comments} c
                       JOIN {course_modules} cm ON cm.id = c.cmid
                      WHERE c.student_id = :sid AND cm.course = :cid",
                    ['sid' => $sid, 'cid' => $courseid]
                );
                if ($has_notes) {
                    $learn[] = $item('Заметки педагога', '/local/unics/pages/course_notes.php', 'i/edit',
                        ['student_id' => $sid, 'courseid' => $courseid]);
                }
            }
            $groups[] = ['label' => 'Обучение', 'items' => $learn];

            $groups[] = ['label' => 'Результаты', 'items' => [
                $item('Мои результаты', '/local/unics/pages/student_report.php', 'i/report', ['student_id' => $sid]),
                $item('Мои достижения', '/local/unics/pages/achievements.php', 'i/badge', ['student_id' => $sid]),
                $item('Магазин баллов', '/local/unics/pages/shop.php', 'i/star'),
            ]];

            $groups[] = $svyaz;
            return $finalize($groups);
        }

        // Не-ученик (родитель/персонал): «Справка» последним пунктом нижней группы «Связь».
        $svyaz['items'][] = $item('Справка', '/local/unics/pages/help.php', 'i/info');

        // --- Родитель: рельс ведёт на ребёнка. Переключатель ребёнка (если детей >1) -
        //     задача дашборд-каркаса; v1 ведёт на первого ребёнка. ---
        if (access::is_parent()) {
            $groups = $base;
            $kids   = $DB->get_records('unics_parent_student',
                ['parent_mdl_user_id' => $USER->id], 'id ASC', 'id, student_id', 0, 1);
            $childid = $kids ? (int)reset($kids)->student_id : 0;
            if ($childid) {
                $groups[] = ['label' => 'Обучение', 'items' => [
                    $item('Маршрут ребёнка', '/local/unics/pages/my_path.php', 'i/competencies',
                        ['student_id' => $childid]),
                ]];
                $groups[] = ['label' => 'Результаты', 'items' => [
                    $item('Результаты ребёнка', '/local/unics/pages/student_report.php', 'i/report',
                        ['student_id' => $childid]),
                    $item('Достижения ребёнка', '/local/unics/pages/achievements.php', 'i/badge',
                        ['student_id' => $childid]),
                ]];
            }
            $groups[] = $svyaz;
            return $finalize($groups);
        }

        // --- Персонал: педагог / методист / региональн. админ / админ сайта.
        //     Тот же порядок проверок прав, что и в extend_navigation. ---
        $ctx          = \context_system::instance();
        $is_admin     = has_capability('local/unics:manage', $ctx);
        $is_manageorg = has_capability('local/unics:manageorg', $ctx);
        $is_teacher   = has_capability('local/unics:viewstudents', $ctx);
        if (!$is_admin && !$is_manageorg && !$is_teacher) {
            return ['groups' => []];
        }

        $is_scoped_admin = !$is_admin && access::is_scoped_admin();
        $is_methodist    = !$is_admin && !$is_scoped_admin && access::is_methodist();
        if (!$is_admin && !$is_scoped_admin && !$is_methodist
            && !$DB->record_exists('unics_teachers', ['mdl_user_id' => $USER->id])) {
            return ['groups' => []];
        }

        $orgs_label = get_string('organizations', 'local_unics');
        $groups     = $base;

        // Региональный методист / администратор (region_methodist / region_admin).
        if ($is_scoped_admin) {
            $groups[] = ['label' => 'Учащиеся', 'items' => [
                $item('Все учащиеся', '/local/unics/pages/my_students.php', 'i/users'),
                $item('Создать пользователя', '/local/unics/pages/create_user.php', 'i/user'),
                $item('Привязки', '/local/unics/pages/assign.php', 'i/group'),
            ]];
            $groups[] = ['label' => 'Курсы', 'items' => [
                $item('Курсы (архив)', '/local/unics/pages/courses.php', 'i/course'),
                $item('Делегирование курсов', '/local/unics/pages/course_delegation.php', 'i/permissions'),
                $item('Запись учащихся на курс', '/local/unics/pages/enrol_students.php', 'i/users'),
                $item('Запись педагогов на курс', '/local/unics/pages/enrol_teachers.php', 'i/user'),
            ]];
            $groups[] = ['label' => 'Аналитика', 'items' => [
                $item('Журнал', '/local/unics/pages/gradebook.php', 'i/grades'),
                $item('Статистика', '/local/unics/pages/statistics.php', 'i/stats'),
                $item('Отчёт по организации', '/local/unics/pages/org_report.php', 'i/report'),
                $item('Кодификатор', '/local/unics/pages/codifier.php', 'i/competencies'),
            ]];
            $groups[] = ['label' => 'Организации', 'items' => [
                $item($orgs_label, '/local/unics/pages/organizations.php', 'i/cohort'),
            ]];
            $groups[] = $svyaz;
            return $finalize($groups);
        }

        // Методист организации / муниципальный методист (methodist / district_methodist).
        if ($is_methodist) {
            $groups[] = ['label' => 'Учащиеся', 'items' => [
                $item('Все учащиеся', '/local/unics/pages/my_students.php', 'i/users'),
                $item('Пользователи', '/local/unics/pages/users.php', 'i/user'),
                $item('Привязки', '/local/unics/pages/assign.php', 'i/group'),
            ]];
            $groups[] = ['label' => 'Курсы', 'items' => [
                $item('Курсы (архив)', '/local/unics/pages/courses.php', 'i/course'),
                $item('Запись учащихся на курс', '/local/unics/pages/enrol_students.php', 'i/users'),
                $item('Запись педагогов на курс', '/local/unics/pages/enrol_teachers.php', 'i/user'),
                $item('Генерация УМК', '/local/unics/pages/generate_umk.php', 'i/edit'),
                $item('История УМК', '/local/unics/pages/umk_status.php', 'i/calendar'),
            ]];
            $groups[] = ['label' => 'Аналитика', 'items' => [
                $item('Журнал', '/local/unics/pages/gradebook.php', 'i/grades'),
                $item('Статистика', '/local/unics/pages/statistics.php', 'i/stats'),
                $item('Отчёт по организации', '/local/unics/pages/org_report.php', 'i/report'),
            ]];
            // «Организации» - только муниципальному методисту (district_methodist):
            // он принял функции муниципального администратора (роли v3).
            if (access::user_has_role((int)$USER->id, ['district_methodist'])) {
                $groups[] = ['label' => 'Организации', 'items' => [
                    $item($orgs_label, '/local/unics/pages/organizations.php', 'i/cohort'),
                ]];
            }
            $groups[] = $svyaz;
            return $finalize($groups);
        }

        // Педагог (роль 5 editingteacher / роль 6 teacher) или администратор сайта.
        $can_courses = !access::is_nonediting_teacher() || $is_admin;

        $students = [$item('Мои учащиеся', '/local/unics/pages/my_students.php', 'i/users')];
        if ($is_admin) {
            $students[] = $item('Пользователи', '/local/unics/pages/users.php', 'i/user');
            $students[] = $item('Импорт из CSV', '/local/unics/pages/import_users.php', 'i/import');
            $students[] = $item('Привязки', '/local/unics/pages/assign.php', 'i/group');
            $students[] = $item('Перевод в следующий класс', '/local/unics/pages/promote_students.php', 'i/navigationitem');
        }
        $groups[] = ['label' => 'Учащиеся', 'items' => $students];

        // Группа «Курсы» - у педагога-создателя и админа (педагог роли 6 контент не создаёт).
        if ($can_courses) {
            $courses = [];
            if ($is_admin) {
                $courses[] = $item('Курсы (архив)', '/local/unics/pages/courses.php', 'i/course');
            }
            $courses[] = $item('Шаблоны курсов', '/local/unics/pages/course_templates.php', 'i/course');
            if ($is_admin) {
                $courses[] = $item('Запись учащихся на курс', '/local/unics/pages/enrol_students.php', 'i/users');
                $courses[] = $item('Запись педагогов на курс', '/local/unics/pages/enrol_teachers.php', 'i/user');
            }
            $courses[] = $item('Генерация УМК', '/local/unics/pages/generate_umk.php', 'i/edit');
            if ($is_admin) {
                $courses[] = $item('История УМК', '/local/unics/pages/umk_status.php', 'i/calendar');
            }
            $groups[] = ['label' => 'Курсы', 'items' => $courses];
        }

        $analytics = [$item('Журнал', '/local/unics/pages/gradebook.php', 'i/grades')];
        if ($is_admin) {
            $analytics[] = $item('Статистика', '/local/unics/pages/statistics.php', 'i/stats');
            $analytics[] = $item('Отчёт по организации', '/local/unics/pages/org_report.php', 'i/report');
        }
        $groups[] = ['label' => 'Аналитика', 'items' => $analytics];

        if ($is_admin) {
            $groups[] = ['label' => 'Организации', 'items' => [
                $item($orgs_label, '/local/unics/pages/organizations.php', 'i/cohort'),
            ]];
        }

        $groups[] = $svyaz;
        return $finalize($groups);
    }

    /**
     * Курсы пользователя для группы «Мои курсы» бокового рельса.
     *
     * Все записанные видимые курсы текущего пользователя ($USER), отсортированные по недавности
     * доступа (ни разу не открытые - в конец, тайбрейк по названию). Включает и не открытые курсы,
     * чтобы новичок впервые попал в курс. JOIN к user_lastaccess и сортировку делает сама
     * enrol_get_my_courses (префикс ul.timeaccess -> COALESCE(ul.timeaccess,0) DESC).
     *
     * @return array [stdClass[] $courses (не больше 5), bool $truncated (всего курсов больше лимита)]
     */
    public static function get_rail_courses(): array {
        $limit   = 5;
        $courses = enrol_get_my_courses('id, fullname', 'ul.timeaccess DESC, fullname ASC');
        if (!$courses) {
            return [[], false];
        }
        return [array_slice($courses, 0, $limit, true), count($courses) > $limit];
    }
}
