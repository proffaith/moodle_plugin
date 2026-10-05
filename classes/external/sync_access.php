<?php
// Make one ProfFaith user's access to one product true in Moodle. ProfFaith
// sends the desired state (active until timeend, or suspended); this finds or
// creates the Moodle user and adds/removes them from the product cohort.
// Idempotent — ProfFaith retries it freely.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_proffaith\access;

class sync_access extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pf_user_id' => new external_value(PARAM_INT, 'ProfFaith user id'),
            'email' => new external_value(PARAM_EMAIL, 'ProfFaith account email (verified)'),
            'firstname' => new external_value(PARAM_NOTAGS, 'First name'),
            'lastname' => new external_value(PARAM_NOTAGS, 'Last name'),
            'product_key' => new external_value(PARAM_ALPHANUMEXT, 'Product key (cohort idnumber suffix)'),
            'entitlement_id' => new external_value(PARAM_INT, 'ProfFaith entitlement id', VALUE_DEFAULT, 0),
            'timeend' => new external_value(PARAM_INT, 'Unix time access ends; 0 = no end', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHA, 'active or suspended', VALUE_DEFAULT, 'active'),
        ]);
    }

    /**
     * @return array{userid:int,created:int,cohortid:int,status:string}
     */
    public static function execute($pfuserid, $email, $firstname, $lastname, $productkey,
            $entitlementid = 0, $timeend = 0, $status = 'active'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'pf_user_id' => $pfuserid, 'email' => $email, 'firstname' => $firstname,
            'lastname' => $lastname, 'product_key' => $productkey,
            'entitlement_id' => $entitlementid, 'timeend' => $timeend, 'status' => $status,
        ]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/user:create', $systemcontext);
        require_capability('moodle/user:update', $systemcontext);
        require_capability('moodle/cohort:assign', $systemcontext);

        if ($params['pf_user_id'] <= 0 || $params['product_key'] === '' || $params['email'] === '') {
            throw new \invalid_parameter_exception('pf_user_id, email and product_key are required');
        }
        if (trim($params['firstname']) === '' || trim($params['lastname']) === '') {
            throw new \invalid_parameter_exception('firstname and lastname are required');
        }
        if (!in_array($params['status'], [access::STATUS_ACTIVE, access::STATUS_SUSPENDED], true)) {
            throw new \invalid_parameter_exception('status must be active or suspended');
        }

        return access::apply(
            (int) $params['pf_user_id'], $params['email'], trim($params['firstname']), trim($params['lastname']),
            $params['product_key'], (int) $params['entitlement_id'], (int) $params['timeend'], $params['status']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'created' => new external_value(PARAM_INT, '1 if the Moodle user was created by this call'),
            'cohortid' => new external_value(PARAM_INT, 'Product cohort id'),
            'status' => new external_value(PARAM_ALPHA, 'Resulting status: active, suspended or expired'),
        ]);
    }
}
