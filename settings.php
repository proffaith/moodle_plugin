<?php
// Admin settings: where to notify ProfFaith on assignment activity, and the
// shared secret used to HMAC-sign those webhook calls.
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_proffaith', get_string('pluginname', 'local_proffaith'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_proffaith/webhook_url',
        get_string('webhook_url', 'local_proffaith'),
        get_string('webhook_url_desc', 'local_proffaith'),
        '', PARAM_URL));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_proffaith/webhook_secret',
        get_string('webhook_secret', 'local_proffaith'),
        get_string('webhook_secret_desc', 'local_proffaith'),
        ''));
}
