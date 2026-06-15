<?php
// Provision a Moodle course from a ProfFaith course design.
namespace local_proffaith\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class provision_course extends external_api {

    /** Marker appended to ProfFaith-managed section summaries so a re-push into
     * an existing (e.g. SIS-provisioned) course can find and replace only its
     * own sections, leaving everything else intact. */
    const SECTION_MARKER = '<!-- proffaith:managed -->';

    /** Describe the input payload. */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'course' => new external_single_structure([
                'idnumber'   => new external_value(PARAM_RAW, 'Stable ProfFaith course key (idempotency)'),
                'fullname'   => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname'  => new external_value(PARAM_TEXT, 'Course short name (unique)'),
                'summary'    => new external_value(PARAM_RAW, 'Course summary HTML', VALUE_DEFAULT, ''),
                'categoryid' => new external_value(PARAM_INT, 'Category id', VALUE_DEFAULT, 1),
            ]),
            'tool' => new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Preconfigured LTI tool type name', VALUE_DEFAULT, 'ProfFaith'),
            ], 'Tool binding', VALUE_DEFAULT, []),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'name'    => new external_value(PARAM_TEXT, 'Section name'),
                    'summary' => new external_value(PARAM_RAW, 'Section summary HTML', VALUE_DEFAULT, ''),
                    'activities' => new external_multiple_structure(
                        new external_single_structure([
                            'type'   => new external_value(PARAM_ALPHA, 'label | alwd | assign | url | quiz | page | forum | resource'),
                            'name'   => new external_value(PARAM_TEXT, 'Activity name'),
                            'html'   => new external_value(PARAM_RAW, 'HTML body (label/assign/page/quiz intro)', VALUE_DEFAULT, ''),
                            'url'    => new external_value(PARAM_RAW, 'External URL (url type)', VALUE_DEFAULT, ''),
                            'set_id' => new external_value(PARAM_INT, 'ProfFaith ALWD set id', VALUE_DEFAULT, 0),
                            'assignment_id' => new external_value(PARAM_INT, 'ProfFaith assignment id', VALUE_DEFAULT, 0),
                            'material_id' => new external_value(PARAM_INT, 'ProfFaith material id', VALUE_DEFAULT, 0),
                            'draft_itemid' => new external_value(PARAM_INT, 'Uploaded file draft itemid (resource type)', VALUE_DEFAULT, 0),
                            'grade'  => new external_value(PARAM_INT, 'Max grade', VALUE_DEFAULT, 100),
                            // Quiz fields.
                            'questions_xml' => new external_value(PARAM_RAW, 'Moodle-XML question bank (quiz type)', VALUE_DEFAULT, ''),
                            'purpose' => new external_value(PARAM_ALPHA, 'homework | assessment | pretest | gamify', VALUE_DEFAULT, ''),
                            'time_limit' => new external_value(PARAM_INT, 'Quiz time limit (minutes; 0 = none)', VALUE_DEFAULT, 0),
                            'attempts' => new external_value(PARAM_INT, 'Quiz attempts allowed (0 = unlimited)', VALUE_DEFAULT, 0),
                            'co_id'  => new external_value(PARAM_INT, 'ProfFaith course-objective id (quiz/page gating key)', VALUE_DEFAULT, 0),
                            'task_id' => new external_value(PARAM_INT, 'ProfFaith checklist-task id (legacy task-quiz link key)', VALUE_DEFAULT, 0),
                            // Pathway (page) gating.
                            'pathway' => new external_value(PARAM_ALPHANUMEXT, 'gamification | traditional_1 | traditional_2', VALUE_DEFAULT, ''),
                            'threshold' => new external_value(PARAM_INT, 'Pre-test routing threshold percent', VALUE_DEFAULT, 80),
                            // Advanced-grading rubric (assign type).
                            'rubric' => new external_single_structure([
                                'name' => new external_value(PARAM_TEXT, 'Rubric name', VALUE_DEFAULT, ''),
                                'criteria' => new external_multiple_structure(new external_single_structure([
                                    'description' => new external_value(PARAM_RAW, 'Criterion description'),
                                    'levels' => new external_multiple_structure(new external_single_structure([
                                        'score' => new external_value(PARAM_FLOAT, 'Level score'),
                                        'definition' => new external_value(PARAM_RAW, 'Level definition'),
                                    ]), 'Levels', VALUE_DEFAULT, []),
                                ]), 'Criteria', VALUE_DEFAULT, []),
                            ], 'Assignment rubric', VALUE_DEFAULT, []),
                        ]),
                        'Activities', VALUE_DEFAULT, []
                    ),
                ]),
                'Sections', VALUE_DEFAULT, []
            ),
            'target_courseid' => new external_value(PARAM_INT,
                'Existing Moodle course id to push INTO (faculty section). '
                . '0 = create/update a ProfFaith-owned course by idnumber.',
                VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Create or update the course and its activities.
     *
     * @return array
     */
    public static function execute($course, $tool, $sections, $target_courseid = 0): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'course' => $course, 'tool' => $tool, 'sections' => $sections,
            'target_courseid' => $target_courseid,
        ]);
        $course = $params['course'];
        $tool = $params['tool'];
        $sections = $params['sections'];
        $targetid = (int) $params['target_courseid'];

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/course:create', $systemcontext);

        // Resolve the preconfigured ProfFaith LTI tool type (activities bind to it).
        $toolname = !empty($tool['name']) ? $tool['name'] : 'ProfFaith';
        $typeid = (int) $DB->get_field('lti_types', 'id', ['name' => $toolname], IGNORE_MULTIPLE);
        if (!$typeid) {
            throw new \moodle_exception('invalidrecord', 'error', '',
                "No preconfigured LTI tool type named '{$toolname}' — register the ProfFaith tool first.");
        }

        $numsections = max(1, count($sections));
        $sectionbase = 0;       // ProfFaith sections are written at $sectionbase + 1 ..
        $targetmode = false;

        if ($targetid) {
            // Push INTO an existing (e.g. SIS-provisioned) course the faculty
            // teaches: leave its identity (idnumber/shortname/category) alone and
            // manage ONLY ProfFaith's own sections + activities.
            $targetcourse = $DB->get_record('course', ['id' => $targetid]);
            if (!$targetcourse || (int) $targetcourse->id === (int) SITEID) {
                throw new \moodle_exception('invalidcourseid', 'error', '', null,
                    "Target Moodle course {$targetid} not found.");
            }
            $courseid = $targetid;
            $targetmode = true;
            self::purge_proffaith_content($courseid);   // idempotent re-push, SIS-safe
            // Reuse a course's empty leading topics (e.g. a freshly-provisioned
            // course's default blank sections) rather than appending after them,
            // so ProfFaith content isn't preceded by "New section" placeholders.
            // Only sections with a name or activities are kept ahead of it.
            $sectionbase = 0;
            foreach ($DB->get_records('course_sections', ['course' => $courseid], 'section ASC') as $sr) {
                if ((int) $sr->section === 0) {
                    continue; // the general/top section is always present
                }
                $hasmods = $DB->record_exists('course_modules',
                    ['course' => $courseid, 'section' => $sr->id]);
                if (trim((string) $sr->name) !== '' || $hasmods) {
                    $sectionbase = max($sectionbase, (int) $sr->section);
                }
            }
        } else {
            // Idempotent on idnumber: a ProfFaith-owned course we fully manage.
            $existing = !empty($course['idnumber'])
                ? $DB->get_record('course', ['idnumber' => $course['idnumber']]) : false;
            if ($existing) {
                $courseid = $existing->id;
                update_course((object) [
                    'id' => $courseid,
                    'fullname' => $course['fullname'],
                    'shortname' => $course['shortname'],
                    'summary' => $course['summary'],
                    'summaryformat' => FORMAT_HTML,
                ]);
                course_create_sections_if_missing($courseid, range(0, $numsections));
                // Idempotent re-sync: remove existing activities before re-creating them.
                foreach (get_course_mods($courseid) as $cm) {
                    course_delete_module($cm->id);
                }
            } else {
                $newcourse = create_course((object) [
                    'fullname' => $course['fullname'],
                    'shortname' => $course['shortname'],
                    'idnumber' => $course['idnumber'],
                    'category' => !empty($course['categoryid']) ? $course['categoryid'] : 1,
                    'summary' => $course['summary'],
                    'summaryformat' => FORMAT_HTML,
                    'format' => 'topics',
                    'numsections' => $numsections,
                    'visible' => 1,
                ]);
                $courseid = $newcourse->id;
            }
        }
        $courserec = get_course($courseid);

        // Make sure all the sections we're about to write are visible (topics
        // format hides sections beyond numsections); only grows, never shrinks.
        self::ensure_numsections($courseid, $sectionbase + $numsections);

        $result = ['course_id' => $courseid, 'sections' => 0, 'activities' => 0,
                   'alwd' => 0, 'quizzes' => 0, 'pages' => 0, 'forums' => 0,
                   'resources' => 0, 'rubrics' => 0, 'gated' => 0,
                   'target_mode' => $targetmode ? 1 : 0, 'typeid' => $typeid];

        $pfseq = 0;                 // unique suffix for ProfFaith cm idnumbers

        $alwdcmidbyset = [];        // set_id => cmid, to resolve checklist links
        $assigncmidbyid = [];       // assignment_id => cmid
        $materialviewbyid = [];     // material_id => full view URL (url or resource)
        $forumcmidbyid = [];        // discussion assignment_id => forum cmid
        $quizcmidbycopurpose = [];  // "co_id:purpose" => cmid (proffaith-quiz: links)
        $taskquizcmidbyid = [];     // checklist task_id => cmid (legacy task-quiz links)
        $pretestgradeitembyco = []; // co_id => quiz grade_item id (pathway gating)
        $gamifygradeitem = 0;       // course-wide Lawyer's Quest grade_item id
        $pathpages = [];            // [['cmid','pathway','co_id','threshold'], ...]
        $labelids = [];             // label instance ids whose intro has link markers
        $pageids = [];              // page instance ids whose content has link markers

        $sectionnum = $sectionbase;
        foreach ($sections as $sec) {
            $sectionnum++;
            course_create_sections_if_missing($courseid, $sectionnum);
            $sectionrec = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum]);
            // Tag ProfFaith-managed sections so a re-push into a shared course can
            // find and replace only its own block.
            $summary = $sec['summary'] . ($targetmode ? self::SECTION_MARKER : '');
            $DB->update_record('course_sections', (object) [
                'id' => $sectionrec->id,
                'name' => $sec['name'],
                'summary' => $summary,
                'summaryformat' => FORMAT_HTML,
            ]);
            $result['sections']++;

            foreach ($sec['activities'] as $act) {
                if ($act['type'] === 'label') {
                    $mi = self::base_moduleinfo('label', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor($act['html']);
                } else if ($act['type'] === 'alwd') {
                    $mi = self::base_moduleinfo('lti', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor('');
                    $mi->typeid = $typeid;
                    $mi->toolurl = '';
                    $mi->securetoolurl = '';
                    $mi->launchcontainer = 4; // new window → top-level → first-party state cookie
                    $mi->instructorcustomparameters = "proffaith_tool=alwd\nset_id=" . (int) $act['set_id'];
                    $mi->instructorchoiceacceptgrades = 1; // LTI_SETTING_ALWAYS → makes the gradebook line item
                    $mi->instructorchoicesendname = 1;
                    $mi->instructorchoicesendemailaddr = 1;
                    $mi->instructorchoiceallowroster = 1;
                    $mi->showtitlelaunch = 1;
                    $mi->showdescriptionlaunch = 0;
                    $mi->grade = !empty($act['grade']) ? (int) $act['grade'] : 100;
                    $result['alwd']++;
                } else if ($act['type'] === 'assign') {
                    $mi = self::base_moduleinfo('assign', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor($act['html']);
                    $mi->alwaysshowdescription = 1;
                    $mi->submissiondrafts = 0;
                    $mi->requiresubmissionstatement = 0;
                    $mi->sendnotifications = 0;
                    $mi->sendstudentnotifications = 1;
                    $mi->sendlatenotifications = 0;
                    $mi->duedate = 0;
                    $mi->allowsubmissionsfromdate = 0;
                    $mi->cutoffdate = 0;
                    $mi->gradingduedate = 0;
                    $mi->teamsubmission = 0;
                    $mi->requireallteammemberssubmit = 0;
                    $mi->teamsubmissiongroupingid = 0;
                    $mi->blindmarking = 0;
                    $mi->markingworkflow = 0;
                    $mi->markingallocation = 0;
                    $mi->markinganonymous = 0;
                    $mi->attemptreopenmethod = 'none';
                    $mi->maxattempts = -1;
                    $mi->timelimit = 0;
                    $mi->submissionattachments = 0;
                    $mi->activityformat = 0;
                    $mi->grade = !empty($act['grade']) ? (int) $act['grade'] : 100;
                    // Submission/feedback plugins: enable online text so it's usable.
                    $mi->assignsubmission_onlinetext_enabled = 1;
                    $mi->assignsubmission_file_enabled = 0;
                    $mi->assignsubmission_comments_enabled = 0;
                    $mi->assignfeedback_comments_enabled = 1;
                    $mi->assignfeedback_file_enabled = 0;
                    $mi->assignfeedback_offline_enabled = 0;
                } else if ($act['type'] === 'url') {
                    $mi = self::base_moduleinfo('url', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor('');
                    $mi->externalurl = (string) $act['url'];
                    $mi->display = 0;
                } else if ($act['type'] === 'resource') {
                    if (empty($act['draft_itemid'])) {
                        continue; // file upload failed upstream → skip
                    }
                    require_once($CFG->libdir . '/resourcelib.php');
                    $mi = self::base_moduleinfo('resource', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor('');
                    $mi->files = (int) $act['draft_itemid']; // resource_set_mainfile reads this
                    $mi->display = RESOURCELIB_DISPLAY_AUTO;
                    $mi->printintro = 0;
                    $mi->showsize = 1;
                    $mi->showtype = 1;
                    $mi->uploaded = 0;
                } else if ($act['type'] === 'page') {
                    require_once($CFG->libdir . '/resourcelib.php');
                    $mi = self::base_moduleinfo('page', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor('');
                    // page_add_instance($data, $mform=null) reads content/contentformat directly.
                    $mi->content = (string) $act['html'];
                    $mi->contentformat = FORMAT_HTML;
                    $mi->display = RESOURCELIB_DISPLAY_AUTO;
                    $mi->printintro = 0;
                    $mi->printlastmodified = 1;
                } else if ($act['type'] === 'forum') {
                    require_once($CFG->dirroot . '/mod/forum/lib.php');
                    $mi = self::base_moduleinfo('forum', $courseid, $sectionnum, $act['name']);
                    $mi->introeditor = self::editor($act['html']);
                    $mi->type = 'general';
                    $mi->assessed = 0;
                    $mi->scale = 0;
                    $mi->forcesubscribe = 0;
                    $mi->grade_forum = 0;
                } else if ($act['type'] === 'quiz') {
                    $mi = self::quiz_moduleinfo($courseid, $sectionnum, $act);
                } else {
                    continue;
                }

                // Stamp a unique ProfFaith idnumber so a re-push can identify and
                // replace only its own activities (never SIS/other content).
                $mi->cmidnumber = 'proffaith:' . (++$pfseq) . ':' . $act['type'];
                $created = create_module($mi);
                $result['activities']++;

                if ($act['type'] === 'alwd') {
                    $alwdcmidbyset[(int) $act['set_id']] = $created->coursemodule;
                } else if ($act['type'] === 'assign') {
                    if (!empty($act['assignment_id'])) {
                        $assigncmidbyid[(int) $act['assignment_id']] = $created->coursemodule;
                    }
                    if (!empty($act['rubric']['criteria'])
                            && self::apply_rubric($created->coursemodule, $act['rubric'])) {
                        $result['rubrics']++;
                    }
                } else if ($act['type'] === 'url') {
                    if (!empty($act['material_id'])) {
                        $materialviewbyid[(int) $act['material_id']] =
                            $CFG->wwwroot . '/mod/url/view.php?id=' . $created->coursemodule;
                    }
                } else if ($act['type'] === 'resource') {
                    $result['resources']++;
                    if (!empty($act['material_id'])) {
                        $materialviewbyid[(int) $act['material_id']] =
                            $CFG->wwwroot . '/mod/resource/view.php?id=' . $created->coursemodule;
                    }
                } else if ($act['type'] === 'forum') {
                    $result['forums']++;
                    if (!empty($act['assignment_id'])) {
                        $forumcmidbyid[(int) $act['assignment_id']] = $created->coursemodule;
                    }
                } else if ($act['type'] === 'page') {
                    $result['pages']++;
                    if (strpos((string) $act['html'], 'proffaith-') !== false) {
                        $pageids[] = $created->instance;
                    }
                    if (!empty($act['pathway'])) {
                        $pathpages[] = [
                            'cmid' => $created->coursemodule,
                            'pathway' => (string) $act['pathway'],
                            'co_id' => (int) $act['co_id'],
                            'threshold' => (int) ($act['threshold'] ?: 80),
                        ];
                    }
                } else if ($act['type'] === 'quiz') {
                    $result['quizzes']++;
                    $gradeitemid = self::populate_quiz($created, $courserec, $act);
                    $purpose = (string) $act['purpose'];
                    $coid = (int) $act['co_id'];
                    $quizcmidbycopurpose[$coid . ':' . $purpose] = $created->coursemodule;
                    if (!empty($act['task_id'])) {
                        $taskquizcmidbyid[(int) $act['task_id']] = $created->coursemodule;
                    }
                    if ($gradeitemid) {
                        if ($purpose === 'pretest') {
                            $pretestgradeitembyco[$coid] = $gradeitemid;
                        } else if ($purpose === 'gamify') {
                            $gamifygradeitem = $gradeitemid;
                        }
                    }
                } else if ($act['type'] === 'label'
                        && strpos((string) $act['html'], 'proffaith-') !== false) {
                    $labelids[] = $created->instance;
                }
            }
        }

        // In a targeted push, drop any empty unnamed topics trailing ProfFaith's
        // block (extra default sections) so the course doesn't end in blanks.
        if ($targetmode) {
            $courserec = get_course($courseid);
            foreach ($DB->get_records_select('course_sections', 'course = ? AND section > ?',
                    [$courseid, $sectionnum], 'section DESC') as $sr) {
                $hasmods = $DB->record_exists('course_modules',
                    ['course' => $courseid, 'section' => $sr->id]);
                if (trim((string) $sr->name) === '' && !$hasmods) {
                    course_delete_section($courserec, $sr->section, true);
                }
            }
        }

        // Resolve ProfFaith link markers to the created activities, in both
        // checklist labels and generated page content.
        $resolve = function ($text) use ($alwdcmidbyset, $assigncmidbyid,
                $materialviewbyid, $forumcmidbyid, $quizcmidbycopurpose,
                $taskquizcmidbyid, $CFG) {
            $text = preg_replace_callback('/proffaith-lti:set:(\d+)/', function ($m) use ($alwdcmidbyset, $CFG) {
                $sid = (int) $m[1];
                return isset($alwdcmidbyset[$sid])
                    ? $CFG->wwwroot . '/mod/lti/view.php?id=' . $alwdcmidbyset[$sid] : '#';
            }, $text);
            $text = preg_replace_callback('/proffaith-assign:(\d+)/', function ($m) use ($assigncmidbyid, $CFG) {
                $aid = (int) $m[1];
                return isset($assigncmidbyid[$aid])
                    ? $CFG->wwwroot . '/mod/assign/view.php?id=' . $assigncmidbyid[$aid] : '#';
            }, $text);
            $text = preg_replace_callback('/proffaith-forum:(\d+)/', function ($m) use ($forumcmidbyid, $CFG) {
                $fid = (int) $m[1];
                return isset($forumcmidbyid[$fid])
                    ? $CFG->wwwroot . '/mod/forum/view.php?id=' . $forumcmidbyid[$fid] : '#';
            }, $text);
            $text = preg_replace_callback('/proffaith-material:(\d+)/', function ($m) use ($materialviewbyid) {
                $mid = (int) $m[1];
                return $materialviewbyid[$mid] ?? '#';
            }, $text);
            $text = preg_replace_callback('/proffaith-quiz:(\d+):(\w+)/', function ($m) use ($quizcmidbycopurpose, $CFG) {
                $key = (int) $m[1] . ':' . $m[2];
                return isset($quizcmidbycopurpose[$key])
                    ? $CFG->wwwroot . '/mod/quiz/view.php?id=' . $quizcmidbycopurpose[$key] : '#';
            }, $text);
            $text = preg_replace_callback('/proffaith-taskquiz:(\d+)/', function ($m) use ($taskquizcmidbyid, $CFG) {
                $tid = (int) $m[1];
                return isset($taskquizcmidbyid[$tid])
                    ? $CFG->wwwroot . '/mod/quiz/view.php?id=' . $taskquizcmidbyid[$tid] : '#';
            }, $text);
            return $text;
        };
        foreach ($labelids as $labelid) {
            $label = $DB->get_record('label', ['id' => $labelid]);
            if ($label && ($newintro = $resolve($label->intro)) !== $label->intro) {
                $DB->set_field('label', 'intro', $newintro, ['id' => $labelid]);
            }
        }
        foreach ($pageids as $pageid) {
            $page = $DB->get_record('page', ['id' => $pageid]);
            if ($page && ($newcontent = $resolve($page->content)) !== $page->content) {
                $DB->set_field('page', 'content', $newcontent, ['id' => $pageid]);
            }
        }

        // Gate each pathway page on its quiz grade item (availability conditions).
        // gamification → Lawyer's Quest = 100%; traditional_1 → pre-test >= threshold;
        // traditional_2 → pre-test < threshold. Percentages, min inclusive / max exclusive.
        foreach ($pathpages as $pp) {
            $availability = null;
            if ($pp['pathway'] === 'gamification' && $gamifygradeitem) {
                $availability = self::grade_availability($gamifygradeitem, 100, null);
            } else if ($pp['pathway'] === 'traditional_1' && !empty($pretestgradeitembyco[$pp['co_id']])) {
                $availability = self::grade_availability($pretestgradeitembyco[$pp['co_id']], $pp['threshold'], null);
            } else if ($pp['pathway'] === 'traditional_2' && !empty($pretestgradeitembyco[$pp['co_id']])) {
                $availability = self::grade_availability($pretestgradeitembyco[$pp['co_id']], null, $pp['threshold']);
            }
            if ($availability !== null) {
                $DB->set_field('course_modules', 'availability', $availability, ['id' => $pp['cmid']]);
                $result['gated']++;
            }
        }

        rebuild_course_cache($courseid, true);

        $result['course_url'] = $CFG->wwwroot . '/course/view.php?id=' . $courseid;
        return $result;
    }

    /** Describe the return value. */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'course_id'  => new external_value(PARAM_INT, 'Moodle course id'),
            'course_url' => new external_value(PARAM_RAW, 'Course view URL'),
            'sections'   => new external_value(PARAM_INT, 'Sections written'),
            'activities' => new external_value(PARAM_INT, 'Activities created'),
            'alwd'       => new external_value(PARAM_INT, 'ALWD activities created'),
            'quizzes'    => new external_value(PARAM_INT, 'Quiz activities created'),
            'pages'      => new external_value(PARAM_INT, 'Page activities created'),
            'forums'     => new external_value(PARAM_INT, 'Forum (discussion) activities created'),
            'resources'  => new external_value(PARAM_INT, 'File resource activities created'),
            'rubrics'    => new external_value(PARAM_INT, 'Assignment rubrics created'),
            'gated'      => new external_value(PARAM_INT, 'Pathway pages gated by availability'),
            'target_mode' => new external_value(PARAM_INT, '1 if pushed into an existing course'),
            'typeid'     => new external_value(PARAM_INT, 'Bound LTI tool type id'),
        ]);
    }

    /**
     * Remove a prior ProfFaith push from a shared course: delete its marked
     * sections (and the modules within) plus any stray ProfFaith-tagged modules,
     * leaving all non-ProfFaith (e.g. SIS) content untouched.
     */
    protected static function purge_proffaith_content($courseid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $course = get_course($courseid);

        $like = $DB->sql_like('summary', '?');
        $marked = $DB->get_records_select('course_sections',
            "course = ? AND $like", [$courseid, '%' . self::SECTION_MARKER . '%'], 'section DESC');
        foreach ($marked as $s) {
            if ((int) $s->section === 0) {
                continue; // never the general (top) section
            }
            course_delete_section($course, $s->section, true);
        }
        // Sweep any ProfFaith-tagged modules left outside a marked section.
        $cmlike = $DB->sql_like('idnumber', '?');
        $cms = $DB->get_records_select('course_modules',
            "course = ? AND $cmlike", [$courseid, 'proffaith:%']);
        foreach ($cms as $cm) {
            course_delete_module($cm->id);
        }
    }

    /** Grow the course's visible section count so appended sections show (topics
     * format hides sections beyond numsections). Never shrinks. */
    protected static function ensure_numsections($courseid, $needed): void {
        $format = course_get_format($courseid);
        $opts = $format->get_format_options();
        if (array_key_exists('numsections', $opts) && (int) $opts['numsections'] < (int) $needed) {
            $format->update_course_format_options(['numsections' => (int) $needed]);
        }
    }

    /** Minimal moduleinfo skeleton for create_module(). */
    protected static function base_moduleinfo($modulename, $courseid, $sectionnum, $name): \stdClass {
        $mi = new \stdClass();
        $mi->modulename = $modulename;
        $mi->course = $courseid;
        $mi->section = $sectionnum;
        $mi->visible = 1;
        $mi->visibleoncoursepage = 1;
        $mi->name = $name;
        $mi->cmidnumber = '';
        return $mi;
    }

    /** Build an editor field with a fresh draft area. */
    protected static function editor($html): array {
        return ['text' => (string) $html, 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
    }

    /**
     * Full quiz moduleinfo (mirrors mod_quiz's test generator defaults so
     * create_module → quiz_add_instance has every field it reads).
     */
    protected static function quiz_moduleinfo($courseid, $sectionnum, $act): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $mi = self::base_moduleinfo('quiz', $courseid, $sectionnum, $act['name']);
        // Quiz intro (e.g. a scenario quiz's shared fact pattern) rides in 'html'.
        $mi->introeditor = self::editor((string) ($act['html'] ?? ''));
        $defaults = [
            'timeopen' => 0, 'timeclose' => 0, 'preferredbehaviour' => 'deferredfeedback',
            'attemptonlast' => 0, 'grademethod' => QUIZ_GRADEHIGHEST, 'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'attemptduring' => 1, 'correctnessduring' => 1, 'maxmarksduring' => 1, 'marksduring' => 1,
            'specificfeedbackduring' => 1, 'generalfeedbackduring' => 1, 'rightanswerduring' => 1,
            'overallfeedbackduring' => 0,
            'attemptimmediately' => 1, 'correctnessimmediately' => 1, 'maxmarksimmediately' => 1,
            'marksimmediately' => 1, 'specificfeedbackimmediately' => 1, 'generalfeedbackimmediately' => 1,
            'rightanswerimmediately' => 1, 'overallfeedbackimmediately' => 1,
            'attemptopen' => 1, 'correctnessopen' => 1, 'maxmarksopen' => 1, 'marksopen' => 1,
            'specificfeedbackopen' => 1, 'generalfeedbackopen' => 1, 'rightansweropen' => 1,
            'overallfeedbackopen' => 1,
            'attemptclosed' => 1, 'correctnessclosed' => 1, 'maxmarksclosed' => 1, 'marksclosed' => 1,
            'specificfeedbackclosed' => 1, 'generalfeedbackclosed' => 1, 'rightanswerclosed' => 1,
            'overallfeedbackclosed' => 1,
            'questionsperpage' => 1, 'shuffleanswers' => 1, 'sumgrades' => 0,
            'timelimit' => 0, 'overduehandling' => 'autosubmit', 'graceperiod' => 86400,
            'quizpassword' => '', 'subnet' => '', 'browsersecurity' => '',
            'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
        ];
        foreach ($defaults as $k => $v) {
            $mi->{$k} = $v;
        }
        $mi->grade = !empty($act['grade']) ? (int) $act['grade'] : 100;
        $mi->attempts = max(0, (int) $act['attempts']);
        $mi->timelimit = max(0, (int) $act['time_limit']) * 60; // minutes → seconds
        return $mi;
    }

    /**
     * Import the quiz's question bank and add the questions to it, then return
     * the quiz's gradebook grade_item id (0 if none/empty).
     */
    protected static function populate_quiz($created, $courserec, $act): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $quizid = (int) $created->instance;
        $cmid = (int) $created->coursemodule;
        $xml = (string) ($act['questions_xml'] ?? '');

        if ($xml !== '') {
            $context = \context_module::instance($cmid);
            $category = question_get_default_category($context->id, true);
            $questionids = self::import_questions_xml($xml, $context, $category, $courserec);

            if ($questionids) {
                $quiz = $DB->get_record('quiz', ['id' => $quizid]);
                $quiz->cmid = $cmid;
                foreach ($questionids as $qid) {
                    quiz_add_quiz_question($qid, $quiz);
                }
                // Recompute sumgrades so the quiz grade item has a non-zero max
                // (the availability percentage is raw_score / sumgrades).
                \mod_quiz\quiz_settings::create($quizid)->get_grade_calculator()->recompute_quiz_sumgrades();
            }
        }

        $gi = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quizid,
            'courseid' => (int) $courserec->id, 'itemnumber' => 0,
        ]);
        return $gi ? (int) $gi->id : 0;
    }

    /**
     * Import a Moodle-XML question string into $category, returning the created
     * question ids. Resilient: a single malformed question is skipped, and any
     * importer failure yields an empty list rather than aborting the push.
     */
    protected static function import_questions_xml($xml, $context, $category, $courserec): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $tmpfile = tempnam(sys_get_temp_dir(), 'pf_qxml_');
        file_put_contents($tmpfile, $xml);

        try {
            $qformat = new \qformat_xml();
            $qformat->setCategory($category);
            $qformat->setContexts([$context]);
            $qformat->setCourse($courserec);
            $qformat->setFilename($tmpfile);
            $qformat->setRealfilename('proffaith.xml');
            $qformat->setMatchgrades('nearest');
            $qformat->setCatfromfile(0);      // force our category, ignore in-file categories
            $qformat->setContextfromfile(0);
            $qformat->setStoponerror(false);  // one bad question must not drop the rest
            $qformat->set_display_progress(false);

            ob_start();                       // swallow importer progress/notices
            $ok = $qformat->importprocess();
            ob_end_clean();

            return $ok ? array_map('intval', $qformat->questionids) : [];
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            debugging('ProfFaith quiz import failed: ' . $e->getMessage());
            return [];
        } finally {
            @unlink($tmpfile);
        }
    }

    /**
     * A restrict-access availability JSON string gating on a grade item.
     * $min / $max are percentages (0–100): min inclusive, max exclusive.
     */
    protected static function grade_availability(int $gradeitemid, $min, $max): string {
        $cond = ['type' => 'grade', 'id' => $gradeitemid];
        if ($min !== null) {
            $cond['min'] = (float) $min;
        }
        if ($max !== null) {
            $cond['max'] = (float) $max;
        }
        return json_encode([
            'op' => '&',
            'c' => [$cond],
            'showc' => [true],
        ]);
    }

    /**
     * Attach an advanced-grading rubric to an assignment's submissions area.
     * Returns true on success. Best-effort: a malformed rubric is logged and
     * skipped rather than aborting the push.
     */
    protected static function apply_rubric($cmid, $rubric): bool {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        if (empty($rubric['criteria']) || empty($USER->id)) {
            return false;
        }
        try {
            $criteria = [];
            $ci = 0;
            foreach ($rubric['criteria'] as $crit) {
                if (empty($crit['levels'])) {
                    continue;
                }
                $ci++;
                $levels = [];
                $li = 0;
                foreach ($crit['levels'] as $lvl) {
                    $li++;
                    $levels["NEWID{$li}"] = [
                        'definition' => (string) $lvl['definition'],
                        'score' => (float) $lvl['score'],
                    ];
                }
                $criteria["NEWID{$ci}"] = [
                    'sortorder' => $ci,
                    'description' => (string) $crit['description'],
                    'levels' => $levels,
                ];
            }
            if (!$criteria) {
                return false;
            }

            $context = \context_module::instance($cmid);
            $manager = get_grading_manager($context, 'mod_assign', 'submissions');
            $manager->set_active_method('rubric');
            $controller = $manager->get_controller('rubric');

            $definition = (object) [
                'name' => !empty($rubric['name']) ? $rubric['name'] : 'Rubric',
                'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
                'rubric' => [
                    'criteria' => $criteria,
                    'options' => [
                        'sortlevelsasc' => 1, 'lockzeropoints' => 1,
                        'showdescriptionteacher' => 1, 'showdescriptionstudent' => 1,
                        'showscoreteacher' => 1, 'showscorestudent' => 1,
                        'enableremarks' => 1, 'showremarksstudent' => 1,
                    ],
                ],
                'saverubric' => 'Save rubric and make it ready',
                'status' => \gradingform_controller::DEFINITION_STATUS_READY,
            ];
            $controller->update_definition($definition);
            return true;
        } catch (\Throwable $e) {
            debugging('ProfFaith rubric apply failed for cm ' . $cmid . ': ' . $e->getMessage());
            return false;
        }
    }
}
