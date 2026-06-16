<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

require_login();
// Полный доступ — у системного админа (manage); районный/региональн. админ и методист
// (manageorg) видят и управляют пользователями в рамках своего скоупа.
local_unics_require_manage_or_manageorg();

global $USER, $DB, $CFG;
$ctx           = context_system::instance();
$is_full_admin = has_capability('local/unics:manage', $ctx);

// ---------------------------------------------------------------------------
// POST: архив / восстановление / жёсткое удаление учащегося. PRG (redirect).
// «Удалить ученика» методистом (#10) = мягкий архив; hard-delete — только полному
// админу. На каждое действие — scope-проверка целевого пользователя.
// ---------------------------------------------------------------------------
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action !== '' && confirm_sesskey()) {
    $student_id = required_param('student_id', PARAM_INT);
    $student = $DB->get_record('unics_students', ['id' => $student_id],
        'id, mdl_user_id', MUST_EXIST);

    // Не-админ может трогать только учеников своего скоупа.
    local_unics_require_manage_or_scope_user((int)$student->mdl_user_id);

    $return = new moodle_url('/local/unics/pages/users.php');

    if ($action === 'archive') {
        $ok = unics_user_manager::archive_student($student_id, (int)$USER->id);
        redirect($return,
            $ok ? 'Учащийся перемещён в архив. Аккаунт, оценки и история сохранены.'
                : 'Учащийся уже в архиве.',
            null, $ok ? \core\output\notification::NOTIFY_SUCCESS
                      : \core\output\notification::NOTIFY_WARNING);

    } else if ($action === 'restore') {
        $ok = unics_user_manager::restore_student($student_id, (int)$USER->id);
        redirect($return,
            $ok ? 'Учащийся восстановлен из архива.' : 'Учащийся уже активен.',
            null, $ok ? \core\output\notification::NOTIFY_SUCCESS
                      : \core\output\notification::NOTIFY_WARNING);

    } else if ($action === 'delete') {
        // Жёсткое удаление аккаунта — только полному админу (решение #10).
        if (!$is_full_admin) {
            throw new moodle_exception('nopermissions', 'error', '',
                'жёсткое удаление аккаунта учащегося доступно только администратору');
        }
        $mdl_user = $DB->get_record('user',
            ['id' => $student->mdl_user_id, 'deleted' => 0], '*', MUST_EXIST);
        require_once($CFG->dirroot . '/user/lib.php');
        user_delete_user($mdl_user);
        redirect($return, 'Аккаунт учащегося удалён.', null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

$PAGE->set_context($ctx);
$PAGE->set_url(new moodle_url('/local/unics/pages/users.php'));
$PAGE->set_title(get_string('users', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

// Фильтры
$filter_role     = optional_param('role', 0, PARAM_INT);
$filter_org      = optional_param('org', 0, PARAM_INT);
$filter_district = optional_param('district', 0, PARAM_INT);
$filter_class    = optional_param('class', 0, PARAM_INT);
// Буква класса — кириллица (А–Ж), поэтому PARAM_TEXT, не PARAM_ALPHA.
$filter_letter   = optional_param('letter', '', PARAM_TEXT);
// Привязки учащегося: '' / noteacher / noparent / noclass (поиск «висящих» учащихся).
$filter_bind     = optional_param('bind', '', PARAM_ALPHA);

// Доп. условия (район / класс / буква) — джойнятся в get_users() через extra_where:
// доступны алиасы uo (unics_user_org), o (LEFT organizations), s (LEFT students).
$extra        = [];
$extra_params = [];
if ($filter_district > 0) {
    // Совпадение и по территории управленца (uo.district_id), и по району его орг (o.district_id).
    $extra[] = '(uo.district_id = :fd1 OR o.district_id = :fd2)';
    $extra_params['fd1'] = $filter_district;
    $extra_params['fd2'] = $filter_district;
}
if ($filter_class > 0) {
    $extra[] = 's.class_number = :fcls';
    $extra_params['fcls'] = $filter_class;
}
if ($filter_letter !== '') {
    $extra[] = 's.class_letter = :flet';
    $extra_params['flet'] = $filter_letter;
}
// Фильтр по привязкам — только учащиеся (s.id IS NOT NULL: у не-учащихся записи в
// unics_students нет, поэтому LEFT-джойн даёт NULL и они исключаются). Подзапросы
// NOT EXISTS параметров не требуют — комбинируются с прочими условиями через AND.
if ($filter_bind === 'noteacher') {
    $extra[] = '(s.id IS NOT NULL AND NOT EXISTS '
        . '(SELECT 1 FROM {unics_teacher_student} ts WHERE ts.student_id = s.id))';
} else if ($filter_bind === 'noparent') {
    $extra[] = '(s.id IS NOT NULL AND NOT EXISTS '
        . '(SELECT 1 FROM {unics_parent_student} ps WHERE ps.student_id = s.id))';
} else if ($filter_bind === 'noclass') {
    $extra[] = '(s.id IS NOT NULL AND (s.class_number IS NULL OR s.class_number = 0))';
}

// Архивные учащиеся по умолчанию скрыты. Условие пропускает не-учащихся (у них нет
// строки в unics_students → s.archived_at IS NULL через LEFT JOIN) и активных учеников.
$show_archived = optional_param('show_archived', 0, PARAM_INT);
if (!$show_archived) {
    $extra[] = 's.archived_at IS NULL';
}

if ($is_full_admin) {
    $where = implode(' AND ', $extra);
    $users = unics_user_manager::get_users($filter_org, $filter_role, $where, $extra_params);
    $orgs  = unics_user_manager::get_organizations_menu();
} else {
    // Скоуп-фильтр: только пользователи района/региона/организации смотрящего.
    // Доп. фильтры комбинируются со скоупом через AND — обойти территорию нельзя.
    [$scope_where, $scope_params] =
        \local_unics\scope_checker::user_list_filter_sql((int)$USER->id, 'uo', 'o');
    $where = implode(' AND ', array_merge([$scope_where], $extra));
    $users = unics_user_manager::get_users($filter_org, $filter_role, $where,
        $extra_params + $scope_params);

    // Выпадающий список организаций — тоже по скоупу.
    [$ofw, $ofp] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $orgs_rows = $DB->get_records_sql(
        "SELECT o.id, o.name FROM {unics_organizations} o
          WHERE o.is_active = 1 AND ({$ofw}) ORDER BY o.name", $ofp);
    $orgs = [];
    foreach ($orgs_rows as $o) { $orgs[$o->id] = $o->name; }
}

// Меню для фильтров «Район / Класс / Буква».
$districts_menu = \local_unics\scope_checker::accessible_districts_menu((int)$USER->id, $is_full_admin);
$class_menu = [];
for ($i = 1; $i <= 11; $i++) { $class_menu[$i] = $i . ' класс'; }
$letters_menu = ['А' => 'А', 'Б' => 'Б', 'В' => 'В', 'Г' => 'Г',
                 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];

$role_labels = [
    1 => 'Региональный администратор',
    2 => 'Муниципальный администратор',
    9 => 'Муниципальный методист',
    3 => 'Администратор организации',       // legacy
    4 => 'Методист организации',
    5 => 'Педагог, создающий курсы',
    6 => 'Педагог',                          // non-editing
    7 => 'Учащийся',
    8 => 'Родитель',
];

echo $OUTPUT->header();
echo local_unics_dashboard_button();

// Навигационные кнопки
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/unics/pages/create_user.php'),
        get_string('create_user', 'local_unics'),
        ['class' => 'btn btn-primary']
    ) .
    html_writer::link(
        new moodle_url('/local/unics/pages/assign.php'),
        get_string('assignments', 'local_unics'),
        ['class' => 'btn btn-secondary']
    ) .
    html_writer::link(
        new moodle_url('/local/unics/pages/organizations.php'),
        get_string('organizations', 'local_unics'),
        ['class' => 'btn btn-outline-secondary']
    ),
    'unics-btn-row'
);

// Форма фильтров
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'd-flex flex-wrap align-items-center gap-2 mb-3']);

// Фильтр по району — только если доступно больше одного (для районного/орг-скоупа район один).
if (count($districts_menu) > 1) {
    echo html_writer::select(
        [0 => 'Все муниципалитеты'] + $districts_menu,
        'district', $filter_district, false, ['class' => 'form-control', 'aria-label' => 'Муниципалитет']
    );
}

// Фильтр по организации
echo html_writer::select(
    [0 => get_string('all_orgs', 'local_unics')] + $orgs,
    'org', $filter_org, false, ['class' => 'form-control', 'aria-label' => 'Организация']
);

// Фильтр по роли
echo html_writer::select(
    [0 => get_string('all_roles', 'local_unics')] + $role_labels,
    'role', $filter_role, false, ['class' => 'form-control', 'aria-label' => 'Роль']
);

// Фильтр по классу (учащиеся)
echo html_writer::select(
    [0 => 'Все классы'] + $class_menu,
    'class', $filter_class, false, ['class' => 'form-control', 'aria-label' => 'Класс']
);

// Фильтр по букве класса (учащиеся)
echo html_writer::select(
    ['' => 'Все буквы'] + $letters_menu,
    'letter', $filter_letter, false, ['class' => 'form-control', 'aria-label' => 'Буква класса']
);

// Фильтр по привязкам (учащиеся без педагога / родителя / класса)
echo html_writer::select(
    [
        ''          => 'Все привязки',
        'noteacher' => 'Без педагога',
        'noparent'  => 'Без родителя',
        'noclass'   => 'Без класса',
    ],
    'bind', $filter_bind, false, ['class' => 'form-control', 'aria-label' => 'Привязки учащегося']
);

// Тоггл: показать архивных учащихся.
echo html_writer::start_tag('div', ['class' => 'form-check form-check-inline']);
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'checkbox', 'name' => 'show_archived', 'value' => '1',
     'id' => 'show_archived', 'class' => 'form-check-input'],
    $show_archived ? ['checked' => 'checked'] : []));
