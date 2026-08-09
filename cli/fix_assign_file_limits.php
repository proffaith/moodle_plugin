<?php
// One-off repair: give a course's assignments a working file-upload area.
//
// Assignments provisioned before the maxfiles fix have the file submission
// plugin enabled with maxfilesubmissions=0 (create_module with $mform=null
// skips form defaults), which Moodle renders as no upload area at all.
//
// Usage: php public/local/proffaith/cli/fix_assign_file_limits.php <courseid>

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$courseid = (int) ($argv[1] ?? 0);
if (!$courseid) {
    fwrite(STDERR, "usage: fix_assign_file_limits.php <courseid>\n");
    exit(1);
}

$assigns = $DB->get_records('assign', ['course' => $courseid], 'id ASC');
$fixed = 0;
foreach ($assigns as $a) {
    foreach ([['maxfilesubmissions', 5], ['maxsubmissionsizebytes', 0]] as [$name, $value]) {
        $cond = ['assignment' => $a->id, 'subtype' => 'assignsubmission',
                 'plugin' => 'file', 'name' => $name];
        if ($rec = $DB->get_record('assign_plugin_config', $cond)) {
            $rec->value = $value;
            $DB->update_record('assign_plugin_config', $rec);
        } else {
            $DB->insert_record('assign_plugin_config', (object) ($cond + ['value' => $value]));
        }
    }
    $fixed++;
}
echo "updated file-submission limits on {$fixed} assignment(s) in course {$courseid}\n";
