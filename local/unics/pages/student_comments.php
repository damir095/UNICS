<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
global $USER, $DB;

$student_id = required_param('student_id', PARAM_INT);
$cmid       = optional_param('cmid', 0, PARAM_INT);   // 0 = общие заметки

$ctx        = context_system::instance();
$is_admin   = has_capability('local/unics:manage',       $ctx);
$is_teacher = has_capability('local/unics:viewstudents', $ctx);

if (!$is_admin && !$is_teacher) {
    throw new moodle_exception('accessdenied', 'error');
}

$student  = $DB->get_record('unics_students', ['id' => $student_id], '*', MUST_EXIST);
$mdl_user = $DB->get_record('user', ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);

// Педагог может комментировать только своих учащихся;
// методист - всех учащихся своей организации.
if (!$is_admin) {
    $is_methodist = local_unics_is_methodist();
    if ($is_methodist) {
        $methodist_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
        $methodist_org_id = ($methodist_rec && $methodist_rec->organization_id)
            ? (int)$methodist_rec->organization_id : 0;
        if ($methodist_org_id === 0
            || (int)$student->organization_id !== $methodist_org_id) {
            throw new moodle_exception('accessdenied', 'error');
        }
    } else {
        $teacher_rec = $DB->get_record('unics_teachers', ['mdl_user_id' => $USER->id]);
        if (!$teacher_rec
            || !$DB->record_exists('unics_teacher_student', [
                'teacher_id' => $teacher_rec->id,
                'student_id' => $student_id,
            ])
        ) {
            throw new moodle_exception('accessdenied', 'error');
        }
    }
}

// Если указан cmid - проверяем и получаем информацию об активности
$cm_info      = null;
$module_name  = '';
$module_label = '';
if ($cmid > 0) {
    $cm_info = $DB->get_record_sql(
        "SELECT cm.id, cm.instance, cm.course, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.id = :cmid",
        ['cmid' => $cmid]
    );
    if ($cm_info) {
        $module_name = $DB->get_field($cm_info->modname, 'name', ['id' => $cm_info->instance]);
        $type_labels = ['quiz' => 'Тест', 'page' => 'Страница', 'assign' => 'Задание', 'resource' => 'Файл'];
        $module_label = ($type_labels[$cm_info->modname] ?? ucfirst($cm_info->modname))
                      . ': ' . ($module_name ?: '#' . $cm_info->instance);
    } else {
        $cmid = 0; // некорректный cmid - сбрасываем
    }
}

$page_url = new moodle_url('/local/unics/pages/student_comments.php',
    array_filter(['student_id' => $student_id, 'cmid' => $cmid ?: null]));
$PAGE->set_context($ctx);
$PAGE->set_url($page_url);
$PAGE->set_title('Заметки педагога');
$PAGE->set_heading('Заметки педагога');
$PAGE->set_pagelayout('standard');

