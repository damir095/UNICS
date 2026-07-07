<?php
/**
 * Массовый перевод учащихся в следующий класс (пункт #1, встреча 2026-05-20).
 *
 * Только системный администратор (local/unics:manage). Выбор организации (+ класс/буква),
 * выборка чекбоксами, предпросмотр «X → Y», подтверждение, batch-UPDATE.
 * 11-классники помечаются выпускниками: graduated_at = сегодня, class_number = NULL.
 *
 * Дополнительно в той же операции (по запросу пользователя):
 *  - чекбокс «скрыть текущие курсы выбранных» → course.visible = 0 для всех курсов,
 *    в которых состоят переводимые (конец учебного года: прячем старые курсы);
 *  - запись выбранных сразу на несколько новых курсов (курсы — чекбоксами с фильтром).
 *
 * Каждая операция перевода пишется в unics_audit_log (action = promote / graduate).
 */
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/identity/user_manager.php');
require_once($CFG->dirroot . '/course/lib.php'); // course_change_visibility()

require_login();
local_unics_require_not_student();

$ctx = context_system::instance();
require_capability('local/unics:manage', $ctx); // только системный администратор

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/promote_students.php'));
$PAGE->set_title('Перевод в следующий класс - УНИКС');
$PAGE->set_heading('Перевод учащихся в следующий класс');
$PAGE->set_pagelayout('admin');

$filter_org    = optional_param('org', 0, PARAM_INT);
$filter_class  = optional_param('class', 0, PARAM_INT);
$filter_letter = optional_param('letter', '', PARAM_TEXT); // кириллица А–Ж

/**
 * Метка «куда переводим»: 11 класс → выпуск, иначе следующий класс с той же буквой.
 */
$next_label = function (int $class_number, string $letter): string {
    return $class_number >= 11 ? 'Выпуск' : ($class_number + 1) . $letter;
};

// ----------------------------------------------------------------
// POST: выполнить перевод (+ опц. скрытие курсов / запись на курсы)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $student_ids   = optional_param_array('student_ids', [], PARAM_INT);
    $student_ids   = array_values(array_filter($student_ids));
    $hide_courses  = optional_param('hide_courses', 0, PARAM_INT);
    $enrol_courses = optional_param_array('enrol_course_ids', [], PARAM_INT);
    $enrol_courses = array_values(array_filter($enrol_courses));

    $back = new moodle_url('/local/unics/pages/promote_students.php', array_filter([
        'org'    => $filter_org,
        'class'  => $filter_class,
        'letter' => $filter_letter,
    ]));

    if (empty($student_ids)) {
        redirect($back, 'Не выбрано ни одного учащегося.', null,
            \core\output\notification::NOTIFY_WARNING);
    }

    $today     = date('Y-m-d');
    $promoted  = 0;
    $graduated = 0;
    $skipped   = 0;
    $mdl_uids  = []; // mdl_user_id обработанных — для скрытия курсов и записи

    [$in_sql, $in_params] = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'sid');
    $rows = $DB->get_records_select('unics_students',
        "id {$in_sql} AND graduated_at IS NULL AND archived_at IS NULL AND class_number IS NOT NULL", $in_params);

    foreach ($rows as $s) {
        $old_class = (int)$s->class_number;
        if ($old_class < 1) { $skipped++; continue; }

        $old_value  = json_encode(['class_number' => $old_class, 'class_letter' => $s->class_letter]);
        $mdl_uids[] = (int)$s->mdl_user_id;

        if ($old_class >= 11) {
            $s->class_number = null;
            $s->graduated_at = $today;
            $action          = 'graduate';
            $new_value       = json_encode(['graduated_at' => $today]);
            $graduated++;
        } else {
            $s->class_number = $old_class + 1; // буква сохраняется
            $action          = 'promote';
            $new_value       = json_encode(['class_number' => $old_class + 1, 'class_letter' => $s->class_letter]);
            $promoted++;
        }
        $DB->update_record('unics_students', $s);

        $DB->insert_record('unics_audit_log', (object)[
            'mdl_user_id' => (int)$USER->id,
            'action'      => $action,
            'table_name'  => 'unics_students',
            'record_id'   => (int)$s->id,
            'old_value'   => $old_value,
            'new_value'   => $new_value,
            'ip_address'  => getremoteaddr(),
            'changed_at'  => time(),
        ]);
    }

    $mdl_uids = array_values(array_unique($mdl_uids));

    // --- Скрыть текущие курсы выбранных учащихся (course.visible = 0) ---
    $hidden_courses = 0;
    if ($hide_courses && $mdl_uids) {
        $course_ids = [];
        foreach ($mdl_uids as $uid) {
            foreach (enrol_get_users_courses($uid, true, 'id') as $c) {
                if ((int)$c->id !== SITEID) { $course_ids[(int)$c->id] = true; }
            }
        }
        foreach (array_keys($course_ids) as $cid) {
            $vis = $DB->get_field('course', 'visible', ['id' => $cid]);
            if ((int)$vis === 1) {
                course_change_visibility($cid, false);
                $hidden_courses++;
            }
        }
    }

    // --- Записать выбранных на новые курсы (manual enrol, роль student) ---
    $enrolments = 0;
    if ($enrol_courses && $mdl_uids) {
        $enrol        = enrol_get_plugin('manual');
        $student_role = $DB->get_record('role', ['shortname' => 'student'], 'id');
        $role_id      = $student_role ? (int)$student_role->id : 5;

        foreach ($enrol_courses as $cid) {
            if ($cid === SITEID || !$DB->record_exists('course', ['id' => $cid])) { continue; }
            $instance = $DB->get_record('enrol',
                ['courseid' => $cid, 'enrol' => 'manual', 'status' => 0]);
            if (!$instance) {
                $course = $DB->get_record('course', ['id' => $cid], '*', MUST_EXIST);
                $enrol->add_default_instance($course);
                $instance = $DB->get_record('enrol',
                    ['courseid' => $cid, 'enrol' => 'manual', 'status' => 0]);
            }
            if (!$instance) { continue; }
            $cctx = \context_course::instance($cid);
            foreach ($mdl_uids as $uid) {
                if (!is_enrolled($cctx, $uid)) {
                    $enrol->enrol_user($instance, $uid, $role_id);
                    $enrolments++;
                }
            }
        }
    }

    $parts = [];
    if ($promoted)       { $parts[] = "переведено: {$promoted}"; }
    if ($graduated)      { $parts[] = "выпущено (11 класс): {$graduated}"; }
    if ($skipped)        { $parts[] = "пропущено: {$skipped}"; }
    if ($hidden_courses) { $parts[] = "скрыто курсов: {$hidden_courses}"; }
    if ($enrolments)     { $parts[] = "новых записей на курсы: {$enrolments}"; }
    $msg = $parts ? ('Готово - ' . implode(', ', $parts) . '.') : 'Нечего переводить.';
    redirect($back, $msg, null,
        ($promoted || $graduated) ? \core\output\notification::NOTIFY_SUCCESS
                                  : \core\output\notification::NOTIFY_WARNING);
}

