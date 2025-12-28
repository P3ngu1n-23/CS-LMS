<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// DEBUG: Log request đến
error_log("[AI GRADING AJAX] Hit ajax.php");

try {
    require_login();
    require_sesskey(); // Kiểm tra token bảo mật

    $action = required_param('action', PARAM_ALPHA);
    $assignid = required_param('assignid', PARAM_INT);

    // Kiểm tra quyền
    $assign = $DB->get_record('assign', ['id' => $assignid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('assign', $assignid);
    $context = \context_module::instance($cm->id);
    require_capability('mod/assign:grade', $context);

    error_log("[AI GRADING AJAX] Action: $action | AssignID: $assignid");

    $response = ['status' => 'error', 'message' => 'Unknown action'];

    // --- ACTION 1: SUBMIT ---
    if ($action === 'submit') {
        $submission_ids = optional_param_array('submissions', [], PARAM_INT);
        error_log("[AI GRADING AJAX] Submitting IDs: " . json_encode($submission_ids));
        
        if (!empty($submission_ids)) {
            $count = local_aigrading_add_to_queue($assignid, $USER, $submission_ids);
            $response = ['status' => 'success', 'count' => $count];
        } else {
            $response = ['status' => 'error', 'message' => 'No submissions selected'];
        }
    }

    // --- ACTION 2: POLL ---
    if ($action === 'poll') {
        // Lấy tất cả task, sắp xếp ID tăng dần để lấy cái mới nhất
        $sql = "SELECT id, submissionid, status, parsed_grade 
                FROM {local_aigrading_tasks} 
                WHERE assignmentid = :assignid 
                ORDER BY id ASC";
        
        $tasks = $DB->get_records_sql($sql, ['assignid' => $assignid]);
        
        // Chuyển đổi sang format đơn giản
        $data = [];
        foreach ($tasks as $task) {
            // Key là submissionid, giá trị sẽ ghi đè nếu có nhiều dòng (lấy dòng mới nhất)
            $data[$task->submissionid] = [
                'code' => (int)$task->status, 
                'grade' => $task->parsed_grade
            ];
        }
        
        $response = ['status' => 'success', 'data' => $data];
    }

} catch (Exception $e) {
    error_log("[AI GRADING AJAX] Exception: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

// Trả về JSON
header('Content-Type: application/json');
echo json_encode($response);
die();