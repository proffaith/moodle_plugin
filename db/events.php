<?php
// Event observers: tell ProfFaith when a student saves a draft (online text or
// file) or submits an assignment for grading, so AI writing feedback can run.
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\assignsubmission_onlinetext\\event\\assessable_uploaded',
        'callback'  => '\\local_proffaith\\observer::submission_uploaded',
    ],
    [
        'eventname' => '\\assignsubmission_file\\event\\assessable_uploaded',
        'callback'  => '\\local_proffaith\\observer::submission_uploaded',
    ],
    [
        'eventname' => '\\mod_assign\\event\\assessable_submitted',
        'callback'  => '\\local_proffaith\\observer::submission_submitted',
    ],
];
