<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$url = new moodle_url('/local/grading_ai/index.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_grading_ai'));
$PAGE->set_heading(get_string('pluginname', 'local_grading_ai'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_grading_ai'));

echo '<p>Hello World!</p>';

echo $OUTPUT->footer();
