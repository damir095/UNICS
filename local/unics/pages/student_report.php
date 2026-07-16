<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/learning/grade_scale.php');

use local_unics\learning\grade_scale;

require_login();
global $USER, $DB;

$student_id = required_param('student_id', PARAM_INT);
$ctx        = context_system::instance();

$is_admin     = has_capability('local/unics:manage',       $ctx);
$is_teacher   = has_capability('local/unics:viewstudents', $ctx);
$is_methodist = $is_teacher && !$is_admin && local_unics_is_methodist();

$student  = $DB->get_record('unics_students',      ['id' => $student_id],                   '*', MUST_EXIST);
$mdl_user = $DB->get_record('user',                ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);
$org      = $DB->get_record('unics_organizations', ['id' => $student->organization_id]);

// Контроль доступа.
// Порядок важен: методист проверяется ДО педагога, потому что у методиста
// тоже есть запись в unics_teachers (там org-привязка), но он не привязан
// к учащимся через unics_teacher_student.
$access = false;
if ($is_admin) {
    $access = true;
} elseif ($is_methodist) {
    // Методист видит учащихся своей организации.
    $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
        ? (int)$methodist_rec->organization_id : 0;
    $access = $methodist_org_id > 0
        && (int)$student->organization_id === $methodist_org_id;
} elseif ($is_teacher) {
    $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
    if ($teacher_rec) {
        $access = $DB->record_exists('unics_teacher_student', [
            'teacher_id' => $teacher_rec->id,
            'student_id' => $student_id,
        ]);
    }
}
if (!$access) {
    $access = $DB->record_exists('unics_parent_student', [
        'parent_mdl_user_id' => $USER->id,
        'student_id'         => $student_id,
    ]);
}
if (!$access && $USER->id == $student->mdl_user_id) {
    $access = true;
}
if (!$access) {
    throw new moodle_exception('accessdenied', 'error');
}

$is_own_view = ($USER->id == $student->mdl_user_id);

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]));
$report_title = $is_own_view ? 'Мои результаты' : 'Отчёт по учащемуся';
$PAGE->set_title($report_title);
$PAGE->set_heading($report_title);
$PAGE->set_pagelayout('standard');

// ----------------------------------------------------------------
// Данные
// ----------------------------------------------------------------

// Тесты И задания. Балл всегда приводим к единой шкале (grade_scale::from_raw),
// поэтому 100-балльные задания показываются в той же шкале, что и тесты.
$quiz_grades = $DB->get_records_sql(
    "SELECT g.id, gi.courseid, c.fullname AS course_name, gi.itemname AS quiz_name,
            gi.itemmodule, g.finalgrade, gi.grademax, g.timemodified,
            cm.id AS cmid
     FROM {grade_grades} g
     JOIN {grade_items} gi ON gi.id = g.itemid
     JOIN {course} c       ON c.id  = gi.courseid
     LEFT JOIN {modules} m ON m.name = gi.itemmodule
     LEFT JOIN {course_modules} cm
           ON cm.instance = gi.iteminstance
          AND cm.course   = gi.courseid
          AND cm.module   = m.id
     WHERE g.userid  = :userid
       AND gi.itemtype   = 'mod'
       AND gi.itemmodule IN ('quiz', 'assign')
       AND g.finalgrade IS NOT NULL
       AND gi.grademax  > 0
     ORDER BY g.timemodified DESC",
    ['userid' => $student->mdl_user_id]
);

// Хронологический порядок для графика (ASC, max 20 точек)
$grade_history = $DB->get_records_sql(
    "SELECT g.id, g.finalgrade, gi.grademax, g.timemodified
     FROM {grade_grades} g
     JOIN {grade_items} gi ON gi.id = g.itemid
     WHERE g.userid  = :userid
       AND gi.itemtype   = 'mod'
       AND gi.itemmodule IN ('quiz', 'assign')
       AND g.finalgrade IS NOT NULL
       AND gi.grademax  > 0
     ORDER BY g.timemodified ASC
     LIMIT 20",
    ['userid' => $student->mdl_user_id]
);

$enrolled_courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, ue.timestart, ue.timecreated
     FROM {user_enrolments} ue
     JOIN {enrol}  e ON e.id  = ue.enrolid
     JOIN {course} c ON c.id  = e.courseid
     WHERE ue.userid = :userid AND ue.status = 0
     ORDER BY c.fullname",
    ['userid' => $student->mdl_user_id]
);

$umk_list = $DB->get_records_sql(
    "SELECT u.id, u.title, u.topic, u.difficulty_level, u.status, u.generated_at,
            c.fullname AS course_name
     FROM {unics_umk_students} us
     JOIN {unics_umk} u  ON u.id  = us.umk_id
     LEFT JOIN {course} c ON c.id = u.mdl_course_id
     WHERE us.student_id = :sid
     ORDER BY u.generated_at DESC",
    ['sid' => $student_id]
);

