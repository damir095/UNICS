<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../classes/user_manager.php');

class unics_create_user_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

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

        $orgs = unics_user_manager::get_organizations_menu();
        $mform->addElement('select', 'organization_id', get_string('organization', 'local_unics'),
            ['' => get_string('select_org', 'local_unics')] + $orgs);
        $mform->addRule('organization_id', null, 'required');

        $roles = [
            ''  => get_string('select_role', 'local_unics'),
            '3' => get_string('role_org_admin', 'local_unics'),
            '4' => get_string('role_methodist', 'local_unics'),
            '5' => get_string('role_teacher', 'local_unics'),
            '6' => get_string('role_tutor', 'local_unics'),
            '7' => get_string('role_student', 'local_unics'),
            '8' => get_string('role_parent', 'local_unics'),
        ];
        $mform->addElement('select', 'unics_role', get_string('unics_role', 'local_unics'), $roles);
        $mform->addRule('unics_role', null, 'required');

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

        // --- Поля педагога (показываются для ролей 4, 5, 6) ---
        $mform->addElement('header', 'teacher_data', 'Данные педагога');

        $mform->addElement('text', 'subjects', get_string('subjects', 'local_unics'));
        $mform->setType('subjects', PARAM_TEXT);

        $mform->addElement('text', 'qualification', get_string('qualification', 'local_unics'));
        $mform->setType('qualification', PARAM_TEXT);

        // Показывать блок педагога для ролей 4, 5, 6
        foreach (['teacher_data', 'subjects', 'qualification'] as $el) {
            $mform->hideIf($el, 'unics_role', 'eq', '');
            $mform->hideIf($el, 'unics_role', 'eq', '3');
            $mform->hideIf($el, 'unics_role', 'eq', '7');
            $mform->hideIf($el, 'unics_role', 'eq', '8');
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

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (!empty($data['username']) && $DB->record_exists('user', ['username' => $data['username']])) {
            $errors['username'] = 'Пользователь с таким логином уже существует';
        }

        if (!empty($data['email']) && $DB->record_exists('user', ['email' => $data['email']])) {
            $errors['email'] = 'Пользователь с таким email уже существует';
        }

        // Для учащегося - должна быть выбрана хотя бы одна категория.
        if ((int)($data['unics_role'] ?? 0) === 7) {
            $any = false;
            foreach (['cat_1','cat_2','cat_3','cat_4'] as $k) {
                if (!empty($data[$k])) { $any = true; break; }
            }
            if (!$any) {
                // Ошибка крепится к первому чекбоксу - он несёт лейбл колонки «Категория учащегося».
                $errors['cat_1'] = 'Выберите хотя бы одну категорию учащегося';
            }
        }

        return $errors;
    }
}
