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
                    'class_letter'     => !empty($data['class_letter']) ? $data['class_letter'] : null,
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

        // 5. Устанавливаем кастомное поле профиля unics_level (для учащихся)
        if ((int)$data['unics_role'] === 7) {
            self::set_student_level($mdl_user_id, (int)$data['difficulty_level']);
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
                       o.name AS org_name,
                       s.class_number, s.class_letter
                FROM {user} u
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                JOIN {unics_organizations} o ON o.id = uo.organization_id
                LEFT JOIN {unics_students} s ON s.mdl_user_id = u.id
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
                           s.id AS student_id, s.category, s.difficulty_level,
                           s.class_number, s.class_letter
                    FROM {user} u
                    JOIN {unics_students} s ON s.mdl_user_id = u.id
                    WHERE s.organization_id = :org_id AND u.deleted = 0
                    ORDER BY u.lastname, u.firstname";
            return $DB->get_records_sql($sql, ['org_id' => $org_id]);
        }

        $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                       s.id AS student_id, s.category, s.difficulty_level,
                       s.class_number, s.class_letter
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

        // Роли 4 (методист), 5 (педагог), 6 (тьютор) — все имеют запись в unics_teachers
        if ($org_id > 0) {
            $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                           t.id AS teacher_id, t.subjects, uo.unics_role
                    FROM {user} u
                    JOIN {unics_teachers} t ON t.mdl_user_id = u.id
                    JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                    WHERE t.organization_id = :org_id AND u.deleted = 0
                      AND uo.unics_role IN (4, 5, 6)
                    ORDER BY u.lastname, u.firstname";
            return $DB->get_records_sql($sql, ['org_id' => $org_id]);
        }

        $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                       t.id AS teacher_id, t.subjects, uo.unics_role
                FROM {user} u
                JOIN {unics_teachers} t ON t.mdl_user_id = u.id
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                WHERE u.deleted = 0 AND uo.unics_role IN (4, 5, 6)
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

        // Автоматически добавить педагога и учащегося в контакты Moodle Messaging
        try {
            require_once(dirname(__FILE__) . '/social_manager.php');
            $teacher_rec = $DB->get_record('unics_teachers', ['id' => $teacher_id], 'mdl_user_id');
            $student_rec = $DB->get_record('unics_students', ['id' => $student_id], 'mdl_user_id');
            if ($teacher_rec && $student_rec) {
                \local_unics\social_manager::sync_on_teacher_assign(
                    (int)$teacher_rec->mdl_user_id,
                    (int)$student_rec->mdl_user_id,
                    $student_id
                );
            }
        } catch (\Throwable $e) {
            // Нефатально
        }

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
     * Обновить основные данные пользователя Moodle и расширенный профиль УНИКС.
     */
    public static function update_user(int $mdl_user_id, array $data): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $user = $DB->get_record('user', ['id' => $mdl_user_id], '*', MUST_EXIST);
        foreach (['firstname', 'lastname', 'middlename', 'email'] as $f) {
            if (isset($data[$f])) {
                $user->$f = $data[$f];
            }
        }
        user_update_user($user, false, false);

        // Обновить расширенный профиль учащегося
        $student = $DB->get_record('unics_students', ['mdl_user_id' => $mdl_user_id]);
        if ($student) {
            $student->category         = $data['student_category'] ?? $student->category;
            $student->ovz_type         = ((int)($data['student_category'] ?? $student->category) === 1 && !empty($data['ovz_type']))
                                           ? (int)$data['ovz_type'] : null;
            $student->difficulty_level = $data['difficulty_level'] ?? $student->difficulty_level;
            $student->class_number     = $data['class_number'] ?? $student->class_number;
            $student->class_letter     = $data['class_letter'] ?? $student->class_letter;
            $student->special_needs    = $data['special_needs'] ?? $student->special_needs;
            $DB->update_record('unics_students', $student);
            self::set_student_level($mdl_user_id, (int)$student->difficulty_level);
        }

        // Обновить расширенный профиль педагога/тьютора/методиста
        $teacher = $DB->get_record('unics_teachers', ['mdl_user_id' => $mdl_user_id]);
        if ($teacher) {
            $teacher->subjects       = $data['subjects'] ?? $teacher->subjects;
            $teacher->qualification  = $data['qualification'] ?? $teacher->qualification;
            $DB->update_record('unics_teachers', $teacher);
        }
    }

    /**
     * Деактивировать пользователя (soft-delete через mdl_user.suspended).
     */
    public static function suspend_user(int $mdl_user_id): void {
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $mdl_user_id]);
    }

    /**
     * Получить полный профиль пользователя для формы редактирования.
     */
    public static function get_user_profile(int $mdl_user_id): ?object {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.middlename, u.email, u.username,
                       uo.unics_role, uo.organization_id,
                       s.id AS student_id, s.category AS student_category, s.ovz_type,
                       s.difficulty_level, s.class_number, s.class_letter, s.special_needs,
                       t.id AS teacher_id, t.subjects, t.qualification
                FROM {user} u
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                LEFT JOIN {unics_students} s ON s.mdl_user_id = u.id
                LEFT JOIN {unics_teachers} t ON t.mdl_user_id = u.id
                WHERE u.id = :uid AND u.deleted = 0";
        return $DB->get_record_sql($sql, ['uid' => $mdl_user_id]) ?: null;
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
     * Публичный метод — установить/обновить уровень сложности учащегося в профиле Moodle.
     * Вызывается при создании учащегося и при изменении его difficulty_level.
     */
    public static function set_student_level(int $mdl_user_id, int $level): void {
        global $DB;
        $field_id = self::ensure_profile_field();
        $existing = $DB->get_record('user_info_data', ['userid' => $mdl_user_id, 'fieldid' => $field_id]);
        if ($existing) {
            $DB->set_field('user_info_data', 'data', (string)$level, ['id' => $existing->id]);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid'      => $mdl_user_id,
                'fieldid'     => $field_id,
                'data'        => (string)$level,
                'dataformat'  => FORMAT_PLAIN,
            ]);
        }
    }

    /**
     * Создаёт кастомное поле профиля unics_level если не существует.
     * Возвращает id поля.
     */
    public static function ensure_profile_field(): int {
        return \local_unics\course_template::ensure_profile_field();
    }
}
