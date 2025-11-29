<?php
namespace local_aigrading\engine;

defined('MOODLE_INTERNAL') || die;

class processor {

    /**
     * Xử lý chính: Thu thập dữ liệu và gửi sang AI
     *
     * @param stdClass $task Đối tượng task từ DB (đã bao gồm id, assignmentid, submissionid)
     * @return array Trạng thái gửi
     */
    public function process_submission($task) {
        global $DB;

        // Lấy ID từ đối tượng task được truyền vào (QUAN TRỌNG: Không query lại DB để tìm ID nữa)
        $taskid = $task->id; 
        $assignmentid = $task->assignmentid;
        $submissionid = $task->submissionid;

        // 1. Lấy dữ liệu Assignment & Context
        $assign = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignmentid);
        $context = \context_module::instance($cm->id);

        // 2. Nội dung đề bài & File đề bài
        $intro_text = strip_tags($assign->intro);
        $fs = get_file_storage();
        $assign_files = $fs->get_area_files($context->id, 'mod_assign', 'intro', 0, 'sortorder', false);
        $assign_files = array_filter($assign_files, function($f) { return !$f->is_directory(); });

        // 3. Nội dung bài làm & File bài làm
        $submission_text = "";
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($onlinetext) {
            $submission_text = strip_tags($onlinetext->onlinetext);
        }

        $sub_files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submissionid, 'sortorder', false);
        $sub_files = array_filter($sub_files, function($f) { return !$f->is_directory(); });

        // 4. Lấy Cấu hình AI
        $ai_config = $DB->get_record('local_aigrading_config', ['assignmentid' => $assignmentid]);
        
        $reference_text_content = "";
        $explicit_instruction = ""; // Biến lưu hướng dẫn giáo viên

        if ($ai_config) {
            if (!empty($ai_config->reference_text)) {
                $reference_text_content = $ai_config->reference_text;
            }
            // Lấy hướng dẫn từ config
            if (!empty($ai_config->teacher_instruction)) {
                $explicit_instruction = $ai_config->teacher_instruction;
            }
        }
        
        $reference_files = $fs->get_area_files($context->id, 'local_aigrading', 'reference_file', 0, 'sortorder', false);
        $reference_files = array_filter($reference_files, function($f) { return !$f->is_directory(); });

        // 5. Lấy RAG Context (Lịch sử)
        $rag_history = $this->get_teacher_history($assignmentid);

        $final_instruction = "";
        
        if (!empty($explicit_instruction)) {
            $final_instruction .= "YÊU CẦU CỤ THỂ CỦA GIÁO VIÊN:\n" . $explicit_instruction . "\n\n";
        }
        
        if (!empty($rag_history)) {
            $final_instruction .= $rag_history;
        }

        // 6. Cập nhật Token (SỬA ĐỔI QUAN TRỌNG)
        // Chúng ta cập nhật trực tiếp vào ID của task đang xử lý, không quan tâm có bao nhiêu task khác của submission này.
        
        $task_token = bin2hex(random_bytes(32));
        $task->secret_token = $task_token;
        
        // Chỉ cập nhật dòng này trong DB
        try {
            $DB->update_record('local_aigrading_tasks', $task);
        } catch (\Exception $e) {
            throw new \Exception("DB Error: Không thể cập nhật token cho Task ID {$taskid}. " . $e->getMessage());
        }

        $callback_url = new \moodle_url('/local/aigrading/api/callback.php', ['token' => $task_token]);

        // 7. Mapping dữ liệu
        $text_data = [
            'callback_url' => $callback_url->out(false),
            'assignment_content' => $intro_text,
            'student_submission_text' => $submission_text,
            'reference_answer_text' => $reference_text_content, 
            'grading_criteria' => "", 
            'teacher_instruction' => $final_instruction,
            'max_score' => (float)$assign->grade
        ];

        $file_data = [
            'assignment_attachments' => $assign_files,
            'student_submission_files' => $sub_files,
            'reference_answer_file' => $reference_files
        ];

        // 8. Gửi đi
        $client = new \local_aigrading\external\llm_client();
        $result = $client->send_grading_request($text_data, $file_data);

        if (!$result['success']) {
            throw new \Exception($result['message']);
        }

        return ['status' => 'sent'];
    }

    /**
     * Lấy các ghi chú của giáo viên từ các lần chấm trước để làm context (RAG)
     */
    private function get_teacher_history($assignid) {
        global $DB;
        
        // Chỉ lấy những bài đã có điểm GV và có ghi chú (teacher_notes)
        $sql = "SELECT teacher_notes FROM {local_aigrading_tasks} 
                WHERE assignmentid = :aid 
                  AND teacher_grade IS NOT NULL 
                  AND teacher_notes IS NOT NULL 
                  AND teacher_notes != ''
                ORDER BY timemodified DESC";
        
        // Lấy tối đa 3 ví dụ gần nhất
        $recs = $DB->get_records_sql($sql, ['aid' => $assignid], 0, 3);
        
        $txt = "";
        if (!empty($recs)) {
            $txt .= "LƯU Ý QUAN TRỌNG TỪ GIÁO VIÊN (Dựa trên lịch sử chấm trước đây):\n";
            foreach ($recs as $r) {
                $txt .= "- " . $r->teacher_notes . "\n";
            }
        }
        return $txt;
    }
}