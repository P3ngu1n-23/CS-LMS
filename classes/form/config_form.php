<?php
namespace local_aigrading\form;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

class config_form extends \moodleform {
    
    public function definition() {
        $mform = $this->_form;

        // Hidden fields
        $mform->addElement('hidden', 'assignid');
        $mform->setType('assignid', PARAM_INT);
        
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        // Header
        $mform->addElement('header', 'general', 'Cấu hình Chấm điểm AI');

        // 1. Checkbox Tự động chấm
        $mform->addElement('selectyesno', 'enable_autograde', 'Tự động chấm khi hết hạn (Due Date)');
        // SỬA LẠI: Dùng identifier trùng tên với field để dễ quản lý
        $mform->addHelpButton('enable_autograde', 'enable_autograde', 'local_aigrading');
        $mform->setDefault('enable_autograde', 0);

        $mform->addElement('textarea', 'teacher_instruction', 'Hướng dẫn chấm (Prompt)', 'wrap="virtual" rows="5" style="width:100%"');
        $mform->setType('teacher_instruction', PARAM_RAW);
        $mform->addHelpButton('teacher_instruction', 'teacher_instruction', 'local_aigrading');

        // 2. File Manager (Đáp án mẫu)
        $mform->addElement('filemanager', 'reference_file', 'File Đáp án mẫu (Reference)', null, [
            'subdirs' => 0,
            'maxbytes' => 10485760, // 10MB
            'maxfiles' => 1,
            'accepted_types' => ['.pdf', '.docx', '.txt', '.doc']
        ]);
        $mform->addHelpButton('reference_file', 'reference_file', 'local_aigrading');

        $mform->addElement('textarea', 'reference_text', 'Đáp án mẫu (Văn bản)', 'wrap="virtual" rows="8" style="width:100%"');
        // PARAM_RAW cho phép nhập xuống dòng và ký tự đặc biệt, nhưng Moodle vẫn lọc XSS cơ bản
        $mform->setType('reference_text', PARAM_RAW); 
        $mform->addHelpButton('reference_text', 'reference_text', 'local_aigrading');

        // Buttons
        $this->add_action_buttons(true, 'Lưu cấu hình');
    }
}