<?php
// Read a course's gradebook (items + per-student final grades, with student
// identity for roster correlation) — the D2L "grade objects / grade values"
// equivalent — for ProfFaith's messaging rules and course-insights aggregates.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class get_grades extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'userid' => new external_value(PARAM_INT, 'Filter to one student (0 = all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @return array{courseid:int,items:array,grades:array}
     */
    public static function execute($courseid, $userid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'userid' => $userid]);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        $items = [];
        foreach ($DB->get_records('grade_items', ['courseid' => $course->id], 'sortorder') as $gi) {
            $items[] = [
                'itemid' => (int) $gi->id,
                'name' => (string) ($gi->itemname ?? ''),
                'itemtype' => (string) $gi->itemtype,     // course | category | mod | manual
                'itemmodule' => (string) ($gi->itemmodule ?? ''),
                'iteminstance' => (int) ($gi->iteminstance ?? 0),
                'grademax' => (float) $gi->grademax,
            ];
        }

        $sql = "SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.rawgrademax,
                       u.idnumber, u.email, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = :courseid
                  JOIN {user} u ON u.id = gg.userid";
        $sqlparams = ['courseid' => $course->id];
        if ($params['userid']) {
            $sql .= " WHERE gg.userid = :userid";
            $sqlparams['userid'] = (int) $params['userid'];
        }

        $grades = [];
        foreach ($DB->get_records_sql($sql, $sqlparams) as $gg) {
            $grades[] = [
                'itemid' => (int) $gg->itemid,
                'userid' => (int) $gg->userid,
                'idnumber' => (string) $gg->idnumber,
                'email' => (string) $gg->email,
                'fullname' => fullname($gg),
                'finalgrade' => $gg->finalgrade === null ? -1.0 : (float) $gg->finalgrade,
            ];
        }

        return ['courseid' => (int) $course->id, 'items' => $items, 'grades' => $grades];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'items' => new external_multiple_structure(new external_single_structure([
                'itemid' => new external_value(PARAM_INT, 'Grade item id'),
                'name' => new external_value(PARAM_RAW, 'Item name (blank for course total)'),
                'itemtype' => new external_value(PARAM_ALPHANUMEXT, 'course|category|mod|manual'),
                'itemmodule' => new external_value(PARAM_RAW, "Module name for itemtype=mod (e.g. 'assign')"),
                'iteminstance' => new external_value(PARAM_INT, 'Module instance id for itemtype=mod'),
                'grademax' => new external_value(PARAM_FLOAT, 'Maximum grade'),
            ])),
            'grades' => new external_multiple_structure(new external_single_structure([
                'itemid' => new external_value(PARAM_INT, 'Grade item id'),
                'userid' => new external_value(PARAM_INT, 'Student user id'),
                'idnumber' => new external_value(PARAM_RAW, 'Student idnumber'),
                'email' => new external_value(PARAM_RAW, 'Student email'),
                'fullname' => new external_value(PARAM_RAW, 'Student full name'),
                'finalgrade' => new external_value(PARAM_FLOAT, 'Final grade (-1 = ungraded)'),
            ])),
        ]);
    }
}
