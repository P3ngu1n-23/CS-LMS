<?php
defined('MOODLE_INTERNAL') || die;

// ============================================================================
// 1. CẤU HÌNH HẰNG SỐ (CONSTANTS)
// ============================================================================

// Thời gian tối đa (giây) cho phép một task chạy.
// Nếu quá thời gian này mà task vẫn ở status=1 (Processing), nó sẽ bị coi là treo.
// 300 giây = 5 phút.
define('LOCAL_AIGRADING_TIMEOUT', 300); 

// ============================================================================
// 2. CÁC HÀM HỖ TRỢ CẤU HÌNH (SETTINGS)
// ============================================================================

/**
 * Lấy danh sách các model làm tùy chọn cho trang cài đặt
 */
function local_aigrading_get_model_options() {
    $default_models = [
        'gemini-2.5-flash' => get_string('model_default_25_flash', 'local_aigrading'),
        'gemini-2.0-flash-exp' => get_string('model_default_20_flash', 'local_aigrading'),
        'gemini-1.5-pro' => get_string('model_default_15_pro', 'local_aigrading'),
        'gemini-1.5-flash' => get_string('model_default_15_flash', 'local_aigrading'),
    ];

    $cache = null;
    try {
        $cache = \cache::make('local_aigrading', 'models');
        if ($cached_models = $cache->get('model_list')) {
            return array_merge($default_models, $cached_models);
        }
    } catch (\Exception $e) {}

    try {
        $clientfile = __DIR__ . '/classes/external/llm_client.php';
        if (!file_exists($clientfile)) return $default_models;
        require_once($clientfile);
        
        $client = new \local_aigrading\external\llm_client();
        if (!$client->is_configured()) return $default_models;

        // Lưu ý: Hàm này có thể không hoạt động nếu bạn dùng FastAPI base url
        // nên ta bọc try catch kỹ.
        if (method_exists($client, 'get_available_models')) {
             $api_models = $client->get_available_models();
             if (!empty($api_models)) {
                 if ($cache) $cache->set('model_list', $api_models, 86400); 
                 return array_merge($default_models, $api_models);
             }
        }
        return $default_models;

    } catch (\Exception $e) {
        return $default_models;
    }
}

// ============================================================================
// 3. LOGIC HÀNG ĐỢI (QUEUE LOGIC)
// ============================================================================

/**
 * Thêm bài vào hàng đợi (LOGIC THÔNG MINH: LUÔN TẠO MỚI + KIỂM TRA TIMEOUT)
 */
function local_aigrading_add_to_queue(int $assignmentid, stdClass $user, array $submission_ids) {
    global $DB;

    if (empty($submission_ids)) {
        return ['queued' => 0, 'skipped' => 0, 'timeout_reset' => 0];
    }

    $count_queued = 0;
    $count_skipped = 0;
    $count_timeout_reset = 0;

    list($insql, $inparams) = $DB->get_in_or_equal($submission_ids);
    $submissions = $DB->get_records_select('assign_submission', "id $insql", $inparams);

    foreach ($submissions as $sub) {
        
        // Kiểm tra task đang chạy (Status 0 hoặc 1)
        $active_task = $DB->get_record_select('local_aigrading_tasks', 
            "assignmentid = ? AND submissionid = ? AND status IN (0, 1)", 
            [$assignmentid, $sub->id],
            '*', IGNORE_MULTIPLE
        );

        $new_task_status = 0; // Mặc định là chạy (Pending)
        $new_task_message = '';

        if ($active_task) {
            $time_elapsed = time() - $active_task->timemodified;

            // A. Task cũ vẫn "tươi" (< 5 phút) -> BỎ QUA YÊU CẦU MỚI
            if ($time_elapsed < LOCAL_AIGRADING_TIMEOUT) {
                $new_task_status = 3; // Skipped
                $new_task_message = "Skipped: Previous task running ({$time_elapsed}s ago).";
                $count_skipped++;
            } 
            // B. Task cũ bị "treo" (> 5 phút) -> HỦY CŨ, CHẠY MỚI
            else {
                $active_task->status = 3;
                $active_task->error_message = "Timeout: Auto-cancelled (Run > 5m).";
                $active_task->timemodified = time();
                try {
                    $DB->update_record('local_aigrading_tasks', $active_task);
                    $count_timeout_reset++;
                } catch (Exception $e) {}

                $new_task_status = 0; // Chạy mới
                $count_queued++;
            }
        } else {
            // C. Không có task nào -> Chạy mới
            $new_task_status = 0;
            $count_queued++;
        }

        // Luôn tạo bản ghi lịch sử
        $task = new stdClass();
        $task->assignmentid = $assignmentid;
        $task->submissionid = $sub->id;
        $task->userid = $sub->userid;
        $task->status = $new_task_status;
        $task->error_message = $new_task_message;
        $task->timecreated = time();
        $task->timemodified = time();
        
        try {
            $DB->insert_record('local_aigrading_tasks', $task);
        } catch (Exception $e) {
            error_log("[AI GRADING] Insert Error: " . $e->getMessage());
        }
    }

    // Kích hoạt Ad-hoc task
    if ($count_queued > 0) {
        $task = new \local_aigrading\task\process_queue_adhoc();
        $task->set_custom_data(['assignmentid' => $assignmentid, 'userid' => $user->id]);
        \core\task\manager::queue_adhoc_task($task);
    }

    return [
        'queued' => $count_queued,
        'skipped' => $count_skipped,
        'timeout_reset' => $count_timeout_reset
    ];
}

