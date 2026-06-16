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
