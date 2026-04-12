<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/user_manager.php');
require_once(__DIR__ . '/../forms/create_user_form.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/unics/pages/create_user.php'));
$PAGE->set_title(get_string('create_user', 'local_unics'));
$PAGE->set_heading(get_string('pluginname', 'local_unics'));
$PAGE->set_pagelayout('admin');

$form = new unics_create_user_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/unics/pages/users.php'));

} else if ($data = $form->get_data()) {
    try {
        unics_user_manager::create_user((array)$data);
        redirect(
            new moodle_url('/local/unics/pages/users.php'),
            get_string('user_created', 'local_unics'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (Exception $e) {
        redirect(
            new moodle_url('/local/unics/pages/create_user.php'),
            get_string('user_create_error', 'local_unics') . ': ' . $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('create_user', 'local_unics'));
$form->display();
echo $OUTPUT->footer();