echo html_writer::tag('label', 'Показать архивных',
    ['for' => 'show_archived', 'class' => 'form-check-label']);
echo html_writer::end_tag('div');

echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

// Таблица пользователей
if (empty($users)) {
    echo $OUTPUT->notification(get_string('no_users', 'local_unics'), 'info');
} else {
    $table = new html_table();
    $table->head = ['ФИО', 'Email', 'Роль', 'Тип', 'Организация / территория', 'Класс', get_string('actions', 'local_unics')];
    $table->attributes['class'] = 'table table-striped';

    // POST-кнопка действия над учащимся (архив/восстановление/удаление).
    $student_action = function (int $student_id, string $act, string $label,
                                string $cls, ?string $confirm = null): string {
        $attrs = ['method' => 'post', 'class' => 'd-inline ms-1'];
        if ($confirm !== null) {
            $attrs['onsubmit'] = "return confirm('{$confirm}')";
        }
        return html_writer::tag('form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',     'value' => $act])
          . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'student_id', 'value' => $student_id])
          . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()])
          . html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-sm ' . $cls]),
            $attrs);
    };

    foreach ($users as $user) {
        $is_archived = (int)$user->unics_role === 7 && !empty($user->archived_at);
        $fio = s(trim("{$user->lastname} {$user->firstname} {$user->middlename}"));
        if ($is_archived) {
            $fio .= ' ' . html_writer::tag('span', 'Архив', ['class' => 'badge bg-secondary']);
        }
        $role_label = $role_labels[$user->unics_role] ?? '-';

        // Тип (архетип) - только у педагогов; для прочих ролей пусто (D2).
        $type_cell = unics_user_manager::teacher_type_label($user->teacher_type ?? null);
        if ($type_cell === '') {
            $type_cell = '-';
        }

        // Класс: только для учащихся (роль 7)
        $class_cell = '-';
        if ((int)$user->unics_role === 7 && !empty($user->class_number)) {
            $class_cell = $user->class_number . ($user->class_letter ?? '');
        }

        $edit_url = new moodle_url('/local/unics/pages/edit_user.php', ['id' => $user->id]);
        $moodle_edit_url = new moodle_url('/user/editadvanced.php', ['id' => $user->id, 'course' => SITEID]);
        $actions_cell = html_writer::link($edit_url, get_string('edit', 'local_unics'),
                ['class' => 'btn btn-sm btn-outline-primary'])
            . ' '
            . html_writer::link($moodle_edit_url, 'Moodle',
                ['class' => 'btn btn-sm btn-outline-secondary',
                 'title' => 'Редактировать аккаунт в Moodle (пароль, аватар, email)']);

        // Архив/восстановление/удаление — только для учащихся (роль 7).
        if ((int)$user->unics_role === 7 && !empty($user->student_id)) {
            if ($is_archived) {
                $actions_cell .= $student_action((int)$user->student_id, 'restore',
                    'Восстановить', 'btn-outline-success');
                if ($is_full_admin) {
                    $actions_cell .= $student_action((int)$user->student_id, 'delete', 'Удалить',
                        'btn-outline-danger',
                        'Удалить аккаунт без возможности восстановления? Оценки и история будут потеряны.');
                }
            } else {
                $actions_cell .= $student_action((int)$user->student_id, 'archive', 'В архив',
                    'btn-outline-warning',
                    'Переместить ученика в архив? Аккаунт и оценки сохранятся, действие обратимо.');
            }
        }

        $table->data[] = [
            $fio,
            $user->email,
            $role_label,
            $type_cell,
            $user->org_name,
            $class_cell,
            $actions_cell,
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
