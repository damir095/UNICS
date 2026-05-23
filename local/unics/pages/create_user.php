<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');
require_once(__DIR__ . '/../forms/create_user_form.php');

require_login();
local_unics_require_manage_or_manageorg();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/create_user.php'));
$PAGE->set_title(get_string('create_user', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

$form = new unics_create_user_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/unics/pages/users.php'));

} else if ($data = $form->get_data()) {
    try {
        $role = (int)$data->unics_role;

        // Защита от создания роли своего уровня или выше (например, методист не должен
        // создавать администраторов). hideIf в форме — только клиентская подсказка,
        // поэтому проверяем на сервере. См. local_unics_creatable_roles().
        if (!in_array($role, local_unics_creatable_roles((int)$USER->id), true)) {
            throw new moodle_exception('nopermissions', 'error', '',
                'у вас недостаточно прав для создания пользователя с этой ролью');
        }

        // Проверка прав на территорию: новый пользователь должен попадать в вашу зону.
        //   роль 1 — регион; роли 2 и 9 — район; прочие — организация.
        if ($role === 1) {
            if (!empty($data->region_id)) {
                local_unics_require_manage_or_scope_region((int)$data->region_id);
            }
        } else if ($role === 2 || $role === 9) {
            if (!empty($data->district_id)) {
                local_unics_require_manage_or_scope_district((int)$data->district_id);
            }
        } else if (!empty($data->organization_id)) {
            local_unics_require_manage_or_scope_org((int)$data->organization_id);
        }

        $new_mdl_user_id = unics_user_manager::create_user((array)$data);

        // NEW-4: привязки, указанные прямо в форме создания.
        $link_msg = '';

        if ($role === 7 && !empty($data->assign_teacher_id)) {
            $student = $DB->get_record('unics_students', ['mdl_user_id' => $new_mdl_user_id], 'id');
            if ($student) {
                $ok = unics_user_manager::assign_teacher_student(
                    (int)$data->assign_teacher_id, (int)$student->id, (int)$USER->id);
                $link_msg = $ok ? ' Педагог назначен.' : ' (привязка к педагогу уже существовала)';
            }
        } else if ($role === 8 && !empty($data->assign_student_id)) {
            $ok = unics_user_manager::assign_parent_student(
                (int)$new_mdl_user_id, (int)$data->assign_student_id);
            $link_msg = $ok ? ' Ребёнок привязан.' : ' (привязка к ребёнку уже существовала)';
        }

        redirect(
            new moodle_url('/local/unics/pages/users.php'),
            get_string('user_created', 'local_unics') . $link_msg,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (Exception $e) {
        redirect(
            new moodle_url('/local/unics/pages/create_user.php'),
            get_string('user_create_error', 'local_unics') . ': ' . $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('create_user', 'local_unics'));
$form->display();
echo $OUTPUT->footer();
