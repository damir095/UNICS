<?php
defined('MOODLE_INTERNAL') || die();

class unics_user_manager {

    /**
     * Создать пользователя Moodle + записи в unics_* таблицах
     */
    public static function create_user(array $data): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        // 1. Создаём пользователя в mdl_user
        $user = new stdClass();
        $user->firstname   = $data['firstname'];
        $user->lastname    = $data['lastname'];
        $user->middlename  = $data['middlename'] ?? '';
        $user->email       = $data['email'];
        $user->username    = $data['username'];
        $user->password    = $data['password'];
        $user->auth        = 'manual';
        $user->confirmed   = 1;
        $user->mnethostid  = 1;
        $user->lang        = 'ru';

        $mdl_user_id = user_create_user($user, true, false);

        // 2. Привязываем к организации и роли УНИКС
        $DB->insert_record('unics_user_org', (object)[
            'mdl_user_id'     => $mdl_user_id,
            'organization_id' => $data['organization_id'],
            'unics_role'      => $data['unics_role'],
        ]);

        // 3. Создаём расширение профиля в зависимости от роли
        switch ((int)$data['unics_role']) {
            case 7: // Учащийся
                $DB->insert_record('unics_students', (object)[
                    'mdl_user_id'      => $mdl_user_id,
                    'organization_id'  => $data['organization_id'],
                    'category'         => $data['student_category'],
                    'ovz_type'         => ((int)$data['student_category'] === 1 && !empty($data['ovz_type']))
                                           ? (int)$data['ovz_type'] : null,
                    'difficulty_level' => $data['difficulty_level'],
                    'class_number'     => $data['class_number'] ?? null,
                    'special_needs'    => $data['special_needs'] ?? null,
                ]);
                break;

            case 5: // Педагог
            case 6: // Тьютор
            case 4: // Методист
                $DB->insert_record('unics_teachers', (object)[
                    'mdl_user_id'     => $mdl_user_id,
                    'organization_id' => $data['organization_id'],
                    'subjects'        => $data['subjects'] ?? null,
                    'qualification'   => $data['qualification'] ?? null,
                ]);
                break;
        }

        // 4. Назначаем роль Moodle
        $moodle_role_id = self::get_moodle_role_id($data['unics_role']);
        if ($moodle_role_id) {
            $context = context_system::instance();
            role_assign($moodle_role_id, $mdl_user_id, $context->id);
        }

        // 5. Устанавливаем кастомное поле профиля uniks_level (для учащихся)
        if ((int)$data['unics_role'] === 7) {
            self::set_profile_field($mdl_user_id, 'uniks_level', $data['difficulty_level']);
        }

