<?php
// Dev helper: enrol a user into a course as a student (manual enrolment).
// Usage: php enrol_user.php <courseid> <userid>
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/enrollib.php');

$courseid = isset($argv[1]) ? (int) $argv[1] : 0;
$userid = isset($argv[2]) ? (int) $argv[2] : 0;
if (!$courseid || !$userid) {
    cli_error('Usage: php enrol_user.php <courseid> <userid>');
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$plugin = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
if (!$instance) {
    $instanceid = $plugin->add_instance($course);
    $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
}
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$plugin->enrol_user($instance, $userid, $studentroleid);

echo "Enrolled user {$userid} as student in course {$courseid}\n";
