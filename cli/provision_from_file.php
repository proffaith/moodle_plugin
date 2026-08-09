<?php
// Provision a course from a JSON payload file or URL — the timeout-proof path.
//
// The synchronous web-service provision dies at ~30s on Heroku (router H12 +
// FPM request_terminate_timeout), which a large course exceeds. One-off dynos
// (`heroku run`) have neither limit, so big courses provision cleanly here.
//
// Usage:
//   php public/local/proffaith/cli/provision_from_file.php <payload.json | https-url>
//
// The payload is the exact provision_course structure:
//   {"course": {...}, "tool": {...}, "sections": [...], "target_courseid": 0}
// Any draft_itemid/introattachments_itemid values must have been uploaded via
// webservice/upload.php with the provisioning token first — this script runs
// as the same admin user that token belongs to, so the draft areas resolve.
//
// Prints the same JSON the web service returns; feed it back to ProfFaith
// (scripts/moodle_push_via_cli.py record) so assignment bindings get recorded.

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$src = $argv[1] ?? '';
if ($src === '') {
    fwrite(STDERR, "usage: provision_from_file.php <payload.json path or https URL>\n");
    exit(1);
}
$raw = preg_match('#^https?://#', $src)
    ? download_file_content($src)
    : file_get_contents($src);
if ($raw === false || $raw === '') {
    fwrite(STDERR, "could not read payload from {$src}\n");
    exit(1);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fwrite(STDERR, "payload is not valid JSON\n");
    exit(1);
}

// Act as the admin user — the provisioning WS token's owner — so capability
// checks pass and uploaded draft-area files belong to the acting user.
\core\session\manager::set_user(get_admin());

$result = \local_proffaith\external\provision_course::execute(
    $payload['course'] ?? [],
    $payload['tool'] ?? [],
    $payload['sections'] ?? [],
    (int) ($payload['target_courseid'] ?? 0)
);
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
