<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_unics_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026042001) {
        $table = new xmldb_table('unics_umk');
        $field = new xmldb_field('target_section', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '-1', 'topic');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026042001, 'local', 'unics');
    }

    if ($oldversion < 2026042002) {
        $dbman = $DB->get_manager();

        // unics_regions.mdl_category_id
        $table = new xmldb_table('unics_regions');
        $field = new xmldb_field('mdl_category_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'is_active');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // unics_districts.code
        $table = new xmldb_table('unics_districts');
        $field = new xmldb_field('code', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // unics_districts.mdl_category_id
        $field = new xmldb_field('mdl_category_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'code');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // unics_organizations.mdl_category_id
        $table = new xmldb_table('unics_organizations');
        $field = new xmldb_field('mdl_category_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'is_active');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026042002, 'local', 'unics');
    }

    if ($oldversion < 2026042003) {
        $dbman = $DB->get_manager();

        // unics_umk.extra_prompt
        $table = new xmldb_table('unics_umk');
        $field = new xmldb_field('extra_prompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'topic');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026042003, 'local', 'unics');
    }

    if ($oldversion < 2026042004) {
        $table = new xmldb_table('unics_ai_queue');

        $field = new xmldb_field('generate_quiz', XMLDB_TYPE_INTEGER, '2', null, null, null, '1', 'generate_audio');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('generate_assignment', XMLDB_TYPE_INTEGER, '2', null, null, null, '0', 'generate_quiz');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026042004, 'local', 'unics');
    }

    if ($oldversion < 2026050001) {
        $dbman = $DB->get_manager();

        // Таблица комментариев педагога
        $table = new xmldb_table('unics_comments');
        $table->add_field('id',                  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('student_id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('teacher_mdl_user_id', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('body',                XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL);
        $table->add_field('created_at',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('idx_comment_teacher', XMLDB_INDEX_NOTUNIQUE, ['teacher_mdl_user_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Поле generate_video в unics_ai_queue
        $table = new xmldb_table('unics_ai_queue');
        $field = new xmldb_field('generate_video', XMLDB_TYPE_INTEGER, '2', null, null, null, '0', 'generate_assignment');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050001, 'local', 'unics');
    }

    if ($oldversion < 2026050002) {
        $dbman = $DB->get_manager();

        // --- unics_umk: убираем student_id, добавляем difficulty_level + mdl_group_id ---
        $table = new xmldb_table('unics_umk');

        // Сначала удаляем FK-ключ
        $key = new xmldb_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }

        // Удаляем индекс на student_id
        $index = new xmldb_index('idx_umk_student', XMLDB_INDEX_NOTUNIQUE, ['student_id']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Удаляем поле student_id
        $field = new xmldb_field('student_id');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Добавляем difficulty_level
        $field = new xmldb_field('difficulty_level', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '2', 'mdl_course_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Добавляем mdl_group_id
        $field = new xmldb_field('mdl_group_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'difficulty_level');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // --- Новая таблица unics_umk_students ---
        $table = new xmldb_table('unics_umk_students');
        $table->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('umk_id',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('student_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('umk_id',     XMLDB_KEY_FOREIGN, ['umk_id'],     'unics_umk',      ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('uq_umk_student', XMLDB_INDEX_UNIQUE, ['umk_id', 'student_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_ai_queue: добавляем student_ids ---
        $table = new xmldb_table('unics_ai_queue');
        $field = new xmldb_field('student_ids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'umk_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050002, 'local', 'unics');
    }

    if ($oldversion < 2026050003) {
        $dbman = $DB->get_manager();

        // --- unics_notifications ---
        $table = new xmldb_table('unics_notifications');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('mdl_user_id', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('notif_type',  XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL);
        $table->add_field('subject',     XMLDB_TYPE_CHAR,    '200', null, XMLDB_NOTNULL);
        $table->add_field('body',        XMLDB_TYPE_TEXT,    null,  null);
        $table->add_field('sent',        XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('created_at',  XMLDB_TYPE_INTEGER, '10',  null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_notif_user', XMLDB_INDEX_NOTUNIQUE, ['mdl_user_id']);
        $table->add_index('idx_notif_type', XMLDB_INDEX_NOTUNIQUE, ['notif_type']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_achievements ---
        $table = new xmldb_table('unics_achievements');
        $table->add_field('id',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('student_id', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('badge_type', XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL);
        $table->add_field('awarded_at', XMLDB_TYPE_INTEGER, '10',  null);
        $table->add_field('awarded_by', XMLDB_TYPE_INTEGER, '10',  null);
        $table->add_field('note',       XMLDB_TYPE_CHAR,    '300', null);
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('uq_achiev_badge', XMLDB_INDEX_UNIQUE, ['student_id', 'badge_type']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050003, 'local', 'unics');
    }

    if ($oldversion < 2026050005) {
        $dbman = $DB->get_manager();

        // Добавляем cmid в unics_comments (привязка к активности курса)
        $table = new xmldb_table('unics_comments');
        $field = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'created_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('idx_comment_cmid', XMLDB_INDEX_NOTUNIQUE, ['cmid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026050005, 'local', 'unics');
    }

    if ($oldversion < 2026050006) {
        $dbman = $DB->get_manager();

        // --- unics_students.points ---
        $table = new xmldb_table('unics_students');
        $field = new xmldb_field('points', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'special_needs');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // --- unics_points_log ---
        $table = new xmldb_table('unics_points_log');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('student_id',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('points',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('reason_type', XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL);
        $table->add_field('reason_text', XMLDB_TYPE_CHAR,    '200', null, XMLDB_NOTNULL);
        $table->add_field('created_at',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('idx_pts_type', XMLDB_INDEX_NOTUNIQUE, ['reason_type']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_shop_items ---
        $table = new xmldb_table('unics_shop_items');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name',        XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL);
        $table->add_field('description', XMLDB_TYPE_CHAR,    '300', null);
        $table->add_field('cost',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL);
        $table->add_field('icon_emoji',  XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL);
        $table->add_field('item_type',   XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('is_active',   XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sort_order',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('idx_shop_active', XMLDB_INDEX_NOTUNIQUE, ['is_active']);
        $table->add_index('idx_shop_sort',   XMLDB_INDEX_NOTUNIQUE, ['sort_order']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_purchases ---
        $table = new xmldb_table('unics_purchases');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('student_id',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('item_id',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('purchased_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students',  ['id']);
        $table->add_key('item_id',    XMLDB_KEY_FOREIGN, ['item_id'],    'unics_shop_items', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- Заполнить магазин базовыми товарами ---
        if (!$DB->record_exists('unics_shop_items', [])) {
            $items = [
                ['name' => 'Умник',          'description' => 'Звание для начинающих отличников',        'cost' =>  100, 'icon' => '💡', 'sort' => 10],
                ['name' => 'Книжный червь',  'description' => 'Для тех, кто много читает и учится',       'cost' =>  150, 'icon' => '📚', 'sort' => 20],
                ['name' => 'Звёздный ученик','description' => 'Заслуженное звание активного учащегося',   'cost' =>  200, 'icon' => '⭐', 'sort' => 30],
                ['name' => 'Меткий стрелок', 'description' => 'Для того, кто всегда попадает в цель',     'cost' =>  300, 'icon' => '🎯', 'sort' => 40],
                ['name' => 'Суперзвезда',    'description' => 'Высшее звание для самых успешных учащихся','cost' =>  500, 'icon' => '🚀', 'sort' => 50],
                ['name' => 'Чемпион',        'description' => 'Для настоящих чемпионов учёбы',            'cost' =>  750, 'icon' => '🏆', 'sort' => 60],
            ];
            foreach ($items as $i) {
                $DB->insert_record('unics_shop_items', (object)[
                    'name'        => $i['name'],
                    'description' => $i['description'],
                    'cost'        => $i['cost'],
                    'icon_emoji'  => $i['icon'],
                    'item_type'   => 1,
                    'is_active'   => 1,
                    'sort_order'  => $i['sort'],
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026050006, 'local', 'unics');
    }

    if ($oldversion < 2026051600) {
        // Multi-select для category и ovz_type: меняем тип INT → CHAR(32) (CSV).
        // Существующие значения "1", "2"... остаются валидными CSV из одного элемента.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('unics_students');

        $field_cat = new xmldb_field('category', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '2', 'organization_id');
        $dbman->change_field_type($table, $field_cat);

        $field_ovz = new xmldb_field('ovz_type', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'category');
        $dbman->change_field_type($table, $field_ovz);

        upgrade_plugin_savepoint(true, 2026051600, 'local', 'unics');
    }

    if ($oldversion < 2026052002) {
        // Этап 1 пункта #11 (роли) [[meeting-2026-05-20-followup]]:
        // Расширяем unics_user_org для хранения скоупа (регион / район / организация).
        // Используется новой ролью region_admin и для расширения скоупа методиста с org → district.
        $table = new xmldb_table('unics_user_org');

        // Сначала снимаем старый UNIQUE (mdl_user_id, organization_id):
        // organization_id становится nullable, новая модель = одна запись на пользователя.
        $old_index = new xmldb_index('uq_user_org', XMLDB_INDEX_UNIQUE, ['mdl_user_id', 'organization_id']);
        if ($dbman->index_exists($table, $old_index)) {
            $dbman->drop_index($table, $old_index);
        }

        // organization_id NOT NULL → NULL (методист района / region_admin не имеют org).
        // MariaDB не даёт менять колонку, на которой висит foreign key — снимаем его,
        // меняем nullability, затем возвращаем ключ.
        $field_org = new xmldb_field('organization_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'mdl_user_id');
        if ($dbman->field_exists($table, $field_org)) {
            $key_org = new xmldb_key('organization_id', XMLDB_KEY_FOREIGN, ['organization_id'], 'unics_organizations', ['id']);
            try {
                $dbman->drop_key($table, $key_org);
            } catch (\Throwable $e) {
                // Ключа могло не быть (частично применённый апгрейд) — это не ошибка.
                debugging('local_unics: drop_key(organization_id) skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $dbman->change_field_notnull($table, $field_org);
            $dbman->add_key($table, $key_org);
        }

        // Добавляем region_id (для region_admin region-scope).
        $field_region = new xmldb_field('region_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'mdl_user_id');
        if (!$dbman->field_exists($table, $field_region)) {
            $dbman->add_field($table, $field_region);
        }

        // Добавляем district_id (для region_admin district-scope и методиста district-scope).
        $field_district = new xmldb_field('district_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'region_id');
        if (!$dbman->field_exists($table, $field_district)) {
            $dbman->add_field($table, $field_district);
        }

        // Новый non-unique index по mdl_user_id для быстрого поиска скоупа пользователя.
        $new_index = new xmldb_index('idx_user_org_user', XMLDB_INDEX_NOTUNIQUE, ['mdl_user_id']);
        if (!$dbman->index_exists($table, $new_index)) {
            $dbman->add_index($table, $new_index);
        }

        // FK на регион/район (организация уже была).
        $key_region = new xmldb_key('region_id', XMLDB_KEY_FOREIGN, ['region_id'], 'unics_regions', ['id']);
        $dbman->add_key($table, $key_region);

        $key_district = new xmldb_key('district_id', XMLDB_KEY_FOREIGN, ['district_id'], 'unics_districts', ['id']);
        $dbman->add_key($table, $key_district);

        // Применяем матрицу прав ролей автоматически (Q6 из встречи 2026-05-20):
        // у методиста появляется новая capability local/unics:manageorg.
        // Capability local/unics:viewownchild удалена из access.php — Moodle сам её вычистит.
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
        } catch (\Throwable $e) {
            // Не валим апгрейд из-за матрицы: админ может применить руками через setup_roles.php.
            debugging('local_unics: role_manager::apply_matrix() failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026052002, 'local', 'unics');
    }

    if ($oldversion < 2026052003) {
        // Этап 2 пункта #11 (роли) — удаление тьютора (unics_role=6).
        // По решению руководителя (встреча 2026-05-20, вариант D): если тьюторов в системе
        // нет — удаляем роль из кода. Если есть — апгрейд останавливается, требуется ручная
        // миграция (перевод в педагогов или методистов) перед повторным запуском апгрейда.
        $tutor_count = $DB->count_records('unics_user_org', ['unics_role' => 6]);
        if ($tutor_count > 0) {
            throw new \moodle_exception(
                'upgradeerror', 'admin', '', null,
                "local_unics 0.6.30: в системе {$tutor_count} пользовател(ей) с unics_role=6 (тьютор). "
              . "По решению встречи 2026-05-20 роль тьютора удаляется; перед апгрейдом нужно вручную "
              . "перевести этих пользователей в педагогов (unics_role=5) или методистов (unics_role=4). "
              . "SQL для просмотра: SELECT * FROM mdl_unics_user_org WHERE unics_role=6;"
            );
        }

        // Тьюторов нет — применяем обновлённую матрицу role_manager (уже без блока 'teacher').
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
        } catch (\Throwable $e) {
            debugging('local_unics: role_manager::apply_matrix() failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026052003, 'local', 'unics');
    }

    if ($oldversion < 2026052004) {
        // Этап 3 пункта #11 — Региональный администратор.
        // Создаём Moodle-роль region_admin (если ещё не существует) и применяем матрицу.
        // Скоуп региона/района хранится в unics_user_org.region_id / district_id (см. этап 1).
        if (!$DB->record_exists('role', ['shortname' => 'region_admin'])) {
            $roleid = create_role(
                'Региональный администратор',
                'region_admin',
                'Администратор уровня региона или района (скоуп задаётся в unics_user_org). '
              . 'Управляет методистами, педагогами и учащимися в своём скоупе через capability local/unics:manageorg.',
                'manager'  // archetype-родитель
            );
            // Делаем роль доступной на системном контексте — иначе apply_matrix не сможет
            // назначить capabilities в CONTEXT_SYSTEM.
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
        } catch (\Throwable $e) {
            debugging('local_unics: role_manager::apply_matrix() failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026052004, 'local', 'unics');
    }

    if ($oldversion < 2026052300) {
        // Переработка ролевой модели [[role-model-rework-2026-05-23]].
        // Две роли админа (региональный/районный) и две роли методиста
        // (районный/организации). region_admin теперь только регион, methodist —
        // только организация; скоупы пишутся в unics_user_org. Подробности и
        // целевая матрица — в плане и в classes/role_manager.php::get_matrix().

        // --- Районный администратор (district_admin) ---
        // Управленческая роль уровня района: копия region_admin по правам,
        // скоуп = район (unics_user_org.district_id). Архетип manager.
        if (!$DB->record_exists('role', ['shortname' => 'district_admin'])) {
            $roleid = create_role(
                'Районный администратор',
                'district_admin',
                'Администратор уровня района (скоуп задаётся в unics_user_org.district_id). '
              . 'Управляет методистами, педагогами и учащимися своего района через capability '
              . 'local/unics:manageorg со scope-проверкой на каждой странице.',
                'manager'
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        // --- Районный методист (district_methodist) ---
        // Права идентичны методисту организации (создаёт курсы/УМК + manageorg),
        // отличается только скоупом = район (unics_user_org.district_id).
        // Архетип editingteacher — как у роли methodist.
        if (!$DB->record_exists('role', ['shortname' => 'district_methodist'])) {
            $roleid = create_role(
                'Районный методист',
                'district_methodist',
                'Методист уровня района (скоуп задаётся в unics_user_org.district_id). '
              . 'Создаёт курсы/УМК и пользователей своего района через local/unics:manageorg '
              . 'со scope-проверкой. Права совпадают с методистом организации, разница только в скоупе.',
                'editingteacher'
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        // --- Стандартный архетип teacher (код 6 = «Педагог», non-editing) ---
        // Должен существовать (создаётся при установке Moodle). Делаем его назначаемым
        // на системном контексте — наш user_manager назначает роль в CONTEXT_SYSTEM.
        $teacher = $DB->get_record('role', ['shortname' => 'teacher'], 'id');
        if ($teacher) {
            $levels = get_role_contextlevels($teacher->id);
            if (!in_array(CONTEXT_SYSTEM, $levels, true)) {
                $levels[] = CONTEXT_SYSTEM;
                set_role_contextlevels($teacher->id, $levels);
            }
        } else {
            debugging('local_unics 0.6.34: стандартная роль teacher не найдена — '
                . 'код 6 (Педагог) не сможет назначаться. Создайте архетип teacher.', DEBUG_DEVELOPER);
        }

        // Применяем обновлённую матрицу прав (district_admin, district_methodist, teacher).
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
        } catch (\Throwable $e) {
            debugging('local_unics: role_manager::apply_matrix() failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026052300, 'local', 'unics');
    }

    if ($oldversion < 2026052402) {
        // Пункт #7: editingteacher (роль 5) больше не может создавать/удалять курсы —
        // в матрицу role_manager добавлен явный prevent на course:create/course:delete.
        // Переприменяем матрицу, чтобы запрет вступил в силу для существующей роли.
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
        } catch (\Throwable $e) {
            debugging('local_unics: role_manager::apply_matrix() failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026052402, 'local', 'unics');
    }

    if ($oldversion < 2026052403) {
        // Пункт #1: массовый перевод в следующий класс. 11-классники помечаются
        // выпускниками — для этого новое поле unics_students.graduated_at (дата 'YYYY-MM-DD',
        // NULL = активный). Аккаунт и отчётность сохраняются, из активных списков убираются.
        $table = new xmldb_table('unics_students');
        $field = new xmldb_field('graduated_at', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'class_letter');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026052403, 'local', 'unics');
    }

    if ($oldversion < 2026052701) {
        // Пункт #10: «удалить ученика» методистом = мягкий архив. Новое поле
        // unics_students.archived_at (дата 'YYYY-MM-DD', NULL = активный). Аккаунт,
        // оценки и привязки сохраняются; архивный ученик убирается из активных списков.
        $table = new xmldb_table('unics_students');
        $field = new xmldb_field('archived_at', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'graduated_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026052701, 'local', 'unics');
    }

    if ($oldversion < 2026052900) {
        // Наблюдения #8 + #13 (2026-05-28):
        //  #8 — editingteacher (роль 5 = «педагог, создающий курсы») снова получает
        //       course:create/course:delete. Прежний prevent (upgrade 2026052402)
        //       снят, матрица переприменяется.
        //  #13 — отображаемые имена Moodle-ролей приведены к нашим терминам
        //       (editingteacher → «Педагог (создающий курсы)», teacher → «Педагог»).
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
            \local_unics\role_manager::apply_role_names();
        } catch (\Throwable $e) {
            debugging('local_unics: role refresh failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026052900, 'local', 'unics');
    }

    if ($oldversion < 2026053000) {
        // apply_role_names() is idempotent — only changed rows are touched.
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_role_names();
        } catch (\Throwable $e) {
            debugging('local_unics: apply_role_names() failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026053000, 'local', 'unics');
    }

    if ($oldversion < 2026053001) {
        // Dashboard "active students" counts filter by (organization_id, archived_at).
        // Without this composite index the methodist + scoped-admin counts table-scan
        // unics_students on every login.
        $table = new xmldb_table('unics_students');
        $index = new xmldb_index('ix_student_org_arch', XMLDB_INDEX_NOTUNIQUE,
            ['organization_id', 'archived_at']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026053001, 'local', 'unics');
    }

    if ($oldversion < 2026053111) {
        // Review-гейт УМК: published_at NULL = на проверке (черновик),
        // задано = опубликован педагогом (активности стали visible=1).
        $table = new xmldb_table('unics_umk');
        $field = new xmldb_field('published_at', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'generated_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026053111, 'local', 'unics');
    }

    if ($oldversion < 2026053112) {
        // Бэкфилл review-гейта: УМК, готовые ДО внедрения гейта (status=3), уже были
        // видимы учащимся — считаем их опубликованными, иначе они покажутся как
        // «черновик на проверке» и кнопка «Удалить черновик» снесёт живые активности.
        $DB->execute(
            "UPDATE {unics_umk} SET published_at = ? WHERE status = 3 AND published_at IS NULL",
            [date('Y-m-d H:i:s')]
        );

        upgrade_plugin_savepoint(true, 2026053112, 'local', 'unics');
    }

    if ($oldversion < 2026053113) {
        // Детальный профиль педагога (#9/#2/#18): множественный выбор предметов
        // (= категории курсов) + диапазон классов (мягкий фильтр).
        $table = new xmldb_table('unics_teachers');
        foreach ([
            new xmldb_field('grade_from', XMLDB_TYPE_INTEGER, '4', null, null, null, null, 'qualification'),
            new xmldb_field('grade_to',   XMLDB_TYPE_INTEGER, '4', null, null, null, null, 'grade_from'),
        ] as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Привязка педагог × предмет (категория). Структурное хранение мультивыбора;
        // unics_teachers.subjects остаётся как человекочитаемый дубль для отображения.
        $tsub = new xmldb_table('unics_teacher_subject');
        $tsub->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $tsub->add_field('teacher_id',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tsub->add_field('category_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tsub->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $tsub->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $tsub->add_key('teacher_id', XMLDB_KEY_FOREIGN, ['teacher_id'], 'unics_teachers', ['id']);
        $tsub->add_index('uq_teacher_category', XMLDB_INDEX_UNIQUE,    ['teacher_id', 'category_id']);
        $tsub->add_index('ix_category',         XMLDB_INDEX_NOTUNIQUE, ['category_id']);
        if (!$dbman->table_exists($tsub)) {
            $dbman->create_table($tsub);
        }

        upgrade_plugin_savepoint(true, 2026053113, 'local', 'unics');
    }

    if ($oldversion < 2026053115) {
        // Образовательный цикл B5: флаг «итоговый экзамен» (+ задел под B4 «контрольная точка»).
        // Метаданные активности курса хранятся отдельной таблицей (cmid → флаги),
        // чтобы не трогать ядровые course_modules. См. [[educational-cycle-implementation]].
        $table = new xmldb_table('unics_activity_meta');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cmid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('is_final',     XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('is_milestone', XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('uq_cmid', XMLDB_INDEX_UNIQUE, ['cmid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053115, 'local', 'unics');
    }

    if ($oldversion < 2026053117) {
        // B7: автопересдача итогового экзамена при провале (observer на attempt_submitted).
        $table = new xmldb_table('unics_retakes');
        $table->add_field('id',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mdl_user_id',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mdl_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade',         XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
        $table->add_field('grademax',      XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
        $table->add_field('gradepass',     XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
        $table->add_field('status',        XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeresolved',  XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ix_user_cm',     XMLDB_INDEX_NOTUNIQUE, ['mdl_user_id', 'cmid']);
        $table->add_index('ix_course_open', XMLDB_INDEX_NOTUNIQUE, ['mdl_course_id', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053117, 'local', 'unics');
    }

    if ($oldversion < 2026053121) {
        // B2: «темы для повторения». Провал теста темы (с B1-гейтом, не итогового)
        // фиксируется записью; учащемуся и педагогу уходит уведомление. Структура
        // зеркалит unics_retakes (B7), но это отдельная сущность (повтор темы, не пересдача
        // экзамена). См. [[educational-cycle-implementation]] узлы 14/16/17.
        $table = new xmldb_table('unics_topic_retries');
        $table->add_field('id',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mdl_user_id',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mdl_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cmid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade',         XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
        $table->add_field('grademax',      XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
        $table->add_field('gradepass',     XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null);
        $table->add_field('status',        XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timeresolved',  XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ix_user_cm',     XMLDB_INDEX_NOTUNIQUE, ['mdl_user_id', 'cmid']);
        $table->add_index('ix_course_open', XMLDB_INDEX_NOTUNIQUE, ['mdl_course_id', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053121, 'local', 'unics');
    }

    if ($oldversion < 2026053125) {
        // A2: ИОМ (индивидуальный образовательный маршрут) v1 [[iom-design]].
        // Своя сущность: маршрут (план/трекер на ученика) + шаги (= тема: курс + тема
        // + опц. УМК). Доступ к курсам НЕ блокирует — только план и прогресс.

        // --- unics_learning_path ---
        $table = new xmldb_table('unics_learning_path');
        $table->add_field('id',                     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('created_by_mdl_user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('goal',                   XMLDB_TYPE_TEXT,    null, null, null,          null, null);
        $table->add_field('status',                 XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '1');
        $table->add_field('created_at',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('updated_at',             XMLDB_TYPE_INTEGER, '10', null, null,          null, null);
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('ix_path_student_status', XMLDB_INDEX_NOTUNIQUE, ['student_id', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_path_step ---
        $table = new xmldb_table('unics_path_step');
        $table->add_field('id',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('path_id',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('ordinal',       XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('title',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('topic',         XMLDB_TYPE_CHAR,    '255', null, null, null, null);
        $table->add_field('mdl_course_id', XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_field('umk_id',        XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_field('target_level',  XMLDB_TYPE_INTEGER, '2',   null, null, null, null);
        $table->add_field('status',        XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('source',        XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('planned_from',  XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_field('planned_to',    XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_field('note',          XMLDB_TYPE_TEXT,    null,  null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('path_id', XMLDB_KEY_FOREIGN, ['path_id'], 'unics_learning_path', ['id']);
        $table->add_index('ix_step_path_ordinal', XMLDB_INDEX_NOTUNIQUE, ['path_id', 'ordinal']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053125, 'local', 'unics');
    }

    if ($oldversion < 2026053126) {
        // A4: заметки педагога, фаза 2 [[teacher-notes-redesign]].
        // audience (кумулятивная шкала видимости) + parent_id (под нити фазы 3) +
        // archived_at (мягкий архив) + таблица last-seen для бейджей «N новых».

        $table = new xmldb_table('unics_comments');

        // audience: 1=private, 2=staff, 3=student, 4=family. Существующим строкам
        // ставим 4 (= текущее поведение: видно всей команде ребёнка + семье).
        $field = new xmldb_field('audience', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '4', 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // parent_id: корень нити (фаза 3); добавляем сейчас, не используем.
        $field = new xmldb_field('parent_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'audience');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // archived_at: unix, NULL = активна.
        $field = new xmldb_field('archived_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'parent_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('idx_comment_audience', XMLDB_INDEX_NOTUNIQUE, ['student_id', 'audience', 'archived_at']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('idx_comment_parent', XMLDB_INDEX_NOTUNIQUE, ['parent_id']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // unics_comment_seen: одна строка на (ученик, зритель) = последний просмотр.
        $table = new xmldb_table('unics_comment_seen');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mdl_user_id',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('last_seen_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('uq_comment_seen', XMLDB_INDEX_UNIQUE, ['student_id', 'mdl_user_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053126, 'local', 'unics');
    }

    if ($oldversion < 2026053127) {
        // A5: статистика [[statistics-collection-design]].
        // Предусловие — история уровней (adaptive_engine пишет сюда при изменении уровня)
        // + кумулятивный rollup учебной статистики (учащийся x курс), считает task aggregate_stats.

        // --- unics_level_history ---
        $table = new xmldb_table('unics_level_history');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mdl_user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('old_level',   XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('new_level',   XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('avg_score',   XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('changed_at',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('ix_levelhist_student', XMLDB_INDEX_NOTUNIQUE, ['student_id', 'changed_at']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_stats_student_course ---
        $table = new xmldb_table('unics_stats_student_course');
        $table->add_field('id',                   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mdl_user_id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mdl_course_id',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('views',                XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('time_est_min',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completed_activities', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('total_activities',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attempts',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('avg_score_pct',        XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null);
        $table->add_field('last_active_at',       XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('ai_uses',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('computed_at',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('uq_stats_student_course', XMLDB_INDEX_UNIQUE,    ['student_id', 'mdl_course_id']);
        $table->add_index('ix_stats_course',         XMLDB_INDEX_NOTUNIQUE, ['mdl_course_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053127, 'local', 'unics');
    }

    if ($oldversion < 2026060201) {
        // A6 / M1: групповые беседы класса (core_message group conversations).
        // Связка класс (организация+номер+буква) -> id групповой беседы. Само
        // членство беседы синхронизируется class_chat_manager из unics_students.
        $table = new xmldb_table('unics_class_conversation');
        $table->add_field('id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('organization_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('class_number',    XMLDB_TYPE_INTEGER, '4',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('class_letter',    XMLDB_TYPE_CHAR,    '2',  null, XMLDB_NOTNULL, null, '');
        $table->add_field('conversationid',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',    XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary',         XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('organization_id', XMLDB_KEY_FOREIGN, ['organization_id'], 'unics_organizations', ['id']);
        $table->add_index('uq_class_conv', XMLDB_INDEX_UNIQUE,    ['organization_id', 'class_number', 'class_letter']);
        $table->add_index('ix_conv',       XMLDB_INDEX_NOTUNIQUE, ['conversationid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060201, 'local', 'unics');
    }

    if ($oldversion < 2026060900) {
        // D2 (остаток #9): архетип педагога. Новое поле unics_teachers.teacher_type
        // (char-код subject / primary / support; NULL = не указан). Пока только хранение
        // и отображение — на поведение (изоляция/фильтры) не влияет. См. [[subject-binding-design]].
        $table = new xmldb_table('unics_teachers');
        $field = new xmldb_field('teacher_type', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'grade_to');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026060900, 'local', 'unics');
    }

    if ($oldversion < 2026061000) {
        // Мотивация этап 2 ([[motivation-module-design]]): SVG-иконки магазина и
        // значков вместо эмодзи. Поле icon = ключ файла pix/shop/{icon}.svg;
        // icon_emoji остается legacy-фолбэком. Плюс новый тип товара - дешевые
        // стикеры-карточки (item_type=3), чтобы первая покупка случалась быстро.
        $table = new xmldb_table('unics_shop_items');
        $field = new xmldb_field('icon', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'icon_emoji');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Иконки существующим титулам (матчим по имени, не по id).
        $map = [
            'Умник'           => 'bulb',
            'Книжный червь'   => 'book',
            'Звездный ученик' => 'star',
            'Звёздный ученик' => 'star',
            'Меткий стрелок'  => 'target',
            'Суперзвезда'     => 'rocket',
            'Чемпион'         => 'trophy',
        ];
        foreach ($map as $name => $icon) {
            $DB->set_field('unics_shop_items', 'icon', $icon, ['name' => $name]);
        }

        // Стикеры-коллекция.
        $stickers = [
            ['name' => 'Молния',     'cost' => 30, 'icon' => 'lightning', 'sort' => 10],
            ['name' => 'Сова',       'cost' => 40, 'icon' => 'owl',       'sort' => 11],
            ['name' => 'Кот-ученый', 'cost' => 40, 'icon' => 'cat',       'sort' => 12],
            ['name' => 'Самоцвет',   'cost' => 60, 'icon' => 'gem',       'sort' => 13],
        ];
        foreach ($stickers as $s) {
            if (!$DB->record_exists('unics_shop_items', ['name' => $s['name'], 'item_type' => 3])) {
                $DB->insert_record('unics_shop_items', (object)[
                    'name'        => $s['name'],
                    'description' => 'Коллекционная карточка',
                    'cost'        => $s['cost'],
                    'icon_emoji'  => '',
                    'icon'        => $s['icon'],
                    'item_type'   => 3,
                    'is_active'   => 1,
                    'sort_order'  => $s['sort'],
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2026061000, 'local', 'unics');
    }

    if ($oldversion < 2026061100) {
        // Ролевая модель v3 [[role-model-v3-2026-06-11]] (встреча 11.06):
        // муниципальный администратор удаляется, его функции переходят
        // муниципальному методисту. Слияние district_admin (код 2) -> district_methodist (9).
        // Шаги: (1) данные в unics_user_org; (2) назначения Moodle-роли; (3) удаление роли.

        // 1. Привязки: меняем код роли 2 -> 9. После этого ни одной строки с кодом 2.
        $cnt = $DB->count_records('unics_user_org', ['unics_role' => 2]);
        if ($cnt > 0) {
            $DB->set_field('unics_user_org', 'unics_role', 9, ['unics_role' => 2]);
            mtrace("local_unics v3: мигрировано привязок district_admin -> district_methodist: {$cnt}");
        }

        // 2/3. Moodle-роль district_admin: переназначаем её носителей на district_methodist,
        // затем удаляем саму роль (delete_role снимает остаточные назначения и capability).
        $da = $DB->get_record('role', ['shortname' => 'district_admin'], 'id');
        if ($da) {
            $dm = $DB->get_record('role', ['shortname' => 'district_methodist'], 'id');
            if ($dm) {
                $assignments = $DB->get_records('role_assignments', ['roleid' => $da->id]);
                foreach ($assignments as $ra) {
                    // Сохраняем тот же контекст/компонент/itemid, что и у исходного назначения.
                    role_assign($dm->id, $ra->userid, $ra->contextid, $ra->component, $ra->itemid);
                }
                mtrace('local_unics v3: переназначено носителей district_admin -> district_methodist: '
                    . count($assignments));
            } else {
                debugging('local_unics v3: роль district_methodist не найдена — '
                    . 'носители district_admin останутся без муниципальной роли.', DEBUG_DEVELOPER);
            }
            delete_role($da->id);
            mtrace('local_unics v3: Moodle-роль district_admin удалена.');
        }

        // Переприменяем матрицу/имена ролей (district_admin удалён из обоих наборов).
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
            \local_unics\role_manager::apply_role_names();
        } catch (\Throwable $e) {
            debugging('local_unics v3: role refresh failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026061100, 'local', 'unics');
    }

    if ($oldversion < 2026061101) {
        // Ролевая модель v3, фаза 2 [[role-model-v3-2026-06-11]]: новая роль
        // «региональный методист» (region_methodist, код unics_role=10). Учебно-методический
        // контур регионального уровня без техадминистрирования и без создания курсов.
        // Права = алиас region_admin (manageorg по региону, enrol, отчёты, просмотр оценок,
        // БЕЗ course:create / site:config), скоуп = unics_user_org.region_id. Меню/дашборд
        // общие с region_admin через local_unics_is_scoped_admin (заголовок условный).
        // Назначать роль может региональный администратор и системный админ.
        if (!$DB->record_exists('role', ['shortname' => 'region_methodist'])) {
            $roleid = create_role(
                'Региональный методист',
                'region_methodist',
                'Методист уровня региона (скоуп задаётся в unics_user_org.region_id). '
              . 'Ведёт и распределяет курсы, назначает учащихся на любые курсы региона, '
              . 'выдаёт сертификаты, готовит отчётность через capability local/unics:manageorg '
              . 'со scope-проверкой. Права совпадают с региональным администратором, '
              . 'но это учебно-методическая роль без техадминистрирования и без создания курсов.',
                'manager'  // archetype-родитель, как у region_admin
            );
            // Делаем роль доступной на системном контексте — иначе apply_matrix не сможет
            // назначить capabilities в CONTEXT_SYSTEM (наш user_manager назначает в CONTEXT_SYSTEM).
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        // Применяем матрицу (region_methodist = алиас region_admin) и имена ролей.
        require_once(__DIR__ . '/../classes/role_manager.php');
        try {
            \local_unics\role_manager::apply_matrix();
            \local_unics\role_manager::apply_role_names();
        } catch (\Throwable $e) {
            debugging('local_unics v3 фаза 2: role refresh failed during upgrade: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        upgrade_plugin_savepoint(true, 2026061101, 'local', 'unics');
    }

    if ($oldversion < 2026061501) {
        // Стартовая диагностика (адаптивный цикл, стадия «Диагностика и старт»):
        //  - unics_activity_meta.is_diagnostic - флаг входного диагностического теста;
        //  - unics_students.diagnosed - стартовый уровень установлен один раз.
        $table = new xmldb_table('unics_activity_meta');
        $field = new xmldb_field('is_diagnostic', XMLDB_TYPE_INTEGER, '2', null,
            XMLDB_NOTNULL, null, '0', 'is_milestone');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('unics_students');
        $field = new xmldb_field('diagnosed', XMLDB_TYPE_INTEGER, '2', null,
            XMLDB_NOTNULL, null, '0', 'difficulty_level');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026061501, 'local', 'unics');
    }

    if ($oldversion < 2026061700) {
        // Кодификатор v1 [[codifier-design]]: дерево элементов содержания + полиморфная
        // привязка контента + сквозная аналитика. Три таблицы.

        // --- unics_codifier (шапка: 1 на дисциплину) ---
        $table = new xmldb_table('unics_codifier');
        $table->add_field('id',                     XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('mdl_category_id',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('name',                   XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description',            XMLDB_TYPE_TEXT,    null,  null, null, null, null);
        $table->add_field('status',                 XMLDB_TYPE_INTEGER, '2',   null, XMLDB_NOTNULL, null, '1');
        $table->add_field('created_by_mdl_user_id', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',           XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ix_codifier_category', XMLDB_INDEX_NOTUNIQUE, ['mdl_category_id', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_codifier_element (дерево self-ref) ---
        $table = new xmldb_table('unics_codifier_element');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('codifier_id',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('parent_id',    XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_field('code',         XMLDB_TYPE_CHAR,    '40',  null, XMLDB_NOTNULL, null, '');
        $table->add_field('title',        XMLDB_TYPE_CHAR,    '510', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ordinal',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('path',         XMLDB_TYPE_CHAR,    '255', null, null, null, null);
        $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',  null, null, null, null);
        $table->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('codifier_id', XMLDB_KEY_FOREIGN, ['codifier_id'], 'unics_codifier', ['id']);
        $table->add_index('ix_element_tree', XMLDB_INDEX_NOTUNIQUE, ['codifier_id', 'parent_id', 'ordinal']);
        $table->add_index('ix_element_path', XMLDB_INDEX_NOTUNIQUE, ['codifier_id', 'path']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_codifier_link (m2m, полиморфная) ---
        $table = new xmldb_table('unics_codifier_link');
        $table->add_field('id',                     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('element_id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('target_type',            XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '1');
        $table->add_field('target_id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weight',                 XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('created_by_mdl_user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated',            XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('element_id', XMLDB_KEY_FOREIGN, ['element_id'], 'unics_codifier_element', ['id']);
        $table->add_index('uq_codifier_link', XMLDB_INDEX_UNIQUE,    ['element_id', 'target_type', 'target_id']);
        $table->add_index('ix_link_target',   XMLDB_INDEX_NOTUNIQUE, ['target_type', 'target_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026061700, 'local', 'unics');
    }

    if ($oldversion < 2026061701) {
        // title элемента кодификатора - в TEXT: описания элементов содержания ФИПИ
        // бывают длиннее 500 символов (напр. развёрнутые формулировки по информатике).
        $table = new xmldb_table('unics_codifier_element');
        $field = new xmldb_field('title', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'code');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026061701, 'local', 'unics');
    }

    if ($oldversion < 2026061702) {
        // Роли v3 фаза 3 [[role-model-v3-2026-06-11]]: делегирование курсов скоупу
        // (муниципалитет/организация). Методист видит для назначения только
        // делегированные его подчинению курсы; региональные роли - все.
        $table = new xmldb_table('unics_course_delegation');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scope_type',  XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scope_id',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usercreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('uq_delegation',       XMLDB_INDEX_UNIQUE,    ['courseid', 'scope_type', 'scope_id']);
        $table->add_index('ix_delegation_scope', XMLDB_INDEX_NOTUNIQUE, ['scope_type', 'scope_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026061702, 'local', 'unics');
    }

    if ($oldversion < 2026061703) {
        // G5 - фиксы прав ролей по аудиту [[role-capability-audit-2026-06-17]].
        require_once(__DIR__ . '/../classes/role_manager.php');

        // A. Методист (methodist) и муниципальный методист (district_methodist, наследует
        //    methodist) больше НЕ создают и НЕ редактируют курсы: контент-строительные
        //    capability перенесены из allow в prevent в role_manager::get_matrix().
        //    Переприменяем матрицу - prevent перезаписывает прежние ALLOW в role_capabilities.
        try {
            \local_unics\role_manager::apply_matrix();
            \local_unics\role_manager::apply_role_names();
        } catch (\Throwable $e) {
            debugging('local_unics G5: apply_matrix() failed during upgrade: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }

        // B. Удаляем осиротевшие мощные Moodle-роли municipal_admin и org_admin.
        //    Обе с архетипом manager и local/unics:manage=ALLOW (полная власть над плагином),
        //    но никому не назначены (district_admin слит в district_methodist в v3 фазе 1).
        //    Безопасность: удаляем только если назначений 0 - иначе оставляем и логируем,
        //    чтобы не лишить пользователей роли по ошибке. delete_role снимает остаточные
        //    capability и contextlevels.
        foreach (['municipal_admin', 'org_admin'] as $orphan) {
            $role = $DB->get_record('role', ['shortname' => $orphan], 'id');
            if (!$role) {
                continue;
            }
            $assigned = $DB->count_records('role_assignments', ['roleid' => $role->id]);
            if ($assigned > 0) {
                debugging("local_unics G5: роль {$orphan} имеет {$assigned} назначений - "
                    . 'удаление пропущено (требуется ручная проверка).', DEBUG_DEVELOPER);
                mtrace("local_unics G5: роль {$orphan} НЕ удалена - есть назначения ({$assigned}).");
                continue;
            }
            delete_role($role->id);
            mtrace("local_unics G5: осиротевшая Moodle-роль {$orphan} удалена.");
        }

        upgrade_plugin_savepoint(true, 2026061703, 'local', 'unics');
    }

    if ($oldversion < 2026062002) {
        // ИИ-адаптив S1 [[adaptive-ai-design]] / [[adaptive-s1-plan]]: владение по навыкам.
        // Три таблицы + element_id у шага ИОМ.

        // --- unics_skill_mastery (ядро: строка на пару ученик+элемент) ---
        $table = new xmldb_table('unics_skill_mastery');
        $table->add_field('id',                XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
        $table->add_field('element_id',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
        $table->add_field('score',             XMLDB_TYPE_NUMBER,  '5, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('band',              XMLDB_TYPE_INTEGER, '2',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attempts_n',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_field('last_score',        XMLDB_TYPE_NUMBER,  '5, 2', null, null, null, null);
        $table->add_field('last_attempt_cmid', XMLDB_TYPE_INTEGER, '10',   null, null, null, null);
        $table->add_field('updated_at',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_key('element_id', XMLDB_KEY_FOREIGN, ['element_id'], 'unics_codifier_element', ['id']);
        $table->add_index('uq_mastery_student_element', XMLDB_INDEX_UNIQUE,    ['student_id', 'element_id']);
        $table->add_index('ix_mastery_element_band',    XMLDB_INDEX_NOTUNIQUE, ['element_id', 'band']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_mastery_history (append-only лог смен) ---
        $table = new xmldb_table('unics_mastery_history');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
        $table->add_field('element_id',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, null);
        $table->add_field('old_score',    XMLDB_TYPE_NUMBER,  '5, 2', null, null, null, null);
        $table->add_field('new_score',    XMLDB_TYPE_NUMBER,  '5, 2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('old_band',     XMLDB_TYPE_INTEGER, '2',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('new_band',     XMLDB_TYPE_INTEGER, '2',    null, XMLDB_NOTNULL, null, '0');
        $table->add_field('trigger_cmid', XMLDB_TYPE_INTEGER, '10',   null, null, null, null);
        $table->add_field('changed_at',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_key('element_id', XMLDB_KEY_FOREIGN, ['element_id'], 'unics_codifier_element', ['id']);
        $table->add_index('ix_masthist_student', XMLDB_INDEX_NOTUNIQUE, ['student_id', 'changed_at']);
        $table->add_index('ix_masthist_element', XMLDB_INDEX_NOTUNIQUE, ['element_id', 'changed_at']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_adaptive_suggestion (очередь гейта; CRUD в S1, применение в S2) ---
        $table = new xmldb_table('unics_adaptive_suggestion');
        $table->add_field('id',               XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('element_id',       XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('kind',             XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, null);
        $table->add_field('payload',          XMLDB_TYPE_TEXT,    null, null, null, null, null);
        $table->add_field('status',           XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('auto_apply_after', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('created_at',       XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('decided_by',       XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('decided_at',       XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('rationale',        XMLDB_TYPE_TEXT,    null, null, null, null, null);
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_index('ix_sugg_student_status', XMLDB_INDEX_NOTUNIQUE, ['student_id', 'status']);
        $table->add_index('ix_sugg_open',           XMLDB_INDEX_NOTUNIQUE, ['status', 'auto_apply_after']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // --- unics_path_step += element_id (навык-причина adaptive-шага) ---
        // Только поле; soft-FK (индекс) объявлен в install.xml для свежих установок,
        // на живом стенде он не обязателен (в S1 по element_id шага не запрашиваем).
        $table = new xmldb_table('unics_path_step');
        $field = new xmldb_field('element_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'target_level');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062002, 'local', 'unics');
    }

    if ($oldversion < 2026062200) {
        // unics_item_irt - параметры заданий IRT (Rasch b).
        $table = new xmldb_table('unics_item_irt');
        $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('item_ref',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('element_id',   XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('model',        XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, 'rasch');
        $table->add_field('a',            XMLDB_TYPE_NUMBER, '6, 4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('b',            XMLDB_TYPE_NUMBER, '6, 4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('c',            XMLDB_TYPE_NUMBER, '6, 4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('calibrated_n', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('updated_at',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('uq_item_irt_ref', XMLDB_INDEX_UNIQUE, ['item_ref']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // theta/theta_se в unics_skill_mastery (nullable; rolling_avg-строки не затронуты).
        $table = new xmldb_table('unics_skill_mastery');
        $field = new xmldb_field('theta', XMLDB_TYPE_NUMBER, '7, 4', null, null, null, null, 'updated_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('theta_se', XMLDB_TYPE_NUMBER, '7, 4', null, null, null, null, 'theta');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062200, 'local', 'unics');
    }

    if ($oldversion < 2026062400) {
        // unics_cat_session - сессия адаптивной проверки (CAT) по одному навыку.
        $table = new xmldb_table('unics_cat_session');
        $table->add_field('id',                 XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('student_id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('element_id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('qubaid',             XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('status',             XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('theta',              XMLDB_TYPE_NUMBER, '7, 4', null, null, null, null);
        $table->add_field('theta_se',           XMLDB_TYPE_NUMBER, '7, 4', null, null, null, null);
        $table->add_field('items_administered', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('started_at',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('finished_at',        XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('student_id', XMLDB_KEY_FOREIGN, ['student_id'], 'unics_students', ['id']);
        $table->add_key('element_id', XMLDB_KEY_FOREIGN, ['element_id'], 'unics_codifier_element', ['id']);
        $table->add_index('ix_cat_student_status', XMLDB_INDEX_NOTUNIQUE, ['student_id', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // unics_cat_step - лог шагов сессии (append-only): траектория theta + размеченные данные для ML.
        $table = new xmldb_table('unics_cat_step');
        $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('session_id',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slot',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('item_ref',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('correct',     XMLDB_TYPE_INTEGER, '2',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('theta_after', XMLDB_TYPE_NUMBER, '7, 4', null, null, null, null);
        $table->add_field('se_after',    XMLDB_TYPE_NUMBER, '7, 4', null, null, null, null);
        $table->add_field('created_at',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('session_id',  XMLDB_KEY_FOREIGN, ['session_id'], 'unics_cat_session', ['id']);
        $table->add_index('ix_catstep_session', XMLDB_INDEX_NOTUNIQUE, ['session_id', 'created_at']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026062400, 'local', 'unics');
    }

    if ($oldversion < 2026070100) {
        // Направление C: описание/компетенции элемента кодификатора.
        $table = new xmldb_table('unics_codifier_element');
        $field = new xmldb_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null, 'title');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026070100, 'local', 'unics');
    }

    return true;
}
