<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/form/config_form.php');

global $DB, $PAGE, $OUTPUT, $USER;

// 1. Lấy tham số
$assignid = required_param('assignid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

// 2. Xác thực
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$assign = $DB->get_record('assign', ['id' => $assignid], '*', MUST_EXIST);
$context = \context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('moodle/course:manageactivities', $context);

// 3. Setup Page
$url = new moodle_url('/local/aigrading/configure.php', ['assignid' => $assignid, 'cmid' => $cmid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title('Cấu hình AI: ' . $assign->name);
$PAGE->set_heading('Cấu hình AI Grading');

// 4. Khởi tạo Form
$mform = new \local_aigrading\form\config_form($url);

// ============================================================================
// 5. XỬ LÝ FORM (LOAD & SAVE)
// ============================================================================

// A. Nếu người dùng bấm "Hủy"
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/assign/view.php', ['id' => $cmid]));
} 
// B. Nếu người dùng bấm "Lưu" (Submit)
else if ($data = $mform->get_data()) {
    
    // Lấy bản ghi cũ (nếu có) để lấy ID cập nhật
    $config = $DB->get_record('local_aigrading_config', ['assignmentid' => $assignid]);
    
    $record = new stdClass();
    $record->assignmentid = $assignid;
    $record->enable_autograde = $data->enable_autograde;
    
    // --- QUAN TRỌNG: LƯU CÁC TRƯỜNG TEXT ---
    $record->reference_text = $data->reference_text;         // Lưu đáp án mẫu
    $record->teacher_instruction = $data->teacher_instruction; // Lưu hướng dẫn chấm (Prompt)
    // ----------------------------------------

    $record->timemodified = time();

    if ($config) {
        // Cập nhật (Update)
        $record->id = $config->id;
        try {
            $DB->update_record('local_aigrading_config', $record);
        } catch (Exception $e) {
            print_error('Lỗi Database Update: Có thể thiếu cột teacher_instruction. Hãy cài lại plugin.');
        }
    } else {
        // Tạo mới (Insert)
        $record->timecreated = time();
        try {
            $DB->insert_record('local_aigrading_config', $record);
        } catch (Exception $e) {
            print_error('Lỗi Database Insert: Có thể thiếu cột teacher_instruction. Hãy cài lại plugin.');
        }
    }

    // Lưu File (Giữ nguyên)
    file_save_draft_area_files(
        $data->reference_file, 
        $context->id, 
        'local_aigrading', 
        'reference_file', 
        0, 
        ['subdirs' => 0]
    );

    \core\notification::add('Đã lưu cấu hình thành công!', 'notifysuccess');
    redirect($url); // Reload trang
} 
// C. Nếu mới vào trang (Load dữ liệu cũ)
else {
    $config = $DB->get_record('local_aigrading_config', ['assignmentid' => $assignid]);

    $default_data = [
        'assignid' => $assignid,
        'cmid' => $cmid,
        'enable_autograde' => ($config && isset($config->enable_autograde)) ? $config->enable_autograde : 0,
        
        // --- QUAN TRỌNG: LOAD DỮ LIỆU CŨ LÊN FORM ---
        // Sử dụng isset để tránh lỗi nếu DB cũ chưa có cột này
        'reference_text' => ($config && isset($config->reference_text)) ? $config->reference_text : '',
        'teacher_instruction' => ($config && isset($config->teacher_instruction)) ? $config->teacher_instruction : ''
    ];

    // Chuẩn bị File Manager
    $draftitemid = file_get_submitted_draft_itemid('reference_file');
    file_prepare_draft_area(
        $draftitemid, 
        $context->id, 
        'local_aigrading', 
        'reference_file', 
        0, 
        ['subdirs' => 0]
    );
    $default_data['reference_file'] = $draftitemid;

    $mform->set_data($default_data);
}

// 6. Hiển thị
echo $OUTPUT->header();
echo $OUTPUT->heading('Cấu hình AI cho bài tập này');
echo $OUTPUT->single_button(new moodle_url('/mod/assign/view.php', ['id' => $cmid]), 'Quay lại Assignment', 'get');

$mform->display();

echo $OUTPUT->footer();