// ----------------------------------------------------------------
// GET: фильтры + список с предпросмотром
// ----------------------------------------------------------------
$orgs_menu  = unics_user_manager::get_organizations_menu();
$class_menu = [];
for ($i = 1; $i <= 11; $i++) { $class_menu[$i] = $i . ' класс'; }
$letters_menu = ['А' => 'А', 'Б' => 'Б', 'В' => 'В', 'Г' => 'Г',
                 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];

echo $OUTPUT->header();
echo local_unics_dashboard_button();

echo html_writer::div(
    html_writer::link(new moodle_url('/local/unics/pages/users.php'),
        'Назад к пользователям', ['class' => 'btn btn-outline-secondary btn-sm']),
    'mb-3'
);

echo $OUTPUT->notification(
    'Перевод увеличивает номер класса на 1 (буква сохраняется). Учащиеся 11 класса '
    . 'помечаются выпускниками (убираются из активных списков, отчётность сохраняется). '
    . 'Учащиеся без класса (надомное обучение) не переводятся.',
    'info'
);

// --- Форма фильтров ---
echo html_writer::start_tag('form', ['method' => 'get',
    'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3']);
echo html_writer::select([0 => '- выберите организацию -'] + $orgs_menu,
    'org', $filter_org, false, ['class' => 'form-control']);
echo html_writer::select([0 => 'Все классы'] + $class_menu,
    'class', $filter_class, false, ['class' => 'form-control']);
echo html_writer::select(['' => 'Все буквы'] + $letters_menu,
    'letter', $filter_letter, false, ['class' => 'form-control']);
