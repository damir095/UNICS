<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/unics/index.php'));
$PAGE->set_title('УНИКС');
$PAGE->set_heading('Управление системой УНИКС');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

echo '<div class="list-group" style="max-width:400px">';
echo '<a href="pages/users.php" class="list-group-item list-group-item-action">
    Пользователи
</a>';
echo '<a href="pages/organizations.php" class="list-group-item list-group-item-action">
    Организации и районы
</a>';
echo '<a href="pages/assign.php" class="list-group-item list-group-item-action">
    Привязки педагог / родитель → учащийся
</a>';
echo '</div>';

echo $OUTPUT->footer();
