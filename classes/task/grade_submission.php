<?php
namespace local_aigrading\task;

defined('MOODLE_INTERNAL') || die;

/**
 * Tác vụ nền để xử lý chấm điểm AI
 */
class grade_submission extends \core\task\scheduled_task {

    /**
     * Lấy tên hiển thị của tác vụ.
     *
     * @return string
     */
    public function get_name() {
        // Tên này sẽ hiển thị trong trang Admin -> Tasks
        return get_string('task_grade_submission', 'local_aigrading');
    }

    /**
     * Hàm chính được Moodle Cron gọi.
     * Đây chính là "hàm kích hoạt" của bạn.
     */
    public function execute() {
        
        // --- YÊU CẦU CỦA BẠN: GHI LOG ---
        // mtrace() là hàm chuẩn của Moodle để ghi log trong CLI/Cron
        mtrace("--- [AI GRADING TASK] Tác vụ nền (Cron) đã được kích hoạt ---");
        error_log("AI Grading Task started at " . date('Y-m-d H:i:s'));

        // --- LOGIC TƯƠNG LAI CỦA BẠN SẼ Ở ĐÂY ---
        // Ví dụ:
        // 1. Tìm các assignment đã "overdue" (duedate < time()) và chưa xử lý.
        // 2. Lấy tất cả bài nộp (submissions) của các assignment đó.
        // 3. Thêm các bài nộp vào hàng đợi chấm điểm (bảng CSDL của bạn).
        // 4. mtrace("Đã thêm X bài nộp vào hàng đợi.");
        
        mtrace("--- [AI GRADING TASK] Tác vụ kết thúc ---");
    }
}