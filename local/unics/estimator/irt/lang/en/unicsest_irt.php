<?php
defined('MOODLE_INTERNAL') || die();

// Английский - мастер-язык Moodle: без него строка не резолвится и в списке оценщиков
// показывается [[pluginname]] (поймано живой проверкой 2026-08-14).
$string['pluginname'] = 'IRT via Python service (Rasch model)';
$string['privacy:metadata'] = 'The mastery estimator subplugin stores no personal data: '
    . 'only numeric item parameters and correctness flags are sent to the external service.';
