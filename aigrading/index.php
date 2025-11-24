<?php
require_once('../../config.php');
require_once('classes/form/edit_form.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/aigrading/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_aigrading'));
$PAGE->set_heading(get_string('pluginname', 'local_aigrading'));

$mform = new \local_aigrading\form\edit_form();

$default_data = new stdClass();
$default_data->is_enabled = get_user_preferences('local_aigrading_enabled', 0);
$default_data->apikey     = get_user_preferences('local_aigrading_apikey', '');
$mform->set_data($default_data);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/my/'));
} else if ($data = $mform->get_data()) {
    
    set_user_preference('local_aigrading_enabled', $data->is_enabled);
    set_user_preference('local_aigrading_apikey', $data->apikey);
    
    redirect($PAGE->url, get_string('saved_msg', 'local_aigrading'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->box_start('generalbox');
$mform->display();
echo $OUTPUT->box_end();
echo $OUTPUT->footer();