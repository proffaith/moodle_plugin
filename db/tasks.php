<?php
// Scheduled tasks for local_proffaith.
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_proffaith\task\expire_access',
        'blocking'  => 0,
        'minute'    => 'R',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
