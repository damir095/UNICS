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

        // 2. Привязываем к скоупу (регион/район/организация) и роли УНИКС.
        // organization_id уже nullable (с 0.6.29); region_id/district_id — слоты под
        // регионального админа и методиста района (этап 1 #11). При создании передаются
        // те поля, которые соответствуют роли:
        //   роль 1 (region_admin)        — region_id ИЛИ district_id;
        //   роль 4 (methodist), 4 в district scope — district_id;
        //   роль 4 в org scope, остальные — organization_id.
        $DB->insert_record('unics_user_org', (object)[
            'mdl_user_id'     => $mdl_user_id,
            'region_id'       => !empty($data['region_id'])       ? (int)$data['region_id']       : null,
            'district_id'     => !empty($data['district_id'])     ? (int)$data['district_id']     : null,
            'organization_id' => !empty($data['organization_id']) ? (int)$data['organization_id'] : null,
            'unics_role'      => $data['unics_role'],
        ]);

        // 3. Создаём расширение профиля в зависимости от роли
        switch ((int)$data['unics_role']) {
            case 1:  // Региональный администратор (скоуп: регион)
            case 10: // Региональный методист (скоуп: регион) — v3 фаза 2
                    // Управленческие роли уровня региона без расширения профиля;
                    // весь скоуп — в unics_user_org (region_id).
                break;

            case 7: // Учащийся
                // category / ovz_type приходят как CSV-строки "1,3" из формы.
                // Если пришёл int (старый код / API), нормализуем через helper.
                $category_csv = is_array($data['student_category'] ?? null)
                    ? \local_unics\identity\student_helper::to_csv($data['student_category'])
                    : (string)($data['student_category'] ?? '');
                $ovz_csv = is_array($data['ovz_type'] ?? null)
                    ? \local_unics\identity\student_helper::to_csv($data['ovz_type'])
                    : (string)($data['ovz_type'] ?? '');

                // Виды ОВЗ имеют смысл только если в категориях отмечен «ОВЗ» (1).
                $has_ovz_cat = in_array(1, \local_unics\identity\student_helper::parse_csv($category_csv), true);
                if (!$has_ovz_cat) {
                    $ovz_csv = '';
                }

                $new_student_id = $DB->insert_record('unics_students', (object)[
                    'mdl_user_id'      => $mdl_user_id,
                    'organization_id'  => $data['organization_id'],
                    'category'         => $category_csv, // пусто = обычный учащийся
                    'ovz_type'         => $ovz_csv !== '' ? $ovz_csv : null,
                    'difficulty_level' => $data['difficulty_level'] ?? 2, // при создании всегда «средний»
                    'class_number'     => $data['class_number'] ?? null,
                    'class_letter'     => !empty($data['class_letter']) ? $data['class_letter'] : null,
                    'special_needs'    => $data['special_needs'] ?? null,
                ]);
                // Нормализованные таблицы категорий/ОВЗ (этап 2.6, dual-write).
                self::sync_student_taxonomies((int)$new_student_id, $category_csv, $ovz_csv);
                break;

            case 4: // Методист организации (скоуп: организация)
            case 9: // Районный методист (скоуп: район — organization_id будет null)
            case 5: // Педагог, создающий курсы (editingteacher)
            case 6: // Педагог, non-editing (teacher)
                // Предметы = категории курсов (множественный выбор). Структурная
                // привязка — в unics_teacher_subject; имена дублируются в поле subjects
                // для legacy-отображения. См. [[subject-binding-design]].
                $cat_ids = array_values(array_unique(array_filter(array_map('intval',
                    (array)($data['subject_categories'] ?? [])))));

                $teacher_id = $DB->insert_record('unics_teachers', (object)[
                    'mdl_user_id'     => $mdl_user_id,
                    // Районный методист (9) скоупится районом — organization_id пуст.
                    'organization_id' => !empty($data['organization_id']) ? (int)$data['organization_id'] : null,
                    // Если категории не выбраны — оставляем строку из API/импорта.
                    'subjects'        => is_string($data['subjects'] ?? null) ? $data['subjects'] : null,
                    'qualification'   => $data['qualification'] ?? null,
                    'grade_from'      => !empty($data['grade_from']) ? (int)$data['grade_from'] : null,
                    'grade_to'        => !empty($data['grade_to'])   ? (int)$data['grade_to']   : null,
                    'teacher_type'    => self::clean_teacher_type($data['teacher_type'] ?? null),
                ]);

                if ($cat_ids) {
                    $names = self::persist_teacher_subjects($teacher_id, $cat_ids);
                    if ($names !== '') {
                        $DB->set_field('unics_teachers', 'subjects', $names, ['id' => $teacher_id]);
                    }
                }
                break;
        }

        // 4. Назначаем роль Moodle
        $moodle_role_id = self::get_moodle_role_id($data['unics_role']);
        if ($moodle_role_id) {
            $context = context_system::instance();
            role_assign($moodle_role_id, $mdl_user_id, $context->id);
        }

        // 5. Устанавливаем кастомное поле профиля unics_level (для учащихся)
        if ((int)$data['unics_role'] === role_manager::ROLE_STUDENT) {
            self::set_student_level($mdl_user_id, (int)($data['difficulty_level'] ?? 2));
        }

        // 6. Региональный администратор (роль 1) автоматически получает siteadmin
        // Moodle (#5, ответ пользователя 2026-05-29: только регионального; для
        // районного админа — роль 2 — этого не делаем, её существование под вопросом).
        // При удалении/архиве siteadmin НЕ отзывается автоматически — потребуется
        // ручная чистка через Site administration → Permissions → Site administrators.
        if ((int)$data['unics_role'] === role_manager::ROLE_REGION_ADMIN) {
            self::add_to_siteadmins($mdl_user_id);
        }

        return $mdl_user_id;
    }

    /**
     * Добавляет пользователя в `$CFG->siteadmins`. Идемпотентно: повторный вызов
     * не создаёт дубликата. Используется для unics_role=1 (региональный админ).
     */
    private static function add_to_siteadmins(int $mdl_user_id): void {
        global $CFG;
        $raw     = (string)($CFG->siteadmins ?? '');
        $admins  = array_filter(array_map('intval', explode(',', $raw)));
        if (!in_array($mdl_user_id, $admins, true)) {
            $admins[] = $mdl_user_id;
            set_config('siteadmins', implode(',', array_values(array_unique($admins))));
        }
    }

    /**
     * Сборка WHERE + параметров для списка пользователей (DRY для get_users/count_users).
     *
     * @return array [string $where, array $params]
     */
    protected static function users_where(int $org_id, int $unics_role,
                                          string $extra_where, array $extra_params): array {
        $where  = '1=1';
        $params = [];

        if ($org_id > 0) {
            $where .= ' AND uo.organization_id = :org_id';
            $params['org_id'] = $org_id;
        }
        if ($unics_role > 0) {
            $where .= ' AND uo.unics_role = :unics_role';
            $params['unics_role'] = $unics_role;
        }
        // Доп. фильтр по скоупу (для районного/регионального админа/методиста на users.php).
        if ($extra_where !== '') {
            $where .= ' AND (' . $extra_where . ')';
            $params += $extra_params;
        }

        return [$where, $params];
    }

    /**
     * Получить список пользователей организации с фильтрами.
     * Лимит для серверной пагинации (0 = все).
     */
    public static function get_users(int $org_id = 0, int $unics_role = 0,
                                     string $extra_where = '', array $extra_params = [],
                                     int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

        [$where, $params] = self::users_where($org_id, $unics_role, $extra_where, $extra_params);

        // LEFT JOIN на организацию: управленческие роли (региональн./районный админ,
        // районный методист) не привязаны к организации — у них заполнен region_id/district_id.
        // org_name берём из первой непустой сущности скоупа.
        $sql = "SELECT u.id, u.firstname, u.lastname, u.middlename,
                       u.email, u.username, uo.unics_role, uo.organization_id,
                       COALESCE(o.name, d.name, rg.name) AS org_name,
                       s.id AS student_id, s.class_number, s.class_letter, s.archived_at,
                       t.teacher_type
                FROM {user} u
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                LEFT JOIN {unics_organizations} o ON o.id = uo.organization_id
                LEFT JOIN {unics_districts}     d ON d.id = uo.district_id
                LEFT JOIN {unics_regions}      rg ON rg.id = uo.region_id
                LEFT JOIN {unics_students}      s ON s.mdl_user_id = u.id
                LEFT JOIN {unics_teachers}      t ON t.mdl_user_id = u.id
                WHERE $where AND u.deleted = 0
                ORDER BY u.lastname, u.firstname";

        return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }

    /**
     * Число пользователей с теми же фильтрами, что get_users (для пагинации).
     */
    public static function count_users(int $org_id = 0, int $unics_role = 0,
                                       string $extra_where = '', array $extra_params = []): int {
        global $DB;

        [$where, $params] = self::users_where($org_id, $unics_role, $extra_where, $extra_params);

        $sql = "SELECT COUNT(DISTINCT u.id)
                FROM {user} u
                JOIN {unics_user_org} uo ON uo.mdl_user_id = u.id
                LEFT JOIN {unics_organizations} o ON o.id = uo.organization_id
                LEFT JOIN {unics_districts}     d ON d.id = uo.district_id
                LEFT JOIN {unics_regions}      rg ON rg.id = uo.region_id
                LEFT JOIN {unics_students}      s ON s.mdl_user_id = u.id
                LEFT JOIN {unics_teachers}      t ON t.mdl_user_id = u.id
                WHERE $where AND u.deleted = 0";

        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Мягкий архив учащегося («удаление» методистом): archived_at = сегодня.
     * Аккаунт, оценки и привязки сохраняются; ученик убирается из активных списков.
     * Пишет в unics_audit_log. Идемпотентно: уже архивный — вернёт false.
     *
     * @param int $student_id unics_students.id
     * @param int $actor_id   mdl_user_id инициатора
     * @return bool true если статус изменился
     */
    public static function archive_student(int $student_id, int $actor_id): bool {
        global $DB;
        $student = $DB->get_record('unics_students', ['id' => $student_id], 'id, archived_at', MUST_EXIST);
        if (!empty($student->archived_at)) {
            return false; // уже в архиве
        }
        $today = date('Y-m-d');
        $DB->set_field('unics_students', 'archived_at', $today, ['id' => $student_id]);
        self::log_student_archive($student_id, $actor_id, 'archive', null, $today);
        return true;
    }

    /**
     * Восстановление из мягкого архива: archived_at = NULL.
     *
     * @param int $student_id unics_students.id
     * @param int $actor_id   mdl_user_id инициатора
     * @return bool true если статус изменился
     */
    public static function restore_student(int $student_id, int $actor_id): bool {
        global $DB;
        $student = $DB->get_record('unics_students', ['id' => $student_id], 'id, archived_at', MUST_EXIST);
        if (empty($student->archived_at)) {
            return false; // и так активный
        }
        $DB->set_field('unics_students', 'archived_at', null, ['id' => $student_id]);
        self::log_student_archive($student_id, $actor_id, 'restore', $student->archived_at, null);
        return true;
    }

    /** Запись в unics_audit_log для архива/восстановления учащегося. */
    private static function log_student_archive(int $student_id, int $actor_id,
                                               string $action, ?string $old, ?string $new): void {
        global $DB;
        $DB->insert_record('unics_audit_log', (object)[
            'mdl_user_id' => $actor_id,
            'action'      => $action,
            'table_name'  => 'unics_students',
            'record_id'   => $student_id,
            'old_value'   => json_encode(['archived_at' => $old]),
            'new_value'   => json_encode(['archived_at' => $new]),
            'ip_address'  => getremoteaddr(),
            'changed_at'  => time(),
        ]);
    }

    /**
     * Получить учащихся: все (org_id=0) или конкретной организации
     */
    public static function get_students(int $org_id): array {
        global $DB;

        if ($org_id > 0) {
            $sql = "SELECT u.id AS mdl_user_id, u.firstname, u.lastname,
                           s.id AS student_id, s.category, s.ovz_type, s.difficulty_level,
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

        // Роли 4 (методист орг.), 5 (педагог, создающий курсы), 6 (педагог non-editing)
        // — все имеют запись в unics_teachers и могут быть назначены/записаны на курс.
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
            'assigned_at' => time(),
        ]);

        // Автоматически добавить педагога и учащегося в контакты Moodle Messaging
        try {
            $teacher_rec = $DB->get_record('unics_teachers', ['id' => $teacher_id], 'mdl_user_id');
            $student_rec = $DB->get_record('unics_students', ['id' => $student_id], 'mdl_user_id');
            if ($teacher_rec && $student_rec) {
                \local_unics\social\social_manager::sync_on_teacher_assign(
                    (int)$teacher_rec->mdl_user_id,
                    (int)$student_rec->mdl_user_id,
                    $student_id
                );
            }
        } catch (\Throwable $e) {
            // Нефатально
            debugging('local_unics: подавленное исключение: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
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
            if (array_key_exists('student_category', $data)) {
                $student->category = is_array($data['student_category'])
                    ? \local_unics\identity\student_helper::to_csv($data['student_category'])
                    : (string)$data['student_category'];
            }
            if (array_key_exists('ovz_type', $data)) {
                $ovz_csv = is_array($data['ovz_type'])
                    ? \local_unics\identity\student_helper::to_csv($data['ovz_type'])
                    : (string)($data['ovz_type'] ?? '');
                $cats = \local_unics\identity\student_helper::parse_csv($student->category);
                $student->ovz_type = (in_array(1, $cats, true) && $ovz_csv !== '') ? $ovz_csv : null;
            }
            $student->difficulty_level = $data['difficulty_level'] ?? $student->difficulty_level;
            $student->class_number     = $data['class_number'] ?? $student->class_number;
            $student->class_letter     = $data['class_letter'] ?? $student->class_letter;
            $student->special_needs    = $data['special_needs'] ?? $student->special_needs;
            $DB->update_record('unics_students', $student);
            // Нормализованные таблицы категорий/ОВЗ (этап 2.6, dual-write).
            self::sync_student_taxonomies((int)$student->id, $student->category, $student->ovz_type);
            self::set_student_level($mdl_user_id, (int)$student->difficulty_level);
        }

        // Обновить расширенный профиль педагога/тьютора/методиста
        $teacher = $DB->get_record('unics_teachers', ['mdl_user_id' => $mdl_user_id]);
        if ($teacher) {
            // Предметы = категории (мультивыбор) синхронизируются в unics_teacher_subject;
            // имена дублируются в subjects. Если форма прислала subject_categories —
            // считаем его полным набором (пустой = снять все предметы).
            if (array_key_exists('subject_categories', $data)) {
                $cat_ids = array_values(array_unique(array_filter(array_map('intval',
                    (array)$data['subject_categories']))));
                $names = self::persist_teacher_subjects((int)$teacher->id, $cat_ids);
                $teacher->subjects = $names !== '' ? $names : null;
            } else {
                $teacher->subjects = $data['subjects'] ?? $teacher->subjects;
            }
            $teacher->qualification = $data['qualification'] ?? $teacher->qualification;
            if (array_key_exists('grade_from', $data)) {
                $teacher->grade_from = !empty($data['grade_from']) ? (int)$data['grade_from'] : null;
            }
            if (array_key_exists('grade_to', $data)) {
                $teacher->grade_to = !empty($data['grade_to']) ? (int)$data['grade_to'] : null;
            }
            if (array_key_exists('teacher_type', $data)) {
                $teacher->teacher_type = self::clean_teacher_type($data['teacher_type']);
            }
            $DB->update_record('unics_teachers', $teacher);
        }
    }

    /**
     * Синхронизирует предметы педагога (категории курсов) в unics_teacher_subject:
     * удаляет прежние привязки и записывает переданные (только реально существующие
     * категории). Возвращает человекочитаемую строку имён для поля subjects.
     */
    private static function persist_teacher_subjects(int $teacher_id, array $cat_ids): string {
        global $DB;
        $DB->delete_records('unics_teacher_subject', ['teacher_id' => $teacher_id]);
        $cat_ids = array_values(array_unique(array_filter(array_map('intval', $cat_ids))));
        if (empty($cat_ids)) {
            return '';
        }
        [$in_sql, $in_params] = $DB->get_in_or_equal($cat_ids);
        $cats = $DB->get_records_select('course_categories',
            "id {$in_sql}", $in_params, 'sortorder ASC', 'id, name');
        $now   = time();
        $names = [];
        foreach ($cats as $c) {
            $DB->insert_record('unics_teacher_subject', (object)[
                'teacher_id'  => $teacher_id,
                'category_id' => (int)$c->id,
                'timecreated' => $now,
            ]);
            $names[] = format_string($c->name);
        }
        return implode(', ', $names);
    }

    /**
     * ID категорий-предметов педагога (для предзаполнения формы редактирования).
     */
    public static function get_teacher_subject_ids(int $teacher_id): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'unics_teacher_subject', 'category_id', 'teacher_id = ?', [$teacher_id]));
    }

    /**
     * Коды архетипов педагога (teacher_type) -> человекочитаемые метки.
     * Один источник для форм (создание/редактирование) и отображения в ростере.
     * См. [[subject-binding-design]] «Три архетипа педагога».
     */
    public static function teacher_type_options(): array {
        return [
            'subject' => get_string('teacher_type_subject', 'local_unics'),
            'primary' => get_string('teacher_type_primary', 'local_unics'),
            'support' => get_string('teacher_type_support', 'local_unics'),
        ];
    }

    /** Метка архетипа по коду (пусто для NULL/неизвестного) - для отображения. */
    public static function teacher_type_label(?string $code): string {
        if ($code === null || $code === '') {
            return '';
        }
        $opts = self::teacher_type_options();
        return $opts[$code] ?? '';
    }

    /** Нормализует код архетипа: валидный subject/primary/support или null. */
    private static function clean_teacher_type($v): ?string {
        $v = is_string($v) ? trim($v) : '';
        return in_array($v, ['subject', 'primary', 'support'], true) ? $v : null;
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
                       t.id AS teacher_id, t.subjects, t.qualification,
                       t.grade_from, t.grade_to, t.teacher_type
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
            1 => 'region_admin',        // Региональный администратор (скоуп: регион)
            // код 2 (district_admin) удалён в v3 [[role-model-v3-2026-06-11]] — слит в district_methodist (9)
            3 => 'org_admin',           // Адм. организации — legacy, из селекта убран, маппинг сохранён
            4 => 'methodist',           // Методист организации (скоуп: организация)
            5 => 'editingteacher',      // Педагог, создающий курсы (скоуп: организация)
            6 => 'teacher',             // Педагог, non-editing (скоуп: организация)
            7 => 'student',             // Учащийся
            8 => 'parent',              // Родитель
            9 => 'district_methodist',  // Районный методист (скоуп: район)
            10 => 'region_methodist',   // Региональный методист (скоуп: регион) — v3 фаза 2
        ];

        if (!isset($map[$unics_role])) {
            return null;
        }

        $role = $DB->get_record('role', ['shortname' => $map[$unics_role]], 'id');
        return $role ? (int)$role->id : null;
    }

    /**
     * Публичный метод - установить/обновить уровень сложности учащегося в профиле Moodle.
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
        return \local_unics\ai\course_template::ensure_profile_field();
    }

    /**
     * Синхронизация нормализованных таблиц категорий/ОВЗ с CSV-полями ученика
     * (этап 2.6, инкремент A: dual-write - обе формы хранятся согласованно,
     * CSV-колонки уйдут в инкременте C). Полная замена набора: delete + insert.
     *
     * @param int   $student_id   id в unics_students
     * @param mixed $category_csv CSV/int/null - значение поля category
     * @param mixed $ovz_csv      CSV/int/null - значение поля ovz_type
     */
    public static function sync_student_taxonomies(int $student_id, $category_csv, $ovz_csv): void {
        global $DB;
        $map = [
            'unics_student_category' => ['category',
                \local_unics\identity\student_helper::parse_csv($category_csv)],
            'unics_student_ovz'      => ['ovz_type',
                \local_unics\identity\student_helper::parse_csv($ovz_csv)],
        ];
        foreach ($map as $table => [$col, $ids]) {
            $DB->delete_records($table, ['student_id' => $student_id]);
            foreach ($ids as $v) {
                $DB->insert_record($table, (object)['student_id' => $student_id, $col => $v]);
            }
        }
    }
}
