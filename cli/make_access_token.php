<?php
// Mint (or reuse) the web-service token for the ProfFaith *access* service
// (paid prep enrolment). Deliberately NOT the admin's provisioning token: it
// belongs to a dedicated service user whose role carries only what
// sync_access / list_access need.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');

const PF_ACCESS_USERNAME = 'pf_access_service';
const PF_ACCESS_ROLE = 'proffaithaccess';
const PF_ACCESS_CAPS = [
    'webservice/rest:use',
    'moodle/user:create',
    'moodle/user:update',
    'moodle/cohort:assign',
];

$service = $DB->get_record('external_services', ['shortname' => 'proffaith_access'], '*', MUST_EXIST);
$context = context_system::instance();

// Service user (no usable password: it only ever authenticates by token).
$user = $DB->get_record('user', ['username' => PF_ACCESS_USERNAME, 'deleted' => 0]);
if (!$user) {
    $id = user_create_user((object) [
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'username' => PF_ACCESS_USERNAME,
        'password' => random_string(40) . 'aA1!',
        'firstname' => 'ProfFaith',
        'lastname' => 'Access Service',
        'email' => 'pf-access-service@' . (parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost') . '.invalid',
    ], true, false);
    $user = $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
}

// Role with only the service's capabilities, assigned at system level.
$roleid = $DB->get_field('role', 'id', ['shortname' => PF_ACCESS_ROLE]);
if (!$roleid) {
    $roleid = create_role('ProfFaith access service', PF_ACCESS_ROLE,
        'Token-only role for ProfFaith paid prep enrolment sync.');
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
}
foreach (PF_ACCESS_CAPS as $cap) {
    assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
}
role_assign($roleid, $user->id, $context->id);

// The service is restricted to authorised users.
if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid' => $user->id,
        'timecreated' => time(),
    ]);
}

$existing = $DB->get_record('external_tokens', [
    'externalserviceid' => $service->id,
    'userid' => $user->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
]);
if ($existing) {
    $token = $existing->token;
} else {
    $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $user->id, $context);
}

echo "TOKEN=" . $token . "\n";
echo "WSROOT=" . $CFG->wwwroot . "/webservice/rest/server.php\n";
echo "SERVICEID=" . $service->id . " USERID=" . $user->id . " ROLEID=" . $roleid . "\n";
