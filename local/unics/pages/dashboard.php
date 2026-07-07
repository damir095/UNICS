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
 * Содержимое блоков - per-role; рендер-каркас общий (замыкания ниже).
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

echo $OUTPUT->header();

// ============================================================================
// Общий рендер-каркас (один на все роли). Содержимое блоков передаёт каждая роль.
// ============================================================================

// 1) Приветствие. $titlehtml - уже безопасный HTML (имя экранирует вызывающий).
$render_welcome = function (string $titlehtml, string $subline): void {
    echo '<div class="unics-welcome mb-4">';
    echo '<h2>' . $titlehtml . '</h2>';
    echo '<div class="sub">' . s($subline) . '</div>';
    echo '</div>';
};

// 2) Требует внимания. Карточки-действия; блок скрыт, если массив пуст.
//    $card: ['label','url','icon'(pix, opt),'tone'(opt),'badge'(html, opt)].
$render_attention = function (array $cards) use ($OUTPUT): void {
    if (empty($cards)) {
        return;
    }
    echo '<h2 class="unics-section-title">Требует внимания</h2>';
    echo '<div class="unics-attention mb-4">';
    foreach ($cards as $c) {
        $icon = !empty($c['icon'])
            ? $OUTPUT->pix_icon($c['icon'], '', 'moodle', ['class' => 'icon unics-attention-card__icon'])
            : '';
        $tone  = !empty($c['tone']) ? ' unics-attention-card--' . $c['tone'] : '';
        $badge = !empty($c['badge']) ? ' <span class="badge badge-danger">' . $c['badge'] . '</span>' : '';
        echo html_writer::link($c['url'],
            $icon . '<span class="unics-attention-card__label">' . s($c['label']) . '</span>' . $badge,
            ['class' => 'unics-attention-card' . $tone]);
    }
    echo '</div>';
};

// 3) Быстрые действия - карточки иконка+метка. $a: ['label','url','icon'(pix),'badge'(html, opt)].
$render_actions = function (array $actions) use ($OUTPUT): void {
    if (empty($actions)) {
        return;
    }
    echo '<h2 class="unics-section-title">Быстрые действия</h2>';
    echo '<div class="unics-action-cards mb-4">';
    foreach ($actions as $a) {
        $icon = !empty($a['icon'])
            ? $OUTPUT->pix_icon($a['icon'], '', 'moodle', ['class' => 'icon unics-action-card__icon'])
            : '';
        $badge = !empty($a['badge'])
            ? ' <span class="badge badge-danger unics-action-card__badge">' . $a['badge'] . '</span>'
            : '';
        $inner = $icon
               . '<span class="unics-action-card__label">' . s($a['label']) . '</span>'
               . $badge;
        echo html_writer::link($a['url'], $inner, ['class' => 'unics-action-card']);
    }
    echo '</div>';
};

// 4) Метрики - .unics-stat-card. $m: ['value'(html),'label'(html),'extraclass'(opt),'col'(opt)].
$render_metrics = function (array $metrics): void {
    if (empty($metrics)) {
        return;
    }
    echo '<div class="row mb-4">';
    foreach ($metrics as $m) {
        $col   = $m['col'] ?? 'col-6 col-md-3';
        $extra = !empty($m['extraclass']) ? ' ' . $m['extraclass'] : '';
        echo '<div class="' . $col . ' mb-3">';
        echo '<div class="card unics-stat-card' . $extra . ' p-3 text-center">';
        echo '<div class="stat-value">' . $m['value'] . '</div>';
        echo '<div class="stat-label mt-1">' . $m['label'] . '</div>';
        echo '</div></div>';
    }
    echo '</div>';
};

$level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