echo html_writer::tag('button', 'Показать', ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

if ($filter_org <= 0) {
    echo $OUTPUT->notification('Выберите организацию, чтобы увидеть список учащихся для перевода.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// --- Список активных учащихся организации (с учётом фильтров) ---
$where  = 's.organization_id = :org AND s.graduated_at IS NULL AND s.archived_at IS NULL AND s.class_number IS NOT NULL AND u.deleted = 0';
$params = ['org' => $filter_org];
if ($filter_class > 0)    { $where .= ' AND s.class_number = :cls'; $params['cls'] = $filter_class; }
if ($filter_letter !== '') { $where .= ' AND s.class_letter = :let'; $params['let'] = $filter_letter; }

$students = $DB->get_records_sql(
    "SELECT s.id, s.class_number, s.class_letter, u.lastname, u.firstname, u.middlename
       FROM {unics_students} s
       JOIN {user} u ON u.id = s.mdl_user_id
      WHERE {$where}
   ORDER BY s.class_number ASC, s.class_letter ASC, u.lastname ASC, u.firstname ASC",
    $params
);

if (empty($students)) {
    echo $OUTPUT->notification('Активных учащихся по выбранным условиям не найдено.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// --- Форма перевода (POST) ---
$post_url = new moodle_url('/local/unics/pages/promote_students.php', array_filter([
    'org'    => $filter_org,
    'class'  => $filter_class,
    'letter' => $filter_letter,
]));
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $post_url->out(false),
    'onsubmit' => "return confirm('Перевести выбранных учащихся? 11 класс будет выпущен.');"]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::tag('p',
    html_writer::tag('a', 'Выбрать всех', ['href' => '#',
        'onclick' => 'document.querySelectorAll(".promote-cb").forEach(c=>c.checked=true);return false;'])
    . ' / ' .
    html_writer::tag('a', 'Снять все', ['href' => '#',
        'onclick' => 'document.querySelectorAll(".promote-cb").forEach(c=>c.checked=false);return false;']),
    ['class' => 'text-muted']
);

$table = new html_table();
// Пустые заголовки Moodle рендерит как <td> (см. html_writer::table) — они выпадают
// из голубой полосы шапки и не имеют доступного имени. Даём скрытые подписи (accesshide):
// ячейки становятся <th>, полоса смыкается, скринридер получает имя колонки.
$table->head = [
    html_writer::span('Выбор', 'accesshide'),
    'ФИО', 'Текущий класс',
    html_writer::span('Переход', 'accesshide'),
    'Станет',
];
$table->attributes['class'] = 'table table-sm table-striped';
$table->align = ['center', 'left', 'center', 'center', 'center'];

foreach ($students as $s) {
    $fio     = trim("{$s->lastname} {$s->firstname} " . ($s->middlename ?? ''));
    $current = $s->class_number . ($s->class_letter ?? '');
    $next    = $next_label((int)$s->class_number, (string)($s->class_letter ?? ''));
    $is_grad = (int)$s->class_number >= 11;

    $cb = html_writer::empty_tag('input', [
        'type' => 'checkbox', 'name' => 'student_ids[]', 'value' => (int)$s->id,
        'class' => 'promote-cb', 'checked' => 'checked',
    ]);

    $next_cell = $is_grad
        ? html_writer::tag('span', 'Выпуск', ['class' => 'badge badge-warning'])
        : html_writer::tag('strong', $next);

    $table->data[] = [$cb, s($fio), $current, '→', $next_cell];
}

echo html_writer::table($table);

// --- Работа с курсами (необязательно) ---
echo html_writer::start_tag('div', ['class' => 'card mb-3']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h5', 'Курсы (необязательно)', ['class' => 'card-title']);

// Чекбокс: скрыть текущие курсы выбранных.
echo html_writer::start_tag('div', ['class' => 'form-check mb-3']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'hide_courses',
    'name' => 'hide_courses', 'value' => '1', 'class' => 'form-check-input']);
echo html_writer::tag('label',
    'Скрыть текущие курсы выбранных учащихся '
    . html_writer::tag('span',
        '(курсы, в которых они состоят, станут невидимыми — для всех участников)',
        ['class' => 'text-muted']),
    ['for' => 'hide_courses', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

// Запись на новые курсы — чекбоксы с фильтром по названию.
$courses = $DB->get_records_sql(
    "SELECT id, fullname, visible FROM {course} WHERE id <> :site ORDER BY fullname",
    ['site' => SITEID]);

echo html_writer::tag('label', 'Записать выбранных на курсы:', ['class' => 'font-weight-bold d-block mb-1']);
echo html_writer::empty_tag('input', ['type' => 'text', 'id' => 'course-filter',
    'class' => 'form-control mb-2', 'placeholder' => 'Фильтр курсов по названию…',
    'style' => 'max-width:360px']);

if (empty($courses)) {
    echo html_writer::tag('p', 'Курсов нет.', ['class' => 'text-muted']);
} else {
    echo html_writer::start_tag('div', ['id' => 'course-list',
        'class' => 'border rounded p-2', 'style' => 'max-height:260px;overflow-y:auto;background:#fff']);
    foreach ($courses as $c) {
        $label = htmlspecialchars($c->fullname)
            . ((int)$c->visible === 0
                ? ' ' . html_writer::tag('span', 'скрыт', ['class' => 'badge badge-secondary'])
                : '');
        echo html_writer::start_tag('div', ['class' => 'form-check course-row',
            'data-name' => \core_text::strtolower($c->fullname)]);
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'enrol_course_ids[]',
            'value' => (int)$c->id, 'id' => 'crs_' . (int)$c->id,
            'class' => 'form-check-input enrol-course-cb']);
        echo html_writer::tag('label', $label,
            ['for' => 'crs_' . (int)$c->id, 'class' => 'form-check-label']);
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');
    echo html_writer::tag('small',
        'Записываются только переводимые (выбранные выше) учащиеся, на роль «Учащийся». '
        . 'Уже записанные пропускаются.',
        ['class' => 'form-text text-muted']);
}

echo html_writer::end_tag('div'); // card-body
echo html_writer::end_tag('div'); // card

echo html_writer::tag('button', 'Перевести выбранных',
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

// JS: клиентский фильтр списка курсов по названию.
echo html_writer::script("
(function(){
    var inp = document.getElementById('course-filter');
    if (!inp) return;
    inp.addEventListener('input', function(){
        var q = this.value.toLowerCase();
        document.querySelectorAll('#course-list .course-row').forEach(function(row){
            var name = row.getAttribute('data-name') || '';
            row.style.display = (name.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
");

echo $OUTPUT->footer();
