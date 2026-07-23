<?php
/**
 * Рендер - mustache (2.5 аудита, [[session-kickoff-mustache-slices]]): страница
 * собирает контекст, разметка целиком в templates/umk_status.mustache. Сама
 * таблица остается доверенным пре-рендером html_writer::table() (см. заметку
 * в шаблоне) - action-обработчики ниже не менялись.
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

require_login();
local_unics_require_not_student();

$sys_ctx       = context_system::instance();
$is_admin_user = has_capability('local/unics:manage', $sys_ctx);
$is_teacher    = has_capability('local/unics:viewstudents', $sys_ctx);

if (!$is_admin_user && !$is_teacher) {
    require_capability('local/unics:viewstudents', $sys_ctx);
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/umk_status.php'));
$PAGE->set_title('История генерации УМК - УНИКС');
$PAGE->set_heading('История генерации материалов');
$PAGE->set_pagelayout('admin');

$page    = optional_param('page', 0, PARAM_INT);
$perpage = 25;

// Ручной запуск обработки очереди (для отладки)
$run_now = optional_param('run_now', 0, PARAM_INT);
if ($run_now && confirm_sesskey()) {
    require_once(__DIR__ . '/../classes/ai/ai_generator.php');
    require_once(__DIR__ . '/../classes/ai/course_builder.php');
    $task = new \local_unics\task\process_ai_queue();
    ob_start();
    $task->execute();
    $log = ob_get_clean();
    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        $log ?: 'Очередь обработана.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Отмена pending-задачи: удаляем запись из очереди, UMK помечаем status=5 (отменён).
$cancel_id = optional_param('cancel_id', 0, PARAM_INT);
if ($cancel_id && confirm_sesskey()) {
    $umk = $DB->get_record('unics_umk', ['id' => $cancel_id]);
    if ($umk && (int)$umk->status === 1) {
        $DB->delete_records('unics_ai_queue', ['umk_id' => $cancel_id]);
        $DB->set_field('unics_umk', 'status', 5, ['id' => $cancel_id]);
        redirect(
            new moodle_url('/local/unics/pages/umk_status.php'),
            'УМК #' . $cancel_id . ' отменён.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        'Можно отменить только записи в статусе «Ожидает».',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Массовая отмена всех pending.
$cancel_all = optional_param('cancel_all', 0, PARAM_INT);
if ($cancel_all && confirm_sesskey()) {
    $pending = $DB->get_records('unics_umk', ['status' => 1], '', 'id');
    if (!empty($pending)) {
        $ids = array_keys($pending);
        [$in_sql, $in_params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('unics_ai_queue', "umk_id {$in_sql}", $in_params);
        $DB->set_field_select('unics_umk', 'status', 5, "id {$in_sql}", $in_params);
    }
    redirect(
        new moodle_url('/local/unics/pages/umk_status.php'),
        'Отменено: ' . count($pending) . ' записей.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Кто вправе публиковать/удалять черновик конкретного УМК (review-гейт):
// системный админ, методист или педагог с правом редактировать активности курса УМК.
// Сквозные проверки (manage / методист) считаем ОДИН раз, а не на каждую строку
// списка - is_methodist ходит в БД (этап 3.2, N+1).
$publish_any = has_capability('local/unics:manage', context_system::instance())
    || local_unics_is_methodist();
$can_publish = function(\stdClass $umk) use ($publish_any): bool {
    if ($publish_any) {
        return true;
    }
    if (!empty($umk->mdl_course_id)) {
        $cctx = \context_course::instance((int)$umk->mdl_course_id, IGNORE_MISSING);
        if ($cctx && has_capability('moodle/course:manageactivities', $cctx)) {
            return true;
        }
    }
    return false;
};

// Публикация УМК: открыть скрытые активности учащимся + начислить баллы + уведомить.
$publish_id = optional_param('publish_id', 0, PARAM_INT);
if ($publish_id && confirm_sesskey()) {
    require_once(__DIR__ . '/../classes/ai/course_builder.php');
    require_once(__DIR__ . '/../classes/social/points_manager.php');
    require_once(__DIR__ . '/../classes/social/notification_manager.php');

    $umk = $DB->get_record('unics_umk', ['id' => $publish_id]);
    if (!$umk || (int)$umk->status !== 3 || !empty($umk->published_at)) {
        redirect(new moodle_url('/local/unics/pages/umk_status.php'),
            'Опубликовать можно только готовый, ещё не опубликованный УМК.',
            null, \core\output\notification::NOTIFY_WARNING);
    }
    if (!$can_publish($umk)) {
        throw new \moodle_exception('nopermissions', 'error', '', 'публикация УМК вне вашего доступа');
    }

    $builder = new \local_unics\ai\course_builder();
    $cmids = $DB->get_fieldset_select('unics_umk_materials',
        'mdl_course_module_id', 'umk_id = ?', [$umk->id]);
    foreach ($cmids as $cmid) {
        $builder->set_cm_visible((int)$cmid, 1);
    }
    $DB->set_field('unics_umk', 'published_at', time(), ['id' => $umk->id]);

    // Событие в штатный журнал (этап 2.4 аудита).
    \local_unics\event\umk_published::create([
        'context'  => context_course::instance((int)$umk->mdl_course_id),
        'objectid' => (int)$umk->id,
        'other'    => ['title' => $umk->title, 'topic' => $umk->topic],
    ])->trigger();

    // Баллы + уведомление учащимся (перенесено со сборки на момент публикации).
    $course_rec  = $DB->get_record('course', ['id' => $umk->mdl_course_id]);
    $course_name = $course_rec ? $course_rec->fullname : '';
    $umk_students = $DB->get_records('unics_umk_students', ['umk_id' => $umk->id]);
    // Ученики одной выборкой вместо get_record на строку (этап 3.2, N+1).
    $sids = array_map(static fn($r) => (int)$r->student_id, $umk_students);
    $students_by_id = $sids
        ? $DB->get_records_list('unics_students', 'id', $sids, '', 'id, mdl_user_id')
        : [];
    foreach ($umk_students as $row) {
        $student = $students_by_id[(int)$row->student_id] ?? null;
        if (!$student) {
            continue;
        }
        try {
            \local_unics\social\notification_manager::notify_umk_ready(
                (int)$student->mdl_user_id,
                $umk->title,
                $course_name,
                (int)$umk->difficulty_level,
                0
            );
        } catch (\Throwable $en) {
            debugging('УМК publish: уведомление не отправлено: ' . $en->getMessage());
        }
    }

    redirect(new moodle_url('/local/unics/pages/umk_status.php'),
        'УМК #' . $umk->id . ' опубликован: материалы открыты учащимся.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Удаление черновика: снести неопубликованные активности УМК и пометить отменённым.
$delete_draft_id = optional_param('delete_draft_id', 0, PARAM_INT);
if ($delete_draft_id && confirm_sesskey()) {
    require_once($CFG->dirroot . '/course/lib.php');

    $umk = $DB->get_record('unics_umk', ['id' => $delete_draft_id]);
    if (!$umk || (int)$umk->status !== 3 || !empty($umk->published_at)) {
        redirect(new moodle_url('/local/unics/pages/umk_status.php'),
            'Удалить черновик можно только у готового, ещё не опубликованного УМК.',
            null, \core\output\notification::NOTIFY_WARNING);
    }
    if (!$can_publish($umk)) {
        throw new \moodle_exception('nopermissions', 'error', '', 'удаление черновика УМК вне вашего доступа');
    }

    $cmids = $DB->get_fieldset_select('unics_umk_materials',
        'mdl_course_module_id', 'umk_id = ?', [$umk->id]);
    foreach ($cmids as $cmid) {
        try {
            course_delete_module((int)$cmid);
        } catch (\Throwable $e) {
            debugging('УМК draft delete: модуль ' . $cmid . ' не удалён: ' . $e->getMessage());
        }
    }
    $DB->delete_records('unics_umk_materials', ['umk_id' => $umk->id]);
    $DB->set_field('unics_umk', 'status', 5, ['id' => $umk->id]);

    redirect(new moodle_url('/local/unics/pages/umk_status.php'),
        'Черновик УМК #' . $umk->id . ' удалён.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$status_labels = [
    1 => '<span class="badge badge-secondary">Ожидает</span>',
    2 => '<span class="badge badge-primary">Генерируется</span>',
    3 => '<span class="badge badge-success">Готов</span>',
    4 => '<span class="badge badge-danger">Ошибка</span>',
    5 => '<span class="badge badge-dark">Отменён</span>',
];

$total = (int)$DB->count_records('unics_umk');
$records = $DB->get_records_sql(
    "SELECT u.id, u.title, u.topic, u.difficulty_level, u.status, u.generated_at, u.published_at, u.mdl_course_id,
            (SELECT q.error_message FROM {unics_ai_queue} q
              WHERE q.umk_id = u.id ORDER BY q.id DESC LIMIT 1) AS error_message,
            (SELECT q.processed_at FROM {unics_ai_queue} q
              WHERE q.umk_id = u.id ORDER BY q.id DESC LIMIT 1) AS processed_at,
            c.fullname AS course_name,
            (SELECT COUNT(*) FROM {unics_umk_students} us WHERE us.umk_id = u.id) AS student_count
     FROM {unics_umk} u
     LEFT JOIN {course} c ON c.id = u.mdl_course_id
     ORDER BY u.generated_at DESC",
    [], $page * $perpage, $perpage
);

echo $OUTPUT->header();
echo local_unics_dashboard_button();

$pending_count = $DB->count_records('unics_umk', ['status' => 1]);

$context = ['toolbar' => [
    'create_url'  => 'generate_umk.php',
    'run_now_url' => '?run_now=1&sesskey=' . sesskey(),
]];
if ($pending_count > 0) {
    $context['toolbar']['cancel_all'] = [
        'url'   => '?cancel_all=1&sesskey=' . sesskey(),
        'count' => $pending_count,
    ];
}

if (empty($records)) {
    $context['empty'] = ['html' => $OUTPUT->notification('Материалов пока нет. Создайте первый УМК.', 'info')];
} else {
    $level_labels = [1 => 'Базовый', 2 => 'Стандартный', 3 => 'Продвинутый'];

    $table = new html_table();
    $table->head = ['Тема', 'Уровень', 'Учащихся', 'Курс', 'Статус', 'Дата', ''];
    $table->attributes['class'] = 'table table-striped table-sm';

    foreach ($records as $r) {
        // Статус «Готов» (3) расщепляется review-гейтом: пока published_at пуст —
        // материал «На проверке» (скрыт от учащихся), после публикации — «Опубликован».
        if ((int)$r->status === 3) {
            $status = empty($r->published_at)
                ? '<span class="badge badge-warning">На проверке</span>'
                : '<span class="badge badge-success">Опубликован</span>';
        } else {
            $status = $status_labels[$r->status] ?? '<span class="badge badge-light">?</span>';
        }

        if ($r->status == 4 && $r->error_message) {
            $status .= '<br><small class="text-danger">' . s($r->error_message) . '</small>';
        }

        $course_link = $r->mdl_course_id
            ? html_writer::link(
                new moodle_url('/course/view.php', ['id' => $r->mdl_course_id]),
                s($r->course_name ?: 'Курс #' . $r->mdl_course_id),
                ['target' => '_blank']
              )
            : '-';

        $lvl_label = $level_labels[$r->difficulty_level] ?? ('Ур.' . $r->difficulty_level);

        $actions = '';
        if ((int)$r->status === 1) {
            $cancel_url = new moodle_url('/local/unics/pages/umk_status.php',
                ['cancel_id' => $r->id, 'sesskey' => sesskey()]);
            $actions = html_writer::link($cancel_url, 'Отменить',
                ['class' => 'btn btn-outline-danger btn-sm',
                 'onclick' => "return confirm('Отменить УМК #{$r->id}?')"]);
        } elseif ((int)$r->status === 3 && empty($r->published_at) && $can_publish($r)) {
            // Готовый черновик на проверке: опубликовать или удалить.
            $publish_url = new moodle_url('/local/unics/pages/umk_status.php',
                ['publish_id' => $r->id, 'sesskey' => sesskey()]);
            $delete_url  = new moodle_url('/local/unics/pages/umk_status.php',
                ['delete_draft_id' => $r->id, 'sesskey' => sesskey()]);
            $actions = html_writer::link($publish_url, 'Опубликовать',
                    ['class' => 'btn btn-success btn-sm me-1',
                     'onclick' => "return confirm('Открыть материалы УМК #{$r->id} учащимся?')"])
                . html_writer::link($delete_url, 'Удалить черновик',
                    ['class' => 'btn btn-outline-danger btn-sm',
                     'onclick' => "return confirm('Удалить черновик УМК #{$r->id}? Активности будут удалены.')"]);
        }

        $table->data[] = [
            s($r->topic),
            $lvl_label,
            (int)$r->student_count,
            $course_link,
            $status,
            $r->generated_at ? userdate((int)$r->generated_at) : '-',
            $actions,
        ];
    }

    $context['table_html'] = html_writer::table($table);
    $context['paging_html'] = local_unics_render_paging_bar(
        $total, $page, $perpage, new moodle_url('/local/unics/pages/umk_status.php'));
}

echo $OUTPUT->render_from_template('local_unics/umk_status', $context);
echo $OUTPUT->footer();
