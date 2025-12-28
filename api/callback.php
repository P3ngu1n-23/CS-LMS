<?php
define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// ============================================================================
// 1. CẤU HÌNH LOG RIÊNG (DEBUG LOGGING)
// ============================================================================
function local_aigrading_write_log($message) {
    // File log sẽ nằm tại: /local/aigrading/api/callback_debug.txt
    $log_file = __DIR__ . '/callback_debug.txt';
    $timestamp = date('Y-m-d H:i:s');
    // Ghi nối tiếp (FILE_APPEND)
    file_put_contents($log_file, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

local_aigrading_write_log("------------------------------------------------");
local_aigrading_write_log("New Callback Request Received");

// ============================================================================
// 2. HÀM LẤY KEY (Hỗ trợ cả X-Secret-Key và Authorization Bearer)
// ============================================================================
function local_aigrading_get_received_key() {
    $headers = getallheaders();
    
    // Debug: In toàn bộ header ra file để xem Apache có chặn không
    local_aigrading_write_log("Headers: " . print_r($headers, true));

    // Ưu tiên 1: Authorization Bearer (Apache luôn cho qua)
    // Header sẽ có dạng: "Authorization: Bearer my_secret_key"
    $auth_header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($headers['Authorization'])) {
        $auth_header = $headers['Authorization'];
    }

    if (!empty($auth_header)) {
        if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            local_aigrading_write_log("Found Key in Authorization header.");
            return trim($matches[1]);
        }
    }

    // Ưu tiên 2: X-Secret-Key (Có thể bị Apache chặn)
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'x-secret-key') {
            local_aigrading_write_log("Found Key in X-Secret-Key header.");
            return trim($value);
        }
    }

    return '';
}

// ============================================================================
// 3. XÁC THỰC GLOBAL SECRET (Mutual Auth)
// ============================================================================
$config = get_config('local_aigrading');
$system_key = isset($config->apikey) ? trim($config->apikey) : '';
$received_key = local_aigrading_get_received_key();

local_aigrading_write_log("System Config Key: '$system_key'");
local_aigrading_write_log("Received Key:      '$received_key'");

if (!empty($system_key)) {
    if ($received_key === '' || $received_key !== $system_key) {
        local_aigrading_write_log("ERROR: 401 Unauthorized. Keys mismatch.");
        http_response_code(401);
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid Secret Key']));
    }
} else {
    local_aigrading_write_log("WARNING: System Key is empty in Plugin Settings!");
}

// ============================================================================
// 4. XỬ LÝ DỮ LIỆU
// ============================================================================
$raw_body = file_get_contents('php://input');
$json_data = json_decode($raw_body, true);
$token = optional_param('token', '', PARAM_ALPHANUM);

local_aigrading_write_log("Payload received length: " . strlen($raw_body));

if (empty($token) || empty($json_data)) {
    local_aigrading_write_log("ERROR: 400 Bad Request. Missing token or body.");
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Missing token or body']));
}

global $DB;

// Tìm task theo token
$task = $DB->get_record('local_aigrading_tasks', ['secret_token' => $token], '*', IGNORE_MISSING);

if (!$task) {
    local_aigrading_write_log("ERROR: 404 Task not found for token: $token");
    http_response_code(404);
    die(json_encode(['status' => 'error', 'message' => 'Task not found']));
}

try {
    // --- PHÂN TÍCH CẤU TRÚC JSON MỚI ---
    // Cấu trúc từ Python WebhookPayload:
    // {
    //    "status": "success" | "error",
    //    "system_error": "...",
    //    "data": { "score": 8.5, "feedback": "...", "error": "..." }
    // }

    $status = $json_data['status'] ?? 'error';
    $result_data = $json_data['data'] ?? [];
    $system_error = $json_data['system_error'] ?? null;
    $logic_error = $result_data['error'] ?? null;

    $update = new stdClass();
    $update->id = $task->id;
    $update->timemodified = time();
    $update->ai_response_raw = $raw_body; // Lưu log raw để debug sau này

    // 1. Kiểm tra lỗi (System Error hoặc Logic Error từ AI)
    if ($status === 'error' || !empty($system_error) || !empty($logic_error)) {
        $final_msg = $system_error ?? $logic_error ?? 'Unknown error';
        
        $update->status = 3; // 3 = ERROR
        $update->error_message = is_string($final_msg) ? $final_msg : json_encode($final_msg);
        
        local_aigrading_write_log("Task Status: FAILED - " . $update->error_message);
    } 
    // 2. Thành công -> Lưu điểm vào bảng AI (KHÔNG lưu vào sổ điểm Moodle)
    else {
        $update->status = 2; // 2 = COMPLETED (AI đã chấm xong)
        
        // Lấy điểm và feedback từ object 'data'
        $update->parsed_grade = isset($result_data['score']) ? (float)$result_data['score'] : 0;
        $update->parsed_feedback = isset($result_data['feedback']) ? $result_data['feedback'] : '';
        
        // Clear lỗi cũ nếu có
        $update->error_message = null; 

        local_aigrading_write_log("Task Status: SUCCESS - Score: " . $update->parsed_grade);
    }

    // Cập nhật Database
    $DB->update_record('local_aigrading_tasks', $update);

    // Gửi thông báo realtime (nếu plugin hỗ trợ)
    if (function_exists('local_aigrading_check_and_notify')) {
        local_aigrading_check_and_notify($task->assignmentid);
    }

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    local_aigrading_write_log("EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}