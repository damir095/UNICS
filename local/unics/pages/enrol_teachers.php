<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once($CFG->dirroot . '/group/lib.php');

require_login();
local_unics_require_not_student();
local_unics_require_manage_or_manageorg();

global $DB, $USER;

$sys_ctx          = context_system::instance();
$is_admin_user    = has_capability('local/unics:manage', $sys_ctx);
$is_scoped_role   = !$is_admin_user;
$my_scope         = $is_admin_user
    ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
    : \local_unics\scope_checker::get_user_scope((int)$USER->id);
$methodist_org_id = $my_scope['organization_id'] ?? 0;

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/enrol_teachers.php'));
$PAGE->set_title('Запись педагогов на курс - УНИКС');
$PAGE->set_heading('Запись педагогов на курс');
$PAGE->set_pagelayout('admin');

// ----------------------------------------------------------------
// Обработка POST
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $course_id       = required_param('course_id',      PARAM_INT);
    $teacher_ids     = optional_param_array('teacher_ids', [], PARAM_INT);
    $group_id        = optional_param('group_id',       0, PARAM_INT);
    $new_group       = trim(optional_param('new_group', '', PARAM_TEXT));
    $separate_groups = optional_param('separate_groups', 0, PARAM_INT);
    $enrol_students  = optional_param('enrol_students',  0, PARAM_INT);
    // #11.3 расширение: выбор конкретных учеников + фильтры для fallback'а.
    $enrol_student_ids   = optional_param_array('enrol_student_ids', [], PARAM_INT);
    $enrol_filter_class  = optional_param('enrol_filter_class', 0, PARAM_INT);
    $enrol_filter_letter = optional_param('enrol_filter_letter', '', PARAM_TEXT);
    $enrol_student_ids   = array_values(array_filter(array_map('intval', $enrol_student_ids)));

    $teacher_ids = array_filter($teacher_ids);

    if (empty($course_id) || empty($teacher_ids)) {
        redirect(
            new moodle_url('/local/unics/pages/enrol_teachers.php'),
            'Выберите курс и хотя бы одного педагога.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // Scope-check: каждый педагог должен входить в скоуп текущего пользователя.
    if (!$is_admin_user) {
        foreach ($teacher_ids as $tid) {
            $t_uid = (int)$DB->get_field('unics_teachers', 'mdl_user_id', ['id' => $tid]);
            if ($t_uid) { local_unics_require_manage_or_scope_user($t_uid); }
        }
    }

    // Создаём новую группу если указана
    if ($new_group !== '') {
        $grp           = new stdClass();
        $grp->courseid = $course_id;
        $grp->name     = $new_group;
        $group_id = groups_create_group($grp);
        // #12 (наблюдение 2026-05-28): создатель курса (часто методист, записан
        // editingteacher'ом) при separate-groups не видит участников группы, пока
        // сам в неё не входит. Авто-добавляем себя в только что созданную группу,
        // если уже записан на курс.
        if (is_enrolled(\context_course::instance($course_id), (int)$USER->id)) {
            groups_add_member($group_id, (int)$USER->id);
        }
    }

    $enrol    = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);
    if (!$instance) {
        $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
        $enrol->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['courseid' => $course_id, 'enrol' => 'manual', 'status' => 0]);
    }

    // Курс-роль определяется ИСКЛЮЧИТЕЛЬНО по unics_role педагога:
    //   unics_role 6 (педагог без редактирования) → 'teacher' (non-editing) на курсе;
    //   unics_role 4 (методист) и 5 (педагог, создающий курсы) → 'editingteacher'.
    // Раньше тут был select «роль на курсе», но он дублировал выбор уже сделанный
    // при создании пользователя — наблюдение #11.2 (2026-05-28).
    $role_id_cache = [];
    $resolve_role_id = function (string $shortname) use ($DB, &$role_id_cache): int {
        if (!isset($role_id_cache[$shortname])) {
            $rec = $DB->get_record('role', ['shortname' => $shortname], 'id');
            $role_id_cache[$shortname] = $rec ? (int)$rec->id : 0;
        }
        return $role_id_cache[$shortname];
    };

    $ctx      = \context_course::instance($course_id);
    $enrolled = 0;
    $skipped  = 0;

    foreach ($teacher_ids as $tid) {
        $teacher = $DB->get_record('unics_teachers', ['id' => $tid]);
        if (!$teacher) continue;

        $mdl_uid = (int)$teacher->mdl_user_id;

        // Курс-роль строго по unics_role: 6 → 'teacher', 4/5 → 'editingteacher'.
        $u_role        = (int)$DB->get_field('unics_user_org', 'unics_role', ['mdl_user_id' => $mdl_uid]);
        $eff_shortname = ($u_role === 6) ? 'teacher' : 'editingteacher';
        $eff_role_id   = $resolve_role_id($eff_shortname) ?: $resolve_role_id('editingteacher');

        if (!is_enrolled($ctx, $mdl_uid)) {
            $enrol->enrol_user($instance, $mdl_uid, $eff_role_id);
            $enrolled++;
        } else {
            $skipped++;
        }

        if ($group_id > 0) {
            if (!groups_is_member($group_id, $mdl_uid)) {
                groups_add_member($group_id, $mdl_uid);
            }
        }
    }

    // Настройка раздельных групп на курсе
    if ($separate_groups) {
        $DB->set_field('course', 'groupmode',      1, ['id' => $course_id]); // 1 = Separate groups
        $DB->set_field('course', 'groupmodeforce', 1, ['id' => $course_id]); // не даём активностям переопределять
        // Запрещаем accessallgroups для editingteacher на уровне курса
        $ctx_course = \context_course::instance($course_id);
        $et_role = $DB->get_record('role', ['shortname' => 'editingteacher'], 'id');
        if ($et_role) {
            assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $et_role->id, $ctx_course->id, true);
        }
        // Для teacher (педагог без редактирования) - тоже
        $t_role = $DB->get_record('role', ['shortname' => 'teacher'], 'id');
        if ($t_role) {
            assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $t_role->id, $ctx_course->id, true);
        }
    }

    // #11.3: авто-запись закреплённых учеников. Подтягиваем пары teacher_student для
    // выбранных педагогов, группируем по (class, letter, org) и записываем учеников
    // на курс. При включённом separate_groups — кладём каждую (class, letter, org)-
    // комбинацию в свою группу (имя «Группа<N><L>_<orgname>»), существующие группы
    // с этим именем переиспользуем. Педагога тоже добавляем в группы своих учеников
    // (продолжение #12 — иначе он не увидит участников при separate-groups).
    $stu_enrolled    = 0;
    $stu_skipped     = 0;
    $stu_groups_used = [];
    if ($enrol_students && !empty($teacher_ids)) {
        [$tin, $tin_p] = $DB->get_in_or_equal($teacher_ids, SQL_PARAMS_NAMED, 'tt');
        $extra_where  = '';
        $extra_params = [];
        if (!empty($enrol_student_ids)) {
            // Явный выбор — записываем только отмеченных (только из их пар с выбранными
            // педагогами; орг-/scope-проверки ниже).
            [$sin, $sin_p] = $DB->get_in_or_equal($enrol_student_ids, SQL_PARAMS_NAMED, 'ss');
            $extra_where .= " AND s.id {$sin}";
            $extra_params = array_merge($extra_params, $sin_p);
        } else {
            // Без явных отметок — применяем фильтры панели (как «всех видимых»).
            if ($enrol_filter_class > 0) {
                $extra_where .= ' AND s.class_number = :ef_cls';
                $extra_params['ef_cls'] = $enrol_filter_class;
            }
            if ($enrol_filter_letter !== '') {
                $extra_where .= ' AND s.class_letter = :ef_let';
                $extra_params['ef_let'] = $enrol_filter_letter;
            }
        }
        $rows = $DB->get_records_sql(
            "SELECT ts.id, ts.student_id, ts.teacher_id,
                    s.class_number, s.class_letter, s.organization_id,
                    s.mdl_user_id AS student_mdl_id,
                    o.name AS org_name,
                    t.mdl_user_id AS teacher_mdl_id
               FROM {unics_teacher_student} ts
               JOIN {unics_students} s ON s.id = ts.student_id
               JOIN {user} u ON u.id = s.mdl_user_id
               LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
               JOIN {unics_teachers} t ON t.id = ts.teacher_id
              WHERE ts.teacher_id {$tin}
                AND u.deleted = 0
                AND s.archived_at IS NULL
                AND s.graduated_at IS NULL
                {$extra_where}",
            array_merge($tin_p, $extra_params));

        $student_role_id = $resolve_role_id('student');
        $group_cache = [];   // key "class|letter|org_id" → group_id
        $teacher_in_group = []; // [teacher_mdl_id][group_id] = true
        $student_seen = []; // student_mdl_id → true (запись на курс — один раз)

        foreach ($rows as $r) {
            $student_uid = (int)$r->student_mdl_id;

            // Scope-check для не-админа — тихий skip при отказе.
            if (!$is_admin_user) {
                try {
                    local_unics_require_manage_or_scope_user($student_uid);
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if (!isset($student_seen[$student_uid])) {
                $student_seen[$student_uid] = true;
                if (!is_enrolled($ctx, $student_uid)) {
                    $enrol->enrol_user($instance, $student_uid, $student_role_id);
                    $stu_enrolled++;
                } else {
                    $stu_skipped++;
                }
            }

            // Группа создаётся только при separate_groups + у ученика есть класс.
            if (!$separate_groups || empty($r->class_number)) {
                continue;
            }
            $letter = $r->class_letter ?? '';
            $key    = $r->class_number . '|' . $letter . '|' . (int)$r->organization_id;
            if (!isset($group_cache[$key])) {
                $org_part = $r->org_name ? '_' . $r->org_name : '';
                $gname    = 'Группа' . $r->class_number . $letter . $org_part;
                $existing = $DB->get_records('groups',
                    ['courseid' => $course_id, 'name' => $gname], 'id', 'id', 0, 1);
                if ($existing) {
                    $group_cache[$key] = (int)reset($existing)->id;
                } else {
                    $g = new stdClass();
                    $g->courseid = $course_id;
                    $g->name     = $gname;
                    $group_cache[$key] = (int)groups_create_group($g);
                }
                $stu_groups_used[$gname] = true;
            }
            $gid = $group_cache[$key];

            if (!groups_is_member($gid, $student_uid)) {
                groups_add_member($gid, $student_uid);
            }

            // Педагога в группу своих учеников — один раз на пару (teacher, group).
            $tmid = (int)$r->teacher_mdl_id;
            if (empty($teacher_in_group[$tmid][$gid])) {
                if (is_enrolled($ctx, $tmid) && !groups_is_member($gid, $tmid)) {
                    groups_add_member($gid, $tmid);
                }
                $teacher_in_group[$tmid][$gid] = true;
            }
        }
    }

    $msg = "Записано: {$enrolled}";
    if ($skipped > 0) {
        $msg .= ", уже были записаны: {$skipped}";
    }
    if ($group_id > 0) {
        $grp_name = $DB->get_field('groups', 'name', ['id' => $group_id]);
        $msg .= ". Группа: «{$grp_name}»";
    }
    if ($separate_groups) {
        $msg .= '. Режим «Раздельные группы» включён.';
    }
    if ($enrol_students) {
        $msg .= '. Учащихся записано: ' . $stu_enrolled;
        if ($stu_skipped > 0) {
            $msg .= ' (уже было: ' . $stu_skipped . ')';
        }
        if (!empty($stu_groups_used)) {
            $msg .= '. Групп использовано: ' . count($stu_groups_used);
        }
    }

    redirect(
        new moodle_url('/local/unics/pages/enrol_teachers.php', ['course_id' => $course_id]),
        $msg, null, \core\output\notification::NOTIFY_SUCCESS
    );
}

// ----------------------------------------------------------------
// Данные для страницы
// ----------------------------------------------------------------
$selected_course = optional_param('course_id', 0, PARAM_INT);
$filter_org      = optional_param('org_id',    0, PARAM_INT);

if (!$is_admin_user && $methodist_org_id) {
    $filter_org = $methodist_org_id;
} else if (!$is_admin_user && $filter_org > 0
    && !\local_unics\scope_checker::user_can_access_org((int)$USER->id, $filter_org)) {
    $filter_org = 0;
}

// Курсы
$courses_raw  = $DB->get_records_sql("SELECT id, fullname FROM {course} WHERE id <> 1 ORDER BY fullname");
$courses_menu = [0 => '- выберите курс -'];
foreach ($courses_raw as $c) {
    $courses_menu[$c->id] = $c->fullname;
}

// Организации — фильтр по скоупу для не-админа.
if ($is_admin_user) {
    $orgs_raw = $DB->get_records('unics_organizations', ['is_active' => 1], 'name ASC', 'id, name');
} else {
    [$org_where, $org_params] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o');
    $orgs_raw = $DB->get_records_sql(
        "SELECT o.id, o.name FROM {unics_organizations} o
           WHERE o.is_active = 1 AND ({$org_where})
           ORDER BY o.name", $org_params);
}
$orgs_menu = [0 => '- все организации -'];
foreach ($orgs_raw as $o) {
    $orgs_menu[$o->id] = $o->name;
}

// Группы выбранного курса
$groups_menu = [0 => '- без группы -'];
if ($selected_course > 0) {
    foreach (groups_get_all_groups($selected_course) as $g) {
        $groups_menu[$g->id] = $g->name;
    }
}

// Педагоги с фильтрацией: методист орг. (4), педагог создающий курсы (5),
// педагог non-editing (6) — все записываются на курс.
$sql_where  = 'u.deleted = 0 AND uo.unics_role IN (4, 5, 6)';
$sql_params = [];
if ($filter_org > 0) {
    $sql_where .= ' AND t.organization_id = :org_id';
    $sql_params['org_id'] = $filter_org;
} else if (!$is_admin_user) {
    [$scope_where, $scope_params] = \local_unics\scope_checker::org_filter_sql((int)$USER->id, 'o2');
    $sql_where .= " AND t.organization_id IN (SELECT o2.id FROM {unics_organizations} o2 WHERE {$scope_where})";
    $sql_params = array_merge($sql_params, $scope_params);
}

$teachers = $DB->get_records_sql(
    "SELECT t.id AS teacher_id, u.id AS mdl_user_id,
            u.lastname, u.firstname, u.middlename,
            t.subjects, uo.unics_role,
            o.name AS org_name
     FROM {unics_teachers} t
     JOIN {user} u ON u.id = t.mdl_user_id
     JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
     LEFT JOIN {unics_organizations} o ON o.id = t.organization_id
     WHERE {$sql_where}
     ORDER BY u.lastname, u.firstname",
    $sql_params
);

// Помечаем уже записанных + их группы
$enrolled_users  = [];
$teacher_groups  = [];
if ($selected_course > 0) {
    $ctx_course = \context_course::instance($selected_course);
    foreach ($teachers as $t) {
        if (is_enrolled($ctx_course, (int)$t->mdl_user_id)) {
            $enrolled_users[$t->teacher_id] = true;
        }
        $ugroups = groups_get_user_groups($selected_course, (int)$t->mdl_user_id);
        $gnames  = [];
        foreach ($ugroups[0] ?? [] as $gid) {
            $gnames[] = $DB->get_field('groups', 'name', ['id' => $gid]);
        }
        if ($gnames) {
            $teacher_groups[$t->mdl_user_id] = $gnames;
        }
    }
}

$unics_role_labels = [4 => 'Методист', 5 => 'Педагог (создаёт курсы)', 6 => 'Педагог'];

// #11.3 расширение: пары (педагог, ученик) для построения панели выбора учеников.
// Сгруппированы по student_id — каждый ученик показывается один раз с data-tids,
// чтобы JS прятал тех, чей педагог не отмечен на странице.
$student_picks = [];
if (!empty($teachers)) {
    $tids_on_page = array_map(fn($t) => (int)$t->teacher_id, array_values($teachers));
    [$tinp, $tinp_p] = $DB->get_in_or_equal($tids_on_page, SQL_PARAMS_NAMED, 'tip');
    $pairs = $DB->get_records_sql(
        "SELECT ts.id AS pair_id, ts.student_id, ts.teacher_id,
                s.class_number, s.class_letter, s.organization_id,
                u.lastname, u.firstname,
                o.name AS org_name
           FROM {unics_teacher_student} ts
           JOIN {unics_students} s ON s.id = ts.student_id
           JOIN {user} u ON u.id = s.mdl_user_id
           LEFT JOIN {unics_organizations} o ON o.id = s.organization_id
          WHERE ts.teacher_id {$tinp}
            AND u.deleted = 0
            AND s.archived_at IS NULL
            AND s.graduated_at IS NULL
          ORDER BY u.lastname, u.firstname",
        $tinp_p);
    foreach ($pairs as $p) {
        $sid = (int)$p->student_id;
        if (!isset($student_picks[$sid])) {
            $student_picks[$sid] = ['row' => $p, 'tids' => []];
        }
        $student_picks[$sid]['tids'][] = (int)$p->teacher_id;
    }
}

// ----------------------------------------------------------------
// Вывод
// ----------------------------------------------------------------
echo $OUTPUT->header();
echo local_unics_dashboard_button();
echo $OUTPUT->heading('Запись педагогов на курс');

echo html_writer::link(
    new moodle_url('/local/unics/pages/enrol_students.php'),
    'Запись учащихся',
    ['class' => 'btn btn-outline-primary btn-sm mb-3']
);

// --- Форма фильтров ---
$filter_url = new moodle_url('/local/unics/pages/enrol_teachers.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filter_url,
    'class' => 'p-3 bg-light border rounded mb-4']);
echo html_writer::start_tag('div', ['class' => 'row g-2 align-items-end']);

// Курс
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('label', 'Курс', ['class' => 'font-weight-bold d-block mb-1']);
echo html_writer::select($courses_menu, 'course_id', $selected_course, false,
    ['class' => 'form-control', 'style' => 'min-width:250px', 'onchange' => 'this.form.submit()']);
echo html_writer::end_tag('div');

if (!$is_admin_user && $methodist_org_id) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'org_id',
        'value' => (int)$filter_org]);
} else {
    // Организация
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Организация', ['class' => 'd-block mb-1']);
    echo html_writer::select($orgs_menu, 'org_id', $filter_org, false,
        ['class' => 'form-control', 'style' => 'min-width:200px']);
    echo html_writer::end_tag('div');
}

