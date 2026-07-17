<?php
// Web-service function + service for ProfFaith course provisioning.
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_proffaith_provision_course' => [
        'classname'    => 'local_proffaith\external\provision_course',
        'methodname'   => 'execute',
        'description'  => 'Create or update a Moodle course from a ProfFaith course design, '
                        . 'including ALWD activities as gradeable LTI links.',
        'type'         => 'write',
        'capabilities' => 'moodle/course:create,moodle/course:manageactivities',
        'ajax'         => false,
    ],
    'local_proffaith_list_teacher_courses' => [
        'classname'    => 'local_proffaith\external\list_teacher_courses',
        'methodname'   => 'execute',
        'description'  => 'List the Moodle courses a faculty member (matched by email/username) '
                        . 'can edit, so they can target one of their own sections.',
        'type'         => 'read',
        'capabilities' => 'moodle/course:create',
        'ajax'         => false,
    ],
    'local_proffaith_get_submissions' => [
        'classname'    => 'local_proffaith\external\get_submissions',
        'methodname'   => 'execute',
        'description'  => 'List assignment submissions (status, files, online text, student identity) '
                        . 'so ProfFaith can evaluate student writing.',
        'type'         => 'read',
        'capabilities' => 'mod/assign:viewgrades',
        'ajax'         => false,
    ],
    'local_proffaith_save_feedback' => [
        'classname'    => 'local_proffaith\external\save_feedback',
        'methodname'   => 'execute',
        'description'  => 'Write an HTML feedback comment (and optional grade) back onto a student submission.',
        'type'         => 'write',
        'capabilities' => 'mod/assign:grade',
        'ajax'         => false,
    ],
    'local_proffaith_list_assignments' => [
        'classname'    => 'local_proffaith\\external\\list_assignments',
        'methodname'   => 'execute',
        'description'  => 'List a course\'s assignments (name, due date, submitted count) for the faculty-tools picker.',
        'type'         => 'read',
        'capabilities' => 'mod/assign:viewgrades',
        'ajax'         => false,
    ],
    'local_proffaith_list_forums' => [
        'classname'    => 'local_proffaith\\external\\list_forums',
        'methodname'   => 'execute',
        'description'  => 'List a course\'s discussion forums for the discussion-evaluation picker.',
        'type'         => 'read',
        'capabilities' => 'mod/forum:viewdiscussion',
        'ajax'         => false,
    ],
    'local_proffaith_get_forum_posts' => [
        'classname'    => 'local_proffaith\\external\\get_forum_posts',
        'methodname'   => 'execute',
        'description'  => 'Pull forum posts with author identity + threading so ProfFaith can pseudonymize before evaluation.',
        'type'         => 'read',
        'capabilities' => 'mod/forum:viewdiscussion',
        'ajax'         => false,
    ],
    'local_proffaith_get_grades' => [
        'classname'    => 'local_proffaith\\external\\get_grades',
        'methodname'   => 'execute',
        'description'  => 'Read gradebook items + per-student final grades for messaging rules and course insights.',
        'type'         => 'read',
        'capabilities' => 'moodle/grade:viewall',
        'ajax'         => false,
    ],
    'local_proffaith_post_announcement' => [
        'classname'    => 'local_proffaith\\external\\post_announcement',
        'methodname'   => 'execute',
        'description'  => 'Post an announcement to the course news forum (messaging announcements channel).',
        'type'         => 'write',
        'capabilities' => 'mod/forum:addnews',
        'ajax'         => false,
    ],
];

$services = [
    'ProfFaith provisioning' => [
        'functions'       => ['local_proffaith_provision_course', 'local_proffaith_list_teacher_courses',
                              'local_proffaith_get_submissions', 'local_proffaith_save_feedback',
                              'local_proffaith_list_assignments', 'local_proffaith_list_forums',
                              'local_proffaith_get_forum_posts', 'local_proffaith_get_grades',
                              'local_proffaith_post_announcement'],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'proffaith_provisioning',
        'downloadfiles'   => 0,
        'uploadfiles'     => 1,  // S3-file readings upload via webservice/upload.php
    ],
];
