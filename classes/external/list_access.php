<?php
// List every access row for a product so ProfFaith's nightly reconcile can
// diff Moodle against its entitlements and repair drift in either direction.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use local_proffaith\access;

class list_access extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'product_key' => new external_value(PARAM_ALPHANUMEXT, 'Product key'),
        ]);
    }

    /**
     * @return array{product_key:string,access:array}
     */
    public static function execute($productkey): array {
        $params = self::validate_parameters(self::execute_parameters(), ['product_key' => $productkey]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/cohort:assign', $systemcontext);

        return [
            'product_key' => $params['product_key'],
            'access' => access::list_for_product($params['product_key']),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'product_key' => new external_value(PARAM_ALPHANUMEXT, 'Product key'),
            'access' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Moodle user id'),
                'pfuserid' => new external_value(PARAM_INT, 'ProfFaith user id (0 if the idnumber is not ours)'),
                'entitlementid' => new external_value(PARAM_INT, 'ProfFaith entitlement id last synced'),
                'timeend' => new external_value(PARAM_INT, 'Unix time access ends; 0 = no end'),
                'status' => new external_value(PARAM_ALPHA, 'active, suspended or expired'),
                'incohort' => new external_value(PARAM_INT, '1 if the user is in the product cohort now'),
            ])),
        ]);
    }
}
