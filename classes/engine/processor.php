<?php
namespace local_aigrading\engine;

defined('MOODLE_INTERNAL') || die;

class processor {

    /**
     * Xử lý logic chính cho một bài nộp
     */
    public function process_submission($assignmentid, $submissionid) {
        global $DB;

        // 1. Fetch dữ liệu Assignment
        $assign = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
        // Lấy mô tả assignment (đề bài)
        $intro = strip_tags($assign->intro); 

        // 2. Fetch dữ liệu Submission (Bài làm)
        // Lấy nội dung từ plugin 'onlinetext' (Văn bản trực tuyến)
        $onlinetext_table = 'assignsubmission_onlinetext';
        $submission_text = "";
        
        // Kiểm tra xem bảng onlinetext có tồn tại không (đề phòng assignment không bật onlinetext)
        if ($DB->get_manager()->table_exists($onlinetext_table)) {
            $online_sub = $DB->get_record($onlinetext_table, ['submission' => $submissionid]);
            if ($online_sub) {
                $submission_text = strip_tags($online_sub->onlinetext);
            }
        }

        // (Tùy chọn) Logic lấy tên file
        $files = [];
        // ... Code lấy file từ File Storage API của Moodle ...

        if (empty($submission_text) && empty($files)) {
            throw new \moodle_exception('empty_submission', 'local_aigrading');
        }

        // 3. Generate Prompt
        $generator = new prompt_generator();
        $prompt = $generator->generate_prompt($intro, $submission_text, $files);

        // 4. Call API (Sử dụng llm_client)
        // Chúng ta cần sửa llm_client để có hàm gửi prompt (generate_response)
        // Giả sử llm_client đã có hàm generate_content($prompt)
        $client = new \local_aigrading\external\llm_client();
        $response = $client->generate_content($prompt); // Bạn cần thêm hàm này vào llm_client

        if (!$response['success']) {
            throw new \Exception("API Error: " . $response['message']);
        }

        // 5. Parse JSON Response
        // Gemini đôi khi trả về text có dính ```json ... ```, cần làm sạch
        $clean_json = $this->clean_json_string($response['message']); // Message ở đây là nội dung text trả về
        $data = json_decode($clean_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON from AI: " . $clean_json);
        }

        return [
            'raw' => $response['message'],
            'grade' => $data['grade'] ?? 0,
            'feedback' => $data['feedback'] ?? ''
        ];
    }

    /**
     * Hàm phụ trợ để làm sạch chuỗi JSON từ AI
     */
    private function clean_json_string($text) {
        $text = preg_replace('/^```json/', '', $text);
        $text = preg_replace('/^```/', '', $text);
        $text = preg_replace('/```$/', '', $text);
        return trim($text);
    }
}