// Кнопка
echo html_writer::start_tag('div', ['class' => 'col-auto']);
echo html_writer::tag('button', 'Применить', ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('form');

if (empty($teachers)) {
    echo $OUTPUT->notification('Педагогов по выбранному фильтру не найдено.', 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($selected_course <= 0) {
    echo $OUTPUT->notification('Выберите курс, чтобы увидеть статус записи и записать педагогов.', 'info');
}

// --- Форма записи ---
$enrol_url = new moodle_url('/local/unics/pages/enrol_teachers.php', ['sesskey' => sesskey()]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $enrol_url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'course_id', 'value' => $selected_course]);

// --- Выбор группы и роли (только если курс выбран) ---
if ($selected_course > 0) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body py-2']);
    echo html_writer::start_tag('div', ['class' => 'row g-2 align-items-end']);

    // Существующая группа
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Добавить в группу курса:', ['class' => 'font-weight-bold d-block mb-1']);
    echo html_writer::select($groups_menu, 'group_id', 0, false,
        ['class' => 'form-control', 'style' => 'min-width:200px']);
    echo html_writer::end_tag('div');

    // Разделитель
    echo html_writer::start_tag('div', ['class' => 'col-auto align-self-end mb-2']);
    echo html_writer::tag('span', 'или', ['class' => 'text-muted']);
    echo html_writer::end_tag('div');

    // Новая группа
    echo html_writer::start_tag('div', ['class' => 'col-auto']);
    echo html_writer::tag('label', 'Создать новую группу:', ['class' => 'd-block mb-1']);
    echo html_writer::empty_tag('input', [
        'type'        => 'text',
        'name'        => 'new_group',
        'class'       => 'form-control',
        'placeholder' => 'Название новой группы',
        'style'       => 'min-width:220px',
    ]);
    echo html_writer::end_tag('div');

    echo html_writer::tag('div',
        html_writer::tag('small',
            'Если указана новая группа - она будет создана и приоритетна над выбором из списка.',
            ['class' => 'text-muted']
        ),
        ['class' => 'col-12 mt-1']
    );

    // Раздельные группы
    echo html_writer::tag('div',
        html_writer::div(
            html_writer::empty_tag('input', [
                'type'    => 'checkbox',
                'name'    => 'separate_groups',
                'id'      => 'separate_groups',
                'value'   => '1',
                'class'   => 'form-check-input',
                'checked' => 'checked',
            ]) .
            html_writer::tag('label',
                html_writer::tag('strong', 'Включить режим «Раздельные группы» для курса') .
                html_writer::div(
                    'Педагоги будут видеть только участников своей группы.',
                    'text-muted small'
                ),
                ['for' => 'separate_groups', 'class' => 'form-check-label mb-0']
            ),
            'form-check'
        ),
        ['class' => 'col-12 mt-2']
    );

    // #11.3: авто-запись закреплённых учеников выбранных педагогов.
    echo html_writer::tag('div',
        html_writer::div(
            html_writer::empty_tag('input', [
                'type'  => 'checkbox',
                'name'  => 'enrol_students',
                'id'    => 'enrol_students',
                'value' => '1',
                'class' => 'form-check-input',
            ]) .
            html_writer::tag('label',
                html_writer::tag('strong',
                    'Также записать на курс учащихся, закреплённых за выбранными педагогами') .
                html_writer::div(
                    'Если включены «Раздельные группы» — учащиеся разбиваются на группы по '
                    . 'формуле <code>Группа&lt;класс&gt;&lt;буква&gt;_&lt;организация&gt;</code>; '
                    . 'педагог попадает в каждую группу своих учеников. Существующие группы '
                    . 'с тем же именем переиспользуются. Выбор «Добавить в группу» / «Создать '
                    . 'новую группу» выше относится к самим педагогам и не зависит от этого '
                    . 'чекбокса.',
                    'text-muted small'
                ),
                ['for' => 'enrol_students', 'class' => 'form-check-label mb-0']
            ),
            'form-check'
        ),
        ['class' => 'col-12 mt-2']
    );

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    // --- Панель выбора учеников (видна при включённом чекбоксе) ---
    echo html_writer::start_tag('div',
        ['id' => 'enrol_students_panel', 'class' => 'card mb-3', 'style' => 'display:none']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h6', 'Учащиеся для записи',
        ['class' => 'mb-2']);
    echo html_writer::tag('small',
        'Без явных отметок — будут записаны все ученики выбранных педагогов, '
        . 'подходящие под фильтр.',
        ['class' => 'text-muted d-block mb-2']);

    echo html_writer::start_tag('div', ['class' => 'd-flex flex-wrap gap-2 mb-2 align-items-center']);
    echo '<label class="mb-0">Класс: <select id="esp_cls" class="form-control form-control-sm d-inline-block w-auto"><option value="">все</option>';
    for ($i = 1; $i <= 11; $i++) { echo '<option value="' . $i . '">' . $i . '</option>'; }
    echo '</select></label>';
    echo '<label class="mb-0 ml-2">Буква: <select id="esp_let" class="form-control form-control-sm d-inline-block w-auto"><option value="">все</option>';
    foreach (['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж'] as $L) { echo '<option value="' . $L . '">' . $L . '</option>'; }
    echo '</select></label>';
    echo ' <a href="#" id="esp_all" class="ml-3">Выбрать видимых</a>';
    echo ' <a href="#" id="esp_none">Снять все</a>';
    echo html_writer::end_tag('div');

    // Hidden inputs синхронизируются из селектов фильтра — нужны серверу для fallback'а.
    echo html_writer::empty_tag('input', ['type' => 'hidden',
        'name' => 'enrol_filter_class', 'id' => 'esp_cls_hidden', 'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden',
        'name' => 'enrol_filter_letter', 'id' => 'esp_let_hidden', 'value' => '']);

    echo html_writer::start_tag('div', ['id' => 'esp_list', 'class' => 'border rounded p-2',
        'style' => 'max-height:280px;overflow-y:auto;background:#fff']);
    if (empty($student_picks)) {
        echo html_writer::tag('div', 'У педагогов на странице нет закреплённых учеников.',
            ['class' => 'text-muted']);
    } else {
        foreach ($student_picks as $sid => $info) {
            $r = $info['row'];
            $cls = (int)($r->class_number ?? 0);
            $let = (string)($r->class_letter ?? '');
            $org = htmlspecialchars($r->org_name ?? '');
            $fio = htmlspecialchars(trim("{$r->lastname} {$r->firstname}"));
            $cls_str = $cls ? ' - ' . $cls . htmlspecialchars($let) . ' кл.' : '';
            $org_str = $org !== '' ? " ({$org})" : '';
            echo '<div class="form-check esp-row" '
                . 'data-tids="' . implode(',', $info['tids']) . '" '
                . 'data-cls="' . $cls . '" '
                . 'data-let="' . htmlspecialchars($let) . '">';
            echo '<input type="checkbox" class="form-check-input esp-cb" '
                . 'name="enrol_student_ids[]" value="' . $sid . '" '
                . 'id="esp_s_' . $sid . '">';
            echo '<label class="form-check-label" for="esp_s_' . $sid . '">'
                . $fio . $cls_str . $org_str . '</label>';
            echo '</div>';
        }
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

// --- Таблица педагогов ---
$table = new html_table();
$table->head = [
    html_writer::tag('label',
        html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'check_all']) . ' Все',
        ['for' => 'check_all']
    ),
    'Педагог', 'Роль', 'Предметы', 'Организация', 'Статус', 'Группы в курсе'
];
$table->attributes['class'] = 'table table-sm table-bordered table-hover';

foreach ($teachers as $t) {
    $fio  = htmlspecialchars(trim("{$t->lastname} {$t->firstname} " . ($t->middlename ?? '')));
    $role = $unics_role_labels[$t->unics_role] ?? '-';

    $is_enrolled  = isset($enrolled_users[$t->teacher_id]);
    $status_badge = $is_enrolled
        ? html_writer::tag('span', 'Записан',    ['class' => 'badge badge-success'])
        : html_writer::tag('span', 'Не записан', ['class' => 'badge badge-secondary']);

    $checkbox = html_writer::empty_tag('input', [
        'type'  => 'checkbox',
        'name'  => 'teacher_ids[]',
        'value' => $t->teacher_id,
        'class' => 'teacher-check',
    ]);

    $gnames = $teacher_groups[$t->mdl_user_id] ?? [];
    $groups_cell = $gnames
        ? implode(', ', array_map(fn($g) => html_writer::tag('span', htmlspecialchars($g),
            ['class' => 'badge badge-info mr-1']), $gnames))
        : html_writer::tag('span', '-', ['class' => 'text-muted']);

    $row = new html_table_row([
        $checkbox,
        html_writer::tag('strong', $fio),
        $role,
        htmlspecialchars($t->subjects ?? '-'),
        htmlspecialchars($t->org_name ?? '-'),
        $status_badge,
        $groups_cell,
    ]);
    $row->attributes['class'] = $is_enrolled ? 'table-light' : '';
    $table->data[] = $row;
}

echo html_writer::table($table);

if ($selected_course > 0) {
    echo html_writer::tag('button', 'Записать выбранных на курс',
        ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
}

echo html_writer::end_tag('form');

echo html_writer::script("
document.getElementById('check_all').addEventListener('change', function() {
    document.querySelectorAll('.teacher-check').forEach(function(cb) {
        cb.checked = document.getElementById('check_all').checked;
    });
    if (typeof espApply === 'function') espApply();
});

var newGroupInput = document.querySelector('input[name=new_group]');
var groupSelect   = document.querySelector('select[name=group_id]');
if (newGroupInput && groupSelect) {
    newGroupInput.addEventListener('input', function() {
        if (this.value.trim() !== '') groupSelect.value = '0';
    });
    groupSelect.addEventListener('change', function() {
        if (this.value !== '0') newGroupInput.value = '';
    });
}

// #11.3 расширение: панель выбора учеников.
function espGetCheckedTeachers() {
    return Array.from(document.querySelectorAll('.teacher-check:checked'))
        .map(function(cb) { return cb.value; });
}
function espApply() {
    var clsSel = document.getElementById('esp_cls');
    var letSel = document.getElementById('esp_let');
    if (!clsSel) return;
    var cls  = clsSel.value;
    var let_ = letSel.value;
    document.getElementById('esp_cls_hidden').value = cls;
    document.getElementById('esp_let_hidden').value = let_;
    var tch = espGetCheckedTeachers();
    document.querySelectorAll('.esp-row').forEach(function(row) {
        var rTids = (row.getAttribute('data-tids') || '').split(',').filter(Boolean);
        var rCls  = row.getAttribute('data-cls') || '';
        var rLet  = row.getAttribute('data-let') || '';
        var matchT = tch.length === 0 ? false :
            rTids.some(function(t) { return tch.indexOf(t) !== -1; });
        var matchC = !cls  || rCls === cls;
        var matchL = !let_ || rLet === let_;
        row.style.display = (matchT && matchC && matchL) ? '' : 'none';
    });
}
document.querySelectorAll('.teacher-check').forEach(function(cb) {
    cb.addEventListener('change', espApply);
});
['esp_cls', 'esp_let'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', espApply);
});
var espAllBtn = document.getElementById('esp_all');
if (espAllBtn) espAllBtn.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('.esp-row').forEach(function(row) {
        if (row.style.display !== 'none') {
            var cb = row.querySelector('.esp-cb');
            if (cb) cb.checked = true;
        }
    });
});
var espNoneBtn = document.getElementById('esp_none');
if (espNoneBtn) espNoneBtn.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('.esp-cb').forEach(function(cb) { cb.checked = false; });
});
var enrolStuCb = document.getElementById('enrol_students');
var espPanel   = document.getElementById('enrol_students_panel');
if (enrolStuCb && espPanel) {
    function espSyncPanel() {
        espPanel.style.display = enrolStuCb.checked ? '' : 'none';
        if (enrolStuCb.checked) espApply();
    }
    enrolStuCb.addEventListener('change', espSyncPanel);
    espSyncPanel();
}
");

echo $OUTPUT->footer();