// Заметки педагога - через сервис (видимость по audience для роли смотрящего).
// Один вызов: активные видимые заметки этого ученика; делим на по-активностные и общие.
$all_notes = \local_unics\social\comment_manager::get_visible_for_student(
    (int)$student_id, (int)$USER->id, ['archived' => 'active']);
$note_map = [];        // cmid => [заметки]
$general_notes = [];   // заметки без cmid
foreach ($all_notes as $nr) {
    if (!empty($nr->cmid)) {
        $note_map[(int)$nr->cmid][] = $nr;
    } else {
        $general_notes[] = $nr;
    }
}
// Отмечаем заметки ученика как просмотренные (для бейджей «N новых»).
\local_unics\social\comment_manager::mark_seen((int)$student_id, (int)$USER->id);

$last5 = array_slice((array)$quiz_grades, 0, 5);
$avg_score = 0;
if (!empty($last5)) {
    $total = 0;
    foreach ($last5 as $g) {
        $total += grade_scale::from_raw((float)$g->finalgrade, (float)$g->grademax);
    }
    $avg_score = round($total / count($last5), 1);
}

// ----------------------------------------------------------------
// Сборка контекста шаблона (2.5 аудита: разметка целиком в
// templates/student_report.mustache; здесь - только данные).
// ----------------------------------------------------------------

$levels       = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
$umk_statuses = [1 => 'В очереди', 2 => 'Обрабатывается', 3 => 'Готов', 4 => 'Ошибка'];

$context = [];

// Тулбар.
$buttons = [];
if ($is_admin || $is_teacher) {
    $buttons[] = [
        'url'   => (string)new moodle_url('/local/unics/pages/my_students.php'),
        'label' => 'Мои учащиеся',
        'cls'   => 'btn btn-outline-secondary btn-sm',
    ];
}
$buttons[] = [
    'url'   => (string)new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student_id]),
    'label' => 'Значки достижений',
    'cls'   => 'btn btn-outline-warning btn-sm',
];
if ($is_admin || $is_teacher) {
    $buttons[] = [
        'url'   => (string)new moodle_url('/local/unics/pages/essay_check.php', ['student_id' => $student_id]),
        'label' => 'ИИ-проверка ответов',
        'cls'   => 'btn btn-outline-primary btn-sm',
    ];
}
if ($is_admin) {
    $buttons[] = [
        'url'   => (string)new moodle_url('/local/unics/pages/org_report.php', ['org_id' => $org->id ?? 0]),
        'label' => 'Сводный отчёт по организации',
        'cls'   => 'btn btn-outline-info btn-sm',
    ];
}
$buttons[] = [
    'url'   => (string)new moodle_url('/local/unics/pages/codifier_report.php', ['student_id' => $student_id]),
    'label' => 'Элементы содержания',
    'cls'   => 'btn btn-outline-primary btn-sm',
];
$context['toolbar_buttons'] = $buttons;

// Карточка учащегося.
$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));
$class_str = $student->class_number
    ? $student->class_number . ($student->class_letter ? " «{$student->class_letter}»" : '') . ' класс'
    : '-';

$card = [
    'fio'             => $fio,
    'class_str'       => $class_str,
    'avg_badge_class' => grade_scale::badge_class($avg_score),
    'avg_text'        => grade_scale::format($avg_score),
    'org_name'        => $org->name ?? '-',
];
if (!$is_own_view) {
    $cat_label = \local_unics\identity\student_helper::format_categories($student) ?: '-';
    $ovz_label = \local_unics\identity\student_helper::format_ovz_types($student);
    $card['staff_fields'] = [
        'cat_label'   => $cat_label,
        'ovz_label'   => $ovz_label ?: null,
        'level_label' => $levels[$student->difficulty_level] ?? '-',
    ];
    $card['email'] = $mdl_user->email;
}
$context['card'] = $card;

// --- Образовательный маршрут (ИОМ, A2) ---
$path = \local_unics\path_manager::get_active_path($student_id);
$path_ctx = [
    'title'    => $is_own_view ? 'Мой маршрут' : 'Образовательный маршрут',
    'open_url' => (string)new moodle_url('/local/unics/pages/my_path.php', ['student_id' => $student_id]),
];
if ($path) {
    $prog = \local_unics\path_manager::progress((int)$path->id);
    $path_ctx['has_path'] = [
        'done'     => $prog['done'],
        'total'    => $prog['total'],
        'current'  => $prog['current'] ? ['title' => $prog['current']->title] : null,
        'all_done' => !$prog['current'] && $prog['total'] > 0,
    ];
} else {
    $path_ctx['no_path'] = true;
}
if ($is_admin || $is_teacher) {
    $path_ctx['builder'] = [
        'url'   => (string)new moodle_url('/local/unics/pages/path_builder.php', ['student_id' => $student_id]),
        'label' => $path ? 'Редактировать маршрут' : 'Составить маршрут',
    ];
}
$context['path'] = $path_ctx;

