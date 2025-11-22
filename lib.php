<?php
defined('MOODLE_INTERNAL') || die;

/**
 * Lấy danh sách các model làm tùy chọn cho trang cài đặt (configselect)
 */
function local_aigrading_get_model_options() {
    // 1. Danh sách dự phòng
    $default_models = [
        'gemini-2.5-flash' => get_string('model_default_25_flash', 'local_aigrading'),
       
    ];

    // 2. Thử lấy từ Cache
    $cache = null;
    try {
        $cache = \cache::make('local_aigrading', 'models');
        if ($cached_models = $cache->get('model_list')) {
            return array_merge($default_models, $cached_models);
        }
    } catch (\Exception $e) {}

    // 3. Nếu không có cache, thử gọi API
    try {
        $clientfile = __DIR__ . '/classes/external/llm_client.php';
        if (!file_exists($clientfile)) {
            return $default_models;
        }
        require_once($clientfile);
        
        $client = new \local_aigrading\external\llm_client();
        if (!$client->is_configured()) {
            return $default_models;
        }

        $api_models = $client->get_available_models();
        if (empty($api_models)) {
            return $default_models;
        }

        // 4. Lưu cache
        if ($cache) {
            $cache->set('model_list', $api_models, 86400); 
        }
        return array_merge($default_models, $api_models);

    } catch (\Exception $e) {
        return $default_models;
    }
}

/**
 * Thêm danh sách bài nộp vào hàng đợi xử lý AI
 */
function local_aigrading_add_to_queue(int $assignmentid, stdClass $user, array $submission_ids) {
    global $DB;

    error_log("[AI GRADING] Add to Queue called for AssignID: $assignmentid with " . count($submission_ids) . " submissions.");

    if (empty($submission_ids)) {
        return 0;
    }

    $count = 0;
    list($insql, $inparams) = $DB->get_in_or_equal($submission_ids);
    $submissions = $DB->get_records_select('assign_submission', "id $insql", $inparams);

    foreach ($submissions as $sub) {
        $exists = $DB->record_exists_select('local_aigrading_tasks', 
            "assignmentid = ? AND submissionid = ? AND status IN (0, 1)", 
            [$assignmentid, $sub->id]
        );

        if (!$exists) {
            $task = new stdClass();
            $task->assignmentid = $assignmentid;
            $task->submissionid = $sub->id;
            $task->userid = $sub->userid;
            $task->status = 0;
            $task->timecreated = time();
            $task->timemodified = time();
            
            try {
                $DB->insert_record('local_aigrading_tasks', $task);
                $count++;
            } catch (Exception $e) {
                error_log("[AI GRADING] Error inserting task for SubID {$sub->id}: " . $e->getMessage());
            }
        }
    }

    if ($count > 0) {
        error_log("[AI GRADING] Added $count tasks. Queuing Ad-hoc task...");
        $task = new \local_aigrading\task\process_queue_adhoc();
        $task->set_custom_data(['assignmentid' => $assignmentid, 'userid' => $user->id]);
        \core\task\manager::queue_adhoc_task($task);
    }

    return $count;
}

/**
 * THAY ĐỔI QUAN TRỌNG: Sử dụng extend_settings_navigation
 * Hàm này thêm nút vào menu Quản trị (Settings) thay vì menu Điều hướng (Navigation)
 * * @param settings_navigation $settingsnav Đối tượng điều hướng cài đặt
 * @param context $context Ngữ cảnh hiện tại
 */
function local_aigrading_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // 1. Chỉ chạy nếu đang ở trong một Module (Activity)
    if ($context->contextlevel != CONTEXT_MODULE) {
        return;
    }

    // 2. Chỉ chạy nếu là Module Assignment
    if (!$PAGE->cm || $PAGE->cm->modname !== 'assign') {
        return;
    }

    // 3. Kiểm tra quyền Giáo viên
    if (!has_capability('mod/assign:grade', $context)) {
        return;
    }

    // 4. Tìm node gốc của Assignment (Thường là 'modulesettings')
    // Node này đại diện cho phần "Assignment Administration"
    $assign_node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);

    if ($assign_node) {
        
        // --- NÚT 1: CHẤM ĐIỂM AI (Trigger) ---
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

        // --- NÚT 2: XEM KẾT QUẢ (Report) ---
        $url_report = new moodle_url('/local/aigrading/report.php', [
            'id' => $PAGE->cm->instance, // report.php dùng 'id' cho assignid
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
    }
}