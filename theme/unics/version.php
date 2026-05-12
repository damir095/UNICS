<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'theme_unics';
$plugin->version   = 2026051200;
$plugin->requires  = 2025041400; // Moodle 5.0.
$plugin->release   = '0.6.0';
$plugin->maturity  = MATURITY_ALPHA;

$plugin->dependencies = [
    'theme_boost' => 2025041400,
];