// ----------------------------------------------------------------
// Обработка POST: добавить заметку / архивировать
// ----------------------------------------------------------------
use local_unics\comment_manager;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', 'add', PARAM_ALPHA);

    // Архивация / восстановление (автор или системный админ).
    if ($action === 'archive' || $action === 'unarchive') {
        $comment_id = required_param('comment_id', PARAM_INT);
        $c = $DB->get_record('unics_comments', ['id' => $comment_id, 'student_id' => $student_id]);
        if ($c && comment_manager::can_archive($c, (int)$USER->id)) {
            comment_manager::set_archived($comment_id, $action === 'archive');
        }
        redirect($page_url);
    }

    // Добавление заметки.
    $body = trim(required_param('body', PARAM_TEXT));
    // audience валидируем по белому списку; дефолт - семья.
    $audience = optional_param('audience', comment_manager::AUDIENCE_FAMILY, PARAM_INT);
    if (!array_key_exists($audience, comment_manager::audience_options())) {
        $audience = comment_manager::AUDIENCE_FAMILY;
    }
    if (mb_strlen($body) > 0) {
        $rec = (object)[
            'student_id'          => $student_id,
            'teacher_mdl_user_id' => $USER->id,
            'body'                => $body,
            'created_at'          => time(),
            'audience'            => $audience,
        ];
        if ($cmid > 0) {
            $rec->cmid = $cmid;
        }
        $DB->insert_record('unics_comments', $rec);

        // Уведомления по audience (кумулятивно): педагоги команды (>=staff),
        // ученик (>=student), родители (>=family). private - никого.
        try {
            require_once(__DIR__ . '/../classes/notification_manager.php');
            $teacher_name = trim($USER->lastname . ' ' . $USER->firstname);
            $student_name = trim($mdl_user->lastname . ' ' . $mdl_user->firstname);
            $context_lbl  = $cmid > 0 && $cm_info ? ($module_label ?: 'активность курса') : '';

            if ($audience >= comment_manager::AUDIENCE_STAFF) {
                foreach (comment_manager::team_teacher_userids($student_id) as $tuid) {
                    if ($tuid !== (int)$USER->id) {
                        \local_unics\notification_manager::send(
                            $tuid,
                            "Новая заметка об учащемся: {$student_name}",
                            '<p>Педагог <strong>' . htmlspecialchars($teacher_name) . '</strong> оставил заметку об '
                            . 'учащемся <strong>' . htmlspecialchars($student_name) . '</strong>'
                            . ($context_lbl ? ' к «' . htmlspecialchars($context_lbl) . '»' : '') . '.</p>',
                            \local_unics\notification_manager::TYPE_NEW_COMMENT
                        );
                    }
                }
            }
            if ($audience >= comment_manager::AUDIENCE_STUDENT) {
                \local_unics\notification_manager::notify_new_comment(
                    (int)$student->mdl_user_id, $teacher_name, $context_lbl);
            }
            if ($audience >= comment_manager::AUDIENCE_FAMILY) {
                $parent_ids = comment_manager::parent_userids($student_id);
                if (!empty($parent_ids)) {
                    \local_unics\notification_manager::notify_new_comment_parents(
                        $parent_ids, $teacher_name, $student_name, $context_lbl);
                }
            }
        } catch (\Throwable $e) {
            // Нефатально
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
        }
    }
    redirect($page_url);
}

// ----------------------------------------------------------------
// Загружаем комментарии через сервис (видимость по audience + фильтр архива).
// ----------------------------------------------------------------
$show_archived = optional_param('archived', 0, PARAM_INT);
$comments = comment_manager::get_visible_for_student((int)$student_id, (int)$USER->id, [
    'cmid'     => $cmid > 0 ? $cmid : null,
    'archived' => $show_archived ? 'archived' : 'active',
]);

// Отмечаем заметки этого ученика как просмотренные (для бейджей «N новых»).
comment_manager::mark_seen((int)$student_id, (int)$USER->id);

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
$fio = trim("{$mdl_user->lastname} {$mdl_user->firstname} " . ($mdl_user->middlename ?? ''));

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo '<div class="mb-3 d-flex flex-wrap gap-2">';
echo html_writer::link(
    new moodle_url('/local/unics/pages/student_report.php', ['student_id' => $student_id]),
    'Отчёт по учащемуся',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
if ($cmid > 0 && $cm_info) {
    echo html_writer::link(
        new moodle_url('/local/unics/pages/course_notes.php', [
            'student_id' => $student_id,
            'courseid'   => $cm_info->course,
        ]),
        'Все заметки по курсу',
        ['class' => 'btn btn-outline-info btn-sm']
    );
    echo html_writer::link(
        new moodle_url('/mod/' . $cm_info->modname . '/view.php', ['id' => $cmid]),
        'Перейти к активности',
        ['class' => 'btn btn-outline-primary btn-sm', 'target' => '_blank']
    );
}
echo '</div>';

$heading = $cmid > 0
    ? 'Заметки педагога к «' . s($module_label) . '»'
    : 'Заметки педагога';
echo $OUTPUT->heading($heading . ': ' . s($fio));

if ($cmid > 0) {
    echo '<p class="text-muted mb-3 small">Заметки привязаны к конкретной активности курса и видны в разделе «Заметки педагога» этого курса.</p>';
}

// Форма добавления
$form_url = new moodle_url('/local/unics/pages/student_comments.php',
    array_filter(['student_id' => $student_id, 'cmid' => $cmid ?: null, 'sesskey' => sesskey()]));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $form_url, 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'add']);

