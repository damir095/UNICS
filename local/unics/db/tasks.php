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
];
