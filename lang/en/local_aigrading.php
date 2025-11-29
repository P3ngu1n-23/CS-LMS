<?php
defined('MOODLE_INTERNAL') || die;

$string['pluginname'] = 'AI Grading';

// Chuỗi cho settings.php
$string['api_settings_heading'] = 'Cài đặt API Connection';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Nhập Google AI Studio API Key của bạn.';
$string['apiendpoint'] = 'API Endpoint URL';
$string['apiendpoint_desc'] = 'URL cơ sở của dịch vụ API. Đối với Google Gemini, hãy giữ nguyên mặc định.';
$string['modelname'] = 'Tên Model';
// $string['modelname_desc'] = 'Nhập tên model...'; // (Cũ)

// --- CHUỖI MỚI ---
$string['modelname_select_desc'] = 'Chọn model AI. Danh sách này sẽ tự động cập nhật từ Google API sau khi bạn nhập API Key và lưu thay đổi.';
$string['model_default_25_flash'] = 'Gemini 2.5 Flash (Mới nhất - Mặc định)';
$string['model_default_flash'] = 'Gemini 1.5 Flash (Mặc định)';
$string['model_default_pro'] = 'Gemini 1.5 Pro (Mặc định)';
$string['model_default_geminipro'] = 'Gemini Pro (Mặc định)';
// --- HẾT CHUỖI MỚI ---

// Chuỗi cho trang kiểm tra (index.php)
$string['settings_not_complete'] = 'Cài đặt chưa hoàn tất. Vui lòng cung cấp API Key, Endpoint và Tên Model trong trang cài đặt.';
$string['connection_success'] = 'Kết nối thành công!';
$string['connection_failed'] = 'Kết nối thất bại';
$string['key_not_set'] = 'API Key chưa được cấu hình.';

// ... (Thêm chuỗi này vào tệp lang của bạn)
$string['task_grade_submission'] = 'AI Grading: Process overdue submissions';

// ... (Thêm các chuỗi này vào tệp lang của bạn)
$string['trigger_ai_grading'] = 'Chấm AI tất cả bài nộp';
$string['trigger_success_message'] = 'Đã gửi yêu cầu chấm AI cho tất cả bài nộp. Quá trình xử lý sẽ chạy ở chế độ nền.';

// Tin nhắn
$string['messageprovider:grading_completed'] = 'Thông báo khi AI chấm xong';
$string['notification_subject'] = 'AI Grading: Đã hoàn tất chấm điểm cho {assignment}';
$string['notification_body'] = 'Xin chào {teachername},

Hệ thống AI đã hoàn tất quá trình chấm điểm cho bài tập: "{assignment}".

- Tổng số bài xử lý đợt này: {count}
- Thành công: {success}
- Thất bại: {fail}

Bạn có thể xem chi tiết và duyệt điểm tại đường dẫn sau:
{url}

Trân trọng,
AI Grading Plugin.';
$string['enable_autograde'] = 'Tự động chấm khi hết hạn (Due Date)';
$string['enable_autograde_help'] = 'Nếu bật tùy chọn này, hệ thống sẽ tự động quét và đưa các bài nộp vào hàng đợi chấm điểm của AI ngay khi thời hạn nộp bài (Due Date) kết thúc. Lưu ý: Cron của hệ thống phải được cấu hình để chạy tác vụ này.';

$string['reference_file'] = 'File Đáp án mẫu (Reference)';
$string['reference_file_help'] = 'Bạn có thể tải lên tệp chứa đáp án đúng, thang điểm chi tiết hoặc tài liệu tham khảo (định dạng PDF, DOCX, TXT). AI sẽ đọc nội dung tệp này để làm căn cứ so sánh và chấm điểm bài làm của học sinh chính xác hơn.';

$string['reference_text'] = 'Đáp án mẫu (Văn bản)';
$string['reference_text_help'] = 'Bạn có thể nhập hoặc dán trực tiếp nội dung đáp án, barem điểm vào đây. AI sẽ ưu tiên sử dụng nội dung này kết hợp với file đính kèm (nếu có) để chấm bài.';

$string['teacher_instruction'] = 'Hướng dẫn chấm (Prompt)';
$string['teacher_instruction_help'] = 'Tại đây bạn có thể nhập các chỉ đạo cụ thể cho AI. Ví dụ: "Hãy chú trọng vào lỗi logic", "Trừ điểm nặng nếu sai chính tả", hoặc "Bỏ qua lỗi định dạng". AI sẽ kết hợp hướng dẫn này với đáp án mẫu để chấm bài.';