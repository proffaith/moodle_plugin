<?php
// List the assignments in a course (the D2L "dropbox folders" equivalent) so
// ProfFaith's faculty tools can offer a folder picker and track review
// progress per assignment.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class list_assignments extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
        ]);
    }

    /**
     * @return array{courseid:int,assignments:array}
     */
    public static function execute($courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('mod/assign:viewgrades', $context);

        $out = [];
        foreach ($DB->get_records('assign', ['course' => $course->id], 'name') as $assign) {
            $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $out[] = [
                'cmid' => (int) $cm->id,
                'assignid' => (int) $assign->id,
                'name' => (string) $assign->name,
                'duedate' => (int) $assign->duedate,
                'submitted_count' => (int) $DB->count_records('assign_submission', [
                    'assignment' => $assign->id, 'status' => 'submitted', 'latest' => 1,
                ]),
            ];
        }

        return ['courseid' => (int) $course->id, 'assignments' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'assignments' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id (use with get_submissions)'),
                'assignid' => new external_value(PARAM_INT, 'Assignment instance id'),
                'name' => new external_value(PARAM_RAW, 'Assignment name'),
                'duedate' => new external_value(PARAM_INT, 'Due date unix time (0 = none)'),
                'submitted_count' => new external_value(PARAM_INT, 'Latest submissions with status=submitted'),
            ])),
        ]);
    }
}
