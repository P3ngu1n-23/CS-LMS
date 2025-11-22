<?php
namespace local_aigrading\engine;

defined('MOODLE_INTERNAL') || die;

class prompt_generator {

    /**
     * Tạo prompt gửi cho AI
     */
    public function generate_prompt($assignment_intro, $student_text, $file_list = []) {
        
        // Xử lý danh sách file (đơn giản hóa)
        $files_info = "";
        if (!empty($file_list)) {
            $files_info = "Học sinh có đính kèm các file sau: " . implode(", ", $file_list) . ". (Lưu ý: Hiện tại tôi chỉ có thể đọc nội dung văn bản bên dưới).";
        }

        $prompt = <<<EOT
Bạn là một trợ lý giáo viên công tâm. Nhiệm vụ của bạn là chấm điểm bài làm của học sinh.

--- THÔNG TIN BÀI TẬP ---
Yêu cầu/Đề bài:
$assignment_intro

--- BÀI LÀM CỦA HỌC SINH ---
$files_info

Nội dung bài làm (Văn bản):
$student_text

--- YÊU CẦU CHẤM ĐIỂM ---
1. Hãy chấm điểm trên thang điểm 100.
2. Đưa ra nhận xét chi tiết, mang tính xây dựng.
3. QUAN TRỌNG: Bạn PHẢI trả về kết quả dưới dạng JSON thuần túy, không có Markdown, không có code block.
Cấu trúc JSON:
{
    "grade": <số điểm, ví dụ 85>,
    "feedback": "<lời nhận xét>"
}
EOT;
        return $prompt;
    }
}