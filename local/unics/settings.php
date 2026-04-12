<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('root', new admin_externalpage(
        'local_unics',
        get_string('pluginname', 'local_unics'),
        new moodle_url('/local/unics/pages/users.php'),
        'local/unics:manage'
    ));
}
