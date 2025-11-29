<?php
namespace local_aigrading\task;

defined('MOODLE_INTERNAL') || die;

class process_queue_adhoc extends \core\task\adhoc_task {

    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $assignmentid = $data->assignmentid ?? 0;
        
        mtrace("--- Bắt đầu xử lý hàng đợi AI Grading (AssignID: $assignmentid) ---");

        // Lấy các task đang Pending
        $tasks = $DB->get_records('local_aigrading_tasks', 
            ['status' => 0, 'assignmentid' => $assignmentid], 
            'id ASC', '*', 0, 50
        );

        if (empty($tasks)) {
            return;
        }

        if (!class_exists('\local_aigrading\engine\processor')) {
            mtrace("Lỗi: Không tìm thấy class processor.");
            return;
        }
        $processor = new \local_aigrading\engine\processor();

        foreach ($tasks as $task) {
            // Cập nhật trạng thái sang 1 (Processing)
            $task->status = 1; 
            $task->timemodified = time();
            $DB->update_record('local_aigrading_tasks', $task);

            try {
                // SỬA LỖI: Truyền toàn bộ object $task vào
                $processor->process_submission($task);

                mtrace("-> Đã gửi request sang AI (ID {$task->id}). Đang chờ Callback...");

            } catch (\Exception $e) {
                // ... (Xử lý lỗi giữ nguyên) ...
            }
        }

        mtrace("--- Hoàn tất đợt gửi request ---");
    }
}