<?php
// ProfFaith provisioning plugin — creates Moodle courses (with gradeable LTI
// activities bound to the ProfFaith tool) from a pushed course design.
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_proffaith';
$plugin->version   = 2026061408;
$plugin->requires  = 2024100700;   // Moodle 4.5+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.7.0';     // + legacy manual-builder quizzes (CSV → mod_quiz)
