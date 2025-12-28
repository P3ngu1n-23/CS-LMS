<?php
namespace local_aigrading\task;

defined('MOODLE_INTERNAL') || die;

class auto_grade_cron extends \core\task\scheduled_task {

    public function get_name() {
        return 'AI Grading: Auto-grade overdue assignments';
    }

    public function execute() {
        global $DB;

        mtrace("--- Bắt đầu quét các bài tập hết hạn để chấm tự động ---");

        // 1. Tìm các assignment thỏa mãn điều kiện:
        // - Có cấu hình enable_autograde = 1
        // - Đã quá hạn nộp (duedate < now)
        // - (Tùy chọn) Chưa được xử lý gần đây (để tránh chạy lặp lại liên tục)
        
        $now = time();
        $sql = "SELECT c.assignmentid, a.duedate, a.course 
                FROM {local_aigrading_config} c
                JOIN {assign} a ON a.id = c.assignmentid
                WHERE c.enable_autograde = 1 
                  AND a.duedate > 0 
                  AND a.duedate < :now";
        
        $assignments = $DB->get_records_sql($sql, ['now' => $now]);

        foreach ($assignments as $assign) {
            mtrace("Kiểm tra Assignment ID: {$assign->assignmentid}...");

            // 2. Lấy danh sách submission của assignment này mà CHƯA ĐƯỢC CHẤM
            // (Chưa có trong bảng local_aigrading_tasks)
            $sub_sql = "SELECT s.id 
                        FROM {assign_submission} s
                        LEFT JOIN {local_aigrading_tasks} t 
                             ON t.submissionid = s.id AND t.assignmentid = s.assignment
                        WHERE s.assignment = :aid 
                          AND s.status = 'submitted'
                          AND t.id IS NULL"; // Chưa có trong queue
            
            $submissions = $DB->get_records_sql($sub_sql, ['aid' => $assign->assignmentid]);
            $sub_ids = array_keys($submissions);

            if (!empty($sub_ids)) {
                // 3. Đẩy vào hàng đợi (Sử dụng hàm trong lib.php)
                // Lưu ý: User thực hiện là Admin (System)
                $admin = \core_user::get_user(2); // Thường ID 2 là admin
                require_once(__DIR__ . '/../../lib.php');
                
                $count = local_aigrading_add_to_queue($assign->assignmentid, $admin, $sub_ids);
                mtrace("-> Đã tự động thêm $count bài nộp vào hàng đợi.");
            } else {
                mtrace("-> Không có bài mới.");
            }
        }
        
        mtrace("--- Hoàn tất quét ---");
    }
}