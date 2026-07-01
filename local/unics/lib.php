<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Добавляет пункты УНИКС в боковую навигацию для администраторов.
 */
/**
 * Редиректит учащегося на его дашборд, если он пытается открыть педагогическую страницу.
 * Вызывать в начале каждой страницы, доступной педагогам/администраторам.
 */
function local_unics_require_not_student(): void {
    global $DB, $USER;
    if ($DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
        redirect(new moodle_url('/local/unics/pages/dashboard.php'));
    }
}

/**
 * Единая кнопка «На дашборд» для верхней части любой страницы плагина.
 * Ставится сразу после $OUTPUT->header() — одинаковый возврат на портал с любой
 * страницы (дашборд обслуживает все роли). На самом dashboard.php не нужна.
 *
 * @return string HTML кнопки.
 */
function local_unics_dashboard_button(): string {
    return html_writer::div(
        html_writer::link(
            new moodle_url('/local/unics/pages/dashboard.php'),
            'На дашборд',
            ['class' => 'btn btn-outline-secondary btn-sm']
        ),
        'unics-dashboard-back mb-3'
    );
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
 * Возвращает true, если у пользователя назначена одна из Moodle-ролей
 * с указанными shortname (на любом контексте).
 *
 * @param int $userid id пользователя
 * @param string[] $shortnames список shortname ролей
 * @return bool
 */
function local_unics_user_has_role(int $userid, array $shortnames): bool {
    global $DB;
    if (!$userid || empty($shortnames)) {
        return false;
    }
    [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'sn');
    $params['uid'] = $userid;
    return $DB->record_exists_sql(
        "SELECT 1 FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.userid = :uid AND r.shortname {$insql}",
        $params
    );
}

/**
 * Возвращает true, если пользователь - методист (организации ИЛИ района).
 * Методист = Moodle-роль с shortname 'methodist' или 'district_methodist'
 * (переработка ролей 2026-05-23 — оба методиста ведут себя одинаково на наших
 * страницах, различаются только скоупом в unics_user_org).
 *
 * Проверяем только Moodle-роль - это единственный надёжный маркер
 * (user_manager::create_user() создаёт запись в unics_teachers и для методиста,
 * поэтому по таблице teacher'ов методиста не отличить).
 *
 * Capability local/unics:viewstudents проверяется отдельно вызывающим кодом.
 *
 * @param int|null $userid id пользователя; null = текущий $USER->id
 * @return bool
 */
function local_unics_is_methodist(?int $userid = null): bool {
    global $USER;
    if ($userid === null) {
        $userid = (int)$USER->id;
    }
    return local_unics_user_has_role($userid, ['methodist', 'district_methodist']);
}

/**
 * Возвращает true, если пользователь - управленец уровня региона со scope-доступом
 * через local/unics:manageorg, но БЕЗ системного local/unics:manage:
 * региональный администратор ('region_admin') ИЛИ региональный методист
 * ('region_methodist', v3 фаза 2 [[role-model-v3-2026-06-11]]) - права и скоуп (регион)
 * у них совпадают, обе получают управленческое меню. Различие - организационное
 * (admin = техобслуживание, methodist = распределение курсов/отчётность) и в заголовке меню.
 * Муниципальный администратор (district_admin) удалён в v3 — муниципальный уровень ведёт
 * district_methodist (методическое меню), см. local_unics_is_methodist().
 *
 * @param int|null $userid id пользователя; null = текущий $USER->id
 * @return bool
 */
function local_unics_is_scoped_admin(?int $userid = null): bool {
    global $USER;
    if ($userid === null) {
        $userid = (int)$USER->id;
    }
    return local_unics_user_has_role($userid, ['region_admin', 'region_methodist']);
}

/**
 * Возвращает true, если пользователь - педагог без права редактирования
 * (Moodle-роль 'teacher', код 6). Такой педагог видит курс и оценивает, но не
 * создаёт/не редактирует контент — наши страницы для него read-only.
 *
 * @param int|null $userid id пользователя; null = текущий $USER->id
 * @return bool
 */
function local_unics_is_nonediting_teacher(?int $userid = null): bool {
    global $USER;
    if ($userid === null) {
        $userid = (int)$USER->id;
    }
    return local_unics_user_has_role($userid, ['teacher']);
}

/**
 * Какие коды unics_role пользователь вправе назначать при создании другого пользователя.
 * Принцип: нельзя создать роль своего уровня или выше — только нижестоящих.
 *   - системный администратор (manage) — все роли;
 *   - региональный администратор — региональный методист (10) и ниже (не другого региона);
 *   - региональный методист — муниципальный методист (9) и ниже (назначает мун. методистов);
 *   - методист организации/муниципалитета — только педагоги, учащиеся, родители;
 *   - остальные — никого.
 * Муниципальный администратор (код 2) удалён в v3 [[role-model-v3-2026-06-11]];
 * региональный методист (код 10) добавлен в фазе 2.
 *
 * @param int|null $userid id пользователя; null = текущий $USER->id
 * @return int[] разрешённые коды unics_role
 */
function local_unics_creatable_roles(?int $userid = null): array {
    global $USER;
    if ($userid === null) {
        $userid = (int)$USER->id;
    }
    $ctx = context_system::instance();

    if (has_capability('local/unics:manage', $ctx, $userid)) {
        return [1, 10, 9, 4, 5, 6, 7, 8];
    }
    if (local_unics_user_has_role($userid, ['region_admin'])) {
        return [10, 9, 4, 5, 6, 7, 8];
    }
    if (local_unics_user_has_role($userid, ['region_methodist'])) {
        return [9, 4, 5, 6, 7, 8];
    }
    if (local_unics_is_methodist($userid)) {
        return [5, 6, 7, 8];
    }
    return [];
}

/**
 * Возвращает роль пользователя в УНИКС: student | parent | admin | methodist | teacher | guest.
 * Приоритет совпадает с порядком веток в local_unics_extend_navigation.
 *
 * @param int|null $userid id пользователя; null = текущий $USER->id
 * @return string
 */
function local_unics_get_role_for_user(?int $userid = null): string {
    global $DB, $USER;
    if ($userid === null) {
        $userid = (int)$USER->id;
    }
    if (!$userid || isguestuser($userid)) {
        return 'guest';
    }
    if ($DB->record_exists('unics_students', ['mdl_user_id' => $userid])) {
        return 'student';
    }
    // Родитель: либо привязан к ребёнку в unics_parent_student,
    // либо у него unics_role=8 в unics_user_org (даже без активной привязки).
    if ($DB->record_exists('unics_parent_student', ['parent_mdl_user_id' => $userid])
        || $DB->record_exists('unics_user_org', ['mdl_user_id' => $userid, 'unics_role' => 8])) {
        return 'parent';
    }
    $ctx = context_system::instance();
    // Системный админ (manage) и региональный/районный админ (manageorg) → 'admin'.
    if (has_capability('local/unics:manage', $ctx, $userid) || local_unics_is_scoped_admin($userid)) {
        return 'admin';
    }
    if (has_capability('local/unics:viewstudents', $ctx, $userid)) {
        return local_unics_is_methodist($userid) ? 'methodist' : 'teacher';
    }
    // Fallback по unics_role в unics_user_org - на случай если capability не пробрасываются.
    $unics_role = $DB->get_field('unics_user_org', 'unics_role', ['mdl_user_id' => $userid]);
    if ($unics_role !== false) {
        return match ((int)$unics_role) {
            1, 2, 3 => 'admin',     // региональн. / районный / org-админ (legacy)
            4, 9    => 'methodist', // методист орг. / районный методист
            5, 6    => 'teacher',   // педагог создающий курсы / non-editing
            7       => 'student',
            8       => 'parent',
            default => 'guest',
        };
    }
    return 'guest';
}

/**
 * Guard для входа на write-страницу без конкретного target'а (просмотр формы).
 * Принимает любого, у кого есть либо `local/unics:manage`, либо `local/unics:manageorg`.
 *
 * Конкретный target (org/district/user) ДОЛЖЕН проверяться отдельно после
 * получения данных формы — через `_scope_org`/`_scope_district`/`_scope_user`.
 */
function local_unics_require_manage_or_manageorg(): void {
    $ctx = context_system::instance();
    if (has_capability('local/unics:manage', $ctx)) {
        return;
    }
    require_capability('local/unics:manageorg', $ctx);
}

/**
 * Guard для write-операций над регионом. Семантика та же, что и `_org`.
 */
function local_unics_require_manage_or_scope_region(int $region_id): void {
    global $USER;
    $ctx = context_system::instance();

    if (has_capability('local/unics:manage', $ctx)) {
        return;
    }
    require_capability('local/unics:manageorg', $ctx);

    if (!\local_unics\scope_checker::user_can_access_region((int)$USER->id, $region_id)) {
        throw new \moodle_exception('nopermissions', 'error', '',
            'регион вне вашего скоупа');
    }
}

/**
 * Guard для write-операций над организацией.
 *
 * Принимает, если:
 *   - у текущего пользователя есть `local/unics:manage` (системный админ), ИЛИ
 *   - есть `local/unics:manageorg` И организация входит в его скоуп.
 *
 * Иначе кидает `required_capability_exception` (manageorg) или
 * `moodle_exception('nopermissions')` (scope mismatch).
 *
 * Используется на write-страницах в связке с `\local_unics\scope_checker`.
 */
function local_unics_require_manage_or_scope_org(int $org_id): void {
    global $USER;
    $ctx = context_system::instance();

    if (has_capability('local/unics:manage', $ctx)) {
        return;
    }
    require_capability('local/unics:manageorg', $ctx);

    if (!\local_unics\scope_checker::user_can_access_org((int)$USER->id, $org_id)) {
        throw new \moodle_exception('nopermissions', 'error', '',
            'организация вне вашего скоупа');
    }
}

/**
 * Guard для write-операций над районом. Семантика та же, что и `_org`.
 */
function local_unics_require_manage_or_scope_district(int $district_id): void {
    global $USER;
    $ctx = context_system::instance();

    if (has_capability('local/unics:manage', $ctx)) {
        return;
    }
    require_capability('local/unics:manageorg', $ctx);

    if (!\local_unics\scope_checker::user_can_access_district((int)$USER->id, $district_id)) {
        throw new \moodle_exception('nopermissions', 'error', '',
            'муниципалитет вне вашего скоупа');
    }
}

/**
 * Guard для write-операций над пользователем.
 * Скоуп target'а вычисляется через `scope_checker::get_user_organization` с fallback на сам скоуп.
 */
function local_unics_require_manage_or_scope_user(int $target_mdl_user_id): void {
    global $USER;
    $ctx = context_system::instance();

    if (has_capability('local/unics:manage', $ctx)) {
        return;
    }
    require_capability('local/unics:manageorg', $ctx);

    if (!\local_unics\scope_checker::user_can_access_user((int)$USER->id, $target_mdl_user_id)) {
        throw new \moodle_exception('nopermissions', 'error', '',
            'пользователь вне вашего скоупа');
    }
}

/**
 * Убирает пункты редактирования профиля из настроек навигации для учащихся.
 * Также редиректит учащегося со страниц редактирования профиля Moodle.
 */
function local_unics_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    global $DB, $USER, $PAGE;

    // На странице (mod_page) - пункт «Скачать PDF» во «Ещё». Для ВСЕХ, кто видит
    // модуль (педагог/методист/админ и ученик: офлайн-чтение, печать крупным
    // шрифтом - линия доступности). Ставим до студенческой ветки (та делает return).
    // cmid берём из контекста модуля - $PAGE->cm на этом этапе ещё не заполнен.
    if ($context instanceof context_module && has_capability('mod/page:view', $context)) {
        $cmid = (int)$context->instanceid;
        $modname = $DB->get_field_sql(
            "SELECT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?",
            [$cmid]
        );
        if ($modname === 'page') {
            $target = $settingsnav->find('modulesettings', null) ?: $settingsnav;
            $target->add(
                'УНИКС: Скачать PDF',
                new moodle_url('/local/unics/pages/umk_export.php', ['cmid' => $cmid]),
                navigation_node::TYPE_SETTING,
                null,
                'local_unics_umk_export',
                new pix_icon('t/download', '')
            );
        }
    }

    // Ветка «учащийся»: локдаун профиля (скрываем настройки, редиректим с edit).
    if ($DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
        // Редиректим со страниц редактирования профиля (совместимо с PHP 7.x)
        $path = $PAGE->url->get_path();
        if (strpos($path, '/user/edit.php') !== false || strpos($path, '/user/editadvanced.php') !== false) {
            redirect(new moodle_url('/local/unics/pages/dashboard.php'));
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
    if ($context instanceof context_module && has_capability('mod/assign:grade', $context)) {
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
                new moodle_url('/local/unics/pages/essay_check.php', ['cmid' => $cmid]),
                navigation_node::TYPE_SETTING,
                null,
                'local_unics_essay_check',
                new pix_icon('i/cohort', '')
            );
        }
    }

    // Тегирование кодификатором - во «Ещё» любой активности. Гейт -
    // course:manageactivities в контексте модуля (педагог-владелец курса/методист/админ).
    // cmid берём из контекста модуля (как и выше: $PAGE->cm ещё не заполнен).
    if ($context instanceof context_module && has_capability('moodle/course:manageactivities', $context)) {
        $cmid = (int)$context->instanceid;
        $target = $settingsnav->find('modulesettings', null) ?: $settingsnav;
        $target->add(
            'УНИКС: Кодификатор',
            new moodle_url('/local/unics/pages/codifier_tag.php', ['cmid' => $cmid]),
            navigation_node::TYPE_SETTING,
            null,
            'local_unics_tag_codifier',
            new pix_icon('i/grades', '')
        );
    }
}

