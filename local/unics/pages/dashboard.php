<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
global $USER, $DB;

$ctx        = context_system::instance();
$is_admin   = has_capability('local/unics:manage',       $ctx);
$is_teacher = has_capability('local/unics:viewstudents', $ctx);
// Региональный / районный администратор — manageorg без системного manage.
$is_scoped_admin = !$is_admin && local_unics_is_scoped_admin();
$is_methodist = !$is_admin && !$is_scoped_admin && local_unics_is_methodist();

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/dashboard.php'));
$PAGE->set_title('УНИКС - Портал');
$PAGE->set_heading('УНИКС - Единый портал');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// Доступно всем ролям: вход в наш мессенджер (контакты под роль).
echo '<div class="d-flex justify-content-end mt-3 mb-3">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/messenger.php'),
    'Сообщения',
    ['class' => 'btn btn-outline-primary btn-sm']
);
echo '</div>';

// ----------------------------------------------------------------
// АДМИНИСТРАТОР
// ----------------------------------------------------------------
if ($is_admin) {

    $total_students  = \local_unics\student_helper::count_active_students();
    $total_orgs      = $DB->count_records('unics_organizations', ['is_active' => 1]);
    $ai_in_queue     = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {unics_ai_queue} WHERE status IN (1, 2)");
    $total_umk_ready = $DB->count_records('unics_umk', ['status' => 3]);

    $recent_umk = $DB->get_records_sql(
        "SELECT u.id, u.title, u.difficulty_level, u.status, u.generated_at,
                (SELECT COUNT(*) FROM {unics_umk_students} us WHERE us.umk_id = u.id) AS student_count
           FROM {unics_umk} u
          ORDER BY u.id DESC
          LIMIT 5"
    );

    $fio_admin = trim($USER->lastname . ' ' . $USER->firstname);

    echo '<div class="unics-welcome mb-4">';
    echo '<h2>Добро пожаловать, ' . s($fio_admin) . '</h2>';
    echo '<div class="sub">Панель администратора УНИКС</div>';
    echo '</div>';

    // Статистика
    echo '<div class="row mb-4">';
    $stats = [
        [$total_students,  'Учащихся'],
        [$total_orgs,      'Организаций'],
        [$total_umk_ready, 'УМК готово'],
        [$ai_in_queue, 'В очереди ИИ'],
    ];
    foreach ($stats as [$val, $lbl]) {
        echo '<div class="col-6 col-md-3 mb-3">';
        echo '<div class="card unics-stat-card p-3 text-center">';
        echo '<div class="stat-value">' . $val . '</div>';
        echo '<div class="stat-label mt-1">' . $lbl . '</div>';
        echo '</div></div>';
    }
    echo '</div>';

    // Быстрые действия
    echo '<h2 class="unics-section-title">Быстрые действия</h2>';
    echo '<div class="unics-action-grid mb-4 d-flex flex-wrap gap-2">';
    // #9 (2026-05-29): палитра «по смыслу». Только два главных действия — primary.
    // «Пользователи» (управление людьми, ежедневное) и «Генерация УМК» (флагманская ИИ-фича).
    // Всё остальное — outline-secondary, чтобы не размывать визуальный приоритет.
    $actions = [
        ['/local/unics/pages/users.php',            'btn-primary',          'Пользователи'],
        ['/local/unics/pages/generate_umk.php',     'btn-primary',          'Генерация УМК'],
        ['/local/unics/pages/course_templates.php', 'btn-outline-secondary','Шаблоны курсов'],
        ['/local/unics/pages/my_students.php',      'btn-outline-secondary','Все учащиеся'],
        ['/local/unics/pages/gradebook.php',        'btn-outline-secondary','Журнал'],
        ['/local/unics/pages/assign.php',           'btn-outline-secondary','Привязки'],
        ['/local/unics/pages/enrol_students.php',   'btn-outline-secondary','Запись учащихся на курс'],
        ['/local/unics/pages/enrol_teachers.php',   'btn-outline-secondary','Запись педагогов на курс'],
        ['/local/unics/pages/promote_students.php', 'btn-outline-secondary','Перевод в следующий класс'],
        ['/local/unics/pages/organizations.php',    'btn-outline-secondary','Организации'],
        ['/local/unics/pages/courses.php',          'btn-outline-secondary','Курсы (архив)'],
        ['/local/unics/pages/umk_status.php',       'btn-outline-secondary','История УМК'],
        ['/local/unics/pages/org_report.php',       'btn-outline-secondary','Отчёт по организации'],
        ['/local/unics/pages/statistics.php',       'btn-outline-secondary','Статистика'],
        ['/local/unics/pages/import_users.php',     'btn-outline-secondary','Импорт CSV'],
    ];
    foreach ($actions as [$url, $cls, $label]) {
        echo html_writer::link(
            new moodle_url($url),
            $label,
            ['class' => 'btn ' . $cls]
        );
    }
    echo '</div>';

    // Последние УМК
    echo '<h2 class="unics-section-title">Последние генерации УМК</h2>';
    if (empty($recent_umk)) {
        echo '<p class="text-muted">УМК ещё не создавались.</p>';
    } else {
        $level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
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
            $dt  = $u->generated_at ? date('d.m.Y', strtotime($u->generated_at)) : '-';
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
    echo '<div class="unics-welcome mb-4">';
    echo '<h2>Добро пожаловать, ' . s($fio_admin) . '</h2>';
    echo '<div class="sub">' . $panel_label . ($scope_name ? ' - ' . s($scope_name) : '') . '</div>';
    echo '</div>';

    echo '<div class="row mb-4">';
    foreach ([[$total_students, 'Учащихся'], [$total_orgs, 'Организаций']] as [$val, $lbl]) {
        echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card p-3 text-center">';
        echo '<div class="stat-value">' . $val . '</div>';
        echo '<div class="stat-label mt-1">' . s($lbl) . '</div>';
        echo '</div></div>';
    }
    echo '</div>';

    echo '<h2 class="unics-section-title">Быстрые действия</h2>';
    echo '<div class="unics-action-grid mb-4 d-flex flex-wrap gap-2">';
    // #9: scoped-админ — primary только «Пользователи» (главное действие);
    // у scoped-роли нет доступа к «Генерация УМК» (его делает методист).
    $actions = [
        ['/local/unics/pages/users.php',          'btn-primary',           'Пользователи'],
        ['/local/unics/pages/create_user.php',    'btn-outline-secondary', 'Создать пользователя'],
        ['/local/unics/pages/my_students.php',    'btn-outline-secondary', 'Все учащиеся'],
        ['/local/unics/pages/gradebook.php',      'btn-outline-secondary', 'Журнал'],
        ['/local/unics/pages/assign.php',         'btn-outline-secondary', 'Привязки'],
        ['/local/unics/pages/organizations.php',  'btn-outline-secondary', 'Организации'],
        ['/local/unics/pages/courses.php',        'btn-outline-secondary', 'Курсы (архив)'],
        ['/local/unics/pages/enrol_students.php', 'btn-outline-secondary', 'Запись учащихся на курс'],
        ['/local/unics/pages/enrol_teachers.php', 'btn-outline-secondary', 'Запись педагогов на курс'],
        ['/local/unics/pages/org_report.php',     'btn-outline-secondary', 'Отчёт по организации'],
        ['/local/unics/pages/statistics.php',     'btn-outline-secondary', 'Статистика'],
    ];
    foreach ($actions as [$url, $cls, $label]) {
        echo html_writer::link(new moodle_url($url), $label, ['class' => 'btn ' . $cls]);
    }
    echo '</div>';

// ----------------------------------------------------------------
// МЕТОДИСТ
// ----------------------------------------------------------------
} elseif ($is_methodist) {

    $fio_methodist = trim($USER->lastname . ' ' . $USER->firstname);
    echo '<div class="unics-welcome mb-4">';
    echo '<h2>Здравствуйте, ' . s($fio_methodist) . '</h2>';
    echo '<div class="sub">Портал методиста УНИКС</div>';
    echo '</div>';

    // Статистика - в рамках организации методиста (если привязан).
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;

    if ($methodist_org_id) {
        $total_students = \local_unics\student_helper::count_active_students(
            ['organization_id' => $methodist_org_id]);
        $students_label = 'Учащихся в организации';
    } else {
        $total_students = \local_unics\student_helper::count_active_students();
        $students_label = 'Учащихся в системе';
    }
    $umk_active     = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {unics_ai_queue} WHERE status IN (1, 2)"
    );
    $umk_ready      = $DB->count_records('unics_umk', ['status' => 3]);

    echo '<div class="row mb-4">';
    echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card p-3 text-center">';
    echo '<div class="stat-value">' . $total_students . '</div>';
    echo '<div class="stat-label mt-1">' . s($students_label) . '</div>';
    echo '</div></div>';
    echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card p-3 text-center">';
    echo '<div class="stat-value">' . $umk_active . '</div>';
    echo '<div class="stat-label mt-1">УМК в очереди</div>';
    echo '</div></div>';
    echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card p-3 text-center">';
    echo '<div class="stat-value">' . $umk_ready . '</div>';
    echo '<div class="stat-label mt-1">УМК готово</div>';
    echo '</div></div>';
    echo '</div>';

    echo '<h2 class="unics-section-title">Быстрые действия</h2>';
    echo '<div class="unics-action-grid mb-4 d-flex flex-wrap gap-2">';
    // #9: методист — primary «Пользователи» + «Сгенерировать УМК» (главные ежедневные);
    // остальное — outline-secondary, чтобы не размывать визуальный приоритет.
    echo html_writer::link(new moodle_url('/local/unics/pages/users.php'),
        'Пользователи', ['class' => 'btn btn-primary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/generate_umk.php'),
        'Сгенерировать УМК', ['class' => 'btn btn-primary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/create_user.php'),
        'Создать пользователя', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/course_templates.php'),
        'Шаблоны курсов', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/my_students.php'),
        'Все учащиеся', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/gradebook.php'),
        'Журнал', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/assign.php'),
        'Привязки', ['class' => 'btn btn-outline-secondary']);
    // «Организации» - муниципальному методисту (district_methodist), принявшему функции
    // удалённого муниципального администратора (v3 [[role-model-v3-2026-06-11]]).
    if (local_unics_user_has_role((int)$USER->id, ['district_methodist'])) {
        echo html_writer::link(new moodle_url('/local/unics/pages/organizations.php'),
            'Организации', ['class' => 'btn btn-outline-secondary']);
    }
    echo html_writer::link(new moodle_url('/local/unics/pages/enrol_students.php'),
        'Запись учащихся на курс', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/enrol_teachers.php'),
        'Запись педагогов на курс', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/courses.php'),
        'Курсы (архив)', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/org_report.php'),
        'Отчёт по организации', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/statistics.php'),
        'Статистика', ['class' => 'btn btn-outline-secondary']);
    echo '</div>';

} elseif ($is_teacher) {

    $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $level_counts   = [1 => 0, 2 => 0, 3 => 0];
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

    // Средний балл по последним 5 тестам каждого учащегося
    $avg_overall = null;
    if (!empty($my_students)) {
        $all_uids = array_column((array)$my_students, 'mdl_user_id');
        [$in_sql, $in_params] = $DB->get_in_or_equal(array_map('intval', $all_uids));
        $grade_rows = $DB->get_records_sql(
            "SELECT g.userid, g.finalgrade, gi.grademax
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
            if (!isset($uid_grades[$uid])) $uid_grades[$uid] = [];
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
    echo '<div class="unics-welcome mb-4">';
    echo '<h2>Добро пожаловать, ' . s($fio_teacher) . '</h2>';
    echo '<div class="sub">Личный кабинет педагога УНИКС</div>';
    echo '</div>';

    // Статистика
    echo '<div class="row mb-4">';
    echo '<div class="col-6 col-md-3 mb-3">';
    echo '<div class="card unics-stat-card p-3 text-center">';
    echo '<div class="stat-value">' . $total_my . '</div>';
    echo '<div class="stat-label mt-1">Моих учащихся</div>';
    echo '</div></div>';

    if ($avg_overall !== null) {
        $bc = $avg_overall >= 85 ? 'success' : ($avg_overall >= 50 ? 'warning' : 'danger');
        echo '<div class="col-6 col-md-3 mb-3">';
        echo '<div class="card unics-stat-card p-3 text-center">';
        echo '<div class="stat-value"><span class="badge badge-' . $bc . ' h5">' . $avg_overall . '%</span></div>';
        echo '<div class="stat-label mt-1">Средний балл</div>';
        echo '</div></div>';
    }

    foreach ([1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'] as $lv => $lbl) {
        echo '<div class="col-6 col-md-2 mb-3">';
        echo '<div class="card unics-stat-card p-3 text-center">';
        echo '<div class="stat-value">' . $level_counts[$lv] . '</div>';
        echo '<div class="stat-label mt-1"><span class="unics-lvl unics-lvl-' . $lv . '">' . $lbl . '</span></div>';
        echo '</div></div>';
    }
    echo '</div>';

    // Быстрые действия
    echo '<h2 class="unics-section-title">Быстрые действия</h2>';
    echo '<div class="unics-action-grid mb-4 d-flex flex-wrap gap-2">';
    // Non-editing teacher (role 6) hits accessdenied on generate_umk.php /
    // course_templates.php — hide instead of prompting then rejecting.
    echo html_writer::link(new moodle_url('/local/unics/pages/my_students.php'),
        'Мои учащиеся', ['class' => 'btn btn-primary']);
    echo html_writer::link(new moodle_url('/local/unics/pages/gradebook.php'),
        'Журнал', ['class' => 'btn btn-outline-secondary']);
    if (!local_unics_is_nonediting_teacher()) {
        echo html_writer::link(new moodle_url('/local/unics/pages/generate_umk.php'),
            'Генерация УМК', ['class' => 'btn btn-primary']);
        echo html_writer::link(new moodle_url('/local/unics/pages/course_templates.php'),
            'Шаблоны курсов', ['class' => 'btn btn-outline-secondary']);
    }
    // «История УМК» (umk_status.php) требует local/unics:manage - педагогу не показываем,
    // иначе клик ведёт на accessdenied. Своей истории УМК у педагога пока нет (T-8).
    echo '</div>';

// ----------------------------------------------------------------
// УЧАЩИЙСЯ / РОДИТЕЛЬ
// ----------------------------------------------------------------
} else {
    $student = $DB->get_record('unics_students', ['mdl_user_id' => $USER->id]);
    if ($student) {
        // --- Учащийся ---
        require_once(__DIR__ . '/../classes/points_manager.php');
        $mdl_user = $USER;
        $fio = trim("{$mdl_user->lastname} {$mdl_user->firstname}");

        $categories  = [1 => 'ОВЗ', 2 => 'Семейное обучение', 3 => 'Длительное лечение', 4 => 'Одарённый'];
        $points_bal  = \local_unics\points_manager::get_balance((int)$student->id);
        $active_title = \local_unics\points_manager::get_active_title((int)$student->id);

        $class_str = $student->class_number
            ? $student->class_number . ($student->class_letter ? " «{$student->class_letter}»" : '') . ' класс'
            : '-';

        echo '<div class="unics-welcome mb-4">';
        echo '<h2>Привет, ' . s($USER->firstname) . '!';
        if ($active_title) {
            $title_pic = !empty($active_title->icon)
                ? '<img src="' . $OUTPUT->image_url('shop/' . $active_title->icon, 'local_unics')
                  . '" width="20" height="20" alt="" style="vertical-align:-4px;margin-right:4px;">'
                : '';
            echo ' <span class="badge badge-warning ml-1" style="font-size:.8em;">'
               . $title_pic . s($active_title->name) . '</span>';
        }
        echo '</h2>';
        echo '<div class="sub">' . s($class_str) . '</div>';
        echo '</div>';

        // Последние 3 оценки
        $last_grades = $DB->get_records_sql(
            "SELECT gi.itemname AS quiz_name, c.fullname AS course_name,
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

        // Достижения
        $badges_earned = $DB->count_records('unics_achievements', ['student_id' => $student->id]);
        $courses_count = $DB->get_field_sql(
            "SELECT COUNT(DISTINCT e.courseid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND ue.status = 0",
            ['uid' => $student->mdl_user_id]
        );

        // Статистика учащегося
        echo '<div class="row mb-4">';
        echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card p-3 text-center">';
        echo '<div class="stat-value">' . (int)$courses_count . '</div><div class="stat-label mt-1">Курсов</div>';
        echo '</div></div>';
        echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card p-3 text-center">';
        echo '<div class="stat-value">' . $badges_earned . ' / 4</div><div class="stat-label mt-1">Значков</div>';
        echo '</div></div>';
        echo '<div class="col-6 col-md-3 mb-3"><div class="card unics-stat-card unics-points-card p-3 text-center">';
        echo '<div class="stat-value">' . number_format($points_bal) . '</div>';
        echo '<div class="stat-label mt-1">Баллов</div>';
        echo '</div></div>';
        echo '</div>';

        // Быстрые ссылки
        echo '<div class="unics-action-grid mb-4 d-flex flex-wrap gap-2">';
        echo html_writer::link(
            new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student->id]),
            'Мои результаты',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::link(
            new moodle_url('/local/unics/pages/my_path.php', ['student_id' => $student->id]),
            'Мой маршрут',
            ['class' => 'btn btn-outline-primary']
        );
        echo html_writer::link(
            new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student->id]),
            'Мои значки',
            ['class' => 'btn btn-outline-warning']
        );
        echo html_writer::link(
            new moodle_url('/local/unics/pages/shop.php'),
            'Магазин',
            ['class' => 'btn btn-warning']
        );
        // Заметки педагога с бейджем непрочитанных (видимые ученику по audience).
        $unread_notes = \local_unics\comment_manager::count_unread((int)$student->id, (int)$USER->id);
        $notes_label = 'Заметки педагога';
        if ($unread_notes > 0) {
            $notes_label .= ' <span class="badge badge-light">' . $unread_notes . '</span>';
        }
        echo html_writer::link(
            new moodle_url('/local/unics/pages/student_report.php',
                ['student_id' => $student->id], 'notes'),
            $notes_label,
            ['class' => 'btn ' . ($unread_notes > 0 ? 'btn-danger' : 'btn-outline-secondary')]
        );
        echo '</div>';

        // Последние тесты
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
        // --- Родитель ---
        $parent_links = $DB->get_records('unics_parent_student', ['parent_mdl_user_id' => $USER->id]);
        if (empty($parent_links)) {
            redirect(new moodle_url('/my'));
        }

        $fio_parent = trim($USER->lastname . ' ' . $USER->firstname);
        echo '<div class="unics-welcome mb-4">';
        echo '<h2>Добро пожаловать, ' . s($fio_parent) . '</h2>';
        echo '<div class="sub">Портал родителя УНИКС</div>';
        echo '</div>';

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

        // Последние оценки на каждого ребёнка
        $all_uids = array_column((array)$children, 'mdl_user_id');
        $grade_map = [];
        if (!empty($all_uids)) {
            [$in2, $in2p] = $DB->get_in_or_equal(array_map('intval', $all_uids));
            $grs = $DB->get_records_sql(
                "SELECT g.userid, g.finalgrade, gi.grademax
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
                if (!isset($grade_map[$uid])) $grade_map[$uid] = [];
                if (count($grade_map[$uid]) < 5) {
                    $grade_map[$uid][] = $gr->finalgrade / $gr->grademax * 100;
                }
            }
        }

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
            // Бейдж новых заметок об этом ребёнке (видимых родителю по audience).
            $unread_notes = \local_unics\comment_manager::count_unread((int)$ch->id, (int)$USER->id);
            if ($unread_notes > 0) {
                echo '<div class="mt-1"><span class="badge badge-danger">'
                   . $unread_notes . ' новых</span></div>';
            }
            echo '<div class="mt-2">';
            echo html_writer::link(
                new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $ch->id], 'notes'),
                'Отчёт →',
                ['class' => 'btn btn-sm btn-outline-primary ml-2']
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
