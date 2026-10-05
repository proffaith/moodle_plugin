<?php
// English strings for local_proffaith.
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ProfFaith provisioning';

// Webhook settings (assignment AI-feedback integration).
$string['webhook_url'] = 'ProfFaith webhook URL';
$string['webhook_url_desc'] = 'ProfFaith endpoint notified when a student saves a draft or submits an '
    . 'assignment bound to AI feedback (e.g. https://app.proffaith.com/webhooks/moodle/assignment). '
    . 'Leave blank to disable notifications.';
$string['webhook_secret'] = 'Webhook shared secret';
$string['webhook_secret_desc'] = 'Shared secret used to HMAC-sign webhook calls (the X-ProfFaith-Signature '
    . 'header). Must match MOODLE_WEBHOOK_SECRET configured on the ProfFaith side.';

// Privacy.
$string['privacy:metadata:proffaith'] = 'For assignments an instructor binds to AI feedback, submission '
    . 'content and student identity are sent to the ProfFaith service to generate writing feedback.';
$string['privacy:metadata:proffaith:fullname'] = 'The student full name, so feedback can be attributed.';
$string['privacy:metadata:proffaith:email'] = 'The student email, used to correlate the submission to the student.';
$string['privacy:metadata:proffaith:submission'] = 'The text and files of the assignment submission, '
    . 'evaluated to generate feedback.';

// Paid prep access (ProfFaith entitlements projected into product cohorts).
$string['task_expire_access'] = 'Expire lapsed ProfFaith prep access';
$string['access_duplicateemail'] = 'More than one Moodle user has the email {$a}; merge them before syncing access.';
$string['access_emailinuse'] = 'The email {$a} already belongs to a different Moodle account.';
$string['privacy:metadata:access'] = 'Paid prep access granted from a ProfFaith entitlement.';
$string['privacy:metadata:access:userid'] = 'The user the access belongs to.';
$string['privacy:metadata:access:productkey'] = 'The ProfFaith product the access is for.';
$string['privacy:metadata:access:entitlementid'] = 'The ProfFaith entitlement the access came from.';
$string['privacy:metadata:access:timeend'] = 'When the access ends.';
$string['privacy:metadata:access:status'] = 'Whether the access is active, suspended or expired.';

// Sign-in handoff from ProfFaith (sso.php).
$string['proffaith_url'] = 'ProfFaith site URL';
$string['proffaith_url_desc'] = 'Base URL of the ProfFaith site that hands buyers into this Moodle. '
    . 'Sign-in codes are redeemed at [this URL]/moodle/sso/redeem, signed with the webhook shared secret above.';
$string['sso_failed'] = 'We could not sign you in to your prep course. Please open it again from ProfFaith.';
