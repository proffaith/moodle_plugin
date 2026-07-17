<?php
// Pull the posts of a forum (optionally one discussion), enriched with author
// identity (idnumber/email/fullname) and parent-post threading, so ProfFaith
// can pseudonymize authors before any LLM evaluation ("Student A37 replied to
// Student B04").
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class get_forum_posts extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Forum course module id (preferred)', VALUE_DEFAULT, 0),
            'forumid' => new external_value(PARAM_INT, 'Forum instance id (alternative)', VALUE_DEFAULT, 0),
            'discussionid' => new external_value(PARAM_INT, 'Filter to one discussion (0 = all)', VALUE_DEFAULT, 0),
            'since' => new external_value(PARAM_INT, 'Only posts modified at/after this unix time', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @return array{forumid:int,cmid:int,courseid:int,posts:array}
     */
    public static function execute($cmid, $forumid, $discussionid, $since): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'forumid' => $forumid,
            'discussionid' => $discussionid, 'since' => $since,
        ]);

        if ($params['cmid']) {
            $cm = get_coursemodule_from_id('forum', $params['cmid'], 0, false, MUST_EXIST);
            $forumid = (int) $cm->instance;
        } else if ($params['forumid']) {
            $cm = get_coursemodule_from_instance('forum', $params['forumid'], 0, false, MUST_EXIST);
            $forumid = (int) $params['forumid'];
        } else {
            throw new \invalid_parameter_exception('Either cmid or forumid is required');
        }
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/forum:viewdiscussion', $context);

        $sql = "SELECT p.id, p.discussion, d.name AS discussionname, p.parent,
                       p.userid, u.idnumber, u.email, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       p.subject, p.message, p.created, p.modified
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                  JOIN {user} u ON u.id = p.userid
                 WHERE d.forum = :forumid";
        $sqlparams = ['forumid' => $forumid];
        if ($params['discussionid']) {
            $sql .= " AND p.discussion = :discussionid";
            $sqlparams['discussionid'] = (int) $params['discussionid'];
        }
        if ($params['since']) {
            $sql .= " AND p.modified >= :since";
            $sqlparams['since'] = (int) $params['since'];
        }
        $sql .= " ORDER BY p.discussion, p.created";

        $out = [];
        foreach ($DB->get_records_sql($sql, $sqlparams) as $p) {
            $out[] = [
                'postid' => (int) $p->id,
                'discussionid' => (int) $p->discussion,
                'discussionname' => (string) $p->discussionname,
                'parentid' => (int) $p->parent,
                'userid' => (int) $p->userid,
                'idnumber' => (string) $p->idnumber,
                'email' => (string) $p->email,
                'fullname' => fullname($p),
                'subject' => (string) $p->subject,
                'message' => (string) $p->message,
                'created' => (int) $p->created,
            ];
        }

        return [
            'forumid' => $forumid,
            'cmid' => (int) $cm->id,
            'courseid' => (int) $cm->course,
            'posts' => $out,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'forumid' => new external_value(PARAM_INT, 'Forum instance id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'posts' => new external_multiple_structure(new external_single_structure([
                'postid' => new external_value(PARAM_INT, 'Post id'),
                'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                'discussionname' => new external_value(PARAM_RAW, 'Discussion (thread) name'),
                'parentid' => new external_value(PARAM_INT, 'Parent post id (0 = thread starter)'),
                'userid' => new external_value(PARAM_INT, 'Author user id'),
                'idnumber' => new external_value(PARAM_RAW, 'Author idnumber (LTI sub correlation)'),
                'email' => new external_value(PARAM_RAW, 'Author email'),
                'fullname' => new external_value(PARAM_RAW, 'Author full name'),
                'subject' => new external_value(PARAM_RAW, 'Post subject'),
                'message' => new external_value(PARAM_RAW, 'Post body HTML'),
                'created' => new external_value(PARAM_INT, 'Created unix time'),
            ])),
        ]);
    }
}
