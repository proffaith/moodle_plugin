<?php
// Notify ProfFaith when a student saves a draft or submits an assignment, so the
// AI writing-feedback pipeline can pull the submission and push feedback back.
// Fire-and-forget: a webhook failure must never block the student's save.
namespace local_proffaith;

defined('MOODLE_INTERNAL') || die();

class observer {

    /** Student saved submission content (draft, or as part of submitting). */
    public static function submission_uploaded(\core\event\base $event): void {
        self::notify($event, 'draft_saved');
    }

    /** Student submitted the assignment for grading. */
    public static function submission_submitted(\core\event\base $event): void {
        self::notify($event, 'submitted');
    }

    /** POST the webhook to ProfFaith (best-effort). */
    private static function notify(\core\event\base $event, string $label): void {
        global $CFG;
        $url = trim((string) get_config('local_proffaith', 'webhook_url'));
        if ($url === '') {
            return;
        }
        $secret = (string) get_config('local_proffaith', 'webhook_secret');

        $cmid = (int) $event->contextinstanceid;
        $assignid = 0;
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $assignid = (int) $cm->instance;
        }

        $payload = json_encode([
            'event' => $label,
            'issuer' => rtrim($CFG->wwwroot, '/'),
            'cmid' => $cmid,
            'assignid' => $assignid,
            'courseid' => (int) $event->courseid,
            'userid' => (int) ($event->relateduserid ?: $event->userid),
            'timestamp' => time(),
        ]);

        $headers = ['Content-Type: application/json'];
        if ($secret !== '') {
            $headers[] = 'X-ProfFaith-Signature: sha256=' . hash_hmac('sha256', $payload, $secret);
        }

        try {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader($headers);
            $curl->post($url, $payload, [
                'CURLOPT_TIMEOUT' => 5,
                'CURLOPT_CONNECTTIMEOUT' => 3,
            ]);
        } catch (\Throwable $e) {
            debugging('local_proffaith webhook POST failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
