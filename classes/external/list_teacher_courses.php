<?php
// List the Moodle courses a faculty member teaches, so ProfFaith can let them
// target one of THEIR sections (SIS-provisioned courses) rather than the whole
// site catalogue. Matched to a Moodle user by email (preferred) or username.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class list_teacher_courses extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_RAW_TRIMMED, 'Faculty email to match a Moodle user', VALUE_DEFAULT, ''),
            'username' => new external_value(PARAM_RAW_TRIMMED, 'Faculty username (fallback)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @return array{matched:int,userid:int,courses:array}
     */
    public static function execute($email, $username): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/accesslib.php');

        $params = self::validate_parameters(self::execute_parameters(),
            ['email' => $email, 'username' => $username]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/course:create', $systemcontext);

        // Resolve the Moodle user by email (preferred), else username.
        $user = null;
        if ($params['email'] !== '') {
            $user = $DB->get_record('user',
                ['email' => $params['email'], 'deleted' => 0], '*', IGNORE_MULTIPLE);
        }
        if (!$user && $params['username'] !== '') {
            $user = $DB->get_record('user', ['username' => $params['username'], 'deleted' => 0]);
        }
        if (!$user) {
            return ['matched' => 0, 'userid' => 0, 'courses' => []];
        }

        // Courses where this user can edit activities (editing teacher / manager).
        // $doanything = false so a site admin doesn't match every course.
        $courses = get_user_capability_course('moodle/course:manageactivities', $user->id,
            false, 'fullname,shortname,idnumber,category', 'fullname ASC');

        $out = [];
        if ($courses) {
            foreach ($courses as $c) {
                if ((int) $c->id === (int) SITEID) {
                    continue;
                }
                $out[] = [
                    'id' => (int) $c->id,
                    'fullname' => format_string($c->fullname),
                    'shortname' => (string) $c->shortname,
                    'idnumber' => (string) $c->idnumber,
                ];
            }
        }
        return ['matched' => 1, 'userid' => (int) $user->id, 'courses' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'matched' => new external_value(PARAM_INT, '1 if a Moodle user matched'),
            'userid' => new external_value(PARAM_INT, 'Matched Moodle user id (0 if none)'),
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Moodle course id'),
                'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                'idnumber' => new external_value(PARAM_RAW, 'Course idnumber'),
            ])),
        ]);
    }
}
