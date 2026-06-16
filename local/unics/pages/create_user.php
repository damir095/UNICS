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
        //   роли 1 и 10 — регион; роль 9 — муниципалитет; роль 8 (родитель) — скоуп через
        //   ребёнка (поэтому при создании orgnization не проверяем); прочие — организация.
        if ($role === 1 || $role === 10) {
            if (!empty($data->region_id)) {
                local_unics_require_manage_or_scope_region((int)$data->region_id);
            }
        } else if ($role === 9) {
            if (!empty($data->district_id)) {
                local_unics_require_manage_or_scope_district((int)$data->district_id);
            }
        } else if ($role !== 8 && !empty($data->organization_id)) {
            local_unics_require_manage_or_scope_org((int)$data->organization_id);
        }

        $new_mdl_user_id = unics_user_manager::create_user((array)$data);

        // NEW-4 + #3 от 2026-05-30: привязки прямо при создании. Multi-чекбоксы
        // для учащихся/педагогов/родителей; родитель → ребёнок остаётся single.
        // На каждый id — две проверки: (1) принадлежит той же орг, что и созданный;
        // (2) входит в скоуп создателя. Тихий skip при несовпадении (UI не даёт выбрать
        // не из той орг, но сервер тоже не доверяет POST'у).
        $link_msg = '';
        $is_full = has_capability('local/unics:manage', context_system::instance());

        $can_touch = function (int $target_mdl_user_id) use ($is_full, $USER): bool {
            return $is_full
                || \local_unics\scope_checker::user_can_access_user((int)$USER->id, $target_mdl_user_id);
        };

        if ($role === 5 || $role === 6) {
            // Создан педагог — привязка выбранных учащихся той же орг.
            $teacher = $DB->get_record('unics_teachers', ['mdl_user_id' => $new_mdl_user_id], 'id');
            $teacher_org = (int)($data->organization_id ?? 0);
            if ($teacher && $teacher_org > 0) {
                $student_ids = optional_param_array('assign_student_ids', [], PARAM_INT);
                $added = 0;
                foreach ($student_ids as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0) { continue; }
                    $s = $DB->get_record('unics_students', ['id' => $sid],
                        'id, mdl_user_id, organization_id');
                    if (!$s || (int)$s->organization_id !== $teacher_org) { continue; }
                    if (!$can_touch((int)$s->mdl_user_id)) { continue; }
                    if (unics_user_manager::assign_teacher_student(
                            (int)$teacher->id, $sid, (int)$USER->id)) {
                        $added++;
                    }
                }
                if ($added > 0) { $link_msg .= ' Привязано учащихся: ' . $added . '.'; }
            }

        } else if ($role === 7) {
            // Создан учащийся — привязка педагогов и родителей той же орг.
            $student = $DB->get_record('unics_students', ['mdl_user_id' => $new_mdl_user_id],
                'id, organization_id');
            if ($student) {
                $stu_org = (int)$student->organization_id;
                $teacher_ids = optional_param_array('assign_teacher_ids', [], PARAM_INT);
                $parent_ids  = optional_param_array('assign_parent_ids',  [], PARAM_INT);

                $added_t = 0;
                foreach ($teacher_ids as $tid) {
                    $tid = (int)$tid;
                    if ($tid <= 0) { continue; }
                    $t = $DB->get_record('unics_teachers', ['id' => $tid],
                        'id, mdl_user_id, organization_id');
                    if (!$t || (int)$t->organization_id !== $stu_org) { continue; }
                    if (!$can_touch((int)$t->mdl_user_id)) { continue; }
                    if (unics_user_manager::assign_teacher_student(
                            $tid, (int)$student->id, (int)$USER->id)) {
                        $added_t++;
                    }
                }
                if ($added_t > 0) { $link_msg .= ' Педагогов: ' . $added_t . '.'; }

                $added_p = 0;
                foreach ($parent_ids as $pid) {
                    $pid = (int)$pid;
                    if ($pid <= 0) { continue; }
                    $p_org = (int)$DB->get_field('unics_user_org',
                        'organization_id', ['mdl_user_id' => $pid, 'unics_role' => 8]);
                    if ($p_org !== $stu_org) { continue; }
                    if (!$can_touch($pid)) { continue; }
                    if (unics_user_manager::assign_parent_student($pid, (int)$student->id)) {
                        $added_p++;
                    }
                }
                if ($added_p > 0) { $link_msg .= ' Родителей: ' . $added_p . '.'; }
            }

        } else if ($role === 8 && !empty($data->assign_student_id)) {
            // Родитель — single ребёнок: + scope-check выбранного учащегося.
            $sid = (int)$data->assign_student_id;
            $s = $DB->get_record('unics_students', ['id' => $sid], 'id, mdl_user_id');
            if ($s && $can_touch((int)$s->mdl_user_id)) {
                $ok = unics_user_manager::assign_parent_student(
                    (int)$new_mdl_user_id, $sid);
                $link_msg = $ok ? ' Ребёнок привязан.' : ' (привязка к ребёнку уже существовала)';
            }
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
echo local_unics_dashboard_button();
echo $OUTPUT->heading(get_string('create_user', 'local_unics'));
$form->display();
echo $OUTPUT->footer();
