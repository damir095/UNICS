<?php
/**
 * Единый портал УНИКС - дашборд под роль (G3 per-role-shell, срез «дашборд-каркас»).
 *
 * Один каркас на все роли (по INFORMATION_ARCHITECTURE.md):
 *   1) Приветствие (имя + роль + строка контекста)
 *   2) Требует внимания (0-N карточек-действий; скрыт, если пусто - сигналы v1 = след. срез)
 *   3) Быстрые действия (короткий курированный набор карточек, как в рельсе)
 *   4) Метрики (.unics-stat-card)
 *   + при необходимости - роль-специфичный дополнительный блок (последние тесты/УМК/дети).
 *
 * Рендер - mustache (2.5 аудита, [[mustache-dashboard-design]]): ветки ролей собирают
 * ОДИН $context, разметка целиком в templates/dashboard.mustache. Запросы/гарды не менялись.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
global $USER, $DB, $OUTPUT;

$ctx        = context_system::instance();
$is_admin   = has_capability('local/unics:manage',       $ctx);
$is_teacher = has_capability('local/unics:viewstudents', $ctx);
// Региональный / районный администратор - manageorg без системного manage.
$is_scoped_admin = !$is_admin && local_unics_is_scoped_admin();
$is_methodist    = !$is_admin && !$is_scoped_admin && local_unics_is_methodist();

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/dashboard.php'));
$PAGE->set_title('УНИКС - Портал');
$PAGE->set_heading('УНИКС - Единый портал');
$PAGE->set_pagelayout('standard');

// ============================================================================
// Хелперы сборки контекста шаблона (разметка - в templates/dashboard.mustache).
// ============================================================================

// Иконка карточки: pix_icon с доп. классом (SCSS-сайзинг) - хелпер {{#pix}} так не умеет,
// поэтому пре-рендер здесь (доверенный вывод ядра, в шаблоне тройной скобкой).
$card_icon = function (?string $pix, string $extraclass) use ($OUTPUT): string {
    return $pix ? $OUTPUT->pix_icon($pix, '', 'moodle', ['class' => 'icon ' . $extraclass]) : '';
};

// Карточка «Требует внимания»: ['label','url','icon'(opt),'tone'(opt),'badge'(opt)].
$attention_ctx = function (array $cards) use ($card_icon): ?array {
    if (empty($cards)) {
        return null;
    }
    return ['cards' => array_map(fn($c) => [
        'url'        => (string)$c['url'],
        'label'      => $c['label'],
        'icon_html'  => $card_icon($c['icon'] ?? null, 'unics-attention-card__icon'),
        'tone_class' => !empty($c['tone']) ? 'unics-attention-card--' . $c['tone'] : null,
        'badge'      => $c['badge'] ?? null,
    ], $cards)];
};

// Карточка «Быстрые действия»: ['label','url','icon','badge'(opt)].
$actions_ctx = function (array $actions) use ($card_icon): ?array {
    if (empty($actions)) {
        return null;
    }
    return ['cards' => array_map(fn($a) => [
        'url'       => (string)$a['url'],
        'label'     => $a['label'],
        'icon_html' => $card_icon($a['icon'] ?? null, 'unics-action-card__icon'),
        'badge'     => $a['badge'] ?? null,
    ], $actions)];
};

// Метрики: элементы уже в форме контекста (value | pct_badge | lvl_pill; label | lvl_label_pill).
$metrics_ctx = function (array $items): ?array {
    if (empty($items)) {
        return null;
    }
    foreach ($items as &$m) {
        $m['col'] = $m['col'] ?? 'col-6 col-md-3';
    }
    return ['items' => $items];
};

$pct_tone = fn(float $pct): string => $pct >= 85 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');

$level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

$context = [];

// ----------------------------------------------------------------
// АДМИНИСТРАТОР
// ----------------------------------------------------------------
if ($is_admin) {

    $total_students  = \local_unics\identity\student_helper::count_active_students();
    $total_orgs      = $DB->count_records('unics_organizations', ['is_active' => 1]);
    $ai_in_queue     = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {unics_ai_queue} WHERE status IN (1, 2)");
    $total_umk_ready = $DB->count_records('unics_umk', ['status' => 3]);

    $fio_admin = trim($USER->lastname . ' ' . $USER->firstname);

    $context['welcome'] = ['greeting' => 'Добро пожаловать, ' . $fio_admin,
        'subline' => 'Панель администратора УНИКС'];

    $attention = [];
    if ($ai_in_queue > 0) {
        $attention[] = ['label' => 'УМК в очереди',
            'url'  => new moodle_url('/local/unics/pages/umk_status.php'),
            'icon' => 'i/edit', 'tone' => 'info', 'badge' => $ai_in_queue];
    }
    $nocourse = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {unics_students} s
           JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
          WHERE s.archived_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM {user_enrolments} ue
                              JOIN {enrol} e ON e.id = ue.enrolid
                             WHERE ue.userid = s.mdl_user_id AND ue.status = 0)");
    if ($nocourse > 0) {
        $attention[] = ['label' => 'Учащиеся без курса',
            'url'  => new moodle_url('/local/unics/pages/enrol_students.php'),
            'icon' => 'i/users', 'tone' => 'warning', 'badge' => $nocourse];
    }
    $adapt_n = count(\local_unics\learning\suggestion_service::list_open_for_user((int)$USER->id));
    if ($adapt_n > 0) {
        $attention[] = ['label' => 'Адаптивные предложения',
            'url'  => new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
            'icon' => 'i/checkpermissions', 'tone' => 'info', 'badge' => $adapt_n];
    }
    $context['attention'] = $attention_ctx($attention);
    $context['actions'] = $actions_ctx([
        ['label' => 'Адаптивные предложения', 'url' => new moodle_url('/local/unics/pages/adaptive_suggestions.php'), 'icon' => 'i/checkpermissions'],
        ['label' => 'Пользователи',  'url' => new moodle_url('/local/unics/pages/users.php'),        'icon' => 'i/user'],
        ['label' => 'Генерация УМК', 'url' => new moodle_url('/local/unics/pages/generate_umk.php'), 'icon' => 'i/edit'],
        ['label' => 'Все учащиеся',  'url' => new moodle_url('/local/unics/pages/my_students.php'),   'icon' => 'i/users'],
        ['label' => 'Журнал',        'url' => new moodle_url('/local/unics/pages/gradebook.php'),     'icon' => 'i/grades'],
        ['label' => 'Организации',   'url' => new moodle_url('/local/unics/pages/organizations.php'), 'icon' => 'i/cohort'],
        ['label' => 'Статистика',    'url' => new moodle_url('/local/unics/pages/statistics.php'),    'icon' => 'i/stats'],
    ]);
    $context['metrics'] = $metrics_ctx([
        ['value' => $total_students,  'label' => 'Учащихся'],
        ['value' => $total_orgs,      'label' => 'Организаций'],
        ['value' => $total_umk_ready, 'label' => 'УМК готово'],
        ['value' => $ai_in_queue,     'label' => 'В очереди ИИ'],
    ]);

    // Доп. блок: последние генерации УМК.
    $recent_umk = $DB->get_records_sql(
        "SELECT u.id, u.title, u.difficulty_level, u.status, u.generated_at,
                (SELECT COUNT(*) FROM {unics_umk_students} us WHERE us.umk_id = u.id) AS student_count
           FROM {unics_umk} u
          ORDER BY u.id DESC
          LIMIT 5"
    );
    $status_labels = [1 => 'В очереди', 2 => 'Обрабатывается', 3 => 'Готов', 4 => 'Ошибка'];
    $status_colors = [1 => 'secondary', 2 => 'info', 3 => 'success', 4 => 'danger'];
    $context['umk_table'] = [
        'empty' => empty($recent_umk),
        'rows'  => array_map(fn($u) => [
            'title'        => $u->title,
            'lvl'          => (int)$u->difficulty_level,
            'lvl_label'    => $level_labels[$u->difficulty_level] ?? '?',
            'students'     => (int)$u->student_count,
            'status_tone'  => $status_colors[$u->status] ?? 'secondary',
            'status_label' => $status_labels[$u->status] ?? '?',
            'date'         => $u->generated_at ? date('d.m.Y', (int)$u->generated_at) : '-',
        ], array_values($recent_umk)),
    ];

// ----------------------------------------------------------------
// РЕГИОНАЛЬНЫЙ / РАЙОННЫЙ АДМИНИСТРАТОР (manageorg, скоуп)
// ----------------------------------------------------------------
} elseif ($is_scoped_admin) {

    [$sw, $sp] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $total_students = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {unics_students} s
            JOIN {unics_organizations} o ON o.id = s.organization_id
            JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
          WHERE s.archived_at IS NULL AND ({$sw})", $sp);
    $total_orgs = (int)$DB->count_records_sql(
        "SELECT COUNT(o.id) FROM {unics_organizations} o WHERE o.is_active = 1 AND ({$sw})", $sp);

    $scope = \local_unics\identity\scope_checker::get_user_scope((int)$USER->id);
    $scope_name = '';
    if ($scope['district_id']) {
        $scope_name = (string)$DB->get_field('unics_districts', 'name', ['id' => $scope['district_id']]);
    } else if ($scope['region_id']) {
        $scope_name = (string)$DB->get_field('unics_regions', 'name', ['id' => $scope['region_id']]);
    }

    $fio_admin = trim($USER->lastname . ' ' . $USER->firstname);
    // Региональный методист (v3 фаза 2) делит дашборд с региональным администратором,
    // но это методическая роль - меняем подпись панели.
    $panel_label = local_unics_user_has_role((int)$USER->id, ['region_methodist'])
        ? 'Портал регионального методиста'
        : 'Панель администратора';

    $context['welcome'] = ['greeting' => 'Добро пожаловать, ' . $fio_admin,
        'subline' => $panel_label . ($scope_name ? ' - ' . $scope_name : '')];

    $attention = [];
    $nocourse = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {unics_students} s
           JOIN {unics_organizations} o ON o.id = s.organization_id
           JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
          WHERE s.archived_at IS NULL AND ({$sw})
            AND NOT EXISTS (SELECT 1 FROM {user_enrolments} ue
                              JOIN {enrol} e ON e.id = ue.enrolid
                             WHERE ue.userid = s.mdl_user_id AND ue.status = 0)", $sp);
    if ($nocourse > 0) {
        $attention[] = ['label' => 'Учащиеся без курса',
            'url'  => new moodle_url('/local/unics/pages/enrol_students.php'),
            'icon' => 'i/users', 'tone' => 'warning', 'badge' => $nocourse];
    }
    $context['attention'] = $attention_ctx($attention);
    $context['actions'] = $actions_ctx([
        ['label' => 'Все учащиеся',        'url' => new moodle_url('/local/unics/pages/my_students.php'),      'icon' => 'i/users'],
        ['label' => 'Делегирование курсов','url' => new moodle_url('/local/unics/pages/course_delegation.php'),'icon' => 'i/permissions'],
        ['label' => 'Организации',         'url' => new moodle_url('/local/unics/pages/organizations.php'),    'icon' => 'i/cohort'],
        ['label' => 'Статистика',          'url' => new moodle_url('/local/unics/pages/statistics.php'),       'icon' => 'i/stats'],
        ['label' => 'Кодификатор',         'url' => new moodle_url('/local/unics/pages/codifier.php'),         'icon' => 'i/competencies'],
    ]);
    $context['metrics'] = $metrics_ctx([
        ['value' => $total_students, 'label' => 'Учащихся'],
        ['value' => $total_orgs,     'label' => 'Организаций'],
    ]);

// ----------------------------------------------------------------
// МЕТОДИСТ
// ----------------------------------------------------------------
} elseif ($is_methodist) {

    $fio_methodist = trim($USER->lastname . ' ' . $USER->firstname);

    // Скоуп методиста берём из unics_user_org через scope_checker (как my_students/
    // statistics), а НЕ из unics_teachers.organization_id: у муниципального методиста
    // записи в unics_teachers нет, и метрики раньше считались по ВСЕЙ системе.
    [$sw, $sp] = \local_unics\identity\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $scope     = \local_unics\identity\scope_checker::get_user_scope((int)$USER->id);
    if ($scope['organization_id']) {
        $scope_name     = (string)$DB->get_field('unics_organizations', 'name', ['id' => $scope['organization_id']]);
        $students_label = 'Учащихся в организации';
    } else if ($scope['district_id']) {
        $scope_name     = (string)$DB->get_field('unics_districts', 'name', ['id' => $scope['district_id']]);
        $students_label = 'Учащихся в муниципалитете';
    } else {
        $scope_name     = '';
        $students_label = 'Учащихся в системе';
    }

    // Учащиеся в скоупе (организация / муниципалитет) - единообразно через org_filter_sql.
    $total_students = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {unics_students} s
           JOIN {unics_organizations} o ON o.id = s.organization_id
           JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
          WHERE s.archived_at IS NULL AND ({$sw})", $sp);

    $umk_active = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {unics_ai_queue} WHERE status IN (1, 2)");
    $umk_ready  = $DB->count_records('unics_umk', ['status' => 3]);

    $is_district = local_unics_user_has_role((int)$USER->id, ['district_methodist']);

    $context['welcome'] = ['greeting' => 'Здравствуйте, ' . $fio_methodist,
        'subline' => 'Портал методиста УНИКС' . ($scope_name ? ' - ' . $scope_name : '')];

    $attention = [];
    // Дети без курса - в пределах скоупа методиста (тот же org_filter_sql).
    $nocourse = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {unics_students} s
           JOIN {unics_organizations} o ON o.id = s.organization_id
           JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
          WHERE s.archived_at IS NULL AND ({$sw})
            AND NOT EXISTS (SELECT 1 FROM {user_enrolments} ue
                              JOIN {enrol} e ON e.id = ue.enrolid
                             WHERE ue.userid = s.mdl_user_id AND ue.status = 0)", $sp);
    if ($nocourse > 0) {
        $attention[] = ['label' => 'Дети без курса',
            'url'  => new moodle_url('/local/unics/pages/enrol_students.php'),
            'icon' => 'i/users', 'tone' => 'warning', 'badge' => $nocourse];
    }
    if ($umk_ready > 0) {
        $attention[] = ['label' => 'УМК готово',
            'url'  => new moodle_url('/local/unics/pages/umk_status.php'),
            'icon' => 'i/edit', 'tone' => 'success', 'badge' => $umk_ready];
    }
    $msgs = \core_message\api::count_unread_conversations();
    if ($msgs > 0) {
        $attention[] = ['label' => 'Новые сообщения',
            'url'  => new moodle_url('/local/unics/pages/messenger.php'),
            'icon' => 't/message', 'tone' => 'info', 'badge' => $msgs];
    }
    $adapt_n = count(\local_unics\learning\suggestion_service::list_open_for_user((int)$USER->id));
    if ($adapt_n > 0) {
        $attention[] = ['label' => 'Адаптивные предложения',
            'url'  => new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
            'icon' => 'i/checkpermissions', 'tone' => 'info', 'badge' => $adapt_n];
    }
    $context['attention'] = $attention_ctx($attention);

    $actions = [
        ['label' => 'Адаптивные предложения',  'url' => new moodle_url('/local/unics/pages/adaptive_suggestions.php'), 'icon' => 'i/checkpermissions'],
        ['label' => 'Все учащиеся',            'url' => new moodle_url('/local/unics/pages/my_students.php'),    'icon' => 'i/users'],
        ['label' => 'Запись учащихся на курс', 'url' => new moodle_url('/local/unics/pages/enrol_students.php'), 'icon' => 'i/users'],
        ['label' => 'Генерация УМК',           'url' => new moodle_url('/local/unics/pages/generate_umk.php'),   'icon' => 'i/edit'],
        ['label' => 'Статистика',              'url' => new moodle_url('/local/unics/pages/statistics.php'),     'icon' => 'i/stats'],
    ];
    // «Организации» - муниципальному методисту (district_methodist), принявшему функции
    // удалённого муниципального администратора (v3 [[role-model-v3-2026-06-11]]).
    if ($is_district) {
        $actions[] = ['label' => 'Организации',
            'url' => new moodle_url('/local/unics/pages/organizations.php'), 'icon' => 'i/cohort'];
    }
    $context['actions'] = $actions_ctx($actions);

    $context['metrics'] = $metrics_ctx([
        ['value' => $total_students, 'label' => $students_label],
        ['value' => $umk_active,     'label' => 'УМК в очереди'],
        ['value' => $umk_ready,      'label' => 'УМК готово'],
    ]);

// ----------------------------------------------------------------
// ПЕДАГОГ
// ----------------------------------------------------------------
} elseif ($is_teacher) {

    $teacher_rec    = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $level_counts   = [1 => 0, 2 => 0, 3 => 0];
    $my_students    = [];
    $my_student_ids = [];

    if ($teacher_rec) {
        $my_students = $DB->get_records_sql(
            "SELECT s.id, s.mdl_user_id, s.difficulty_level
               FROM {unics_teacher_student} ts
               JOIN {unics_students} s ON s.id = ts.student_id
               JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
              WHERE ts.teacher_id = :tid AND s.archived_at IS NULL",
            ['tid' => $teacher_rec->id]
        );
        foreach ($my_students as $s) {
            $my_student_ids[] = (int)$s->id;
            $lv = (int)$s->difficulty_level;
            if (isset($level_counts[$lv])) {
                $level_counts[$lv]++;
            }
        }
    }
    $total_my = count($my_student_ids);

    // Средний балл по последним 5 тестам каждого учащегося.
    $avg_overall = null;
    if (!empty($my_students)) {
        $all_uids = array_column((array)$my_students, 'mdl_user_id');
        [$in_sql, $in_params] = $DB->get_in_or_equal(array_map('intval', $all_uids));
        $grade_rows = $DB->get_records_sql(
            "SELECT g.id, g.userid, g.finalgrade, gi.grademax
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE g.userid {$in_sql}
                AND gi.itemtype   = 'mod'
                AND gi.itemmodule = 'quiz'
                AND g.finalgrade IS NOT NULL
                AND gi.grademax   > 0
              ORDER BY g.userid, g.timemodified DESC",
            $in_params
        );
        $uid_grades = [];
        foreach ($grade_rows as $gr) {
            $uid = (int)$gr->userid;
            if (!isset($uid_grades[$uid])) {
                $uid_grades[$uid] = [];
            }
            if (count($uid_grades[$uid]) < 5) {
                $uid_grades[$uid][] = $gr->finalgrade / $gr->grademax * 100;
            }
        }
        $all_avgs = [];
        foreach ($uid_grades as $pcts) {
            if (count($pcts) >= 1) {
                $all_avgs[] = array_sum($pcts) / count($pcts);
            }
        }
        if (!empty($all_avgs)) {
            $avg_overall = round(array_sum($all_avgs) / count($all_avgs), 1);
        }
    }

    $fio_teacher = trim($USER->lastname . ' ' . $USER->firstname);
    $is_editing  = !local_unics_is_nonediting_teacher();

    $context['welcome'] = ['greeting' => 'Добро пожаловать, ' . $fio_teacher,
        'subline' => 'Личный кабинет педагога УНИКС'];

    // v1-сигналы (дёшево): непрочитанные сообщения. Работы на проверку / риск падения
    // уровня - дороже по данным, отложены (IA v1-минимум).
    $attention = [];
    $msgs = \core_message\api::count_unread_conversations();
    if ($msgs > 0) {
        $attention[] = ['label' => 'Новые сообщения',
            'url'  => new moodle_url('/local/unics/pages/messenger.php'),
            'icon' => 't/message', 'tone' => 'info', 'badge' => $msgs];
    }
    $adapt_n = count(\local_unics\learning\suggestion_service::list_open_for_user((int)$USER->id));
    if ($adapt_n > 0) {
        $attention[] = ['label' => 'Адаптивные предложения',
            'url'  => new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
            'icon' => 'i/checkpermissions', 'tone' => 'info', 'badge' => $adapt_n];
    }
    $context['attention'] = $attention_ctx($attention);

    $actions = [
        ['label' => 'Мои учащиеся', 'url' => new moodle_url('/local/unics/pages/my_students.php'), 'icon' => 'i/users'],
        ['label' => 'Адаптивные предложения', 'url' => new moodle_url('/local/unics/pages/adaptive_suggestions.php'), 'icon' => 'i/checkpermissions'],
        ['label' => 'Журнал',       'url' => new moodle_url('/local/unics/pages/gradebook.php'),   'icon' => 'i/grades'],
    ];
    // Педагог без редактирования (роль 6) контент не создаёт - не показываем
    // «Генерация УМК» / «Шаблоны курсов» (иначе клик ведёт на accessdenied).
    if ($is_editing) {
        $actions[] = ['label' => 'Генерация УМК',
            'url' => new moodle_url('/local/unics/pages/generate_umk.php'), 'icon' => 'i/edit'];
        $actions[] = ['label' => 'Шаблоны курсов',
            'url' => new moodle_url('/local/unics/pages/course_templates.php'), 'icon' => 'i/course'];
    }
    $context['actions'] = $actions_ctx($actions);

    $metrics = [['value' => $total_my, 'label' => 'Моих учащихся']];
    if ($avg_overall !== null) {
        $metrics[] = [
            'pct_badge' => ['pct' => $avg_overall, 'tone' => $pct_tone($avg_overall)],
            'label'     => 'Средний балл',
        ];
    }
    foreach ($level_labels as $lv => $lbl) {
        $metrics[] = [
            'value'          => $level_counts[$lv],
            'lvl_label_pill' => ['lv' => $lv, 'label' => $lbl],
            'col'            => 'col-6 col-md-2',
        ];
    }
    $context['metrics'] = $metrics_ctx($metrics);

// ----------------------------------------------------------------
// УЧАЩИЙСЯ / РОДИТЕЛЬ
// ----------------------------------------------------------------
} else {
    $student = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);

    if ($student) {
        // ---- Учащийся ----
        require_once(__DIR__ . '/../classes/social/points_manager.php');

        $points_bal   = \local_unics\social\points_manager::get_balance((int)$student->id);
        $active_title = \local_unics\social\points_manager::get_active_title((int)$student->id);

        $class_str = $student->class_number
            ? $student->class_number . ($student->class_letter ? " «{$student->class_letter}»" : '') . ' класс'
            : '-';

        // Приветствие с бейджем активного титула (если есть).
        $welcome = ['greeting' => 'Привет, ' . $USER->firstname . '!', 'subline' => $class_str];
        if ($active_title) {
            $welcome['title_badge'] = [
                'name'    => $active_title->name,
                'iconurl' => !empty($active_title->icon)
                    ? $OUTPUT->image_url('shop/' . $active_title->icon, 'local_unics')->out(false)
                    : null,
            ];
        }
        // Аватар ученика (личная деталь, всегда) + надетая рамка/акцент (украшают, когда есть).
        // Только свой вид ученика; у прочих ролей welcome без avatar/accent (разметка прежняя).
        $eq_frame  = \local_unics\social\equipment_manager::get_equipped((int)$student->id, 'frame');
        $eq_accent = \local_unics\social\equipment_manager::get_equipped((int)$student->id, 'accent');
        $welcome['avatar'] = [
            'avatar_html' => $OUTPUT->user_picture($USER, ['size' => 56, 'link' => false]),
            'frame_class' => ($eq_frame && $eq_frame->effect_key)
                ? 'unics-frame-' . $eq_frame->effect_key : '',
        ];
        if ($eq_accent && $eq_accent->effect_key) {
            $welcome['accent_class'] = 'unics-welcome--' . $eq_accent->effect_key;
        }
        $context['welcome'] = $welcome;

        // v1-сигналы (дёшево): незавершённый тест, новые заметки педагога, новые сообщения.
        $attention = [];
        $inprogress = $DB->get_record_sql(
            "SELECT qa.id, cm.id AS cmid, q.name
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
              WHERE qa.userid = :uid AND qa.state = 'inprogress'
              ORDER BY qa.timestart DESC",
            ['uid' => $student->mdl_user_id], IGNORE_MULTIPLE);
        if ($inprogress) {
            $attention[] = ['label' => 'Продолжить тест: ' . $inprogress->name,
                'url'  => new moodle_url('/mod/quiz/view.php', ['id' => $inprogress->cmid]),
                'icon' => 'i/grades', 'tone' => 'warning'];
        }
        $unread_notes = \local_unics\social\comment_manager::count_unread((int)$student->id, (int)$USER->id);
        if ($unread_notes > 0) {
            $attention[] = ['label' => 'Новые заметки педагога',
                'url'  => new moodle_url('/local/unics/pages/student_report.php',
                    ['student_id' => $student->id], 'notes'),
                'icon' => 'i/edit', 'tone' => 'info', 'badge' => $unread_notes];
        }
        $msgs = \core_message\api::count_unread_conversations();
        if ($msgs > 0) {
            $attention[] = ['label' => 'Новые сообщения',
                'url'  => new moodle_url('/local/unics/pages/messenger.php'),
                'icon' => 't/message', 'tone' => 'info', 'badge' => $msgs];
        }
        $context['attention'] = $attention_ctx($attention);

        $context['actions'] = $actions_ctx([
            ['label' => 'Мой маршрут',
                'url' => new moodle_url('/local/unics/pages/my_path.php', ['student_id' => $student->id]),
                'icon' => 'i/competencies'],
            ['label' => 'Мои результаты',
                'url' => new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student->id]),
                'icon' => 'i/report'],
            ['label' => 'Мои достижения',
                'url' => new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student->id]),
                'icon' => 'i/badge'],
            ['label' => 'Магазин баллов',
                'url' => new moodle_url('/local/unics/pages/shop.php'),
                'icon' => 'i/star'],
        ]);

        // Виджет «Стоит повторить»: топ слабых элементов по полосе владения (если есть).
        $weak = \local_unics\learning\mastery_manager::get_weak_elements((int)$student->id, 3);
        if ($weak) {
            $context['weak_widget'] = [
                'items' => array_map(function ($w) {
                    [$wtext, $wcls] = \local_unics\learning\mastery_manager::band_label((int)$w->band, true);
                    return ['title' => $w->title, 'band_text' => $wtext, 'band_class' => $wcls];
                }, array_values($weak)),
                'details_url' => (string)new moodle_url('/local/unics/pages/codifier_report.php',
                    ['student_id' => $student->id]),
            ];
        }

        $badges_earned = $DB->count_records('unics_achievements', ['student_id' => $student->id]);
        $courses_count = $DB->get_field_sql(
            "SELECT COUNT(DISTINCT e.courseid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND ue.status = 0",
            ['uid' => $student->mdl_user_id]
        );
        $lv        = (int)$student->difficulty_level;
        $lvl_label = $level_labels[$lv] ?? '-';

        $context['metrics'] = $metrics_ctx([
            ['value' => (int)$courses_count, 'label' => 'Курсов'],
            ['value' => $badges_earned . ' / 4', 'label' => 'Значков'],
            ['value' => number_format($points_bal), 'label' => 'Баллов', 'extraclass' => 'unics-points-card'],
            ['lvl_pill' => ['lv' => $lv, 'label' => $lvl_label], 'label' => 'Текущий уровень'],
        ]);

        // Доп. блок: последние тесты.
        $last_grades = $DB->get_records_sql(
            "SELECT g.id, gi.itemname AS quiz_name, c.fullname AS course_name,
                    g.finalgrade, gi.grademax, g.timemodified
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
               JOIN {course} c ON c.id = gi.courseid
              WHERE g.userid       = :uid
                AND gi.itemtype    = 'mod'
                AND gi.itemmodule  = 'quiz'
                AND g.finalgrade  IS NOT NULL
                AND gi.grademax    > 0
              ORDER BY g.timemodified DESC
              LIMIT 3",
            ['uid' => $student->mdl_user_id]
        );
        if (!empty($last_grades)) {
            $context['grades_table'] = ['rows' => array_map(function ($g) use ($pct_tone) {
                $pct = round(($g->finalgrade / $g->grademax) * 100, 1);
                return [
                    'quiz'   => $g->quiz_name ?? '-',
                    'course' => $g->course_name,
                    'score'  => round($g->finalgrade, 1) . '/' . round($g->grademax, 1),
                    'pct'    => $pct,
                    'tone'   => $pct_tone($pct),
                ];
            }, array_values($last_grades))];
        }

    } else {
        // ---- Родитель ----
        $parent_links = $DB->get_records('unics_parent_student', ['parent_mdl_user_id' => $USER->id]);
        if (empty($parent_links)) {
            redirect(new moodle_url('/my'));
        }

        $child_sids = array_column((array)$parent_links, 'student_id');
        [$in_sql, $in_params] = $DB->get_in_or_equal(array_map('intval', $child_sids));
        $children = $DB->get_records_sql(
            "SELECT s.id, s.mdl_user_id, s.class_number, s.class_letter, s.difficulty_level,
                    u.lastname, u.firstname, u.middlename
               FROM {unics_students} s
               JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
              WHERE s.id {$in_sql}
              ORDER BY u.lastname, u.firstname",
            $in_params
        );

        // Последние оценки на каждого ребёнка (для среднего балла).
        $all_uids  = array_column((array)$children, 'mdl_user_id');
        $grade_map = [];
        if (!empty($all_uids)) {
            [$in2, $in2p] = $DB->get_in_or_equal(array_map('intval', $all_uids));
            $grs = $DB->get_records_sql(
                "SELECT g.id, g.userid, g.finalgrade, gi.grademax
                   FROM {grade_grades} g
                   JOIN {grade_items} gi ON gi.id = g.itemid
                  WHERE g.userid {$in2}
                    AND gi.itemtype = 'mod' AND gi.itemmodule = 'quiz'
                    AND g.finalgrade IS NOT NULL AND gi.grademax > 0
                  ORDER BY g.userid, g.timemodified DESC",
                $in2p
            );
            foreach ($grs as $gr) {
                $uid = (int)$gr->userid;
                if (!isset($grade_map[$uid])) {
                    $grade_map[$uid] = [];
                }
                if (count($grade_map[$uid]) < 5) {
                    $grade_map[$uid][] = $gr->finalgrade / $gr->grademax * 100;
                }
            }
        }

        // Выбранный ребёнок (переключатель, если детей > 1). По умолчанию - первый.
        $childids = array_keys($children);
        $sel = optional_param('child', 0, PARAM_INT);
        if (!in_array($sel, $childids, true)) {
            $sel = $childids ? $childids[0] : 0;
        }
        $selchild = $sel ? ($children[$sel] ?? null) : null;

        $fio_parent = trim($USER->lastname . ' ' . $USER->firstname);
        $context['welcome'] = ['greeting' => 'Добро пожаловать, ' . $fio_parent,
            'subline' => 'Портал родителя УНИКС'];

        // Переключатель ребёнка.
        if (count($children) > 1) {
            $context['child_switcher'] = [
                'action_url' => (new moodle_url('/local/unics/pages/dashboard.php'))->out(false),
                'options'    => array_map(fn($ch) => [
                    'id'       => (int)$ch->id,
                    'fio'      => trim("{$ch->lastname} {$ch->firstname}"),
                    'selected' => $ch->id == $sel,
                ], array_values($children)),
            ];
        }

        // v1-сигналы (дёшево): новые заметки педагога по каждому ребёнку, новые сообщения.
        $attention = [];
        foreach ($children as $ch) {
            $un = \local_unics\social\comment_manager::count_unread((int)$ch->id, (int)$USER->id);
            if ($un > 0) {
                $attention[] = ['label' => 'Новые заметки: ' . trim("{$ch->lastname} {$ch->firstname}"),
                    'url'  => new moodle_url('/local/unics/pages/student_report.php',
                        ['student_id' => $ch->id], 'notes'),
                    'icon' => 'i/edit', 'tone' => 'info', 'badge' => $un];
            }
        }
        $msgs = \core_message\api::count_unread_conversations();
        if ($msgs > 0) {
            $attention[] = ['label' => 'Новые сообщения',
                'url'  => new moodle_url('/local/unics/pages/messenger.php'),
                'icon' => 't/message', 'tone' => 'info', 'badge' => $msgs];
        }
        $context['attention'] = $attention_ctx($attention);

        if ($selchild) {
            $context['actions'] = $actions_ctx([
                ['label' => 'Результаты ребёнка',
                    'url' => new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $selchild->id]),
                    'icon' => 'i/report'],
                ['label' => 'Маршрут ребёнка',
                    'url' => new moodle_url('/local/unics/pages/my_path.php', ['student_id' => $selchild->id]),
                    'icon' => 'i/competencies'],
                ['label' => 'Достижения ребёнка',
                    'url' => new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $selchild->id]),
                    'icon' => 'i/badge'],
                ['label' => 'Сообщения',
                    'url' => new moodle_url('/local/unics/pages/messenger.php'),
                    'icon' => 't/message'],
            ]);

            $pcts = $grade_map[$selchild->mdl_user_id] ?? [];
            $avg  = !empty($pcts) ? round(array_sum($pcts) / count($pcts), 1) : null;
            $lv   = (int)$selchild->difficulty_level;
            $context['metrics'] = $metrics_ctx([
                $avg !== null
                    ? ['pct_badge' => ['pct' => $avg, 'tone' => $pct_tone($avg)], 'label' => 'Средний балл']
                    : ['value' => '-', 'label' => 'Средний балл'],
                ['lvl_pill' => ['lv' => $lv, 'label' => $level_labels[$lv] ?? '-'],
                    'label' => 'Текущий уровень'],
            ]);
        }

        // Доп. блок: все дети карточками (с бейджем новых заметок).
        $context['children_cards'] = ['rows' => array_map(function ($ch) use ($grade_map, $pct_tone, $USER) {
            $pcts = $grade_map[$ch->mdl_user_id] ?? [];
            $avg  = !empty($pcts) ? round(array_sum($pcts) / count($pcts), 1) : null;
            return [
                'fio'        => trim("{$ch->lastname} {$ch->firstname} " . ($ch->middlename ?? '')),
                'cls'        => $ch->class_number
                    ? $ch->class_number . ($ch->class_letter ? " «{$ch->class_letter}»" : '')
                    : '-',
                'has_avg'    => $avg !== null,
                'avg'        => $avg,
                'tone'       => $avg !== null ? $pct_tone($avg) : 'secondary',
                'unread'     => \local_unics\social\comment_manager::count_unread((int)$ch->id, (int)$USER->id),
                'report_url' => (string)new moodle_url('/local/unics/pages/student_report.php',
                    ['student_id' => $ch->id], 'notes'),
            ];
        }, array_values($children))];

        $context['children_link'] = ['url' => (string)new moodle_url('/local/unics/pages/my_children.php')];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_unics/dashboard', $context);
echo $OUTPUT->footer();
