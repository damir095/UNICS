<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/unics:manage', context_system::instance());

redirect(new moodle_url('/local/unics/pages/users.php'));
