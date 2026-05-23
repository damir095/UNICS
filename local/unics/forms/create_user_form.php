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
        // регион. админ (1), районный админ (2), районный методист (9).
        $mform->hideIf('organization_id', 'unics_role', 'eq', '1');
        $mform->hideIf('organization_id', 'unics_role', 'eq', '2');
        $mform->hideIf('organization_id', 'unics_role', 'eq', '9');

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

        $regions_menu   = ['' => '— не выбран —'];
        foreach ($regions_raw   as $r) { $regions_menu[$r->id]   = $r->name; }
        $districts_menu = ['' => '— не выбран —'];
        foreach ($districts_raw as $d) { $districts_menu[$d->id] = $d->name; }

        // Регион — территория ТОЛЬКО регионального администратора (роль 1).
        $mform->addElement('select', 'region_id',
            'Регион (для регионального администратора)', $regions_menu);
        $mform->setType('region_id', PARAM_INT);
        $mform->hideIf('region_id', 'unics_role', 'neq', '1');

        // Район — территория районного администратора (2) и районного методиста (9).
        // hideIf OR-комбинируется, поэтому скрываем для всех ролей, кроме 2 и 9.
        $mform->addElement('select', 'district_id',
            'Район (для районного администратора / методиста)', $districts_menu);
        $mform->setType('district_id', PARAM_INT);
        foreach (['', '1', '4', '5', '6', '7', '8'] as $r) {
            $mform->hideIf('district_id', 'unics_role', 'eq', $r);
        }

        // --- Поля учащегося (показываются только если роль = 7) ---
        $mform->addElement('header', 'student_data', 'Данные учащегося');

        // Категории учащегося. Лейбл столбца показываем только у первого чекбокса -
        // последующие выравниваются под ним, что даёт чистую вертикальную колонку.
        $mform->addElement('advcheckbox', 'cat_1', get_string('student_category', 'local_unics'),
            get_string('category_ovz',       'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'cat_2', '',
            get_string('category_family',    'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'cat_3', '',
            get_string('category_treatment', 'local_unics'), null, [0, 1]);
        $mform->addElement('advcheckbox', 'cat_4', '',
            get_string('category_gifted',    'local_unics'), null, [0, 1]);

        // Виды ОВЗ. Видны только если в категориях отмечен «ОВЗ» (cat_1).
        $mform->addElement('advcheckbox', 'ovz_1', get_string('ovz_type', 'local_unics'),
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

        foreach (['ovz_1','ovz_2','ovz_3','ovz_4','ovz_5','ovz_6'] as $el) {
            $mform->hideIf($el, 'cat_1', 'eq', '0');
            $mform->hideIf($el, 'unics_role', 'neq', '7');
        }

        $levels = [
            '1' => get_string('level_weak', 'local_unics'),
            '2' => get_string('level_normal', 'local_unics'),
            '3' => get_string('level_gifted', 'local_unics'),
        ];
        $mform->addElement('select', 'difficulty_level', get_string('difficulty_level', 'local_unics'), $levels);
        $mform->setDefault('difficulty_level', '2');

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
        $mform->hideIf('student_categories', 'unics_role', 'neq', '7');
        $mform->hideIf('difficulty_level', 'unics_role', 'neq', '7');
        $mform->hideIf('class_number', 'unics_role', 'neq', '7');
        $mform->hideIf('special_needs', 'unics_role', 'neq', '7');

        // --- Поля педагога (показываются для ролей 4 методист, 5 педагог) ---
        $mform->addElement('header', 'teacher_data', 'Данные педагога');

        $mform->addElement('text', 'subjects', get_string('subjects', 'local_unics'));
        $mform->setType('subjects', PARAM_TEXT);

        $mform->addElement('text', 'qualification', get_string('qualification', 'local_unics'));
        $mform->setType('qualification', PARAM_TEXT);

        // Показывать блок педагога для ролей 4 (методист орг.), 9 (районный методист),
        // 5 (педагог создающий курсы), 6 (педагог non-editing).
        // Скрыть для пустой, 1, 2 (админы), 3 (legacy), 7, 8.
        foreach (['teacher_data', 'subjects', 'qualification'] as $el) {
            $mform->hideIf($el, 'unics_role', 'eq', '');
            $mform->hideIf($el, 'unics_role', 'eq', '2');
            $mform->hideIf($el, 'unics_role', 'eq', '3');
            $mform->hideIf($el, 'unics_role', 'eq', '7');
            $mform->hideIf($el, 'unics_role', 'eq', '8');
        }

        // --- Привязки (NEW-4): сразу при создании, иначе только через assign.php ---

        // Для учащегося (роль 7) — выбор педагога. Запись в unics_teacher_student.
        $mform->addElement('header', 'link_data', 'Привязка');

        $role_short = [4 => 'методист', 5 => 'педагог', 6 => 'педагог'];
        $teacher_menu = ['' => '— не назначать (можно позже через «Назначения») —'];
        foreach (unics_user_manager::get_teachers(0) as $t) {
            $r = $role_short[(int)$t->unics_role] ?? 'педагог';
            $teacher_menu[$t->teacher_id] = trim($t->lastname . ' ' . $t->firstname) . ' (' . $r . ')';
        }
        $mform->addElement('select', 'assign_teacher_id', 'Педагог учащегося', $teacher_menu);
        $mform->setType('assign_teacher_id', PARAM_INT);
        $mform->hideIf('assign_teacher_id', 'unics_role', 'neq', '7');

        // Для родителя (роль 8) — выбор ребёнка. Запись в unics_parent_student.
        $student_menu = ['' => '— не привязывать (можно позже через «Назначения») —'];
        foreach (unics_user_manager::get_students(0) as $s) {
            $cls = $s->class_number ? ', ' . $s->class_number . ($s->class_letter ?? '') . ' кл.' : '';
            $student_menu[$s->student_id] = trim($s->lastname . ' ' . $s->firstname) . $cls;
        }
        $mform->addElement('select', 'assign_student_id', 'Ребёнок (учащийся)', $student_menu);
        $mform->setType('assign_student_id', PARAM_INT);
        $mform->hideIf('assign_student_id', 'unics_role', 'neq', '8');

        // Весь блок виден только для ролей 7 (учащийся) и 8 (родитель).
        // Скрываем для всех прочих ролей.
        foreach (['', '1', '2', '3', '4', '5', '6', '9'] as $r) {
            $mform->hideIf('link_data', 'unics_role', 'eq', $r);
        }

        // Блок данных учащегося — не для регион. админа.
        $mform->hideIf('student_data', 'unics_role', 'eq', '1');

        // Блок данных педагога — не для регион. админа.
        foreach (['teacher_data', 'subjects', 'qualification'] as $el) {
            $mform->hideIf($el, 'unics_role', 'eq', '1');
        }

        $this->add_action_buttons(true, get_string('create_user', 'local_unics'));
    }

    /**
     * Сворачивает плоские advcheckbox'ы в CSV-строки `student_category` и `ovz_type`.
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }

        $cats = [];
        foreach ([1, 2, 3, 4] as $i) {
            if (!empty($data->{'cat_' . $i})) {
                $cats[] = $i;
            }
        }
        $data->student_category = \local_unics\student_helper::to_csv($cats);

        $ovz = [];
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            if (!empty($data->{'ovz_' . $i})) {
                $ovz[] = $i;
            }
        }
        // Виды ОВЗ имеют смысл только если в категориях отмечен «ОВЗ» (1).
        $data->ovz_type = in_array(1, $cats, true) ? \local_unics\student_helper::to_csv($ovz) : '';

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
        //   остальные (4,5,6,7,8) — организация.
        if ($role === 1) {
            if (empty($data['region_id'])) {
                $errors['region_id'] = 'Укажите регион для регионального администратора';
            }
        } else if ($role === 2 || $role === 9) {
            if (empty($data['district_id'])) {
                $errors['district_id'] = 'Укажите район для этой роли';
            }
        } else if (empty($data['organization_id'])) {
            $errors['organization_id'] = get_string('required');
        }

        if ($role === 7) {
            $any = false;
            foreach (['cat_1','cat_2','cat_3','cat_4'] as $k) {
                if (!empty($data[$k])) { $any = true; break; }
            }
            if (!$any) {
                $errors['cat_1'] = 'Выберите хотя бы одну категорию учащегося';
            }
        }

        return $errors;
    }
}