// --- График прогресса (пре-рендер ядра, в шаблоне тройной скобкой) ---
if (count($grade_history) >= 2) {
    $chart_vals   = [];
    $chart_labels = [];
    foreach ($grade_history as $gh) {
        $chart_vals[]   = grade_scale::from_raw((float)$gh->finalgrade, (float)$gh->grademax);
        $chart_labels[] = userdate($gh->timemodified, '%d.%m');
    }
    $chart = new \core\chart_line();
    $chart->set_smooth(true);
    $series = new \core\chart_series('Балл', $chart_vals);
    $chart->add_series($series);
    $chart->set_labels($chart_labels);

    $context['chart'] = [
        'title' => $is_own_view ? 'Мой прогресс' : 'Динамика успеваемости',
        'html'  => $OUTPUT->render_chart($chart, false),
    ];
}

// Результаты тестов и заданий.
$type_labels = ['quiz' => 'Тест', 'assign' => 'Задание'];
if (empty($quiz_grades)) {
    $context['grades'] = ['empty' => true];
} else {
    $grades_rows = [];
    foreach ($quiz_grades as $g) {
        $score = grade_scale::from_raw((float)$g->finalgrade, (float)$g->grademax);
        $gcmid = (int)($g->cmid ?? 0);
        $notes_for_quiz = $gcmid ? ($note_map[$gcmid] ?? []) : [];
        $note_count = count($notes_for_quiz);

        $row = [
            'course'      => $g->course_name,
            'type_label'  => $type_labels[$g->itemmodule] ?? $g->itemmodule,
            'name'        => $g->quiz_name ?? '-',
            'raw_score'   => round($g->finalgrade, 1) . ' / ' . round($g->grademax, 1),
            'badge_class' => grade_scale::badge_class($score),
            'score_text'  => grade_scale::format($score),
            'date'        => $g->timemodified ? userdate($g->timemodified, '%d.%m.%Y') : '-',
        ];
        if ($gcmid && ($is_admin || $is_teacher)) {
            $row['note_btn'] = [
                'url'   => (string)new moodle_url('/local/unics/pages/student_comments.php', [
                    'student_id' => $student_id,
                    'cmid'       => $gcmid,
                ]),
                'label' => $note_count > 0 ? '💬 ' . $note_count : '+ заметка',
                'cls'   => 'btn btn-sm btn-outline-' . ($note_count > 0 ? 'info' : 'secondary'),
            ];
        } elseif ($gcmid && $note_count > 0) {
            $row['note_badge'] = ['count' => $note_count];
        }
        // Все заметки этой активности - inline-строкой под оценкой.
        if (!empty($notes_for_quiz)) {
            $row['notes_row'] = ['notes' => array_map(function ($note) {
                [$abadge, $aclass] = \local_unics\social\comment_manager::audience_badge((int)$note->audience);
                return [
                    'author'         => trim("{$note->lastname} {$note->firstname}"),
                    'audience_label' => $abadge,
                    'audience_class' => $aclass,
                    'date'           => userdate($note->created_at, '%d.%m.%Y'),
                    'body'           => $note->body,
                ];
            }, $notes_for_quiz)];
        }
        $grades_rows[] = $row;
    }
    $context['grades'] = ['rows' => $grades_rows];
}

// Контрольные точки (B4) — промежуточная аттестация по milestone-тестам.
// Видны всем ролям, у кого есть доступ к отчёту (учащийся видит свои).
$milestones = \local_unics\learning\milestone_manager::student_milestones($student->mdl_user_id);
if (empty($milestones)) {
    $context['milestones'] = ['empty' => true];
} else {
    $context['milestones'] = ['rows' => array_map(function ($m) {
        $r = $m->result;
        return [
            'course'      => $m->course_name,
            'name'        => $m->quiz_name,
            'status_html' => \local_unics\learning\milestone_manager::status_html($r),
            'grade_html'  => \local_unics\learning\milestone_manager::grade_text($r),
            'date'        => !empty($r->timemodified) ? userdate($r->timemodified, '%d.%m.%Y') : '-',
        ];
    }, array_values($milestones))];
}