// ============================================================================
// 4. LOGIC THÔNG BÁO (NOTIFICATION)
// ============================================================================

/**
 * Kiểm tra xem assignment đã chấm xong hết chưa, nếu xong thì gửi thông báo
 * Hàm này được gọi bởi callback.php
 */
function local_aigrading_check_and_notify($assignmentid) {
    global $DB;

    // Kiểm tra còn bài nào đang Processing (1) hoặc Pending (0) không?
    $remaining = $DB->count_records_select('local_aigrading_tasks', 
        "assignmentid = ? AND status IN (0, 1)", 
        [$assignmentid]
    );

    if ($remaining > 0) {
        return; // Chưa xong
    }

    // Lấy thông tin để gửi báo cáo (Lấy userid của task gần nhất làm người nhận)
    // Lưu ý: Logic này tạm thời gửi cho người đã trigger task gần nhất.
    // Để chính xác hơn, bạn nên lưu 'trigger_userid' vào bảng tasks.
    $last_task = $DB->get_record_sql(
        "SELECT * FROM {local_aigrading_tasks} WHERE assignmentid = ? ORDER BY id DESC", 
        [$assignmentid], 
        IGNORE_MULTIPLE
    );
    
    // Ở đây ta tạm lấy Admin (id=2) hoặc người dùng hiện tại nếu có session, 
    // Thực tế nên lưu ID giáo viên vào bảng task lúc trigger.
    // Đoạn code dưới đây là ví dụ gửi cho Admin nếu chạy qua Callback
    $recipient_id = 2; // Default admin
    
    // Gửi thông báo (Copy logic send_notification từ task cũ vào đây nếu cần thiết,
    // hoặc gọi hàm send_notification nếu bạn public nó)
}

// ============================================================================
// 5. NAVIGATION (MENU)
// ============================================================================

/**
 * Thêm nút vào menu cài đặt Assignment
 */
function local_aigrading_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    if ($context->contextlevel != CONTEXT_MODULE) return;
    if (!$PAGE->cm || $PAGE->cm->modname !== 'assign') return;
    if (!has_capability('mod/assign:grade', $context)) return;

    $assign_node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);

    if ($assign_node) {
        // Nút 1: Chấm điểm
        $url_trigger = new moodle_url('/local/aigrading/trigger.php', [
            'assignid' => $PAGE->cm->instance,
            'cmid' => $PAGE->cm->id
        ]);
        $assign_node->add(
            'Chấm điểm với AI',
            $url_trigger,
            navigation_node::TYPE_SETTING,
            null,
            'local_aigrading_trigger',
            new pix_icon('i/grades', '')
        );

        // Nút 2: Kết quả
        $url_report = new moodle_url('/local/aigrading/report.php', [
            'id' => $PAGE->cm->instance,
            'cmid' => $PAGE->cm->id
        ]);
        $assign_node->add(
            'Kết quả chấm AI',
            $url_report,
            navigation_node::TYPE_SETTING,
            null,
            'local_aigrading_report',
            new pix_icon('i/report', '')
        );

        // Nút 3: Cấu hình
        $url_config = new moodle_url('/local/aigrading/configure.php', [
            'assignid' => $PAGE->cm->instance,
            'cmid' => $PAGE->cm->id
        ]);
        $assign_node->add(
            'Cấu hình AI Grading',
            $url_config,
            navigation_node::TYPE_SETTING,
            null,
            'local_aigrading_config',
            new pix_icon('i/settings', '')
        );
    }
}