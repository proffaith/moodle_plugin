<?php
// Write an HTML feedback comment (and optionally a grade) back onto a student's
// submission, so ProfFaith's AI feedback lands in Moodle's native feedback
// channel. Delegates to core mod_assign save_grade (assignfeedback_comments).
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class save_feedback extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Assignment course module id (preferred)', VALUE_DEFAULT, 0),
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id (alternative)', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'Student user id'),
            'feedbackhtml' => new external_value(PARAM_RAW, 'Feedback comment HTML'),
            'grade' => new external_value(PARAM_FLOAT, 'Numeric grade, or -1 to leave unset', VALUE_DEFAULT, -1),
            'workflowstate' => new external_value(PARAM_ALPHA, "Marking workflow state (e.g. 'released'); blank to leave", VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @return array{status:int}
     */
    public static function execute($cmid, $assignid, $userid, $feedbackhtml, $grade, $workflowstate): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/externallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'assignid' => $assignid, 'userid' => $userid,
            'feedbackhtml' => $feedbackhtml, 'grade' => $grade, 'workflowstate' => $workflowstate,
        ]);

        if ($params['cmid']) {
            $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
            $assignid = (int) $cm->instance;
        } else if ($params['assignid']) {
            $cm = get_coursemodule_from_instance('assign', $params['assignid'], 0, false, MUST_EXIST);
            $assignid = (int) $params['assignid'];
        } else {
            throw new \invalid_parameter_exception('Either cmid or assignid is required');
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $plugindata = [
            'assignfeedbackcomments_editor' => [
                'text' => (string) $params['feedbackhtml'],
                'format' => FORMAT_HTML,
            ],
        ];

        // attemptnumber -1 = latest; addattempt 0; applytoall 0. Core handles the
        // feedback-plugin save and (if marking workflow is on) the workflow state.
        \mod_assign_external::save_grade(
            $assignid, (int) $params['userid'], (float) $params['grade'],
            -1, 0, (string) $params['workflowstate'], 0, $plugindata);

        return ['status' => 1];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 on success'),
        ]);
    }
}
