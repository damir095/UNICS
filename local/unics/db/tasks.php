<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'  => 'local_unics\task\process_ai_queue',
        'blocking'   => 0,
        'minute'     => '*/5',
        'hour'       => '*',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
    [
        'classname'  => 'local_unics\task\evaluate_adaptive_levels',
        'blocking'   => 0,
        'minute'     => '0',
        'hour'       => '2',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
];
