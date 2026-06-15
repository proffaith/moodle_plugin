<?php
// Mint (or reuse) a web-service token for the ProfFaith provisioning service.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/externallib.php');

$service = $DB->get_record('external_services', ['shortname' => 'proffaith_provisioning'], '*', MUST_EXIST);
$admin = get_admin();
$context = context_system::instance();

$existing = $DB->get_record('external_tokens', [
    'externalserviceid' => $service->id,
    'userid' => $admin->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
]);

if ($existing) {
    $token = $existing->token;
} else if (class_exists('\\core_external\\util') && method_exists('\\core_external\\util', 'generate_token')) {
    $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $admin->id, $context);
} else {
    $token = external_generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $admin->id, $context);
}

echo "TOKEN=" . $token . "\n";
echo "WSROOT=" . $CFG->wwwroot . "/webservice/rest/server.php\n";
echo "SERVICEID=" . $service->id . " USERID=" . $admin->id . "\n";