/**
 * Пункты УНИКС во вкладке «Ещё» курса (вторичная навигация Moodle).
 * Инструменты курса педагога — работает не уходя из курса.
 *
 * Учащемуся не показываем (проверка по unics_students в БД, не по Moodle-роли —
 * как и во всём плагине: ошибочно назначенная роль не должна открыть меню).
 * Каждый пункт гейтится в КОНТЕКСТЕ КУРСА, поэтому педагог видит инструменты
 * только в своих курсах (как gradebook.php через course-context capability).
 *
 * Меню «Ещё» в Boost плоское (один уровень — ядро так же показывает «Отчёты»,
 * «Банки вопросов» одиночными пунктами-ссылками на landing). Вложенный flyout
 * тема не делает, поэтому добавляем пункты плоско с префиксом «УНИКС:» — так они
 * визуально сгруппированы и оба достижимы. Когда в фазе 2-3 появится hub-страница
 * курса, можно будет схлопнуть в один пункт «УНИКС» → hub.
 *
 * Фаза 1: только ссылки на уже готовые страницы.
 *
 * @param navigation_node $navigation вторичная навигация курса
 * @param stdClass $course текущий курс
 * @param context_course $context контекст курса
 */
function local_unics_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    global $DB, $USER;

    // Учащийся — меню курса не показываем.
    if ($DB->record_exists('unics_students', ['mdl_user_id' => $USER->id])) {
        return;
    }

    $items = [];

    // Журнал курса, Отчёт по курсу, Учащиеся курса, Адаптивные уровни — персонал
    // курса (moodle/grade:viewall в контексте курса: педагог видит только свои
    // курсы), либо методист/системный админ. Те же гейты, что и на самих страницах.
    $is_course_staff = has_capability('moodle/grade:viewall', $context)
        || has_capability('local/unics:manage', context_system::instance())
        || local_unics_is_methodist();
    if ($is_course_staff) {
        $items[] = [
            'УНИКС: Журнал курса',
            new moodle_url('/local/unics/pages/gradebook.php', ['course_id' => $course->id]),
            'local_unics_course_gradebook',
        ];
        $items[] = [
            'УНИКС: Отчёт по курсу',
            new moodle_url('/local/unics/pages/course_report.php', ['course_id' => $course->id]),
            'local_unics_course_report',
        ];
        $items[] = [
            'УНИКС: Учащиеся курса',
            new moodle_url('/local/unics/pages/course_students.php', ['course_id' => $course->id]),
            'local_unics_course_students',
        ];
        $items[] = [
            'УНИКС: Адаптивные уровни',
            new moodle_url('/local/unics/pages/course_levels.php', ['course_id' => $course->id]),
            'local_unics_course_levels',
        ];
        $items[] = [
            'УНИКС: Кодификатор',
            new moodle_url('/local/unics/pages/codifier_tag.php', ['courseid' => $course->id]),
            'local_unics_course_codifier',
        ];
    }

    // Сгенерировать УМК — педагог, создающий контент (course:manageactivities),
    // методист или системный админ. Non-editing teacher (роль 6) контент не
    // создаёт — пункт ему скрыт. Орг-скоуп дособлюдает сама generate_umk.php.
    $can_umk = !local_unics_is_nonediting_teacher() && (
        has_capability('local/unics:manage', context_system::instance())
        || has_capability('moodle/course:manageactivities', $context)
        || local_unics_is_methodist()
    );
    if ($can_umk) {
        $items[] = [
            'УНИКС: Сгенерировать УМК',
            new moodle_url('/local/unics/pages/generate_umk.php', ['course_id' => $course->id]),
            'local_unics_course_umk',
        ];
        // Стартовая диагностика: пометить входной тест (определяет стартовый уровень).
        $items[] = [
            'УНИКС: Входная диагностика',
            new moodle_url('/local/unics/pages/course_diagnostic.php', ['course_id' => $course->id]),
            'local_unics_course_diagnostic',
        ];
        // B5: пометить итоговый экзамен курса (+ открытые пересдачи B7).
        $items[] = [
            'УНИКС: Итоговый экзамен',
            new moodle_url('/local/unics/pages/course_final_exam.php', ['course_id' => $course->id]),
            'local_unics_course_final',
        ];
        // B4: контрольные точки курса (промежуточная аттестация) + сводка по учащимся.
        $items[] = [
            'УНИКС: Контрольные точки',
            new moodle_url('/local/unics/pages/course_milestones.php', ['course_id' => $course->id]),
            'local_unics_course_milestones',
        ];
    }

    foreach ($items as [$text, $url, $key]) {
        $navigation->add(
            $text,
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            $key,
            new pix_icon('i/cohort', '')
        );
    }
}

