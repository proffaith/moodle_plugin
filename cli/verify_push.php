<?php
// TEMP verification: inspect a provisioned course's quizzes + pathway gating.
// Usage: php public/local/proffaith/cli/verify_push.php <courseid>
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$courseid = (int) ($argv[1] ?? 0);
if (!$courseid) {
    cli_error("usage: verify_push.php <courseid>");
}

echo "=== Quizzes in course {$courseid} ===\n";
$quizzes = $DB->get_records('quiz', ['course' => $courseid], 'id ASC');
$gradeitembyquiz = [];
foreach ($quizzes as $q) {
    $cm = get_coursemodule_from_instance('quiz', $q->id, $courseid);
    $nslots = $DB->count_records('quiz_slots', ['quizid' => $q->id]);
    $gi = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz',
        'iteminstance' => $q->id, 'courseid' => $courseid, 'itemnumber' => 0]);
    $giid = $gi ? (int) $gi->id : 0;
    $gradeitembyquiz[$giid] = $q->name;
    printf("  cmid=%-4d giid=%-5d questions=%-3d sumgrades=%-6s grademax=%-5s  %s\n",
        $cm->id, $giid, $nslots, (string) $q->sumgrades,
        $gi ? (string) $gi->grademax : '?', $q->name);
}

echo "\n=== Pages in course {$courseid} (availability) ===\n";
$modinfo = get_fast_modinfo($courseid);
$gated = 0;
foreach ($modinfo->get_cms() as $cm) {
    if ($cm->modname !== 'page') {
        continue;
    }
    $avail = $cm->availability;
    $desc = 'UNGATED';
    if ($avail) {
        $j = json_decode($avail, true);
        $parts = [];
        foreach (($j['c'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'grade') {
                $giid = (int) ($c['id'] ?? 0);
                $on = $gradeitembyquiz[$giid] ?? "grade_item#{$giid}";
                $rng = [];
                if (isset($c['min'])) { $rng[] = ">= {$c['min']}%"; }
                if (isset($c['max'])) { $rng[] = "< {$c['max']}%"; }
                $parts[] = "[{$on}] " . implode(' & ', $rng);
            }
        }
        $desc = 'GATE ' . implode('; ', $parts);
        $gated++;
    }
    printf("  cmid=%-4d %-44s %s\n", $cm->id, substr($cm->name, 0, 44), $desc);
}
echo "\nTotal pages gated: {$gated}\n";
