<?php
// Sign-in handoff from ProfFaith. A buyer arrives with a single-use opaque
// code (?code=…); this page redeems it with ProfFaith over a back channel
// signed with the shared HMAC secret, then logs the buyer in and sends them to
// their prep course. Nothing readable travels in the URL, and the code is dead
// after one redemption or ~2 minutes.
//
// Buyers only: site admins and anyone holding a teaching or managing role are
// refused, so a ProfFaith account can never become a privileged Moodle session.

// phpcs:ignore moodle.Files.RequireLogin.Missing -- this page IS the login.
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$code = required_param('code', PARAM_RAW_TRIMMED);

$PAGE->set_url(new moodle_url('/local/proffaith/sso.php'));
$PAGE->set_context(context_system::instance());

$base = rtrim((string) get_config('local_proffaith', 'proffaith_url'), '/');
if ($base === '') {
    $base = 'https://proffaith.com';
}
$secret = (string) get_config('local_proffaith', 'webhook_secret');
$backurl = $base . '/books';

/** Send the buyer back to ProfFaith with a message; never reveals why in detail. */
$fail = function(string $reason) use ($backurl) {
    debugging('local_proffaith sso refused: ' . $reason, DEBUG_DEVELOPER);
    redirect($backurl, get_string('sso_failed', 'local_proffaith'), null, \core\output\notification::NOTIFY_ERROR);
};

if ($secret === '' || !preg_match('/^[A-Za-z0-9_-]{20,128}$/', $code)) {
    $fail($secret === '' ? 'no shared secret configured' : 'malformed code');
}

// Redeem over the back channel.
$payload = json_encode(['code' => $code, 'issuer' => rtrim($CFG->wwwroot, '/')]);
$curl = new curl();
$curl->setHeader([
    'Content-Type: application/json',
    'X-ProfFaith-Signature: sha256=' . hash_hmac('sha256', $payload, $secret),
]);
$response = $curl->post($base . '/moodle/sso/redeem', $payload, [
    'CURLOPT_TIMEOUT' => 10,
    'CURLOPT_CONNECTTIMEOUT' => 5,
]);
$status = (int) ($curl->get_info()['http_code'] ?? 0);
$data = json_decode((string) $response, true);
if ($status !== 200 || !is_array($data) || empty($data['ok']) || empty($data['pf_user_id'])) {
    $fail('redeem refused (HTTP ' . $status . ')');
}

$user = $DB->get_record('user', [
    'idnumber' => \local_proffaith\access::user_idnumber((int) $data['pf_user_id']),
    'deleted' => 0,
]);
if (!$user || $user->suspended || $user->auth === 'nologin') {
    $fail('no usable Moodle user for this ProfFaith account');
}
if (\local_proffaith\access::is_privileged($user)) {
    $fail('privileged account');
}

// Someone else signed in on this browser: end that session first.
if (isloggedin() && !isguestuser() && (int) $USER->id !== (int) $user->id) {
    require_logout();
}
if (!isloggedin() || isguestuser()) {
    complete_user_login($user);
    \core\session\manager::apply_concurrent_login_limit($user->id, session_id());
}

redirect(\local_proffaith\access::landing_url($user, (string) ($data['product_key'] ?? '')));
