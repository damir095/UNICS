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

    return true;
}
