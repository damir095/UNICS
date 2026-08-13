<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'unicsest_irt';
$plugin->version   = 2026081400;
$plugin->requires  = 2024100700;
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';

$plugin->dependencies = [
    'local_unics' => 2026081301,
];
