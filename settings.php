<?php
defined('MOODLE_INTERNAL') || die;

// SỬA LỖI: Thêm dòng này
// Chúng ta phải nạp tệp lib.php (nơi chứa hàm)
// TRƯỚC KHI chúng ta gọi hàm đó
require_once($CFG->dirroot . '/local/aigrading/lib.php');

/**
 * Trang cài đặt cho plugin local_aigrading
 */

if ($hassiteconfig) { // Chỉ hiển thị cho admin
    
    // Tạo một trang cài đặt cho plugin
    $settings = new admin_settingpage('local_aigrading_settings', get_string('pluginname', 'local_aigrading'));
    $ADMIN->add('localplugins', $settings);

    // === Phần 1: Cài đặt API ===
    $settings->add(new admin_setting_heading('local_aigrading_api_heading', 
        get_string('api_settings_heading', 'local_aigrading'), ''
    ));

    // 1. API Key
    $setting = new admin_setting_configpasswordunmask(
        'local_aigrading/apikey', 
        get_string('apikey', 'local_aigrading'), 
        get_string('apikey_desc', 'local_aigrading'), 
        '', 
        PARAM_TEXT
    );
    $settings->add($setting);

    // 2. API Endpoint URL
    $setting = new admin_setting_configtext(
        'local_aigrading/apiendpoint',
        get_string('apiendpoint', 'local_aigrading'),
        get_string('apiendpoint_desc', 'local_aigrading'),
        'https://generativelanguage.googleapis.com', 
        PARAM_URL
    );
    $settings->add($setting);

    // === THAY ĐỔI Ở ĐÂY ===
    // 3. Tên Model (Dropdown)
    
    // Lấy danh sách các tùy chọn từ hàm helper
    // Dòng này (khoảng dòng 44) sẽ không còn lỗi
    $model_options = local_aigrading_get_model_options();
    
    // Giá trị mặc định (model đầu tiên trong danh sách)
    $default_model = 'gemini-2.5-flash';
    if (!empty($model_options)) {
        // Đảm bảo giá trị mặc định tồn tại trong danh sách
        $default_model = array_keys($model_options)[0];
    }

    $setting = new admin_setting_configselect(
        'local_aigrading/modelname', // Tên cài đặt
        get_string('modelname', 'local_aigrading'), // Tên hiển thị
        get_string('modelname_select_desc', 'local_aigrading'), // Mô tả mới
        $default_model, // Giá trị mặc định
        $model_options  // Mảng các tùy chọn
    );
    $settings->add($setting);
}