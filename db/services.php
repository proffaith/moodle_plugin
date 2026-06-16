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
];

$services = [
    'ProfFaith provisioning' => [
        'functions'       => ['local_proffaith_provision_course', 'local_proffaith_list_teacher_courses',
                              'local_proffaith_get_submissions', 'local_proffaith_save_feedback'],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'proffaith_provisioning',
        'downloadfiles'   => 0,
        'uploadfiles'     => 1,  // S3-file readings upload via webservice/upload.php
    ],
];