function local_unics_extend_navigation(global_navigation $nav) {
    global $DB, $USER, $PAGE;

    // NEW-5: «Сообщения» — наша страница со списком контактов под роль
    // (обёртка над штатным Moodle messaging). Доступна всем ролям, поэтому
    // добавляется ДО ветвления по ролям, одним пунктом.
    if (isloggedin() && !isguestuser()) {
        $nav->add(
            'Сообщения',
            new moodle_url('/local/unics/pages/messenger.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_messenger',
            new pix_icon('t/message', '')
        );
        // A1: Доступность — персональные темы/контраст/шрифт/акцент. Для всех ролей.
        $nav->add(
            'Доступность',
            new moodle_url('/local/unics/pages/accessibility.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_accessibility',
            new pix_icon('t/preferences', '')
        );
    }

    // Учащийся - проверяем по БД в первую очередь, до любых проверок возможностей.
    // Это гарантирует, что неправильно назначенная Moodle-роль не откроет педагогическое меню.
    $student_rec = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
    if ($student_rec) {
        $branch = $nav->add(
            'УНИКС - Мой портал',
            new moodle_url('/local/unics/pages/dashboard.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_student_root',
            new pix_icon('i/cohort', '')
        );
        $branch->add(
            'Мои результаты',
            new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_rec->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_my_report'
        );
        $branch->add(
            'Мои достижения',
            new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student_rec->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_achievements'
        );
        $branch->add(
            'Мой маршрут',
            new moodle_url('/local/unics/pages/my_path.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_my_path'
        );
        if ((int)get_config('local_unics', 'adaptive_cat_enabled') === 1) {
            $branch->add(
                'Адаптивная проверка',
                new moodle_url('/local/unics/pages/cat.php'),
                navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_cat_page'
            );
        }

        // Ссылки «Заметки педагога» для активного курса
        if ($PAGE->context instanceof context_course) {
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
                    new moodle_url('/local/unics/pages/course_notes.php', [
                        'student_id' => $student_rec->id,
                        'courseid'   => $courseid,
                    ]),
                    navigation_node::TYPE_CUSTOM,
                    null,
                    'local_unics_course_notes'
                );
            }
        }
        return;
    }

    // Родитель
    if ($DB->record_exists('unics_parent_student', ['parent_mdl_user_id' => $USER->id])) {
        $nav->add(
            'УНИКС - Мои дети',
            new moodle_url('/local/unics/pages/dashboard.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_parent_root',
            new pix_icon('i/cohort', '')
        );
        return;
    }

    // Педагог / администратор / методист (переработка ролей 2026-05-23).
    $ctx          = context_system::instance();
    $is_admin     = has_capability('local/unics:manage', $ctx);       // системный Moodle-админ
    $is_manageorg = has_capability('local/unics:manageorg', $ctx);    // региональн./районный админ ИЛИ методист
    $is_teacher   = has_capability('local/unics:viewstudents', $ctx); // педагоги + всё выше

    if (!$is_admin && !$is_manageorg && !$is_teacher) {
        return;
    }

    // Региональный / районный администратор — управленческое меню со scope-доступом.
    $is_scoped_admin = !$is_admin && local_unics_is_scoped_admin();
    // Методист организации / районный методист — методическое меню.
    $is_methodist    = !$is_admin && !$is_scoped_admin && local_unics_is_methodist();

    if (!$is_admin && !$is_scoped_admin && !$is_methodist
        && !$DB->record_exists('unics_teachers', ['mdl_user_id' => $USER->id])) {
        // viewstudents/manageorg есть, но это не админ/методист и не реальный педагог УНИКС.
        return;
    }

    // Региональный / районный администратор: меню управленца (без создания курсов и УМК).
    // Закрывает прежний пробел — region_admin раньше не получал никакого меню.
    if ($is_scoped_admin) {
        // Заголовок ветки зависит от роли: региональный методист (v3 фаза 2) делит
        // меню и права с региональным администратором, но это методическая роль.
        $scoped_title = local_unics_user_has_role($USER->id, ['region_methodist'])
            ? 'УНИКС - Портал регионального методиста'
            : 'УНИКС - Портал администратора';
        $branch = $nav->add(
            $scoped_title,
            new moodle_url('/local/unics/pages/dashboard.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_scoped_admin_root',
            new pix_icon('i/cohort', '')
        );
        $branch->add('Все учащиеся',
            new moodle_url('/local/unics/pages/my_students.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_students');
        $branch->add('Создать пользователя',
            new moodle_url('/local/unics/pages/create_user.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_create_user');
        $branch->add('Привязки',
            new moodle_url('/local/unics/pages/assign.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_assign');
        $branch->add(get_string('organizations', 'local_unics'),
            new moodle_url('/local/unics/pages/organizations.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_orgs');
        $branch->add('Курсы (архив)',
            new moodle_url('/local/unics/pages/courses.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_courses');
        $branch->add('Делегирование курсов',
            new moodle_url('/local/unics/pages/course_delegation.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_delegation');
        $branch->add('Запись учащихся на курс',
            new moodle_url('/local/unics/pages/enrol_students.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_enrol_students');
        $branch->add('Запись педагогов на курс',
            new moodle_url('/local/unics/pages/enrol_teachers.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_enrol_teachers');
        $branch->add('Отчёт по организации',
            new moodle_url('/local/unics/pages/org_report.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_org_report');
        $branch->add('Статистика',
            new moodle_url('/local/unics/pages/statistics.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_statistics');
        $branch->add('Кодификатор',
            new moodle_url('/local/unics/pages/codifier.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_codifier');
        $branch->add('Журнал',
            new moodle_url('/local/unics/pages/gradebook.php'),
            navigation_node::TYPE_CUSTOM, null, 'local_unics_sa_gradebook');
        return;
    }

    // Меню методиста - короткое, без «Мои учащиеся» (нет своих, но видит всех).
    if ($is_methodist) {
        $branch = $nav->add(
            'УНИКС - Портал методиста',
            new moodle_url('/local/unics/pages/dashboard.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_root',
            new pix_icon('i/cohort', '')
        );
        $branch->add(
            'Все учащиеся',
            new moodle_url('/local/unics/pages/my_students.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_all_students'
        );
        $branch->add(
            'Пользователи',
            new moodle_url('/local/unics/pages/users.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_users'
        );
        $branch->add(
            'Привязки',
            new moodle_url('/local/unics/pages/assign.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_assign'
        );
        // «Организации» — для муниципального методиста (district_methodist): в v3
        // [[role-model-v3-2026-06-11]] он принял функции удалённого муниципального
        // администратора, включая управление организациями своего муниципалитета.
        // Методисту организации (methodist) пункт не показываем — его скоуп = одна орг.
        if (local_unics_user_has_role((int)$USER->id, ['district_methodist'])) {
            $branch->add(
                get_string('organizations', 'local_unics'),
                new moodle_url('/local/unics/pages/organizations.php'),
                navigation_node::TYPE_CUSTOM,
                null,
                'local_unics_methodist_orgs'
            );
        }
        // «Шаблоны курсов» убраны у методиста: курсы создаёт только педагог-создатель
        // и админ (G5 [[role-capability-audit-2026-06-17]], встречи 10-11.06). Методист
        // распределяет детей по уже готовым (делегированным) курсам.
        $branch->add(
            'Курсы (архив)',
            new moodle_url('/local/unics/pages/courses.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_courses'
        );
        $branch->add(
            'Запись учащихся на курс',
            new moodle_url('/local/unics/pages/enrol_students.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_enrol_students'
        );
        $branch->add(
            'Запись педагогов на курс',
            new moodle_url('/local/unics/pages/enrol_teachers.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_enrol_teachers'
        );
        $branch->add(
            'Генерация УМК (ИИ)',
            new moodle_url('/local/unics/pages/generate_umk.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_umk'
        );
        $branch->add(
            'История генерации УМК',
            new moodle_url('/local/unics/pages/umk_status.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_umk_status_methodist'
        );
        $branch->add(
            'Отчёт по организации',
            new moodle_url('/local/unics/pages/org_report.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_org_report'
        );
        $branch->add(
            'Статистика',
            new moodle_url('/local/unics/pages/statistics.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_statistics'
        );
        $branch->add(
            'Журнал',
            new moodle_url('/local/unics/pages/gradebook.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_methodist_gradebook'
        );
        return;
    }

    $root_url = new moodle_url('/local/unics/pages/dashboard.php');

    $branch = $nav->add(
        'УНИКС - Портал',
        $root_url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_root',
        new pix_icon('i/cohort', '')
    );

    // Дашборд - для педагогов и администраторов
    $branch->add(
        'Портал (дашборд)',
        new moodle_url('/local/unics/pages/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_dashboard'
    );

    // Страница «Мои учащиеся» - для всех (педагог видит только своих)
    $branch->add(
        'Мои учащиеся',
        new moodle_url('/local/unics/pages/my_students.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_my_students'
    );

    // Журнал — для педагогов и админа (видит курсы, где есть grade:viewall).
    $branch->add(
        'Журнал',
        new moodle_url('/local/unics/pages/gradebook.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_unics_gradebook'
    );

    // Генерация УМК + Шаблоны курсов — только для педагогов, создающих курсы
    // (editingteacher, роль 5) и администраторов. Педагог без редактирования
    // (роль 6) контент НЕ создаёт — у него меню read-only (только дашборд + «Мои учащиеся»).
    if (!local_unics_is_nonediting_teacher()) {
        $branch->add(
            'Шаблоны курсов',
            new moodle_url('/local/unics/pages/course_templates.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_course_templates_teacher'
        );
        $branch->add(
            'Генерация УМК (ИИ)',
            new moodle_url('/local/unics/pages/generate_umk.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_umk'
        );
    }

    if ($is_admin) {
        $branch->add(
            get_string('users', 'local_unics'),
            new moodle_url('/local/unics/pages/users.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_users'
        );

        $branch->add(
            'Импорт из CSV',
            new moodle_url('/local/unics/pages/import_users.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_import'
        );

        $branch->add(
            get_string('organizations', 'local_unics'),
            new moodle_url('/local/unics/pages/organizations.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_orgs'
        );

        $branch->add(
            'Курсы (архив)',
            new moodle_url('/local/unics/pages/courses.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_courses'
        );

        $branch->add(
            get_string('assignments', 'local_unics'),
            new moodle_url('/local/unics/pages/assign.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_assign'
        );

        $branch->add(
            'Запись учащихся на курс',
            new moodle_url('/local/unics/pages/enrol_students.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_enrol'
        );

        $branch->add(
            'Запись педагогов на курс',
            new moodle_url('/local/unics/pages/enrol_teachers.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_enrol_teachers'
        );

        $branch->add(
            'Перевод в следующий класс',
            new moodle_url('/local/unics/pages/promote_students.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_promote'
        );

        $branch->add(
            'История генерации УМК',
            new moodle_url('/local/unics/pages/umk_status.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_umk_status'
        );

        // Подраздел «Отчёты»
        $reports = $branch->add(
            'Отчёты',
            new moodle_url('/local/unics/pages/org_report.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_reports'
        );
        $reports->add(
            'Отчёт по организации',
            new moodle_url('/local/unics/pages/org_report.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_org_report'
        );
        $reports->add(
            'Статистика',
            new moodle_url('/local/unics/pages/statistics.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_unics_statistics'
        );

    }
}

// =============================================================================
// A1. Доступность (per-user темы + голосовой ввод) — [[accessibility-features-design]]
// =============================================================================

/**
 * Допустимые значения предпочтений доступности.
 * Используется и при чтении (валидация), и формой accessibility.php (рендер опций).
 *
 * @return array<string, string[]>
 */
function local_unics_a11y_allowed_values(): array {
    return [
        'theme'    => ['light', 'dark'],
        'contrast' => ['0', '1'],
        'font'     => ['normal', 'large', 'xlarge'],
        'accent'   => ['default', 'blue', 'green', 'purple'],
    ];
}

/**
 * Читает предпочтения доступности текущего (или указанного) пользователя
 * из Moodle user preferences. Невалидные/отсутствующие значения → дефолт.
 *
 * @param int|null $userid id пользователя; null = текущий
 * @return array{theme:string, contrast:string, font:string, accent:string}
 */
function local_unics_a11y_get_prefs(?int $userid = null): array {
    $allowed = local_unics_a11y_allowed_values();
    $defaults = ['theme' => 'light', 'contrast' => '0', 'font' => 'normal', 'accent' => 'default'];
    $prefs = [];
    foreach ($defaults as $key => $def) {
        $val = get_user_preferences('local_unics_a11y_' . $key, $def, $userid);
        $prefs[$key] = in_array($val, $allowed[$key], true) ? $val : $def;
    }
    return $prefs;
}

/**
 * Callback ядра Moodle: вызывается при построении <head> на каждой странице.
 *
 * Делает две вещи:
 *  1. Доступность — по предпочтениям пользователя вешает классы на <body>
 *     (тёмная схема / контраст / акцент) и возвращает инлайн-стиль масштаба шрифта
 *     (на <html>, т.к. rem считается от корня, а классы мы вешаем на body).
 *  2. Голосовой ввод — если включён админом и страница это assign/quiz, подключает
 *     AMD-модуль, навешивающий кнопку «Диктовать» на текстовые поля ответа.
 *
 * @return string HTML/стиль для вставки в <head>
 */
function local_unics_before_standard_html_head(): string {
    global $PAGE;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $out = '';

    // --- 1. Доступность: классы на <html> + масштаб шрифта. ---
    // Классы вешаем на <html> инлайн-скриптом в <head>, а НЕ через
    // $PAGE->add_body_class(): к моменту вызова этого callback'а страница уже в
    // состоянии PRINTING_HEADER, и add_body_class бросает coding_exception.
    // Скрипт в <head> отрабатывает синхронно до отрисовки body — без мигания.
    $prefs = local_unics_a11y_get_prefs();
    $classes = [];
    if ($prefs['theme'] === 'dark') {
        $classes[] = 'unics-a11y-dark';
    }
    if ($prefs['contrast'] === '1') {
        $classes[] = 'unics-a11y-contrast';
    }
    if ($prefs['accent'] !== 'default') {
        $classes[] = 'unics-a11y-accent-' . $prefs['accent'];
    }
    if ($classes) {
        $json = json_encode($classes);
        $out .= '<script>(function(c){var e=document.documentElement;'
              . 'c.forEach(function(x){e.classList.add(x);});})(' . $json . ');</script>' . "\n";
    }
    if ($prefs['font'] !== 'normal') {
        $scale = $prefs['font'] === 'xlarge' ? '125%' : '112.5%';
        $out .= '<style id="unics-a11y-font">html{font-size:' . $scale . ';}</style>' . "\n";
    }

    // --- 2. Голосовой ввод (Web Speech API). ---
    if (get_config('local_unics', 'voice_input_enabled')) {
        $pagetype = (string)$PAGE->pagetype;
        // Страницы ответа: онлайн-текст задания и попытка теста.
        $voicepages = ['mod-assign-view', 'mod-quiz-attempt'];
        if (in_array($pagetype, $voicepages, true)) {
            try {
                $PAGE->requires->js_call_amd('local_unics/voice_input', 'init');
            } catch (\Throwable $e) {
                // Нефатально — кнопка просто не появится.
            }
        }
    }

    return $out;
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
function local_unics_render_navbar_output(\renderer_base $output): string {
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
        $burger = html_writer::tag('button',
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
        $links .= html_writer::link(
            new moodle_url($path),
            $output->pix_icon($pix, $label),
            [
                'class'      => 'nav-link unics-navbar-link',
                'title'      => $label,
                'aria-label' => $label,
            ]
        );
    }

    return html_writer::div($burger . $links, 'd-flex align-items-center unics-navbar-actions');
}

/**
 * Структура бокового рельса (G3 per-role-shell) для текущего пользователя.
 *
 * Единый ИСТОЧНИК пунктов оболочки: те же ролевые правила, что в
 * {@see local_unics_extend_navigation}, но сгруппированные по смыслу
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
function local_unics_get_shell_nav(): array {
    global $USER, $DB, $PAGE;

    if (!isloggedin() || isguestuser()) {
        return ['groups' => []];
    }

    $current = $PAGE->has_set_url() ? $PAGE->url->out_omit_querystring() : '';
    // Фабрика пункта: метка, путь, иконка (pix-ключ), доп. query-параметры, опц. override активности.
    $item = function (string $label, string $path, string $icon = '', array $params = [],
                      ?bool $active = null) use ($current): array {
        $url = new moodle_url($path, $params);
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
    [$railcourses, $railtrunc] = local_unics_get_rail_courses();
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
    $student_rec = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
    if ($student_rec) {
        $sid    = (int)$student_rec->id;
        $groups = $base;

        $learn = [$item('Мой маршрут', '/local/unics/pages/my_path.php', 'i/competencies')];
        // «Заметки педагога» - только в контексте курса и если они есть (зеркало
        // extend_navigation; на страницах /local/unics/* контекст курса появится с
        // охватом курса в следующем срезе).
        if ($PAGE->context instanceof context_course) {
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
    if ($DB->record_exists('unics_parent_student', ['parent_mdl_user_id' => $USER->id])) {
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
    $ctx          = context_system::instance();
    $is_admin     = has_capability('local/unics:manage', $ctx);
    $is_manageorg = has_capability('local/unics:manageorg', $ctx);
    $is_teacher   = has_capability('local/unics:viewstudents', $ctx);
    if (!$is_admin && !$is_manageorg && !$is_teacher) {
        return ['groups' => []];
    }

    $is_scoped_admin = !$is_admin && local_unics_is_scoped_admin();
    $is_methodist    = !$is_admin && !$is_scoped_admin && local_unics_is_methodist();
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
        if (local_unics_user_has_role((int)$USER->id, ['district_methodist'])) {
            $groups[] = ['label' => 'Организации', 'items' => [
                $item($orgs_label, '/local/unics/pages/organizations.php', 'i/cohort'),
            ]];
        }
        $groups[] = $svyaz;
        return $finalize($groups);
    }

    // Педагог (роль 5 editingteacher / роль 6 teacher) или администратор сайта.
    $can_courses = !local_unics_is_nonediting_teacher() || $is_admin;

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
function local_unics_get_rail_courses(): array {
    $limit   = 5;
    $courses = enrol_get_my_courses('id, fullname', 'ul.timeaccess DESC, fullname ASC');
    if (!$courses) {
        return [[], false];
    }
    return [array_slice($courses, 0, $limit, true), count($courses) > $limit];
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
 * Инкапсулирует групповую изоляцию, фильтр учеников, оценки и матрицу. [[gradebook-export-design]].
 *
 * @return array{notice: ?array{text:string,level:string}, students: array, by_user: array,
 *               item_meta: array, item_class_avg: array}
 */
function local_unics_gradebook_matrix(int $course_id, int $filter_class, string $filter_letter): array {
    global $DB, $USER;
    $result = ['notice' => null, 'students' => [], 'by_user' => [],
               'item_meta' => [], 'item_class_avg' => []];

    $course     = get_course($course_id);
    $course_ctx = context_course::instance($course_id);

    // Групповая изоляция: раздельные группы без accessallgroups -> только свои группы.
    $restrict_uids = null;
    if (groups_get_course_groupmode($course) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $course_ctx)) {
        $my_groups = groups_get_user_groups($course_id, $USER->id);
        $gids = !empty($my_groups[0]) ? $my_groups[0] : [];
        $restrict_uids = [];
        foreach ($gids as $gid) {
            foreach (groups_get_members($gid, 'u.id') as $m) {
                $restrict_uids[(int)$m->id] = true;
            }
        }
        $restrict_uids = array_keys($restrict_uids);
        if (empty($restrict_uids)) {
            $result['notice'] = ['text' =>
                'Вы не состоите ни в одной группе этого курса, а курс использует раздельные группы. '
                . 'Обратитесь к методисту для добавления в группу.', 'level' => 'warning'];
            return $result;
        }
    }

    // Ученики (активные, записанные на курс, + фильтр класса/буквы).
    $where  = 'ue.status = 0 AND s.archived_at IS NULL AND e.courseid = :cid';
    $params = ['cid' => $course_id];
    if ($filter_class > 0)     { $where .= ' AND s.class_number = :fclass';  $params['fclass']  = $filter_class; }
    if ($filter_letter !== '') { $where .= ' AND s.class_letter = :fletter'; $params['fletter'] = $filter_letter; }
    if ($restrict_uids !== null) {
        [$ruin, $ruparams] = $DB->get_in_or_equal($restrict_uids, SQL_PARAMS_NAMED, 'ru');
        $where .= " AND s.mdl_user_id {$ruin}";
        $params += $ruparams;
    }
    $students = $DB->get_records_sql(
        "SELECT DISTINCT s.id AS student_id, s.mdl_user_id,
                u.lastname, u.firstname, u.middlename,
                s.class_number, s.class_letter
           FROM {unics_students} s
           JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
           JOIN {user_enrolments} ue ON ue.userid = s.mdl_user_id
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE {$where}
          ORDER BY s.class_number, s.class_letter, u.lastname, u.firstname",
        $params);
    if (empty($students)) {
        $result['notice'] = ['text' =>
            'На этот курс не записано активных учащихся по выбранным условиям.', 'level' => 'info'];
        return $result;
    }

    // Оценки + матрица.
    $user_ids = array_map('intval', array_column((array)$students, 'mdl_user_id'));
    [$uin, $uparams] = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NAMED, 'u');
    $grade_rows = $DB->get_records_sql(
        "SELECT g.id, g.userid, g.itemid, g.finalgrade, gi.grademax, gi.itemname, gi.itemmodule,
                gi.sortorder, COALESCE(g.timemodified, g.timecreated, 0) AS gtime
           FROM {grade_grades} g
           JOIN {grade_items} gi ON gi.id = g.itemid
          WHERE g.userid {$uin}
            AND gi.courseid   = :cid
            AND gi.itemtype   = 'mod'
            AND gi.grademax   > 0
            AND g.finalgrade IS NOT NULL
          ORDER BY g.userid, gtime, g.id",
        $uparams + ['cid' => $course_id]);

    $by_user   = [];
    $item_meta = [];
    $item_sum  = [];
    $item_cnt  = [];
    foreach ($grade_rows as $gr) {
        $uid = (int)$gr->userid;
        $iid = (int)$gr->itemid;
        $pct = $gr->finalgrade / (float)$gr->grademax * 100;
        $by_user[$uid][] = [
            'itemid' => $iid,
            'pct'    => $pct,
            'val'    => \local_unics\grade_scale::from_percent($pct),
            'name'   => $gr->itemname ?: $gr->itemmodule,
            'time'   => (int)$gr->gtime,
        ];
        if (!isset($item_meta[$iid])) {
            $item_meta[$iid] = ['name' => $gr->itemname ?: $gr->itemmodule, 'sortorder' => (int)$gr->sortorder];
            $item_sum[$iid]  = 0.0;
            $item_cnt[$iid]  = 0;
        }
        $item_sum[$iid] += $pct;
        $item_cnt[$iid]++;
    }
    if (empty($grade_rows)) {
        $result['notice'] = ['text' => 'Оценок по этому курсу ещё нет.', 'level' => 'info'];
        return $result;
    }

    $item_class_avg = [];
    foreach ($item_sum as $iid => $sum) {
        $item_class_avg[$iid] = \local_unics\grade_scale::from_percent($sum / $item_cnt[$iid]);
    }

    $result['students']       = $students;
    $result['by_user']        = $by_user;
    $result['item_meta']      = $item_meta;
    $result['item_class_avg'] = $item_class_avg;
    return $result;
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