// ----------------------------------------------------------------
// АДМИНИСТРАТОР
// ----------------------------------------------------------------
if ($is_admin) {

    $total_students  = \local_unics\student_helper::count_active_students();
    $total_orgs      = $DB->count_records('unics_organizations', ['is_active' => 1]);
    $ai_in_queue     = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {unics_ai_queue} WHERE status IN (1, 2)");
    $total_umk_ready = $DB->count_records('unics_umk', ['status' => 3]);

    $fio_admin = trim($USER->lastname . ' ' . $USER->firstname);

    $render_welcome('Добро пожаловать, ' . s($fio_admin), 'Панель администратора УНИКС');

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
    $adapt_n = count(\local_unics\suggestion_service::list_open_for_user((int)$USER->id));
    if ($adapt_n > 0) {
        $attention[] = ['label' => 'Адаптивные предложения',
            'url'  => new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
            'icon' => 'i/checkpermissions', 'tone' => 'info', 'badge' => $adapt_n];
    }
    $render_attention($attention);
    $render_actions([
        ['label' => 'Адаптивные предложения', 'url' => new moodle_url('/local/unics/pages/adaptive_suggestions.php'), 'icon' => 'i/checkpermissions'],
        ['label' => 'Пользователи',  'url' => new moodle_url('/local/unics/pages/users.php'),        'icon' => 'i/user'],
        ['label' => 'Генерация УМК', 'url' => new moodle_url('/local/unics/pages/generate_umk.php'), 'icon' => 'i/edit'],
        ['label' => 'Все учащиеся',  'url' => new moodle_url('/local/unics/pages/my_students.php'),   'icon' => 'i/users'],
        ['label' => 'Журнал',        'url' => new moodle_url('/local/unics/pages/gradebook.php'),     'icon' => 'i/grades'],
        ['label' => 'Организации',   'url' => new moodle_url('/local/unics/pages/organizations.php'), 'icon' => 'i/cohort'],
        ['label' => 'Статистика',    'url' => new moodle_url('/local/unics/pages/statistics.php'),    'icon' => 'i/stats'],
    ]);
    $render_metrics([
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
    echo '<h2 class="unics-section-title">Последние генерации УМК</h2>';
    if (empty($recent_umk)) {
        echo '<p class="text-muted">УМК ещё не создавались.</p>';
    } else {
        $status_labels = [1 => 'В очереди', 2 => 'Обрабатывается', 3 => 'Готов', 4 => 'Ошибка'];
        $status_colors = [1 => 'secondary', 2 => 'info', 3 => 'success', 4 => 'danger'];
        echo '<table class="table table-sm table-bordered">';
        echo '<thead class="table-light"><tr>
            <th>Материал</th><th>Уровень</th><th>Учащихся</th><th>Статус</th><th>Дата</th>
        </tr></thead><tbody>';
        foreach ($recent_umk as $u) {
            $lvl = $level_labels[$u->difficulty_level] ?? '?';
            $sc  = $status_colors[$u->status] ?? 'secondary';
            $sl  = $status_labels[$u->status] ?? '?';
            $dt  = $u->generated_at ? date('d.m.Y', (int)$u->generated_at) : '-';
            echo '<tr>';
            echo '<td>' . s($u->title) . '</td>';
            echo '<td><span class="unics-lvl unics-lvl-' . (int)$u->difficulty_level . '">' . s($lvl) . '</span></td>';
            echo '<td>' . (int)$u->student_count . '</td>';
            echo '<td><span class="badge badge-' . $sc . '">' . $sl . '</span></td>';
            echo '<td>' . $dt . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

// ----------------------------------------------------------------
// РЕГИОНАЛЬНЫЙ / РАЙОННЫЙ АДМИНИСТРАТОР (manageorg, скоуп)
// ----------------------------------------------------------------
} elseif ($is_scoped_admin) {

    [$sw, $sp] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $total_students = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {unics_students} s
            JOIN {unics_organizations} o ON o.id = s.organization_id
            JOIN {user} u ON u.id = s.mdl_user_id AND u.deleted = 0
          WHERE s.archived_at IS NULL AND ({$sw})", $sp);
    $total_orgs = (int)$DB->count_records_sql(
        "SELECT COUNT(o.id) FROM {unics_organizations} o WHERE o.is_active = 1 AND ({$sw})", $sp);

    $scope = \local_unics\scope_checker::get_user_scope((int)$USER->id);
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

    $render_welcome('Добро пожаловать, ' . s($fio_admin),
        $panel_label . ($scope_name ? ' - ' . $scope_name : ''));

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
    $render_attention($attention);
    $render_actions([
        ['label' => 'Все учащиеся',        'url' => new moodle_url('/local/unics/pages/my_students.php'),      'icon' => 'i/users'],
        ['label' => 'Делегирование курсов','url' => new moodle_url('/local/unics/pages/course_delegation.php'),'icon' => 'i/permissions'],
        ['label' => 'Организации',         'url' => new moodle_url('/local/unics/pages/organizations.php'),    'icon' => 'i/cohort'],
        ['label' => 'Статистика',          'url' => new moodle_url('/local/unics/pages/statistics.php'),       'icon' => 'i/stats'],
        ['label' => 'Кодификатор',         'url' => new moodle_url('/local/unics/pages/codifier.php'),         'icon' => 'i/competencies'],
    ]);
    $render_metrics([
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
    [$sw, $sp] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $scope     = \local_unics\scope_checker::get_user_scope((int)$USER->id);
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

    $render_welcome('Здравствуйте, ' . s($fio_methodist),
        'Портал методиста УНИКС' . ($scope_name ? ' - ' . $scope_name : ''));

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
    $adapt_n = count(\local_unics\suggestion_service::list_open_for_user((int)$USER->id));
    if ($adapt_n > 0) {
        $attention[] = ['label' => 'Адаптивные предложения',
            'url'  => new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
            'icon' => 'i/checkpermissions', 'tone' => 'info', 'badge' => $adapt_n];
    }
    $render_attention($attention);

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
    $render_actions($actions);

    $render_metrics([
        ['value' => $total_students, 'label' => s($students_label)],
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

    $render_welcome('Добро пожаловать, ' . s($fio_teacher), 'Личный кабинет педагога УНИКС');

    // v1-сигналы (дёшево): непрочитанные сообщения. Работы на проверку / риск падения
    // уровня - дороже по данным, отложены (IA v1-минимум).
    $attention = [];
    $msgs = \core_message\api::count_unread_conversations();
    if ($msgs > 0) {
        $attention[] = ['label' => 'Новые сообщения',
            'url'  => new moodle_url('/local/unics/pages/messenger.php'),
            'icon' => 't/message', 'tone' => 'info', 'badge' => $msgs];
    }
    $adapt_n = count(\local_unics\suggestion_service::list_open_for_user((int)$USER->id));
    if ($adapt_n > 0) {
        $attention[] = ['label' => 'Адаптивные предложения',
            'url'  => new moodle_url('/local/unics/pages/adaptive_suggestions.php'),
            'icon' => 'i/checkpermissions', 'tone' => 'info', 'badge' => $adapt_n];
    }
    $render_attention($attention);

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
    $render_actions($actions);

    $metrics = [['value' => $total_my, 'label' => 'Моих учащихся']];
    if ($avg_overall !== null) {
        $bc = $avg_overall >= 85 ? 'success' : ($avg_overall >= 50 ? 'warning' : 'danger');
        $metrics[] = [
            'value' => '<span class="badge badge-' . $bc . ' h5">' . $avg_overall . '%</span>',
            'label' => 'Средний балл',
        ];
    }
    foreach ($level_labels as $lv => $lbl) {
        $metrics[] = [
            'value' => $level_counts[$lv],
            'label' => '<span class="unics-lvl unics-lvl-' . $lv . '">' . s($lbl) . '</span>',
            'col'   => 'col-6 col-md-2',
        ];
    }
    $render_metrics($metrics);

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
        $titlehtml = 'Привет, ' . s($USER->firstname) . '!';
        if ($active_title) {
            $title_pic = !empty($active_title->icon)
                ? '<img src="' . $OUTPUT->image_url('shop/' . $active_title->icon, 'local_unics')
                  . '" width="20" height="20" alt="" style="vertical-align:-4px;margin-right:4px;">'
                : '';
            $titlehtml .= ' <span class="badge badge-warning ml-1" style="font-size:.8em;">'
               . $title_pic . s($active_title->name) . '</span>';
        }
        $render_welcome($titlehtml, $class_str);

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
        $render_attention($attention);

        $render_actions([
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
        $weak = \local_unics\mastery_manager::get_weak_elements((int)$student->id, 3);
        if ($weak) {
            echo html_writer::start_tag('div', ['class' => 'card mb-4']);
            echo html_writer::tag('div', 'Стоит повторить', ['class' => 'card-header']);
            echo html_writer::start_tag('div', ['class' => 'card-body']);
            echo html_writer::start_tag('ul', ['class' => 'mb-2']);
            foreach ($weak as $w) {
                [$wtext, $wcls] = \local_unics\mastery_manager::band_label((int)$w->band, true);
                $label = s($w->title);
                echo html_writer::tag('li',
                    $label . ' ' . html_writer::tag('span', $wtext, ['class' => "badge badge-$wcls"]));
            }
            echo html_writer::end_tag('ul');
            echo html_writer::link(
                new moodle_url('/local/unics/pages/codifier_report.php', ['student_id' => $student->id]),
                'Подробнее', ['class' => 'btn btn-sm btn-outline-primary']);
            echo html_writer::end_tag('div');
            echo html_writer::end_tag('div');
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

        $render_metrics([
            ['value' => (int)$courses_count, 'label' => 'Курсов'],
            ['value' => $badges_earned . ' / 4', 'label' => 'Значков'],
            ['value' => number_format($points_bal), 'label' => 'Баллов', 'extraclass' => 'unics-points-card'],
            ['value' => '<span class="unics-lvl unics-lvl-' . $lv . '">' . s($lvl_label) . '</span>',
                'label' => 'Текущий уровень'],
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
            echo '<h2 class="unics-section-title">Последние тесты</h2>';
            echo '<table class="table table-sm table-bordered">';
            echo '<thead class="table-light"><tr><th>Тест</th><th>Курс</th><th>Балл</th><th>%</th></tr></thead><tbody>';
            foreach ($last_grades as $g) {
                $pct = round(($g->finalgrade / $g->grademax) * 100, 1);
                $bc  = $pct >= 85 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
                echo '<tr>';
                echo '<td>' . s($g->quiz_name ?? '-') . '</td>';
                echo '<td>' . s($g->course_name) . '</td>';
                echo '<td>' . round($g->finalgrade, 1) . '/' . round($g->grademax, 1) . '</td>';
                echo '<td><span class="badge badge-' . $bc . '">' . $pct . '%</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
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
        $render_welcome('Добро пожаловать, ' . s($fio_parent), 'Портал родителя УНИКС');

        // Переключатель ребёнка.
        if (count($children) > 1) {
            echo '<form method="get" class="unics-btn-row mb-3" action="'
                . (new moodle_url('/local/unics/pages/dashboard.php'))->out(false) . '">';
            echo '<label class="d-flex align-items-center gap-2" style="gap:.5rem;">'
                . '<span>Ребёнок:</span>';
            echo '<select name="child" class="form-control" style="width:auto;" '
                . 'onchange="this.form.submit()">';
            foreach ($children as $ch) {
                $chfio = trim("{$ch->lastname} {$ch->firstname}");
                echo '<option value="' . (int)$ch->id . '"' . ($ch->id == $sel ? ' selected' : '') . '>'
                    . s($chfio) . '</option>';
            }
            echo '</select></label>';
            echo '<noscript><button type="submit" class="btn btn-outline-secondary btn-sm">Показать</button></noscript>';
            echo '</form>';
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
        $render_attention($attention);

        if ($selchild) {
            $render_actions([
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
            $bc   = $avg !== null ? ($avg >= 85 ? 'success' : ($avg >= 50 ? 'warning' : 'danger')) : 'secondary';
            $lv   = (int)$selchild->difficulty_level;
            $render_metrics([
                ['value' => $avg !== null
                    ? '<span class="badge badge-' . $bc . ' h5">' . $avg . '%</span>'
                    : '-',
                    'label' => 'Средний балл'],
                ['value' => '<span class="unics-lvl unics-lvl-' . $lv . '">'
                    . s($level_labels[$lv] ?? '-') . '</span>',
                    'label' => 'Текущий уровень'],
            ]);
        }

        // Доп. блок: все дети карточками (с бейджем новых заметок).
        echo '<h2 class="unics-section-title">Мои дети</h2>';
        echo '<div class="row">';
        foreach ($children as $ch) {
            $fio = trim("{$ch->lastname} {$ch->firstname} " . ($ch->middlename ?? ''));
            $cls = $ch->class_number
                ? $ch->class_number . ($ch->class_letter ? " «{$ch->class_letter}»" : '')
                : '-';
            $pcts = $grade_map[$ch->mdl_user_id] ?? [];
            $avg  = !empty($pcts) ? round(array_sum($pcts) / count($pcts), 1) : null;
            $bc   = $avg !== null ? ($avg >= 85 ? 'success' : ($avg >= 50 ? 'warning' : 'danger')) : 'secondary';

            echo '<div class="col-md-6 mb-3">';
            echo '<div class="card unics-stat-card p-3">';
            echo '<div class="d-flex justify-content-between align-items-start">';
            echo '<div>';
            echo '<strong>' . s($fio) . '</strong><br>';
            echo '<small class="text-muted">' . s($cls) . ' класс</small>';
            echo '</div>';
            echo $avg !== null
                ? '<span class="badge badge-' . $bc . ' ml-2">' . $avg . '%</span>'
                : '<span class="badge badge-secondary ml-2">-</span>';
            echo '</div>';
            $unread_notes = \local_unics\social\comment_manager::count_unread((int)$ch->id, (int)$USER->id);
            if ($unread_notes > 0) {
                echo '<div class="mt-1"><span class="badge badge-danger">'
                   . $unread_notes . ' новых</span></div>';
            }
            echo '<div class="mt-2">';
            echo html_writer::link(
                new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $ch->id], 'notes'),
                'Отчёт →',
                ['class' => 'btn btn-sm btn-outline-primary']
            );
            echo '</div>';
            echo '</div></div>';
        }
        echo '</div>';

        echo '<div class="mt-2">';
        echo html_writer::link(
            new moodle_url('/local/unics/pages/my_children.php'),
            'Все дети →',
            ['class' => 'btn btn-outline-secondary btn-sm']
        );
        echo '</div>';
    }
}

echo $OUTPUT->footer();
