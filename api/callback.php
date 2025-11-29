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
$data = json_decode($raw_body, true);
$token = optional_param('token', '', PARAM_ALPHANUM);

if (empty($token) || empty($data)) {
    local_aigrading_write_log("ERROR: 400 Bad Request. Missing token or body.");
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Missing token or body']));
}

global $DB;

// Tìm task
$sql = "SELECT * FROM {local_aigrading_tasks} WHERE secret_token = :token AND status = 1";
$tasks = $DB->get_records_sql($sql, ['token' => $token], 0, 1);
$task = reset($tasks);

if (!$task) {
    local_aigrading_write_log("ERROR: 404 Task not found for token: $token");
    http_response_code(404);
    die(json_encode(['status' => 'error', 'message' => 'Task not found']));
}

try {
    $update = new stdClass();
    $update->id = $task->id;
    $update->timemodified = time();

    if (!empty($data['error'])) {
        $update->status = 3; 
        $update->error_message = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
        local_aigrading_write_log("Task Status: ERROR - " . $update->error_message);
    } else {
        $update->status = 2; 
        $update->ai_response_raw = $raw_body;
        $update->parsed_grade = isset($data['score']) ? (float)$data['score'] : 0;
        $update->parsed_feedback = isset($data['feedback']) ? $data['feedback'] : '';
        local_aigrading_write_log("Task Status: SUCCESS - Score: " . $update->parsed_grade);
    }

    $DB->update_record('local_aigrading_tasks', $update);

    if (function_exists('local_aigrading_check_and_notify')) {
        local_aigrading_check_and_notify($task->assignmentid);
    }

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    local_aigrading_write_log("EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}