<?php
require_once(__DIR__ . '/../../config.php'); // Gọi config.php gốc của Moodle

// Yêu cầu đăng nhập
require_login();

// Yêu cầu quyền truy cập (chỉ admin)
require_capability('moodle/site:config', context_system::instance());

// Thiết lập trang
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ai_grading/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_aigrading'));
$PAGE->set_heading($PAGE->title . ': Kiểm tra kết nối');

echo $OUTPUT->header();

// Lấy link đến trang cài đặt
$settings_url = new moodle_url('/admin/settings.php', ['section' => 'local_aigrading_settings']);
$settings_link = $OUTPUT->action_link($settings_url, 'Đi đến cài đặt');

echo $OUTPUT->box_start();

// Khởi tạo client
$client = new \local_aigrading\external\llm_client();

if (!$client->is_configured()) {
    // Trường hợp 1: Chưa cài đặt
    $message = get_string('settings_not_complete', 'local_aigrading');
    $message .= '<br>' . $settings_link;
    echo $OUTPUT->notification($message, 'notifyproblem');

} else {
    // Trường hợp 2: Đã cài đặt, tiến hành kiểm tra
    echo "<p>Đang thực hiện kiểm tra kết nối (sử dụng model: {$client->is_configured()})...</p>";
    
    // Gọi hàm test_connection mới
    $result = $client->test_connection();

    if ($result['success']) {
        // Thành công!
        echo $OUTPUT->notification($result['message'], 'notifysuccess');
    } else {
        // Thất bại!
        $message = '<strong>' . get_string('connection_failed', 'local_aigrading') . ':</strong><br>';
        $message .= $result['message']; // Hiển thị thông báo lỗi chi tiết
        $message .= '<br><br>' . $settings_link;
        echo $OUTPUT->notification($message, 'notifyproblem');
    }
}

echo $OUTPUT->box_end();
echo $OUTPUT->footer();