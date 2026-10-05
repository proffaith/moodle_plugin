<?php
// Paid prep access: makes one ProfFaith user's access to one product true in
// Moodle. ProfFaith is the system of record (textbook_entitlements); this class
// only projects the desired state it is sent — find/create the user, record the
// grant, and add/remove them from the product cohort. Cohort sync does the
// enrolling. Every method is safe to repeat.
namespace local_proffaith;

defined('MOODLE_INTERNAL') || die();

class access {

    /** Access row statuses. */
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_EXPIRED = 'expired';

    /** Moodle user idnumber for a ProfFaith user id. */
    public static function user_idnumber(int $pfuserid): string {
        return 'pf:' . $pfuserid;
    }

    /** Cohort idnumber for a product key. */
    public static function cohort_idnumber(string $productkey): string {
        return 'pf-product-' . $productkey;
    }

    /** ProfFaith user id parsed back out of a Moodle idnumber (0 if not one of ours). */
    public static function pf_user_id(string $idnumber): int {
        return preg_match('/^pf:(\d+)$/', $idnumber, $m) ? (int) $m[1] : 0;
    }

    /** The system cohort for a product, created on first use. */
    public static function ensure_cohort(string $productkey): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $idnumber = self::cohort_idnumber($productkey);
        $cohort = $DB->get_record('cohort', ['idnumber' => $idnumber]);
        if ($cohort) {
            return $cohort;
        }
        $cohort = (object) [
            'contextid' => \context_system::instance()->id,
            'name' => 'ProfFaith product: ' . $productkey,
            'idnumber' => $idnumber,
            'description' => 'Managed by local_proffaith from ProfFaith entitlements. Do not edit members by hand.',
            'descriptionformat' => FORMAT_PLAIN,
            'visible' => 0,
            // Plugin-owned: keeps the manual member editor off this cohort.
            'component' => 'local_proffaith',
        ];
        $cohort->id = cohort_add_cohort($cohort);
        return $DB->get_record('cohort', ['id' => $cohort->id], '*', MUST_EXIST);
    }

    /**
     * Find the Moodle user for a ProfFaith user: by idnumber, then by a single
     * email match, else create one.
     *
     * @return array{0:\stdClass,1:bool} the user and whether it was created
     */
    public static function find_or_create_user(int $pfuserid, string $email, string $firstname, string $lastname): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $idnumber = self::user_idnumber($pfuserid);
        $email = trim($email);

        $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);
        if (!$user) {
            $matches = self::users_by_email($email);
            if (count($matches) > 1) {
                throw new \moodle_exception('access_duplicateemail', 'local_proffaith', '', $email);
            }
            $user = $matches ? reset($matches) : null;
            if ($user && $user->idnumber !== '' && $user->idnumber !== $idnumber) {
                // The email already belongs to an account tied to something else.
                throw new \moodle_exception('access_emailinuse', 'local_proffaith', '', $email);
            }
        }

        if (!$user) {
            $new = (object) [
                'auth' => 'manual',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                // Buyers never type this: they arrive through the ProfFaith handoff.
                'username' => 'pf' . $pfuserid,
                'password' => random_string(40) . 'aA1!',
                'idnumber' => $idnumber,
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'lang' => $CFG->lang,
            ];
            $id = user_create_user($new, true, true);
            return [$DB->get_record('user', ['id' => $id], '*', MUST_EXIST), true];
        }

        // Stamp the idnumber and follow ProfFaith's name/email.
        $update = ['id' => $user->id];
        if ($user->idnumber !== $idnumber) {
            $update['idnumber'] = $idnumber;
        }
        if ($firstname !== '' && $user->firstname !== $firstname) {
            $update['firstname'] = $firstname;
        }
        if ($lastname !== '' && $user->lastname !== $lastname) {
            $update['lastname'] = $lastname;
        }
        if (\core_text::strtolower($user->email) !== \core_text::strtolower($email)) {
            foreach (self::users_by_email($email) as $other) {
                if ((int) $other->id !== (int) $user->id) {
                    throw new \moodle_exception('access_emailinuse', 'local_proffaith', '', $email);
                }
            }
            $update['email'] = $email;
        }
        if (count($update) > 1) {
            user_update_user((object) $update, false, true);
            $user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        }
        return [$user, false];
    }

    /** Non-deleted local users with this email, case-insensitive. */
    private static function users_by_email(string $email): array {
        global $CFG, $DB;
        if ($email === '') {
            return [];
        }
        return $DB->get_records_select('user',
            'LOWER(email) = :email AND deleted = 0 AND mnethostid = :mnet',
            ['email' => \core_text::strtolower($email), 'mnet' => $CFG->mnet_localhost_id]);
    }

    /**
     * Make the desired state true for one user and one product.
     *
     * @param int $timeend unix time access ends; 0 = no end
     * @param string $status active|suspended as ProfFaith wants it
     * @return array{userid:int,created:int,cohortid:int,status:string}
     */
    public static function apply(int $pfuserid, string $email, string $firstname, string $lastname,
            string $productkey, int $entitlementid, int $timeend, string $status): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        [$user, $created] = self::find_or_create_user($pfuserid, $email, $firstname, $lastname);
        $cohort = self::ensure_cohort($productkey);

        $now = time();
        if ($status !== self::STATUS_ACTIVE) {
            $status = self::STATUS_SUSPENDED;
        } else if ($timeend > 0 && $timeend <= $now) {
            // An "active" grant whose end has already passed is not access.
            $status = self::STATUS_EXPIRED;
        }

        $row = $DB->get_record('local_proffaith_access', ['userid' => $user->id, 'productkey' => $productkey]);
        if ($row) {
            $row->entitlementid = $entitlementid;
            $row->timeend = $timeend;
            $row->status = $status;
            $row->timemodified = $now;
            $DB->update_record('local_proffaith_access', $row);
        } else {
            $DB->insert_record('local_proffaith_access', (object) [
                'userid' => $user->id,
                'productkey' => $productkey,
                'entitlementid' => $entitlementid,
                'timeend' => $timeend,
                'status' => $status,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $ismember = cohort_is_member($cohort->id, $user->id);
        if ($status === self::STATUS_ACTIVE && !$ismember) {
            cohort_add_member($cohort->id, $user->id);
        } else if ($status !== self::STATUS_ACTIVE && $ismember) {
            cohort_remove_member($cohort->id, $user->id);
        }

        return [
            'userid' => (int) $user->id,
            'created' => $created ? 1 : 0,
            'cohortid' => (int) $cohort->id,
            'status' => $status,
        ];
    }

    /**
     * Every access row for a product, for ProfFaith's reconcile job.
     *
     * @return array[] rows of userid, pfuserid, timeend, status, incohort
     */
    public static function list_for_product(string $productkey): array {
        global $DB;
        $cohort = $DB->get_record('cohort', ['idnumber' => self::cohort_idnumber($productkey)]);
        $members = $cohort
            ? $DB->get_records_menu('cohort_members', ['cohortid' => $cohort->id], '', 'userid, id')
            : [];

        $sql = "SELECT a.id, a.userid, a.entitlementid, a.timeend, a.status, u.idnumber
                  FROM {local_proffaith_access} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE a.productkey = :productkey AND u.deleted = 0
              ORDER BY a.id";
        $out = [];
        foreach ($DB->get_records_sql($sql, ['productkey' => $productkey]) as $r) {
            $out[] = [
                'userid' => (int) $r->userid,
                'pfuserid' => self::pf_user_id((string) $r->idnumber),
                'entitlementid' => (int) $r->entitlementid,
                'timeend' => (int) $r->timeend,
                'status' => (string) $r->status,
                'incohort' => isset($members[$r->userid]) ? 1 : 0,
            ];
        }
        return $out;
    }

    /**
     * True for accounts the ProfFaith sign-in handoff must never log in: site
     * admins and anyone holding a managing or teaching role in any context.
     */
    public static function is_privileged(\stdClass $user): bool {
        global $DB;
        if (is_siteadmin($user)) {
            return true;
        }
        [$insql, $params] = $DB->get_in_or_equal(
            ['manager', 'coursecreator', 'editingteacher', 'teacher'], SQL_PARAMS_NAMED);
        $params['userid'] = $user->id;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = :userid AND r.archetype $insql", $params);
    }

    /**
     * Where a buyer lands after the handoff: the product's course when there is
     * exactly one they are actively enrolled in, otherwise their dashboard.
     */
    public static function landing_url(\stdClass $user, string $productkey): \moodle_url {
        global $DB;
        if ($productkey !== '') {
            $sql = "SELECT e.courseid
                      FROM {cohort} c
                      JOIN {enrol} e ON e.enrol = 'cohort' AND e.customint1 = c.id AND e.status = 0
                      JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid AND ue.status = 0
                     WHERE c.idnumber = :idnumber";
            $courseids = $DB->get_fieldset_sql($sql,
                ['userid' => $user->id, 'idnumber' => self::cohort_idnumber($productkey)]);
            if (count($courseids) === 1) {
                return new \moodle_url('/course/view.php', ['id' => reset($courseids)]);
            }
        }
        return new \moodle_url('/my/');
    }

    /**
     * Backstop: end access whose timeend has passed, so it still ends if
     * ProfFaith never sends the suspension.
     *
     * @return int rows expired
     */
    public static function expire_due(?int $now = null): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $now = $now ?? time();
        $due = $DB->get_records_select('local_proffaith_access',
            'status = :status AND timeend > 0 AND timeend <= :now',
            ['status' => self::STATUS_ACTIVE, 'now' => $now]);
        foreach ($due as $row) {
            $cohort = $DB->get_record('cohort', ['idnumber' => self::cohort_idnumber($row->productkey)]);
            if ($cohort && cohort_is_member($cohort->id, $row->userid)) {
                cohort_remove_member($cohort->id, $row->userid);
            }
            $row->status = self::STATUS_EXPIRED;
            $row->timemodified = $now;
            $DB->update_record('local_proffaith_access', $row);
        }
        return count($due);
    }
}
