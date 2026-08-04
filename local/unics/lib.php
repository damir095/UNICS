<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Редиректит учащегося на его дашборд, если он пытается открыть педагогическую страницу.
 * Тонкая обёртка над {@see \local_unics\access::require_not_student()}.
 */
function local_unics_require_not_student(): void {
    \local_unics\access::require_not_student();
}

/**
 * Единая кнопка «На дашборд» для верхней части любой страницы плагина.
 * Тонкая обёртка над {@see \local_unics\output\shell::dashboard_button()}.
 */
function local_unics_dashboard_button(): string {
    return \local_unics\output\shell::dashboard_button();
}

/**
 * Перенаправляет учащегося со стандартного дашборда Moodle (`/my/`)
 * на наш дашборд `local_unics`. Срабатывает до отправки HTTP-заголовков,
 * что позволяет redirect() работать без warning'ов о уже отправленных headers.
 *
 * Только для учащихся: педагог/методист/админ используют /my/ продуктивно
 * (там видны их курсы Moodle).
 */
function local_unics_before_http_headers(): void {
    global $DB, $USER, $PAGE, $COURSE;
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Toast-уведомления - поллер на всех страницах для залогиненных пользователей.
    // Подхватывает свежие записи из unics_notifications и показывает их в правом верхнем углу.
    try {
        $PAGE->requires->js_call_amd('local_unics/toast_poller', 'init', [[
            'pollInterval' => 30000,
            'lookbackSec'  => 90,
        ]]);
    } catch (\Throwable $e) {
        // Нефатально - поллер просто не запустится.
        debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
    }

    // Ученический вид страницы курса (course/view.php, формат topics): дружелюбные карточки,
    // прогресс, next-step. Только для ребенка и не в режиме редактирования.
    try {
        $coursepath = $PAGE->url ? $PAGE->url->get_path() : '';
        if ($coursepath === '/course/view.php' && isset($COURSE) && $COURSE->id > 1
                && $COURSE->format === 'topics'
                && \local_unics\output\course_view::is_child_view($COURSE)) {
            $payload = \local_unics\output\course_view::build_payload($COURSE, $USER->id);
            $PAGE->requires->js_call_amd('local_unics/course_child', 'init', [$payload]);
        }
    } catch (\Throwable $e) {
        debugging('local_unics course_child: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Педагогский вид страницы курса: сигнал по классу (что на проверке, кто застрял, прогресс
    // класса по темам). Гейты course_child и course_staff взаимоисключающие - ребенок ИЛИ персонал.
    try {
        $staffpath = $PAGE->url ? $PAGE->url->get_path() : '';
        if ($staffpath === '/course/view.php' && isset($COURSE) && $COURSE->id > 1
                && $COURSE->format === 'topics'
                && \local_unics\output\course_staff_view::is_staff_view($COURSE)) {
            $payload = \local_unics\output\course_staff_view::build_payload($COURSE, $USER->id);
            if ($payload['classSize'] > 0) {
                // Пометка уровневых вариантов - отдельный расчет (свой набор строк и свой источник
                // чисел), но едет в том же payload. Показываем ее только вместе с сигналом по классу:
                // условие classSize > 0 остается единственным гейтом отрисовки.
                $variants = \local_unics\output\course_variants::build($COURSE, $USER->id);
                $payload['variants'] = $variants['variants'];
                $payload['attention']['orphans'] = $variants['orphans'];
                $PAGE->requires->js_call_amd('local_unics/course_staff', 'init', [$payload]);
            }
        }
    } catch (\Throwable $e) {
        debugging('local_unics course_staff: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Стандартный Moodle-дашборд (`/my/index.php`) и страница «Мои курсы»
    // (`/my/courses.php`) оба имеют pagetype == 'my-index', поэтому различаем по URL.
    // Учащегося уводим ТОЛЬКО с дашборда, «Мои курсы» оставляем - это его курсы.
    $path = $PAGE->url ? $PAGE->url->get_path() : '';
    if ($path !== '/my/' && $path !== '/my/index.php') {
        return;
    }
    if (\local_unics\access::student_record() !== null) {
        redirect(new moodle_url('/local/unics/pages/dashboard.php'));
    }
}

/**
 * true, если у пользователя назначена одна из Moodle-ролей с указанными shortname.
 * Тонкая обёртка над {@see \local_unics\access::user_has_role()}.
 */
function local_unics_user_has_role(int $userid, array $shortnames): bool {
    return \local_unics\access::user_has_role($userid, $shortnames);
}

/**
 * true, если пользователь - методист (организации ИЛИ района).
 * Тонкая обёртка над {@see \local_unics\access::is_methodist()}.
 */
function local_unics_is_methodist(?int $userid = null): bool {
    return \local_unics\access::is_methodist($userid);
}

/**
 * true, если пользователь - региональный администратор/методист (manageorg без manage).
 * Тонкая обёртка над {@see \local_unics\access::is_scoped_admin()}.
 */
function local_unics_is_scoped_admin(?int $userid = null): bool {
    return \local_unics\access::is_scoped_admin($userid);
}

/**
 * true, если пользователь - педагог без права редактирования (роль 'teacher', код 6).
 * Тонкая обёртка над {@see \local_unics\access::is_nonediting_teacher()}.
 */
function local_unics_is_nonediting_teacher(?int $userid = null): bool {
    return \local_unics\access::is_nonediting_teacher($userid);
}

/**
 * true, если пользователь - штатный сотрудник, а не «просто родитель».
 * Тонкая обертка над {@see \local_unics\access::is_staff_person()}.
 */
function local_unics_is_staff_person(?int $userid = null, ?\context $context = null): bool {
    return \local_unics\access::is_staff_person($userid, $context);
}

/**
 * Коды unics_role, которые пользователь вправе назначать при создании пользователя.
 * Тонкая обёртка над {@see \local_unics\access::creatable_roles()}.
 */
function local_unics_creatable_roles(?int $userid = null): array {
    return \local_unics\access::creatable_roles($userid);
}

/**
 * Роль пользователя в УНИКС: student | parent | admin | methodist | teacher | guest.
 * Тонкая обёртка над {@see \local_unics\access::get_role_for_user()}.
 */
function local_unics_get_role_for_user(?int $userid = null): string {
    return \local_unics\access::get_role_for_user($userid);
}

/**
 * Guard входа на write-страницу без конкретного target'а (manage ИЛИ manageorg).
 * Тонкая обёртка над {@see \local_unics\access::require_manage_or_manageorg()}.
 */
function local_unics_require_manage_or_manageorg(): void {
    \local_unics\access::require_manage_or_manageorg();
}

/**
 * Guard write-операций над регионом (manage ИЛИ manageorg + скоуп).
 * Тонкая обёртка над {@see \local_unics\access::require_manage_or_scope_region()}.
 */
function local_unics_require_manage_or_scope_region(int $region_id): void {
    \local_unics\access::require_manage_or_scope_region($region_id);
}

/**
 * Guard write-операций над организацией (manage ИЛИ manageorg + скоуп).
 * Тонкая обёртка над {@see \local_unics\access::require_manage_or_scope_org()}.
 */
function local_unics_require_manage_or_scope_org(int $org_id): void {
    \local_unics\access::require_manage_or_scope_org($org_id);
}

/**
 * Guard write-операций над районом (manage ИЛИ manageorg + скоуп).
 * Тонкая обёртка над {@see \local_unics\access::require_manage_or_scope_district()}.
 */
function local_unics_require_manage_or_scope_district(int $district_id): void {
    \local_unics\access::require_manage_or_scope_district($district_id);
}

/**
 * Guard write-операций над пользователем (manage ИЛИ manageorg + скоуп target'а).
 * Тонкая обёртка над {@see \local_unics\access::require_manage_or_scope_user()}.
 */
function local_unics_require_manage_or_scope_user(int $target_mdl_user_id): void {
    \local_unics\access::require_manage_or_scope_user($target_mdl_user_id);
}

/**
 * Колбек ядра: настройки навигации (локдаун профиля ученика, пункты УНИКС во «Ещё» модуля).
 * Тонкая обёртка над {@see \local_unics\output\navigation::extend_settings_navigation()}.
 */
function local_unics_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    \local_unics\output\navigation::extend_settings_navigation($settingsnav, $context);
}

/**
 * Колбек ядра: пункты УНИКС во вкладке «Ещё» курса (вторичная навигация).
 * Тонкая обёртка над {@see \local_unics\output\navigation::extend_navigation_course()}.
 */
function local_unics_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    \local_unics\output\navigation::extend_navigation_course($navigation, $course, $context);
}

/**
 * Колбек ядра: пункты УНИКС в боковой навигации (ветвление по роли).
 * Тонкая обёртка над {@see \local_unics\output\navigation::extend_navigation()}.
 */
function local_unics_extend_navigation(global_navigation $nav) {
    \local_unics\output\navigation::extend_navigation($nav);
}

// =============================================================================
// A1. Доступность (per-user темы + голосовой ввод) — [[accessibility-features-design]]
// =============================================================================

/**
 * Допустимые значения предпочтений доступности.
 * Тонкая обёртка над {@see \local_unics\accessibility::allowed_values()}.
 *
 * @return array<string, string[]>
 */
function local_unics_a11y_allowed_values(): array {
    return \local_unics\accessibility::allowed_values();
}

/**
 * Предпочтения доступности пользователя (валидированные, с дефолтами).
 * Тонкая обёртка над {@see \local_unics\accessibility::get_prefs()}.
 *
 * @return array{theme:string, contrast:string, font:string, accent:string}
 */
function local_unics_a11y_get_prefs(?int $userid = null): array {
    return \local_unics\accessibility::get_prefs($userid);
}

/**
 * Колбек ядра: вставка в <head> (классы доступности + голосовой ввод).
 * Тонкая обёртка над {@see \local_unics\accessibility::before_standard_html_head()}.
 *
 * @return string HTML/стиль для вставки в <head>
 */
function local_unics_before_standard_html_head(): string {
    return \local_unics\accessibility::before_standard_html_head();
}
/**
 * Хук навбара: иконки УНИКС рядом с колокольчиком уведомлений.
 * Тонкая обёртка над {@see \local_unics\output\shell::render_navbar()}.
 *
 * @param \renderer_base $output renderer ядра
 * @return string HTML
 */
function local_unics_render_navbar_output(\renderer_base $output): string {
    return \local_unics\output\shell::render_navbar($output);
}

/**
 * Структура бокового рельса (G3 per-role-shell) для текущего пользователя.
 * Тонкая обёртка над {@see \local_unics\output\shell::get_nav()} - тему не трогаем.
 *
 * @return array{groups: array<int, array{label: ?string, items: array}>}
 */
function local_unics_get_shell_nav(): array {
    return \local_unics\output\shell::get_nav();
}

/**
 * Курсы пользователя для группы «Мои курсы» бокового рельса.
 * Тонкая обёртка над {@see \local_unics\output\shell::get_rail_courses()}.
 *
 * @return array [stdClass[] $courses (не больше 5), bool $truncated]
 */
function local_unics_get_rail_courses(): array {
    return \local_unics\output\shell::get_rail_courses();
}
/**
 * Ядровый paging_bar для списков плагина.
 *
 * @param int        $total   всего строк
 * @param int        $page    текущая страница (0-based)
 * @param int        $perpage строк на странице
 * @param moodle_url $baseurl URL страницы с текущими фильтрами (без page; paging_bar добавит сам)
 * @param string     $pagevar имя query-параметра страницы (для нескольких таблиц на одной странице)
 * @return string HTML навигации (пустая строка, если все на одной странице)
 */
function local_unics_render_paging_bar(int $total, int $page, int $perpage, moodle_url $baseurl,
                                       string $pagevar = 'page'): string {
    global $OUTPUT;
    if ($perpage <= 0 || $total <= $perpage) {
        return '';
    }
    return $OUTPUT->render(new paging_bar($total, $page, $perpage, $baseurl, $pagevar));
}

/**
 * Регистрация пользовательских предпочтений плагина (Moodle core_user hook).
 * Позволяет сохранять их через AJAX (core_user/repository.setUserPreference)
 * без перезагрузки и формы.
 *
 * Пока одно: свёрнут ли боковой рельс оболочки (G3 per-role-shell). Значение 0/1,
 * держится per-user между сессиями. Разрешено менять только своё (is_current_user).
 *
 * @return array
 */
function local_unics_user_preferences(): array {
    return [
        'local_unics_shell_rail_collapsed' => [
            'type'               => PARAM_INT,
            'null'               => NULL_NOT_ALLOWED,
            'default'            => '0',
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}

/**
 * Выгрузка построчной статистики учеников в файл (Excel/CSV/ODS). Вызывать ДО header.
 * Тонкая обёртка над {@see \local_unics\export::student_stats()}. [[export-helper-org-report-design]].
 *
 * @param int[]|null $org_ids  скоуп организаций (null = вся система)
 * @param string     $basename базовое имя файла (дата добавится автоматически)
 */
function local_unics_export_student_stats(?array $org_ids, string $basename): void {
    \local_unics\export::student_stats($org_ids, $basename);
}

/**
 * Кнопки выгрузки «Выгрузить: Excel CSV ODS» для страницы-отчёта.
 * Тонкая обёртка над {@see \local_unics\export::buttons()}.
 */
function local_unics_export_buttons(moodle_url $pageurl): string {
    return \local_unics\export::buttons($pageurl);
}
/**
 * Построение данных журнала (ученики x задания курса) БЕЗ вывода - для экрана и экспорта.
 * Тонкая обёртка над {@see \local_unics\gradebook::matrix()}. [[gradebook-export-design]].
 * $perpage > 0 - пагинация учеников (колонки/средние всё равно по всей выборке); 0 - без неё.
 *
 * @return array{notice: ?array{text:string,level:string}, students: array, by_user: array,
 *               item_meta: array, item_class_avg: array, total: int}
 */
function local_unics_gradebook_matrix(int $course_id, int $filter_class, string $filter_letter,
                                      int $page = 0, int $perpage = 0): array {
    return \local_unics\gradebook::matrix($course_id, $filter_class, $filter_letter, $page, $perpage);
}

/**
 * Выгрузка журнала (матрица ученики x задания) в файл (Excel/CSV/ODS). Вызывать ДО header.
 * Тонкая обёртка над {@see \local_unics\export::gradebook()}. [[gradebook-export-design]].
 */
function local_unics_export_gradebook(int $course_id, int $filter_class, string $filter_letter): void {
    \local_unics\export::gradebook($course_id, $filter_class, $filter_letter);
}

/**
 * Строка классов таблицы для единой системы «Мягкие карточки» ([[tables-staff-design]]).
 *
 * Единственный источник правды: ядровый html_table по умолчанию несет generaltable, по
 * которому бьет theme/unics/scss/_tables.scss, но страницы плагина этот класс
 * перезатирали - отсюда и разъезд вида. Значение совпадает с тем, что задача 1
 * ([[tables-redesign-design]]) поставила в mustache-шаблоны группы C.
 *
 * Обертку .table-responsive (карточка + горизонтальный скролл) НЕ добавляет: у
 * html_writer::table() ее ставит ядро само, у ручных <table> ее пишут на странице.
 *
 * @param bool $compact плотная плотность - для матриц и крупных операционных списков
 * @return string значение атрибута class
 */
function local_unics_table_class(bool $compact = false): string {
    return 'table table-striped table-hover unics-table' . ($compact ? ' unics-compact' : '');
}
