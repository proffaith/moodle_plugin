<?php
// Hourly backstop for paid prep access: remove cohort members whose access end
// has passed, so access still ends if ProfFaith never sends the suspension.
namespace local_proffaith\task;

defined('MOODLE_INTERNAL') || die();

class expire_access extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_expire_access', 'local_proffaith');
    }

    public function execute() {
        $expired = \local_proffaith\access::expire_due();
        if ($expired) {
            mtrace("local_proffaith: expired {$expired} access grant(s)");
        }
    }
}