// Темы для повторения (B2) — проваленные тесты темы (с B1-гейтом), ожидающие повтора.
// Видны всем ролям с доступом к отчёту (учащийся видит свои).
$topic_retries = \local_unics\learning\topic_retry_manager::student_open_retries($student->mdl_user_id);
if (empty($topic_retries)) {
    $context['retries'] = ['empty' => true];
} else {
    $context['retries'] = ['rows' => array_map(fn($tr) => [
        'course'     => $tr->course_name,
        'name'       => $tr->quiz_name ?? 'тест темы',
        'grade_html' => \local_unics\learning\topic_retry_manager::grade_text($tr),
        'date'       => !empty($tr->timecreated) ? userdate($tr->timecreated, '%d.%m.%Y') : '-',
    ], array_values($topic_retries))];
}

// Пробелы (B3) - вопросы с ошибками по последней завершённой попытке каждого теста,
// сгруппированные по теме (тесту). Правило, без ИИ; читаем из ядровых question_*.
// Видны всем ролям с доступом к отчёту (учащийся видит свои).
$gaps = \local_unics\learning\gap_manager::student_gaps($student->mdl_user_id);
if (empty($gaps)) {
    $context['gaps'] = ['empty' => true];
} else {
    $context['gaps'] = ['rows' => array_map(fn($topic) => [
        'course'    => $topic->course_name,
        'name'      => $topic->quiz_name,
        'summary'   => \local_unics\learning\gap_manager::summary_text($topic),
        // Перечень ошибочных вопросов темы (что ответил учащийся).
        'questions' => array_map(fn($qq) => [
            'state_html'   => \local_unics\learning\gap_manager::state_html($qq->state),
            'qname'        => $qq->qname,
            'has_response' => $qq->response !== null && $qq->response !== '',
            'response'     => $qq->response,
        ], array_values($topic->questions)),
    ], array_values($gaps))];
}

// Записан на курсы.
$courses_ctx = ['count' => count($enrolled_courses)];
if (empty($enrolled_courses)) {
    $courses_ctx['empty'] = true;
} else {
    $courses_ctx['rows'] = array_map(function ($c) use ($is_admin, $is_teacher, $student_id) {
        $ts = $c->timestart ?: $c->timecreated;
        $row = [
            'fullname' => $c->fullname,
            'date'     => $ts ? userdate($ts, '%d.%m.%Y') : '-',
        ];
        if ($is_admin || $is_teacher) {
            $row['notes_btn'] = ['url' => (string)new moodle_url('/local/unics/pages/course_notes.php', [
                'student_id' => $student_id,
                'courseid'   => $c->id,
            ])];
        }
        return $row;
    }, array_values($enrolled_courses));
}
$context['courses'] = $courses_ctx;

// История УМК - служебная информация педагогики (статусы очереди генерации).
// Не показываем ни ученику (своя), ни родителю - путает «УМК с ошибкой» с оценкой ребёнка.
if ($is_admin || $is_teacher) {
    $umk_ctx = ['count' => count($umk_list)];
    if (empty($umk_list)) {
        $umk_ctx['empty'] = true;
    } else {
        $status_colors = [1 => 'secondary', 2 => 'info', 3 => 'success', 4 => 'danger'];
        $umk_ctx['rows'] = array_map(fn($u) => [
            'title'        => $u->title,
            'topic'        => $u->topic,
            'level'        => $levels[$u->difficulty_level] ?? '-',
            'course'       => $u->course_name ?? '-',
            'status_tone'  => $status_colors[$u->status] ?? 'secondary',
            'status_label' => $umk_statuses[$u->status] ?? '?',
            'date'         => $u->generated_at ? date('d.m.Y', (int)$u->generated_at) : '-',
        ], array_values($umk_list));
    }
    $context['umk'] = $umk_ctx;
}

// Общие заметки педагога (последние 3 видимые). Видимость по audience уже
// применена сервисом выше ($general_notes). Активностные заметки - inline.
$last_comments = array_slice($general_notes, 0, 3);
$notes_ctx = [];
if (empty($last_comments)) {
    $notes_ctx['empty'] = true;
} else {
    $notes_ctx['cards'] = array_map(function ($cm) {
        [$abadge, $aclass] = \local_unics\social\comment_manager::audience_badge((int)$cm->audience);
        return [
            'author'         => trim("{$cm->lastname} {$cm->firstname}"),
            'audience_label' => $abadge,
            'audience_class' => $aclass,
            'date'           => userdate($cm->created_at, '%d.%m.%Y'),
            'body'           => $cm->body,
        ];
    }, $last_comments);
}
// Создавать заметки могут только педагог и админ.
if ($is_admin || $is_teacher) {
    $notes_ctx['all_link'] = [
        'url'   => (string)new moodle_url('/local/unics/pages/student_comments.php', ['student_id' => $student_id]),
        'label' => count($last_comments) > 0 ? 'Все комментарии и добавить новый →' : 'Добавить комментарий →',
    ];
}
$context['notes'] = $notes_ctx;

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------

echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->render_from_template('local_unics/student_report', $context);
echo $OUTPUT->footer();
