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