<?php
// TEMP fixture: a faculty teacher (matched by email) + an existing "SIS" course
// with pre-existing content, to test list_teacher_courses + target-mode push.
// Usage: php setup_test_section.php <email>
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/enrollib.php');

// CLI has no logged-in user; the draft file area (intro editor) needs one.
\core\session\manager::set_user(get_admin());

$email = isset($argv[1]) ? trim($argv[1]) : '';
if ($email === '') {
    cli_error('Usage: php setup_test_section.php <email>');
}

// 1. Teacher user matched by email (create or reuse).
$user = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MULTIPLE);
if (!$user) {
    $u = new stdClass();
    $u->auth = 'manual';
    $u->confirmed = 1;
    $u->mnethostid = $CFG->mnet_localhost_id;
    $u->username = 'pf_teacher_' . substr(md5($email), 0, 8);
    $u->email = $email;
    $u->firstname = 'ProfFaith';
    $u->lastname = 'Teacher';
    $u->id = user_create_user($u, false, false);
    $user = $DB->get_record('user', ['id' => $u->id]);
    echo "Created teacher user id={$user->id} ({$email})\n";
} else {
    echo "Reusing teacher user id={$user->id} ({$email})\n";
}

// 2. An existing "SIS-provisioned" course (idempotent on idnumber).
$idnumber = 'SIS-LGST103-001-F26';
$course = $DB->get_record('course', ['idnumber' => $idnumber]);
if (!$course) {
    $course = create_course((object) [
        'fullname' => 'LGST 103 Section 001 (Fall 2026)',
        'shortname' => 'LGST103-001-F26',
        'idnumber' => $idnumber,
        'category' => 1,
        'format' => 'topics',
        'numsections' => 3,
        'visible' => 1,
        'summary' => 'Roster-provisioned section (simulated SIS).',
        'summaryformat' => FORMAT_HTML,
    ]);
    echo "Created SIS course id={$course->id} ({$idnumber})\n";
} else {
    echo "Reusing SIS course id={$course->id} ({$idnumber})\n";
}

// Pre-existing, non-ProfFaith content that MUST survive a ProfFaith push (idempotent).
if (!$DB->record_exists('course_modules', ['course' => $course->id, 'idnumber' => 'sis:welcome'])) {
    $mi = new stdClass();
    $mi->modulename = 'label';
    $mi->course = $course->id;
    $mi->section = 1;
    $mi->visible = 1;
    $mi->name = 'SIS welcome';
    $mi->cmidnumber = 'sis:welcome';   // NOT a proffaith: idnumber
    $mi->introeditor = ['text' => '<p>Welcome from the registrar — do not delete.</p>',
        'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
    create_module($mi);
    rebuild_course_cache($course->id, true);
    echo "  + added pre-existing SIS label in section 1\n";
}

// 3. Enrol the teacher as editing teacher.
$plugin = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
if (!$instance) {
    $instanceid = $plugin->add_instance($DB->get_record('course', ['id' => $course->id]));
    $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
}
$teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
$plugin->enrol_user($instance, $user->id, $teacherroleid);
echo "Enrolled user {$user->id} as editingteacher in course {$course->id}\n";
echo "TARGET_COURSE_ID={$course->id}\n";
