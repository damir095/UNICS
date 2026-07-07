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
    global $DB, $USER, $PAGE;
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

    // Стандартный Moodle-дашборд (`/my/index.php`) и страница «Мои курсы»
    // (`/my/courses.php`) оба имеют pagetype == 'my-index', поэтому различаем по URL.
    // Учащегося уводим ТОЛЬКО с дашборда, «Мои курсы» оставляем - это его курсы.
    $path = $PAGE->url ? $PAGE->url->get_path() : '';
    if ($path !== '/my/' && $path !== '/my/index.php') {
        return;
    }
    if ($DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
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
 * Выгрузка построчной статистики учеников в файл (Excel/CSV/ODS) через нативный
 * \core\dataformat. Вызывать РАНО, до $OUTPUT->header(): при валидном параметре download
 * ставит заголовки, стримит файл и завершает скрипт; иначе просто возвращает.
 * Данные - stats_manager::get_student_rows($org_ids) (скоуп задаёт вызывающая страница).
 * [[export-helper-org-report-design]].
 *
 * @param int[]|null $org_ids  скоуп организаций (null = вся система)
 * @param string     $basename базовое имя файла (дата добавится автоматически)
 */
function local_unics_export_student_stats(?array $org_ids, string $basename): void {
    global $DB;
    $download = optional_param('download', '', PARAM_ALPHA);
    if ($download === '' || !in_array($download, ['excel', 'csv', 'ods'], true) || !confirm_sesskey()) {
        return;
    }
    $exrows = \local_unics\stats_manager::get_student_rows($org_ids);
    $names = [];
    if ($exrows) {
        $uids = array_map(static fn($r) => (int)$r->mdl_user_id, $exrows);
        $users = $DB->get_records_list('user', 'id', $uids, '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
        foreach ($users as $u) {
            $names[(int)$u->id] = fullname($u);
        }
    }
    $columns = [
        'name'          => 'ФИО',
        'category'      => 'Категория',
        'ovz'           => 'Вид ОВЗ',
        'class'         => 'Класс',
        'org'           => 'Организация',
        'district'      => 'Муниципалитет',
        'region'        => 'Регион',
        'courses'       => 'Курсов',
        'avg_score'     => 'Средний балл, %',
        'completion'    => 'Завершаемость, %',
        'views'         => 'Просмотры',
        'time_min'      => 'Время, мин',
        'attempts'      => 'Попытки',
        'ai'            => 'Выдано УМК',
        'level_changes' => 'Смен уровня',
        'last_active'   => 'Последняя активность',
    ];
    $data = [];
    foreach ($exrows as $r) {
        $ovz = \local_unics\student_helper::ovz_type_names($r->ovz_type);
        $data[] = [
            'name'          => $names[(int)$r->mdl_user_id] ?? '-',
            'category'      => implode(', ', \local_unics\student_helper::category_names($r->category)),
            'ovz'           => $ovz ? implode(', ', $ovz) : '-',
            'class'         => $r->class_number ? (int)$r->class_number : '-',
            'org'           => $r->organization_name ?: '-',
            'district'      => $r->district_name ?: '-',
            'region'        => $r->region_name ?: '-',
            'courses'       => (int)$r->n_courses,
            'avg_score'     => $r->avg_score_pct === null ? '-' : $r->avg_score_pct,
            'completion'    => (int)$r->total > 0 ? round((int)$r->completed / (int)$r->total * 100) : '-',
            'views'         => (int)$r->views,
            'time_min'      => (int)$r->time_est_min,
            'attempts'      => (int)$r->attempts,
            'ai'            => (int)$r->ai_uses,
            'level_changes' => (int)$r->level_changes,
            'last_active'   => $r->last_active_at ? userdate((int)$r->last_active_at, '%Y-%m-%d') : '-',
        ];
    }
    \core\dataformat::download_data($basename . '-' . userdate(time(), '%Y-%m-%d'),
        $download, $columns, $data);
    die;
}

/**
 * Кнопки выгрузки «Выгрузить: Excel CSV ODS» для страницы-отчёта. Ссылки сохраняют параметры
 * $pageurl (например org_id) и несут sesskey. [[export-helper-org-report-design]].
 */
function local_unics_export_buttons(moodle_url $pageurl): string {
    $out = html_writer::start_div('mb-3');
    $out .= html_writer::tag('span', 'Выгрузить: ', ['class' => 'mr-2']);
    foreach (['excel' => 'Excel', 'csv' => 'CSV', 'ods' => 'ODS'] as $fmt => $lbl) {
        $durl = new moodle_url($pageurl, ['download' => $fmt, 'sesskey' => sesskey()]);
        $out .= html_writer::tag('a', $lbl,
            ['href' => $durl->out(false), 'class' => 'btn btn-outline-secondary btn-sm mr-2']);
    }
    $out .= html_writer::end_div();
    return $out;
}

/**
 * Построение данных журнала (ученики x задания курса) БЕЗ вывода - для экрана и экспорта.
 * Тонкая обёртка над {@see \local_unics\gradebook::matrix()}. [[gradebook-export-design]].
 *
 * @return array{notice: ?array{text:string,level:string}, students: array, by_user: array,
 *               item_meta: array, item_class_avg: array}
 */
function local_unics_gradebook_matrix(int $course_id, int $filter_class, string $filter_letter): array {
    return \local_unics\gradebook::matrix($course_id, $filter_class, $filter_letter);
}

/**
 * Выгрузка журнала (матрица ученики x задания) в файл (Excel/CSV/ODS). Вызывать ДО header.
 * Значения - % (округл.) по заданию + средний %. [[gradebook-export-design]].
 */
function local_unics_export_gradebook(int $course_id, int $filter_class, string $filter_letter): void {
    $download = optional_param('download', '', PARAM_ALPHA);
    if ($download === '' || !in_array($download, ['excel', 'csv', 'ods'], true) || !confirm_sesskey()) {
        return;
    }
    $gb = local_unics_gradebook_matrix($course_id, $filter_class, $filter_letter);
    // Задания по sortorder.
    $item_meta = $gb['item_meta'];
    uasort($item_meta, static fn($a, $b) => $a['sortorder'] <=> $b['sortorder']);
    $ordered_iids = array_keys($item_meta);
    // Быстрый доступ [uid][iid].
    $grade_by_ui = [];
    foreach ($gb['by_user'] as $uid => $list) {
        foreach ($list as $g) {
            $grade_by_ui[$uid][$g['itemid']] = $g;
        }
    }
    $columns = ['name' => 'ФИО', 'class' => 'Класс'];
    foreach ($ordered_iids as $iid) {
        $columns['i' . $iid] = $item_meta[$iid]['name'];
    }
    $columns['avg'] = 'Средний';
    $data = [];
    foreach ($gb['students'] as $s) {
        $uid = (int)$s->mdl_user_id;
        $fio = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
        $cls = $s->class_number
            ? $s->class_number . ($s->class_letter ? ' ' . $s->class_letter : '')
            : '-';
        $row  = ['name' => $fio !== '' ? $fio : '-', 'class' => $cls];
        $pcts = [];
        foreach ($ordered_iids as $iid) {
            if (isset($grade_by_ui[$uid][$iid])) {
                $p = $grade_by_ui[$uid][$iid]['pct'];
                $row['i' . $iid] = round($p);
                $pcts[] = $p;
            } else {
                $row['i' . $iid] = '-';
            }
        }
        $row['avg'] = $pcts ? round(array_sum($pcts) / count($pcts)) : '-';
        $data[] = $row;
    }
    \core\dataformat::download_data('unics-zhurnal-' . $course_id . '-' . userdate(time(), '%Y-%m-%d'),
        $download, $columns, $data);
    die;
}
