<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

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

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]));
$PAGE->set_title('Отчёт по учащемуся');
$PAGE->set_heading('Отчёт по учащемуся');
$PAGE->set_pagelayout('standard');

// ----------------------------------------------------------------
// Данные
// ----------------------------------------------------------------

$quiz_grades = $DB->get_records_sql(
    "SELECT gi.courseid, c.fullname AS course_name, gi.itemname AS quiz_name,
            g.finalgrade, gi.grademax, g.timemodified,
            cm.id AS cmid
     FROM {grade_grades} g
     JOIN {grade_items} gi ON gi.id = g.itemid
     JOIN {course} c       ON c.id  = gi.courseid
     LEFT JOIN {course_modules} cm
           ON cm.instance = gi.iteminstance
          AND cm.course   = gi.courseid
          AND cm.module   = (SELECT id FROM {modules} WHERE name = 'quiz')
     WHERE g.userid  = :userid
       AND gi.itemtype   = 'mod'
       AND gi.itemmodule = 'quiz'
       AND g.finalgrade IS NOT NULL
       AND gi.grademax  > 0
     ORDER BY g.timemodified DESC",
    ['userid' => $student->mdl_user_id]
);

// Хронологический порядок для графика (ASC, max 20 точек)
$grade_history = $DB->get_records_sql(
    "SELECT g.finalgrade, gi.grademax, g.timemodified
     FROM {grade_grades} g
     JOIN {grade_items} gi ON gi.id = g.itemid
     WHERE g.userid  = :userid
       AND gi.itemtype   = 'mod'
       AND gi.itemmodule = 'quiz'
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

// Пакетная загрузка заметок педагога для тестов (по cmid)
$note_map = [];
$quiz_cmids = array_filter(array_column((array)$quiz_grades, 'cmid'));
if (!empty($quiz_cmids)) {
    [$in_sql, $in_params] = $DB->get_in_or_equal(array_map('intval', array_unique($quiz_cmids)));
    $note_rows = $DB->get_records_sql(
        "SELECT c.id, c.cmid, c.body, c.created_at, u.lastname, u.firstname
           FROM {unics_comments} c
           JOIN {user} u ON u.id = c.teacher_mdl_user_id
          WHERE c.student_id = ?
            AND c.cmid {$in_sql}
          ORDER BY c.created_at DESC",
        array_merge([$student_id], $in_params)
    );
    foreach ($note_rows as $nr) {
        $note_map[(int)$nr->cmid][] = $nr;
    }
}

$last5 = array_slice((array)$quiz_grades, 0, 5);
$avg_score = 0;
if (!empty($last5)) {
    $total = 0;
    foreach ($last5 as $g) {
        $total += ($g->finalgrade / $g->grademax) * 100;
    }
    $avg_score = round($total / count($last5), 1);
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------

$categories   = [1 => 'ОВЗ', 2 => 'Семейное обучение', 3 => 'Длительное лечение', 4 => 'Одарённый'];
$levels       = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
$umk_statuses = [1 => 'В очереди', 2 => 'Обрабатывается', 3 => 'Готов', 4 => 'Ошибка'];

$is_own_view = ($USER->id == $student->mdl_user_id);

echo $OUTPUT->header();

echo '<div class="mb-3">';
if ($is_admin || $is_teacher) {
    echo html_writer::link(
        new moodle_url('/local/unics/pages/my_students.php'),
        'Мои учащиеся',
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
}
echo ' ' . html_writer::link(
    new moodle_url('/local/unics/pages/achievements.php', ['student_id' => $student_id]),
    '⭐ Значки достижений',
    ['class' => 'btn btn-outline-warning btn-sm']
);
if ($is_admin) {
    echo ' ' . html_writer::link(
        new moodle_url('/local/unics/pages/org_report.php', ['org_id' => $org->id ?? 0]),
        'Сводный отчёт по организации',
        ['class' => 'btn btn-outline-info btn-sm']
    );
}
echo '</div>';

// Карточка учащегося
$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));
$class_str = $student->class_number
    ? $student->class_number . ($student->class_letter ? " «{$student->class_letter}»" : '') . ' класс'
    : '—';

$avg_badge_class = $avg_score >= 85 ? 'success' : ($avg_score >= 50 ? 'warning' : 'danger');

echo '<div class="card mb-4">';
echo '<div class="card-header bg-light"><strong>' . s($fio) . '</strong></div>';
echo '<div class="card-body">';
echo '<div class="row">';
echo '<div class="col-md-3"><b>Класс:</b> ' . s($class_str) . '</div>';
if (!$is_own_view) {
    echo '<div class="col-md-3"><b>Категория:</b> ' . s($categories[$student->category] ?? '—') . '</div>';
    echo '<div class="col-md-3"><b>Уровень:</b> ' . s($levels[$student->difficulty_level] ?? '—') . '</div>';
}
echo '<div class="col-md-3"><b>Средний балл:</b> <span class="badge badge-' . $avg_badge_class . '">' . $avg_score . '%</span></div>';
echo '</div>';
echo '<div class="row mt-2">';
echo '<div class="col-md-6"><b>Организация:</b> ' . s($org->name ?? '—') . '</div>';
echo '<div class="col-md-6"><b>Email:</b> ' . s($mdl_user->email) . '</div>';
echo '</div>';
echo '</div></div>';

// --- График прогресса ---
if (count($grade_history) >= 2) {
    $chart_pcts   = [];
    $chart_labels = [];
    foreach ($grade_history as $gh) {
        $chart_pcts[]   = round($gh->finalgrade / $gh->grademax * 100, 1);
        $chart_labels[] = userdate($gh->timemodified, '%d.%m');
    }
    $chart = new \core\chart_line();
    $chart->set_smooth(true);
    $series = new \core\chart_series('Балл (%)', $chart_pcts);
    $chart->add_series($series);
    $chart->set_labels($chart_labels);

    echo '<h2 class="unics-section-title mt-4">Динамика успеваемости</h2>';
    echo '<div style="max-height:220px">';
    echo $OUTPUT->render_chart($chart, false);
    echo '</div>';
}

// Результаты тестов
echo '<h2 class="unics-section-title mt-4">Результаты тестов</h2>';
if (empty($quiz_grades)) {
    echo '<p class="text-muted">Тесты ещё не сданы.</p>';
} else {
    echo '<table class="table table-sm table-bordered">';
    echo '<thead class="thead-light"><tr>
        <th>Курс</th><th>Тест</th><th>Баллы</th><th>%</th><th>Дата</th><th></th>
    </tr></thead><tbody>';
    foreach ($quiz_grades as $g) {
        $pct   = round(($g->finalgrade / $g->grademax) * 100, 1);
        $bc    = $pct >= 85 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
        $gcmid = (int)($g->cmid ?? 0);
        $notes_for_quiz = $gcmid ? ($note_map[$gcmid] ?? []) : [];
        $note_count = count($notes_for_quiz);

        echo '<tr>';
        echo '<td>' . s($g->course_name) . '</td>';
        echo '<td>' . s($g->quiz_name ?? '—') . '</td>';
        echo '<td>' . round($g->finalgrade, 1) . ' / ' . round($g->grademax, 1) . '</td>';
        echo '<td><span class="badge badge-' . $bc . '">' . $pct . '%</span></td>';
        echo '<td>' . ($g->timemodified ? userdate($g->timemodified, '%d.%m.%Y') : '—') . '</td>';
        echo '<td>';
        if ($gcmid && ($is_admin || $is_teacher)) {
            $note_lbl = $note_count > 0 ? '💬 ' . $note_count : '+ заметка';
            echo html_writer::link(
                new moodle_url('/local/unics/pages/student_comments.php', [
                    'student_id' => $student_id,
                    'cmid'       => $gcmid,
                ]),
                $note_lbl,
                ['class' => 'btn btn-sm btn-outline-' . ($note_count > 0 ? 'info' : 'secondary')]
            );
        } elseif ($gcmid && $note_count > 0) {
            echo '<span class="badge badge-info">💬 ' . $note_count . '</span>';
        }
        echo '</td>';
        echo '</tr>';

        // Показываем первую заметку inline
        if (!empty($notes_for_quiz)) {
            $first_note = reset($notes_for_quiz);
            $na = trim("{$first_note->lastname} {$first_note->firstname}");
            echo '<tr class="unics-note-row">';
            echo '<td colspan="6">';
            echo '<div class="unics-teacher-note">';
            echo '<div class="note-meta">';
            echo '<span class="note-author">' . s($na) . '</span>';
            echo '<span class="note-date">' . userdate($first_note->created_at, '%d.%m.%Y') . '</span>';
            echo '</div>';
            echo '<p class="note-body">' . s($first_note->body) . '</p>';
            echo '</div>';
            echo '</td></tr>';
        }
    }
    echo '</tbody></table>';
}

// Записан на курсы
echo '<h2 class="unics-section-title mt-4">Записан на курсы (' . count($enrolled_courses) . ')</h2>';
if (empty($enrolled_courses)) {
    echo '<p class="text-muted">Не записан ни на один курс.</p>';
} else {
    echo '<table class="table table-sm table-bordered">';
    echo '<thead class="thead-light"><tr><th>Курс</th><th>Дата записи</th><th></th></tr></thead><tbody>';
    foreach ($enrolled_courses as $c) {
        $ts = $c->timestart ?: $c->timecreated;
        echo '<tr>';
        echo '<td>' . s($c->fullname) . '</td>';
        echo '<td>' . ($ts ? userdate($ts, '%d.%m.%Y') : '—') . '</td>';
        echo '<td>';
        if ($is_admin || $is_teacher) {
            echo html_writer::link(
                new moodle_url('/local/unics/pages/course_notes.php', [
                    'student_id' => $student_id,
                    'courseid'   => $c->id,
                ]),
                'Заметки по курсу',
                ['class' => 'btn btn-sm btn-outline-info']
            );
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

// История УМК — служебная информация педагогики (статусы очереди генерации).
// Не показываем ни ученику (своя), ни родителю — путает «УМК с ошибкой» с оценкой ребёнка.
if ($is_admin || $is_teacher) {
    echo '<h2 class="unics-section-title mt-4">История генерации УМК (' . count($umk_list) . ')</h2>';
    if (empty($umk_list)) {
        echo '<p class="text-muted">УМК ещё не генерировались.</p>';
    } else {
        $level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];
        echo '<table class="table table-sm table-bordered">';
        echo '<thead class="thead-light"><tr>
            <th>Название</th><th>Тема</th><th>Уровень</th><th>Курс</th><th>Статус</th><th>Дата</th>
        </tr></thead><tbody>';
        foreach ($umk_list as $u) {
            $sl  = $umk_statuses[$u->status] ?? '?';
            $sc  = [1 => 'secondary', 2 => 'info', 3 => 'success', 4 => 'danger'][$u->status] ?? 'secondary';
            $dt  = $u->generated_at ? date('d.m.Y', strtotime($u->generated_at)) : '—';
            $lvl = $level_labels[$u->difficulty_level] ?? '—';
            echo '<tr>';
            echo '<td>' . s($u->title) . '</td>';
            echo '<td>' . s($u->topic) . '</td>';
            echo '<td>' . s($lvl) . '</td>';
            echo '<td>' . s($u->course_name ?? '—') . '</td>';
            echo '<td><span class="badge badge-' . $sc . '">' . $sl . '</span></td>';
            echo '<td>' . $dt . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

// Комментарии педагога (последние 3)
if ($is_admin || $is_teacher) {
    // Только общие заметки (cmid IS NULL) — активностные видны inline над
    $last_comments = $DB->get_records_sql(
        "SELECT c.body, c.created_at, u.lastname, u.firstname
         FROM {unics_comments} c
         JOIN {user} u ON u.id = c.teacher_mdl_user_id
         WHERE c.student_id = :sid AND c.cmid IS NULL
         ORDER BY c.created_at DESC",
        ['sid' => $student_id],
        0, 3
    );

    echo '<h2 class="unics-section-title mt-4">Общие заметки педагога</h2>';
    if (empty($last_comments)) {
        echo '<p class="text-muted">Комментариев ещё нет.</p>';
    } else {
        foreach ($last_comments as $cm) {
            $author = trim("{$cm->lastname} {$cm->firstname}");
            echo '<div class="card mb-2">';
            echo '<div class="card-header d-flex justify-content-between">';
            echo '<span class="font-weight-bold">' . s($author) . '</span>';
            echo '<small class="text-muted">' . userdate($cm->created_at, '%d.%m.%Y') . '</small>';
            echo '</div>';
            echo '<div class="card-body py-2">';
            echo '<p class="mb-0" style="white-space:pre-wrap">' . s($cm->body) . '</p>';
            echo '</div>';
            echo '</div>';
        }
    }
    echo html_writer::link(
        new moodle_url('/local/unics/pages/student_comments.php', ['student_id' => $student_id]),
        count($last_comments) > 0 ? 'Все комментарии и добавить новый →' : 'Добавить комментарий →',
        ['class' => 'btn btn-outline-secondary btn-sm mt-1']
    );
}

echo $OUTPUT->footer();
