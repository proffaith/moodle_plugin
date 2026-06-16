<?php
// Pull assignment submissions (status, files, online text, student identity) so
// ProfFaith can evaluate student writing and push feedback back. A thin wrapper
// over core mod_assign get_submissions, reshaped and enriched with user identity
// (idnumber/email/fullname) for LTI-sub correlation.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class get_submissions extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Assignment course module id (preferred)', VALUE_DEFAULT, 0),
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id (alternative)', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHA, "Filter: 'draft' | 'submitted' (blank = all)", VALUE_DEFAULT, ''),
            'userid' => new external_value(PARAM_INT, 'Filter to a single student (0 = all)', VALUE_DEFAULT, 0),
            'since' => new external_value(PARAM_INT, 'Only submissions modified at/after this unix time', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @return array{assignid:int,cmid:int,courseid:int,submissions:array}
     */
    public static function execute($cmid, $assignid, $status, $userid, $since): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/externallib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'assignid' => $assignid, 'status' => $status,
            'userid' => $userid, 'since' => $since,
        ]);

        // Resolve the assignment instance + module context.
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
        require_capability('mod/assign:viewgrades', $context);

        // Let core do the heavy lifting (file URLs, online text, max-attempt logic).
        $raw = \mod_assign_external::get_submissions([$assignid], $params['status'], (int) $params['since'], 0);

        $out = [];
        foreach ($raw['assignments'] as $a) {
            foreach ($a['submissions'] as $s) {
                if ($params['userid'] && (int) $s['userid'] !== (int) $params['userid']) {
                    continue;
                }
                $online = '';
                $files = [];
                foreach (($s['plugins'] ?? []) as $p) {
                    foreach (($p['editorfields'] ?? []) as $ef) {
                        if (($ef['name'] ?? '') === 'onlinetext') {
                            $online = (string) ($ef['text'] ?? '');
                        }
                    }
                    foreach (($p['fileareas'] ?? []) as $fa) {
                        foreach (($fa['files'] ?? []) as $f) {
                            if (!empty($f['fileurl'])) {
                                $files[] = [
                                    'filename' => (string) ($f['filename'] ?? ''),
                                    'fileurl' => (string) $f['fileurl'],
                                    'mimetype' => (string) ($f['mimetype'] ?? ''),
                                ];
                            }
                        }
                    }
                }
                $u = $DB->get_record('user', ['id' => $s['userid']],
                    'id, idnumber, email, firstname, lastname', IGNORE_MISSING);
                $out[] = [
                    'userid' => (int) $s['userid'],
                    'idnumber' => $u ? (string) $u->idnumber : '',
                    'email' => $u ? (string) $u->email : '',
                    'fullname' => $u ? fullname($u) : '',
                    'status' => (string) ($s['status'] ?? ''),
                    'timemodified' => (int) ($s['timemodified'] ?? 0),
                    'onlinetext' => $online,
                    'files' => $files,
                ];
            }
        }

        return [
            'assignid' => $assignid,
            'cmid' => (int) $cm->id,
            'courseid' => (int) $cm->course,
            'submissions' => $out,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assignid' => new external_value(PARAM_INT, 'Assignment instance id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'submissions' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Student user id'),
                'idnumber' => new external_value(PARAM_RAW, 'Student idnumber (for LTI sub correlation)'),
                'email' => new external_value(PARAM_RAW, 'Student email'),
                'fullname' => new external_value(PARAM_RAW, 'Student full name'),
                'status' => new external_value(PARAM_ALPHANUMEXT, 'Submission status (draft|submitted|new|reopened)'),
                'timemodified' => new external_value(PARAM_INT, 'Last modified unix time'),
                'onlinetext' => new external_value(PARAM_RAW, 'Online text submission HTML'),
                'files' => new external_multiple_structure(new external_single_structure([
                    'filename' => new external_value(PARAM_RAW, 'File name'),
                    'fileurl' => new external_value(PARAM_RAW, 'Download URL (append &token=<wstoken>)'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
                ])),
            ])),
        ]);
    }
}
