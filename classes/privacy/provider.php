<?php
// Privacy: the plugin stores no personal data itself, but for assignments bound
// to AI feedback it transmits submission content + student identity to ProfFaith.
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
        return $collection;
    }
}
