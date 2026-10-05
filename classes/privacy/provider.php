<?php
// Privacy: for assignments bound to AI feedback the plugin transmits submission
// content + student identity to ProfFaith; for paid prep access it stores one
// row per user and product (local_proffaith_access).
namespace local_proffaith\privacy;

use core_privacy\local\metadata\collection;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('proffaith', [
            'fullname' => 'privacy:metadata:proffaith:fullname',
            'email' => 'privacy:metadata:proffaith:email',
            'submission' => 'privacy:metadata:proffaith:submission',
        ], 'privacy:metadata:proffaith');
        $collection->add_database_table('local_proffaith_access', [
            'userid' => 'privacy:metadata:access:userid',
            'productkey' => 'privacy:metadata:access:productkey',
            'entitlementid' => 'privacy:metadata:access:entitlementid',
            'timeend' => 'privacy:metadata:access:timeend',
            'status' => 'privacy:metadata:access:status',
        ], 'privacy:metadata:access');
        return $collection;
    }
}
