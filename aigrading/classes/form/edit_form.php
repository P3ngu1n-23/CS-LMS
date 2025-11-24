<?php
namespace local_aigrading\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class edit_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        
        $mform->addElement('header', 'gen', get_string('settings_header', 'local_aigrading'));

        //ON/OFF
        $options = [0 => 'Tắt (Disabled)', 1 => 'Bật (Enabled)'];
        $mform->addElement('select', 'is_enabled', get_string('enable_label', 'local_aigrading'), $options);
        $mform->setType('is_enabled', PARAM_INT);

        //API KEY
        $mform->addElement('passwordunmask', 'apikey', get_string('apikey_label', 'local_aigrading'));
        $mform->setType('apikey', PARAM_TEXT);

        $this->add_action_buttons(false, get_string('savechanges'));
    }
}