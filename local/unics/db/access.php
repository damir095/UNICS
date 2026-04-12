<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/unics:manage' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
