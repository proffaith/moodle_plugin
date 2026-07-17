<?php
// List the discussion forums in a course (the D2L "forums/topics" equivalent)
// so ProfFaith's discussion-evaluation tool can offer a topic picker.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class list_forums extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
        ]);
    }

    /**
     * @return array{courseid:int,forums:array}
     */
    public static function execute($courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('mod/forum:viewdiscussion', $context);

        $out = [];
        foreach ($DB->get_records('forum', ['course' => $course->id], 'name') as $forum) {
            $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $out[] = [
                'cmid' => (int) $cm->id,
                'forumid' => (int) $forum->id,
                'name' => (string) $forum->name,
                'type' => (string) $forum->type,
                'discussion_count' => (int) $DB->count_records('forum_discussions', ['forum' => $forum->id]),
            ];
        }

        return ['courseid' => (int) $course->id, 'forums' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'forums' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'forumid' => new external_value(PARAM_INT, 'Forum instance id (use with get_forum_posts)'),
                'name' => new external_value(PARAM_RAW, 'Forum name'),
                'type' => new external_value(PARAM_ALPHANUMEXT, "Forum type ('news' = announcements)"),
                'discussion_count' => new external_value(PARAM_INT, 'Number of discussions'),
            ])),
        ]);
    }
}
