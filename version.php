<?php
// ProfFaith provisioning plugin — creates Moodle courses (with gradeable LTI
// activities bound to the ProfFaith tool) from a pushed course design.
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_proffaith';
$plugin->version   = 2026100400;
$plugin->requires  = 2024100700;   // Moodle 4.5+
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.12.0';    // + paid prep access: sync_access / list_access, product cohorts, expiry backstop
