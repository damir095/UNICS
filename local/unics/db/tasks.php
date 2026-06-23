<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'  => 'local_unics\task\process_ai_queue',
        'blocking'   => 0,
        'minute'     => '*',
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
    [
        'classname'  => 'local_unics\task\aggregate_stats',
        'blocking'   => 0,
        'minute'     => '30',
        'hour'       => '3',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
    [
        'classname'  => 'local_unics\task\auto_apply_suggestions',
        'blocking'   => 0,
        'minute'     => '15',
        'hour'       => '*',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
    [
        'classname'  => 'local_unics\task\generate_adaptive_explanations',
        'blocking'   => 0,
        'minute'     => '*/5',
        'hour'       => '*',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
    [
        'classname'  => 'local_unics\task\calibrate_irt',
        'blocking'   => 0,
        'minute'     => '45',
        'hour'       => '3',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
];
