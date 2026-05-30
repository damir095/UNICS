<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_unics\observer::course_module_deleted',
    ],
    [
        // B7: реагируем на attempt_graded (грейд уже посчитан), не на attempt_submitted.
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback'  => '\local_unics\observer::quiz_attempt_graded',
    ],
    [
        // Чистка пересдач + осиротевших метаданных при удалении курса.
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_unics\observer::course_deleted',
    ],
];