// Селектор адресата (кому видна заметка).
echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Кому видна заметка', ['class' => 'font-weight-bold', 'for' => 'unics-audience']);
echo html_writer::select(comment_manager::audience_options(), 'audience', comment_manager::AUDIENCE_FAMILY, false,
    ['class' => 'form-control', 'id' => 'unics-audience', 'style' => 'max-width:480px']);
echo html_writer::tag('small', s(comment_manager::audience_hint(comment_manager::AUDIENCE_FAMILY)),
    ['class' => 'form-text text-muted', 'id' => 'unics-audience-hint']);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group']);
$placeholder = $cmid > 0
    ? 'Наблюдение, рекомендация или комментарий к этой активности…'
    : 'Наблюдение, рекомендация или пожелание для учащегося или его родителей…';
echo html_writer::tag('label', 'Новая заметка', ['class' => 'font-weight-bold']);
echo html_writer::tag('textarea', '', [
    'name'        => 'body',
    'class'       => 'form-control',
    'rows'        => 4,
    'required'    => 'required',
    'placeholder' => $placeholder,
]);
echo html_writer::end_tag('div');
echo html_writer::tag('button', 'Сохранить заметку', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// Живое описание выбранного варианта audience.
$hint_map = [];
foreach (array_keys(comment_manager::audience_options()) as $av) {
    $hint_map[$av] = comment_manager::audience_hint($av);
}
$hint_json = json_encode($hint_map);
echo "<script>(function(){var s=document.getElementById('unics-audience'),h=document.getElementById('unics-audience-hint');"
   . "if(!s||!h)return;var m=$hint_json;s.addEventListener('change',function(){h.textContent=m[this.value]||'';});})();</script>";

// Переключатель Активные / Архив.
$toggle_url = new moodle_url('/local/unics/pages/student_comments.php',
    array_filter(['student_id' => $student_id, 'cmid' => $cmid ?: null, 'archived' => $show_archived ? null : 1]));
echo '<p>' . html_writer::link($toggle_url,
    $show_archived ? '← Показать активные заметки' : 'Показать архив',
    ['class' => 'btn btn-sm btn-outline-secondary']) . '</p>';

// Список заметок
if (empty($comments)) {
    echo html_writer::tag('p', $show_archived ? 'В архиве заметок нет.' : 'Заметок пока нет.',
        ['class' => 'text-muted']);
} else {
    foreach ($comments as $cm) {
        $author = trim("{$cm->lastname} {$cm->firstname} " . ($cm->middlename ?? ''));
        [$abadge, $aclass] = comment_manager::audience_badge((int)$cm->audience);
        $is_archived = !empty($cm->archived_at);

        echo '<div class="card mb-3 unics-comment-card' . ($is_archived ? ' border-secondary' : '') . '">';
        echo '<div class="card-header d-flex justify-content-between align-items-center">';
        echo '<span class="font-weight-bold">' . s($author)
           . ' <span class="badge badge-' . $aclass . ' ml-1" title="'
           . s(comment_manager::audience_hint((int)$cm->audience)) . '">' . s($abadge) . '</span>'
           . ($is_archived ? ' <span class="badge badge-light">архив</span>' : '') . '</span>';
        echo '<small class="text-muted">' . userdate($cm->created_at, '%d.%m.%Y %H:%M') . '</small>';
        echo '</div>';
        echo '<div class="card-body py-2">';
        echo '<p class="mb-0" style="white-space:pre-wrap">' . s($cm->body) . '</p>';
        // Кнопка архивации - автору или системному админу.
        if (comment_manager::can_archive($cm, (int)$USER->id)) {
            $a_url = new moodle_url('/local/unics/pages/student_comments.php',
                array_filter(['student_id' => $student_id, 'cmid' => $cmid ?: null, 'sesskey' => sesskey()]));
            echo '<form method="post" action="' . $a_url->out(false) . '" class="mt-2">';
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
                'value' => $is_archived ? 'unarchive' : 'archive']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'comment_id', 'value' => (int)$cm->id]);
            echo html_writer::tag('button', $is_archived ? 'Восстановить' : 'В архив',
                ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']);
            echo '</form>';
        }
        echo '</div>';
        echo '</div>';
    }
}

echo $OUTPUT->footer();
