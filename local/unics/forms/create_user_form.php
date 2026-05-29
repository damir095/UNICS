<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

use local_unics\scope_checker;

class unics_create_user_form extends moodleform {

    public function definition() {
        global $USER, $DB;
        $mform = $this->_form;

        // Фильтрация по скоупу: системный админ (с local/unics:manage) видит всё,
        // методист/региональн. админ (с manageorg) — только свой скоуп.
        $ctx_sys      = context_system::instance();
        $is_full_admin = has_capability('local/unics:manage', $ctx_sys);
        $my_scope      = $is_full_admin
            ? ['region_id' => null, 'district_id' => null, 'organization_id' => null]
            : scope_checker::get_user_scope((int)$USER->id);

        // --- Основные данные ---
        $mform->addElement('header', 'basic', 'Основные данные');

        $mform->addElement('text', 'lastname', get_string('lastname', 'local_unics'));
        $mform->setType('lastname', PARAM_TEXT);
        $mform->addRule('lastname', null, 'required');

        $mform->addElement('text', 'firstname', get_string('firstname', 'local_unics'));
        $mform->setType('firstname', PARAM_TEXT);
        $mform->addRule('firstname', null, 'required');

        $mform->addElement('text', 'middlename', get_string('middlename', 'local_unics'));
        $mform->setType('middlename', PARAM_TEXT);

        $mform->addElement('text', 'email', get_string('email', 'local_unics'));
        $mform->setType('email', PARAM_EMAIL);
        $mform->addRule('email', null, 'required');

        $mform->addElement('text', 'username', get_string('username', 'local_unics'));
        $mform->setType('username', PARAM_USERNAME);
        $mform->addRule('username', null, 'required');

        $mform->addElement('passwordunmask', 'password', get_string('password', 'local_unics'));
        $mform->setType('password', PARAM_RAW);
        $mform->addRule('password', null, 'required');

        // --- Организация и роль ---
        $mform->addElement('header', 'org_role', 'Организация и роль');

        // Список организаций — фильтруем по скоупу, если это не системный админ.
        if ($is_full_admin) {
            $orgs = unics_user_manager::get_organizations_menu();
        } else {
            [$org_where, $org_params] = scope_checker::org_filter_sql((int)$USER->id, 'o');
            $orgs_rows = $DB->get_records_sql(
                "SELECT o.id, o.name FROM {unics_organizations} o
                  WHERE o.is_active = 1 AND ({$org_where})
                  ORDER BY o.name", $org_params);
            $orgs = [];
            foreach ($orgs_rows as $o) { $orgs[$o->id] = $o->name; }
        }
        $mform->addElement('select', 'organization_id', get_string('organization', 'local_unics'),
            ['' => get_string('select_org', 'local_unics')] + $orgs);
        // 'required' для организации проверяем в validation() — для региональн. админа (роль 1)
        // организация не нужна, нужен регион ИЛИ район.

        // Если у текущего пользователя скоуп = одна организация, предзаполняем и фиксируем выбор.
        if (!$is_full_admin && $my_scope['organization_id'] !== null) {
            $mform->setDefault('organization_id', $my_scope['organization_id']);
            $mform->freeze('organization_id');
        }

        // Целевая ролевая модель [[role-model-rework-2026-05-23]]:
        // 1 региональн. админ, 2 районный админ, 9 районный методист,
        // 4 методист орг., 5 педагог создающий курсы, 6 педагог (non-editing),
        // 7 учащийся, 8 родитель. Код 3 (org_admin) из селекта убран (legacy-маппинг
        // сохранён в user_manager для старых записей).
        // Список ограничен правами создателя: нельзя создать роль своего уровня или выше
        // (методист не видит админских ролей и т.д.) — см. local_unics_creatable_roles().
        $all_roles = [
            '1' => get_string('role_region_admin', 'local_unics'),
            '2' => get_string('role_district_admin', 'local_unics'),
            '9' => get_string('role_district_methodist', 'local_unics'),
            '4' => get_string('role_methodist', 'local_unics'),
            '5' => get_string('role_editingteacher', 'local_unics'),
            '6' => get_string('role_teacher', 'local_unics'),
            '7' => get_string('role_student', 'local_unics'),
            '8' => get_string('role_parent', 'local_unics'),
        ];
        $allowed_codes = local_unics_creatable_roles((int)$USER->id);
        $roles = ['' => get_string('select_role', 'local_unics')];
        foreach ($all_roles as $code => $label) {
            if (in_array((int)$code, $allowed_codes, true)) {
                $roles[$code] = $label;
            }
        }
        $mform->addElement('select', 'unics_role', get_string('unics_role', 'local_unics'), $roles);
        $mform->addRule('unics_role', null, 'required');

        // Организация не нужна управленческим/районным ролям (скоуп регион/район):
        // регион. админ (1), районный админ (2), районный методист (9). Также не нужна
        // родителю (8) — его скоуп выводится через ребёнка (unics_parent_student),
        // см. scope_checker::get_user_organization (наблюдение #4, 2026-05-28).
        $mform->hideIf('organization_id', 'unics_role', 'eq', '1');
        $mform->hideIf('organization_id', 'unics_role', 'eq', '2');
        $mform->hideIf('organization_id', 'unics_role', 'eq', '9');
        $mform->hideIf('organization_id', 'unics_role', 'eq', '8');

        // --- Скоуп регионального админа (видим только для роли 1) ---
        // Один из двух полей должен быть заполнен (проверка в validation()).
        // Скоуп фильтруется: регион-админ региона X видит только свой регион
        // и районы своего региона; регион-админ района Y — только свой район.
        if ($is_full_admin) {
            $regions_raw   = $DB->get_records('unics_regions',   ['is_active' => 1], 'name ASC', 'id, name');
            $districts_raw = $DB->get_records('unics_districts', null,               'name ASC', 'id, name');
        } else if ($my_scope['region_id'] !== null) {
            $regions_raw   = $DB->get_records('unics_regions',   ['id' => $my_scope['region_id']],          'name ASC', 'id, name');
            $districts_raw = $DB->get_records('unics_districts', ['region_id' => $my_scope['region_id']],   'name ASC', 'id, name');
        } else if ($my_scope['district_id'] !== null) {
            $regions_raw   = [];
            $districts_raw = $DB->get_records('unics_districts', ['id' => $my_scope['district_id']], 'name ASC', 'id, name');
        } else {
            $regions_raw   = [];
            $districts_raw = [];
        }

        $regions_menu   = ['' => '- не выбран -'];
        foreach ($regions_raw   as $r) { $regions_menu[$r->id]   = $r->name; }
        $districts_menu = ['' => '- не выбран -'];
        foreach ($districts_raw as $d) { $districts_menu[$d->id] = $d->name; }

        // Регион — территория ТОЛЬКО регионального администратора (роль 1).
        $mform->addElement('select', 'region_id',
            'Регион (для регионального администратора)', $regions_menu);
        $mform->setType('region_id', PARAM_INT);
        $mform->hideIf('region_id', 'unics_role', 'neq', '1');

        // Район — территория районного администратора (2) и районного методиста (9).
        // hideIf OR-комбинируется, поэтому скрываем для всех ролей, кроме 2 и 9.
        $mform->addElement('select', 'district_id',
            'Муниципалитет (для муниципального администратора / методиста)', $districts_menu);
        $mform->setType('district_id', PARAM_INT);
        foreach (['', '1', '4', '5', '6', '7', '8'] as $r) {
            $mform->hideIf('district_id', 'unics_role', 'eq', $r);
        }

        // --- Поля учащегося (показываются только если роль = 7) ---
        $mform->addElement('header', 'student_data', 'Данные учащегося');

        // Категория учащегося — единый плоский список (#4, 2026-05-24): 6 пунктов
        // «ОВЗ N категории» + «Одарённый». Категории «семейное обучение» / «длительное
        // лечение» из выбора убраны (старые данные сохраняются и отображаются).
        // Можно отметить несколько. Ничего не отмечено = обычный учащийся.
        // Лейбл колонки показываем только у первого чекбокса.
        $mform->addElement('advcheckbox', 'ovz_1', get_string('student_category', 'local_unics'),
            get_string('ovz_blind', 'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'ovz_2', '',
            get_string('ovz_deaf',  'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'ovz_3', '',
            get_string('ovz_motor', 'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'ovz_4', '',
            get_string('ovz_zpd',   'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'ovz_5', '',
            get_string('ovz_ras',   'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'ovz_6', '',
            get_string('ovz_other', 'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'cat_4', '',
            get_string('category_gifted', 'local_unics'), null, [0, 1]);
        $mform->addElement('static', 'cat_hint', '',
            '<span class="text-muted small">Ничего не отмечено = обычный учащийся.</span>');

        // Уровень подготовки при создании не выбирается — всегда «средний» (2),
        // дальше его подстраивает адаптивный движок. См. user_manager::create_user.
        foreach (['ovz_1','ovz_2','ovz_3','ovz_4','ovz_5','ovz_6','cat_4','cat_hint'] as $el) {
            $mform->hideIf($el, 'unics_role', 'neq', '7');
        }

        $classes = array_combine(range(1, 11), range(1, 11));
        $mform->addElement('select', 'class_number', get_string('class_number', 'local_unics'), $classes);

        $letters = ['' => '- без буквы -', 'А' => 'А', 'Б' => 'Б', 'В' => 'В',
                    'Г' => 'Г', 'Д' => 'Д', 'Е' => 'Е', 'Ж' => 'Ж'];
        $mform->addElement('select', 'class_letter', get_string('class_letter', 'local_unics'), $letters);
        $mform->setType('class_letter', PARAM_TEXT);
        $mform->hideIf('class_letter', 'unics_role', 'neq', '7');

        $mform->addElement('textarea', 'special_needs', get_string('special_needs', 'local_unics'),
            ['rows' => 3, 'cols' => 50]);
        $mform->setType('special_needs', PARAM_TEXT);

        // Показывать блок учащегося только если выбрана роль 7
        $mform->hideIf('student_data', 'unics_role', 'neq', '7');
        $mform->hideIf('class_number', 'unics_role', 'neq', '7');
        $mform->hideIf('special_needs', 'unics_role', 'neq', '7');

        // --- Поля педагога (показываются для ролей 4 методист, 9 районный методист,
        //     5 педагог создающий курсы, 6 педагог non-editing) ---
        $mform->addElement('header', 'teacher_data', 'Данные педагога');

        // Предметы = категории курсов Moodle (верхний уровень дерева). Множественный
        // выбор; список берётся из реальных категорий, поэтому новые предметы
        // подхватываются автоматически при добавлении категории. См. [[subject-binding-design]].
        $subject_options = [];
        foreach ($DB->get_records('course_categories', ['parent' => 0, 'visible' => 1],
                'sortorder ASC', 'id, name') as $cat) {
            $subject_options[(int)$cat->id] = format_string($cat->name);
        }
        $mform->addElement('autocomplete', 'subject_categories',
            get_string('subject_categories', 'local_unics'), $subject_options,
            ['multiple' => true]);
        $mform->addHelpButton('subject_categories', 'subject_categories', 'local_unics');

        $mform->addElement('text', 'qualification', get_string('qualification', 'local_unics'));
        $mform->setType('qualification', PARAM_TEXT);

        // Диапазон классов — мягкий фильтр (можно не указывать).
        $grade_menu = ['' => get_string('grade_any', 'local_unics')];
        for ($g = 1; $g <= 11; $g++) {
            $grade_menu[$g] = $g;
        }
        $grade_grp = [
            $mform->createElement('select', 'grade_from', '', $grade_menu),
            $mform->createElement('select', 'grade_to',   '', $grade_menu),
        ];
        $mform->addGroup($grade_grp, 'grade_range_grp',
            get_string('grade_range', 'local_unics'), ' - ', false);
        $mform->setType('grade_from', PARAM_INT);
        $mform->setType('grade_to', PARAM_INT);
        $mform->addHelpButton('grade_range_grp', 'grade_range', 'local_unics');

        // Показывать блок педагога для ролей 4 (методист орг.), 9 (районный методист),
        // 5 (педагог создающий курсы), 6 (педагог non-editing).
        // Скрыть для пустой, 1, 2 (админы), 3 (legacy), 7, 8.
        foreach (['teacher_data', 'subject_categories', 'qualification', 'grade_range_grp'] as $el) {
            $mform->hideIf($el, 'unics_role', 'eq', '');
            $mform->hideIf($el, 'unics_role', 'eq', '2');
            $mform->hideIf($el, 'unics_role', 'eq', '3');
            $mform->hideIf($el, 'unics_role', 'eq', '7');
            $mform->hideIf($el, 'unics_role', 'eq', '8');
        }

        // --- Привязки (NEW-4 + #3 от 2026-05-30) ---
        // При создании педагога (5/6) — multi-чекбоксы учащихся той же орг с фильтрами
        // класс/буква. При создании учащегося (7) — multi-чекбоксы педагогов и родителей.
        // У родителя (8) — оставляем single child (по решению #3). Списки ограничены
        // зоной создателя; JS дополнительно фильтрует по выбранной #id_organization_id.
        if ($is_full_admin) {
            $students_pool = $DB->get_records_sql(
                "SELECT s.id, u.lastname, u.firstname, s.class_number, s.class_letter,
                        s.organization_id
                   FROM {unics_students} s
                   JOIN {user} u ON u.id = s.mdl_user_id
                  WHERE u.deleted = 0 AND s.archived_at IS NULL AND s.graduated_at IS NULL
                  ORDER BY u.lastname, u.firstname");
            $teachers_pool = $DB->get_records_sql(
                "SELECT t.id, u.lastname, u.firstname, t.organization_id, uo.unics_role
                   FROM {unics_teachers} t
                   JOIN {user} u ON u.id = t.mdl_user_id
                   JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                  WHERE u.deleted = 0 AND uo.unics_role IN (4, 5, 6)
                  ORDER BY u.lastname, u.firstname");
            $parents_pool = $DB->get_records_sql(
                "SELECT u.id, u.lastname, u.firstname, uo.organization_id
                   FROM {user} u
                   JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                  WHERE u.deleted = 0 AND uo.unics_role = 8
                  ORDER BY u.lastname, u.firstname");
        } else {
            [$pk_where, $pk_params] = scope_checker::org_filter_sql((int)$USER->id, 'o');
            $students_pool = $DB->get_records_sql(
                "SELECT s.id, u.lastname, u.firstname, s.class_number, s.class_letter,
                        s.organization_id
                   FROM {unics_students} s
                   JOIN {user} u ON u.id = s.mdl_user_id
                   JOIN {unics_organizations} o ON o.id = s.organization_id
                  WHERE u.deleted = 0 AND s.archived_at IS NULL AND s.graduated_at IS NULL
                    AND ({$pk_where})
                  ORDER BY u.lastname, u.firstname", $pk_params);
            $teachers_pool = $DB->get_records_sql(
                "SELECT t.id, u.lastname, u.firstname, t.organization_id, uo.unics_role
                   FROM {unics_teachers} t
                   JOIN {user} u ON u.id = t.mdl_user_id
                   JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                   JOIN {unics_organizations} o ON o.id = t.organization_id
                  WHERE u.deleted = 0 AND uo.unics_role IN (4, 5, 6)
                    AND ({$pk_where})
                  ORDER BY u.lastname, u.firstname", $pk_params);
            $parents_pool = $DB->get_records_sql(
                "SELECT u.id, u.lastname, u.firstname, uo.organization_id
                   FROM {user} u
                   JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                   JOIN {unics_organizations} o ON o.id = uo.organization_id
                  WHERE u.deleted = 0 AND uo.unics_role = 8
                    AND ({$pk_where})
                  ORDER BY u.lastname, u.firstname", $pk_params);
        }

        // Сохранение отметок между submit'ами при ошибках валидации.
        $sel_st = array_flip(array_map('intval',
            optional_param_array('assign_student_ids', [], PARAM_INT)));
        $sel_tc = array_flip(array_map('intval',
            optional_param_array('assign_teacher_ids', [], PARAM_INT)));
        $sel_pa = array_flip(array_map('intval',
            optional_param_array('assign_parent_ids',  [], PARAM_INT)));

        $mform->addElement('header', 'link_data', 'Привязки');

        // --- Учащиеся (для педагога, роли 5/6) ---
        $st_html  = '<div class="unics-pick" data-pick="student">';
        $st_html .= '<div class="unics-pick-filters mb-2">';
        $st_html .= '<label class="mr-2">Класс: <select class="pick-cls form-control form-control-sm d-inline-block w-auto">';
        $st_html .= '<option value="">все</option>';
        for ($i = 1; $i <= 11; $i++) {
            $st_html .= '<option value="' . $i . '">' . $i . '</option>';
        }
        $st_html .= '</select></label> ';
        $st_html .= '<label class="mr-2">Буква: <select class="pick-let form-control form-control-sm d-inline-block w-auto">';
        $st_html .= '<option value="">все</option>';
        foreach (['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж'] as $L) {
            $st_html .= '<option value="' . $L . '">' . $L . '</option>';
        }
        $st_html .= '</select></label> ';
        $st_html .= '<a href="#" class="pick-all">Выбрать видимых</a> ';
        $st_html .= '<a href="#" class="pick-none">Снять все</a>';
        $st_html .= '</div>';
        $st_html .= '<div class="unics-pick-list border rounded p-2" '
            . 'style="max-height:280px;overflow-y:auto;background:#fff">';
        if (empty($students_pool)) {
            $st_html .= '<div class="text-muted">Нет доступных учащихся.</div>';
        } else {
            foreach ($students_pool as $s) {
                $checked = isset($sel_st[(int)$s->id]) ? ' checked' : '';
                $cls = (int)($s->class_number ?? 0);
                $let = (string)($s->class_letter ?? '');
                $label = htmlspecialchars(trim("{$s->lastname} {$s->firstname}"))
                    . ($cls ? ' — ' . $cls . htmlspecialchars($let) . ' кл.' : '');
                $st_html .= '<div class="form-check unics-pick-row" '
                    . 'data-org="' . (int)$s->organization_id . '" '
                    . 'data-cls="' . $cls . '" '
                    . 'data-let="' . htmlspecialchars($let) . '">';
                $st_html .= '<input type="checkbox" class="form-check-input unics-pick-cb" '
                    . 'name="assign_student_ids[]" value="' . (int)$s->id . '" '
                    . 'id="pick_s_' . (int)$s->id . '"' . $checked . '>';
                $st_html .= '<label class="form-check-label" for="pick_s_' . (int)$s->id . '">'
                    . $label . '</label>';
                $st_html .= '</div>';
            }
        }
        $st_html .= '</div></div>';

        $mform->addElement('static', 'link_students', 'Учащиеся педагога', $st_html);
        foreach (['', '1', '2', '3', '4', '7', '8', '9'] as $r) {
            $mform->hideIf('link_students', 'unics_role', 'eq', $r);
        }

        // --- Педагоги (для учащегося, роль 7) ---
        $tc_html  = '<div class="unics-pick" data-pick="teacher">';
        $tc_html .= '<div class="unics-pick-filters mb-2">';
        $tc_html .= '<a href="#" class="pick-all">Выбрать видимых</a> ';
        $tc_html .= '<a href="#" class="pick-none">Снять все</a>';
        $tc_html .= '</div>';
        $tc_html .= '<div class="unics-pick-list border rounded p-2" '
            . 'style="max-height:280px;overflow-y:auto;background:#fff">';
        if (empty($teachers_pool)) {
            $tc_html .= '<div class="text-muted">Нет доступных педагогов.</div>';
        } else {
            $role_short = [4 => 'методист', 5 => 'педагог', 6 => 'педагог'];
            foreach ($teachers_pool as $t) {
                $checked = isset($sel_tc[(int)$t->id]) ? ' checked' : '';
                $r = $role_short[(int)$t->unics_role] ?? 'педагог';
                $label = htmlspecialchars(trim("{$t->lastname} {$t->firstname}")) . ' (' . $r . ')';
                $tc_html .= '<div class="form-check unics-pick-row" '
                    . 'data-org="' . (int)$t->organization_id . '">';
                $tc_html .= '<input type="checkbox" class="form-check-input unics-pick-cb" '
                    . 'name="assign_teacher_ids[]" value="' . (int)$t->id . '" '
                    . 'id="pick_t_' . (int)$t->id . '"' . $checked . '>';
                $tc_html .= '<label class="form-check-label" for="pick_t_' . (int)$t->id . '">'
                    . $label . '</label>';
                $tc_html .= '</div>';
            }
        }
        $tc_html .= '</div></div>';

        $mform->addElement('static', 'link_teachers', 'Педагоги учащегося', $tc_html);
        foreach (['', '1', '2', '3', '4', '5', '6', '8', '9'] as $r) {
            $mform->hideIf('link_teachers', 'unics_role', 'eq', $r);
        }

        // --- Родители (для учащегося, роль 7) ---
        $pa_html  = '<div class="unics-pick" data-pick="parent">';
        $pa_html .= '<div class="unics-pick-filters mb-2">';
        $pa_html .= '<a href="#" class="pick-all">Выбрать видимых</a> ';
        $pa_html .= '<a href="#" class="pick-none">Снять все</a>';
        $pa_html .= '</div>';
        $pa_html .= '<div class="unics-pick-list border rounded p-2" '
            . 'style="max-height:280px;overflow-y:auto;background:#fff">';
        if (empty($parents_pool)) {
            $pa_html .= '<div class="text-muted">Нет доступных родителей.</div>';
        } else {
            foreach ($parents_pool as $p) {
                $checked = isset($sel_pa[(int)$p->id]) ? ' checked' : '';
                $label = htmlspecialchars(trim("{$p->lastname} {$p->firstname}"));
                $pa_html .= '<div class="form-check unics-pick-row" '
                    . 'data-org="' . (int)$p->organization_id . '">';
                $pa_html .= '<input type="checkbox" class="form-check-input unics-pick-cb" '
                    . 'name="assign_parent_ids[]" value="' . (int)$p->id . '" '
                    . 'id="pick_p_' . (int)$p->id . '"' . $checked . '>';
                $pa_html .= '<label class="form-check-label" for="pick_p_' . (int)$p->id . '">'
                    . $label . '</label>';
                $pa_html .= '</div>';
            }
        }
        $pa_html .= '</div></div>';

        $mform->addElement('static', 'link_parents', 'Родители учащегося', $pa_html);
        foreach (['', '1', '2', '3', '4', '5', '6', '8', '9'] as $r) {
            $mform->hideIf('link_parents', 'unics_role', 'eq', $r);
        }

        // --- Ребёнок (для родителя, роль 8) — оставляем single по решению #3 ---
        $student_menu = ['' => '— не привязывать (можно позже через «Назначения») —'];
        foreach (unics_user_manager::get_students(0) as $s) {
            $cls = $s->class_number ? ', ' . $s->class_number . ($s->class_letter ?? '') . ' кл.' : '';
            $student_menu[$s->student_id] = trim($s->lastname . ' ' . $s->firstname) . $cls;
        }
        $mform->addElement('select', 'assign_student_id', 'Ребёнок (учащийся)', $student_menu);
        $mform->setType('assign_student_id', PARAM_INT);
        $mform->hideIf('assign_student_id', 'unics_role', 'neq', '8');

        // Скрываем заголовок «Привязки» для ролей без связей.
        foreach (['', '1', '2', '3', '4', '9'] as $r) {
            $mform->hideIf('link_data', 'unics_role', 'eq', $r);
        }

        // JS: фильтрация чекбоксов по выбранной организации + локальным фильтрам.
        $js = <<<'JS'
<script>
(function() {
    function getOrg() {
        var el = document.getElementById('id_organization_id');
        return el ? el.value : '';
    }
    function applyAll() {
        var org = getOrg();
        document.querySelectorAll('.unics-pick').forEach(function(block) {
            var clsSel = block.querySelector('.pick-cls');
            var letSel = block.querySelector('.pick-let');
            var cls = clsSel ? clsSel.value : '';
            var let_ = letSel ? letSel.value : '';
            block.querySelectorAll('.unics-pick-row').forEach(function(row) {
                var rOrg = row.getAttribute('data-org') || '';
                var rCls = row.getAttribute('data-cls') || '';
                var rLet = row.getAttribute('data-let') || '';
                var matchOrg = !org || rOrg === org;
                var matchCls = !cls || rCls === cls;
                var matchLet = !let_ || rLet === let_;
                row.style.display = (matchOrg && matchCls && matchLet) ? '' : 'none';
            });
        });
    }
    function init() {
        var org = document.getElementById('id_organization_id');
        if (org) org.addEventListener('change', applyAll);
        document.querySelectorAll('.unics-pick .pick-cls, .unics-pick .pick-let').forEach(function(el) {
            el.addEventListener('change', applyAll);
        });
        document.querySelectorAll('.unics-pick .pick-all').forEach(function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                this.closest('.unics-pick').querySelectorAll('.unics-pick-row').forEach(function(row) {
                    if (row.style.display !== 'none') {
                        var cb = row.querySelector('.unics-pick-cb');
                        if (cb) cb.checked = true;
                    }
                });
            });
        });
        document.querySelectorAll('.unics-pick .pick-none').forEach(function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                this.closest('.unics-pick').querySelectorAll('.unics-pick-cb').forEach(function(cb) {
                    cb.checked = false;
                });
            });
        });
        applyAll();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
JS;
        $mform->addElement('html', $js);

        // Блок данных учащегося — не для регион. админа.
        $mform->hideIf('student_data', 'unics_role', 'eq', '1');

        // Блок данных педагога — не для регион. админа.
        foreach (['teacher_data', 'subject_categories', 'qualification', 'grade_range_grp'] as $el) {
            $mform->hideIf($el, 'unics_role', 'eq', '1');
        }

        $this->add_action_buttons(true, get_string('create_user', 'local_unics'));
    }

    /**
     * Сворачивает плоский чеклист в CSV-строки `student_category` и `ovz_type`.
     * Любая отметка «ОВЗ N категории» → категория 1 (ОВЗ) + вид ОВЗ N.
     * «Одарённый» (cat_4) → категория 4. Ничего не отмечено → пусто (обычный).
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }

        $ovz = [];
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            if (!empty($data->{'ovz_' . $i})) {
                $ovz[] = $i;
            }
        }

        $cats = [];
        if (!empty($ovz)) {
            $cats[] = 1; // отмечен хотя бы один вид ОВЗ → категория ОВЗ
        }
        if (!empty($data->cat_4)) {
            $cats[] = 4; // одарённый
        }

        $data->student_category = \local_unics\student_helper::to_csv($cats);
        $data->ovz_type = !empty($ovz) ? \local_unics\student_helper::to_csv($ovz) : '';

        return $data;
    }

    /**
     * Единая валидация формы:
     *  - уникальность username и email;
     *  - скоуп для регион. админа (роль 1) — нужен регион или район;
     *  - организация для прочих ролей;
     *  - хотя бы одна категория для учащегося (роль 7).
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        $role   = (int)($data['unics_role'] ?? 0);

        if (!empty($data['username']) && $DB->record_exists('user', ['username' => $data['username']])) {
            $errors['username'] = 'Пользователь с таким логином уже существует';
        }

        if (!empty($data['email']) && $DB->record_exists('user', ['email' => $data['email']])) {
            $errors['email'] = 'Пользователь с таким email уже существует';
        }

        // Территория зависит от роли:
        //   1 (региональн. админ) — регион;
        //   2 (районный админ), 9 (районный методист) — район;
        //   8 (родитель) — выводится через ребёнка, поля не требуем;
        //   остальные (4,5,6,7) — организация.
        if ($role === 1) {
            if (empty($data['region_id'])) {
                $errors['region_id'] = 'Укажите регион для регионального администратора';
            }
        } else if ($role === 2 || $role === 9) {
            if (empty($data['district_id'])) {
                $errors['district_id'] = 'Укажите муниципалитет для этой роли';
            }
        } else if ($role !== 8 && empty($data['organization_id'])) {
            $errors['organization_id'] = get_string('required');
        }

        // Категория учащегося больше не обязательна: пустой выбор = обычный учащийся.

        // Диапазон классов педагога (роли 4/9/5/6): нижняя граница ≤ верхней.
        if (in_array($role, [4, 9, 5, 6], true)
                && !empty($data['grade_from']) && !empty($data['grade_to'])
                && (int)$data['grade_from'] > (int)$data['grade_to']) {
            $errors['grade_range_grp'] = get_string('err_grade_range', 'local_unics');
        }

        return $errors;
    }
}