        return $mdl_user_id;
    }

    /**
     * Получить список пользователей организации с фильтрами
     */
    public static function get_users(int $org_id = 0, int $unics_role = 0): array {
        global $DB;

        $where  = '1=1';
        $params = [];

        if ($org_id > 0) {
            $where   .= ' AND uo.organization_id = :org_id';
            $params['org_id'] = $org_id;
        }
        if ($unics_role > 0) {
            $where   .= ' AND uo.unics_role = :unics_role';
            $params['unics_role'] = $unics_role;
        }

        $sql = "SELECT u.id, u.firstname, u.lastname, u.middlename,
                       u.email, u.username, uo.unics_role, uo.organization_id,
                       o.name AS org_name
                FROM {user} u
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                JOIN {unics_organizations} o ON o.id = uo.organization_id
                WHERE $where AND u.deleted = 0
                ORDER BY u.lastname, u.firstname";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Получить учащихся: все (org_id=0) или конкретной организации
     */
    public static function get_students(int $org_id): array {
        global $DB;

        if ($org_id > 0) {
            $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                           s.id AS student_id, s.category, s.difficulty_level, s.class_number
                    FROM {user} u
                    JOIN {unics_students} s ON s.mdl_user_id = u.id
                    WHERE s.organization_id = :org_id AND u.deleted = 0
                    ORDER BY u.lastname, u.firstname";
            return $DB->get_records_sql($sql, ['org_id' => $org_id]);
        }

        $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                       s.id AS student_id, s.category, s.difficulty_level, s.class_number
                FROM {user} u
                JOIN {unics_students} s ON s.mdl_user_id = u.id
                WHERE u.deleted = 0
                ORDER BY u.lastname, u.firstname";
        return $DB->get_records_sql($sql);
    }

    /**
     * Получить педагогов: все (org_id=0) или конкретной организации
     */
    public static function get_teachers(int $org_id): array {
        global $DB;

        if ($org_id > 0) {
            $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                           t.id AS teacher_id, t.subjects
                    FROM {user} u
                    JOIN {unics_teachers} t ON t.mdl_user_id = u.id
                    JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                    WHERE t.organization_id = :org_id AND u.deleted = 0
                      AND uo.unics_role = 5
                    ORDER BY u.lastname, u.firstname";
            return $DB->get_records_sql($sql, ['org_id' => $org_id]);
        }

        $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                       t.id AS teacher_id, t.subjects
                FROM {user} u
                JOIN {unics_teachers} t ON t.mdl_user_id = u.id
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                WHERE u.deleted = 0 AND uo.unics_role = 5
                ORDER BY u.lastname, u.firstname";
        return $DB->get_records_sql($sql);
    }

    /**
     * Привязать педагога к учащемуся
     */
    public static function assign_teacher_student(int $teacher_id, int $student_id, int $assigned_by): bool {
        global $DB;

        if ($DB->record_exists('unics_teacher_student', ['teacher_id' => $teacher_id, 'student_id' => $student_id])) {
            return false; // уже существует
        }

        $DB->insert_record('unics_teacher_student', (object)[
            'teacher_id'  => $teacher_id,
            'student_id'  => $student_id,
            'assigned_by' => $assigned_by,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Привязать родителя к учащемуся
     */
    public static function assign_parent_student(int $parent_mdl_user_id, int $student_id): bool {
        global $DB;

        if ($DB->record_exists('unics_parent_student', ['parent_mdl_user_id' => $parent_mdl_user_id, 'student_id' => $student_id])) {
            return false;
        }

        $DB->insert_record('unics_parent_student', (object)[
            'parent_mdl_user_id' => $parent_mdl_user_id,
            'student_id'         => $student_id,
        ]);

        return true;
    }

    /**
     * Удалить привязку педагог → учащийся
     */
    public static function remove_teacher_student(int $id): void {
        global $DB;
        $DB->delete_records('unics_teacher_student', ['id' => $id]);
    }

    /**
     * Удалить привязку родитель → учащийся
     */
    public static function remove_parent_student(int $id): void {
        global $DB;
        $DB->delete_records('unics_parent_student', ['id' => $id]);
    }

    /**
     * Получить список организаций для выпадающего списка
     */
    public static function get_organizations_menu(): array {
        global $DB;
        $orgs = $DB->get_records('unics_organizations', ['is_active' => 1], 'name', 'id, name');
        $menu = [];
        foreach ($orgs as $org) {
            $menu[$org->id] = $org->name;
        }
        return $menu;
    }

    /**
     * Соответствие роли УНИКС → id роли Moodle (по shortname)
     */
    private static function get_moodle_role_id(int $unics_role): ?int {
        global $DB;

        $map = [
            3 => 'org_admin',
            4 => 'methodist',
            5 => 'editingteacher',  // Педагог
            6 => 'teacher',         // Тьютор
            7 => 'student',         // Учащийся
            8 => 'parent',
        ];

        if (!isset($map[$unics_role])) {
            return null;
        }

        $role = $DB->get_record('role', ['shortname' => $map[$unics_role]], 'id');
        return $role ? (int)$role->id : null;
    }

    /**
     * Установить кастомное поле профиля пользователя
     */
    private static function set_profile_field(int $user_id, string $shortname, $value): void {
        global $DB;

        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if (!$field) {
            return;
        }

        $existing = $DB->get_record('user_info_data', ['userid' => $user_id, 'fieldid' => $field->id]);
        if ($existing) {
            $existing->data = $value;
            $DB->update_record('user_info_data', $existing);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid'  => $user_id,
                'fieldid' => $field->id,
                'data'    => $value,
            ]);
        }
    }
}
