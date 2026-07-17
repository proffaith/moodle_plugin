<?php
// Post an announcement to the course news forum — the D2L "create news item"
// equivalent — used by ProfFaith's messaging tool's announcements channel.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class post_announcement extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id'),
            'subject' => new external_value(PARAM_RAW, 'Announcement subject'),
            'messagehtml' => new external_value(PARAM_RAW, 'Announcement body HTML'),
        ]);
    }

    /**
     * @return array{discussionid:int,forumid:int}
     */
    public static function execute($courseid, $subject, $messagehtml): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'subject' => $subject, 'messagehtml' => $messagehtml,
        ]);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        // forum_get_course_forum creates the news forum if the course lacks one.
        $forum = forum_get_course_forum($course->id, 'news');
        if (!$forum) {
            throw new \moodle_exception('cannotfindorcreateforum', 'forum');
        }
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/forum:addnews', $context);

        $discussion = new \stdClass();
        $discussion->course = $course->id;
        $discussion->forum = $forum->id;
        $discussion->name = trim((string) $params['subject']) ?: get_string('announcements', 'forum');
        $discussion->message = (string) $params['messagehtml'];
        $discussion->messageformat = FORMAT_HTML;
        $discussion->messagetrust = 0;
        $discussion->attachments = null;
        $discussion->mailnow = 0;
        $discussion->timestart = 0;
        $discussion->timeend = 0;
        $discussion->pinned = FORUM_DISCUSSION_UNPINNED;

        $mform = null;
        $discussionid = forum_add_discussion($discussion, $mform);
        if (!$discussionid) {
            throw new \moodle_exception('couldnotadd', 'forum');
        }

        return ['discussionid' => (int) $discussionid, 'forumid' => (int) $forum->id];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'discussionid' => new external_value(PARAM_INT, 'Created discussion id'),
            'forumid' => new external_value(PARAM_INT, 'News forum instance id'),
        ]);
    }
}
