<?php
namespace local_aigrading\task;

defined('MOODLE_INTERNAL') || die;

class process_queue_adhoc extends \core\task\adhoc_task {

    public function execute() {
        global $DB, $CFG;

        $data = $this->get_custom_data();
        $assignmentid = $data->assignmentid ?? 0;
        $teacherid = $data->userid ?? 0; // ID giáo viên để gửi thông báo

        mtrace("--- Bắt đầu xử lý hàng đợi AI Grading (AssignID: $assignmentid) ---");

        // Lấy các task đang Pending
        $tasks = $DB->get_records('local_aigrading_tasks', 
            ['status' => 0, 'assignmentid' => $assignmentid], 
            'id ASC', '*', 0, 50
        );

        if (empty($tasks)) {
            return;
        }

        // Khởi tạo Processor
        // (Kiểm tra xem file có tồn tại không để tránh lỗi fatal nếu bạn chưa tạo processor)
        if (!class_exists('\local_aigrading\engine\processor')) {
            mtrace("Lỗi: Không tìm thấy class processor.");
            return;
        }
        $processor = new \local_aigrading\engine\processor();

        // Biến đếm cho thông báo
        $count_success = 0;
        $count_fail = 0;

        foreach ($tasks as $task) {
            // ... (Logic cập nhật status = 1 giữ nguyên) ...
            $task->status = 1; 
            $task->timemodified = time();
            $DB->update_record('local_aigrading_tasks', $task);

            try {
                // Gọi hàm xử lý
                $result = $processor->process_submission($task->assignmentid, $task->submissionid);

                // ... (Logic cập nhật status = 2 giữ nguyên) ...
                $task->status = 2;
                $task->ai_response_raw = $result['raw'];
                $task->parsed_grade = $result['grade'];
                $task->parsed_feedback = $result['feedback'];
                $task->timemodified = time();
                $DB->update_record('local_aigrading_tasks', $task);
                
                $count_success++; // Tăng biến đếm
                mtrace("-> Thành công: ID {$task->id}");

            } catch (\Exception $e) {
                // ... (Logic cập nhật status = 3 giữ nguyên) ...
                $task->status = 3;
                $task->error_message = $e->getMessage();
                $task->timemodified = time();
                $DB->update_record('local_aigrading_tasks', $task);
                
                $count_fail++; // Tăng biến đếm
                mtrace("-> Thất bại: ID {$task->id} - " . $e->getMessage());
            }
        }

        // --- LOGIC GỬI THÔNG BÁO ---
        
        // 1. Kiểm tra xem còn bài nào đang chờ (Pending) cho assignment này không?
        $remaining = $DB->count_records('local_aigrading_tasks', [
            'assignmentid' => $assignmentid,
            'status' => 0
        ]);

        // --- THÊM DEBUG VÀO ĐÂY ---
        mtrace("---------------- DEBUG INFO ----------------");
        mtrace("Số bài còn lại (Remaining): " . $remaining);
        mtrace("ID Giáo viên nhận tin (TeacherID): " . $teacherid);
        // ---------------------------------------------

        // Chỉ gửi thông báo nếu đã hết bài chờ
        if ($remaining == 0 && $teacherid > 0) {
            mtrace("-> ĐỦ ĐIỀU KIỆN: Đang gọi hàm gửi thông báo..."); // Debug dòng này
            $this->send_notification($assignmentid, $teacherid, $count_success, $count_fail);
        } else {
            mtrace("-> KHÔNG ĐỦ ĐIỀU KIỆN gửi thông báo."); // Debug dòng này
        }

        mtrace("--- Hoàn tất đợt xử lý ---");
    }

    /**
     * Hàm phụ trợ để gửi thông báo
     */
    private function send_notification($assignid, $userid, $success, $fail) {
        global $DB;

        // Lấy thông tin người nhận
        $userto = $DB->get_record('user', ['id' => $userid]);
        // Lấy người gửi (Hệ thống/No-reply)
        $userfrom = \core_user::get_noreply_user();
        
        // Lấy tên Assignment
        $assign = $DB->get_record('assign', ['id' => $assignid]);
        $assign_name = $assign ? $assign->name : 'Assignment #' . $assignid;

        // Tạo URL đến trang báo cáo
        $url = new \moodle_url('/local/aigrading/report.php', [
            'id' => $assignid,
            'cmid' => get_coursemodule_from_instance('assign', $assignid)->id
        ]);

        // Soạn nội dung
        $data = [
            'teachername' => fullname($userto),
            'assignment' => $assign_name,
            'count' => $success + $fail,
            'success' => $success,
            'fail' => $fail,
            'url' => $url->out(false)
        ];

        $subject = get_string('notification_subject', 'local_aigrading', $data);
        $body = get_string('notification_body', 'local_aigrading', $data);

        // Tạo đối tượng tin nhắn
        $message = new \core\message\message();
        $message->component = 'local_aigrading';
        $message->name = 'grading_completed'; // Phải khớp với db/messages.php
        $message->userfrom = $userfrom;
        $message->userto = $userto;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_MARKDOWN;
        $message->fullmessagehtml = text_to_html($body);
        $message->smallmessage = $subject;
        $message->contexturl = $url;
        $message->contexturlname = 'Xem kết quả';

        // Gửi
        $msgid = \message_send($message);
        mtrace("Đã gửi thông báo cho User ID $userid (Message ID: $msgid)");
    }
}