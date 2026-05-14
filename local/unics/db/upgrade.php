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

    return true;
}
