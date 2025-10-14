<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_grading_ai', get_string('pluginname', 'local_grading_ai'));
    $ADMIN->add('localplugins', $settings);
}
