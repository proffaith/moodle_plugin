# local_proffaith

A Moodle local plugin that lets [ProfFaith](https://proffaith.com) push a course
design into Moodle over a web service, and lets faculty target one of their own
sections.

## Web-service functions

Exposed via the `proffaith_provisioning` service (file uploads enabled):

- **`local_proffaith_provision_course`** — create/update a ProfFaith-owned
  course *or* push into an existing one. Builds sections plus:
  - `mod_lti` ALWD activities bound to the preconfigured **ProfFaith** tool
    (gradeable, AGS score writeback);
  - `mod_quiz` quizzes whose question bank is imported from Moodle XML
    (homework / assessment / pre-test / scenario / the Lawyer's Quest choice);
  - `mod_page` learning-path content, **availability-gated** on the pre-test
    grade (≥ / < threshold) and the choice quiz (= 100%);
  - `mod_assign` assignments with **advanced-grading rubrics**;
  - `mod_forum` discussions; `mod_url` + `mod_resource` (uploaded file) readings.
  - Checklist/content links resolve to the real created activities.
  - **Idempotent**, and SIS-safe: a targeted push only replaces ProfFaith's own
    marked sections/activities and reuses empty leading topics — it never
    touches other (e.g. SIS-provisioned) content.
- **`local_proffaith_list_teacher_courses`** — courses a user (matched by
  email/username) can edit, so faculty pick their own section as the target.

## Install

1. Place this directory at `<moodle>/public/local/proffaith`
   (Moodle 4.5+; developed against 5.2).
2. `php admin/cli/upgrade.php --non-interactive`
3. Register the ProfFaith LTI tool (External tool, name **ProfFaith**).
4. Mint a token: `php public/local/proffaith/cli/make_token.php`, then set it on
   the ProfFaith side (`LtiRegistration.provisioning_token`).

## cli/ helpers

| Script | Purpose |
|--------|---------|
| `make_token.php` | Mint/reuse the provisioning web-service token. |
| `enrol_user.php <courseid> <userid>` | Manual student enrolment (dev). |
| `verify_push.php <courseid>` | Dump a pushed course's quizzes + pathway gating. |
| `setup_test_section.php <email>` | Create a teacher + SIS-style target course fixture for testing targeted pushes. |

See `version.php` for the current release.
