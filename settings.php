<?php
defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) { 
    $settings = new admin_settingpage('local_aigrading_settings', get_string('pluginname', 'local_aigrading'));
    $ADMIN->add('localplugins', $settings);

    // Base URL của FastAPI
    $setting = new admin_setting_configtext(
        'local_aigrading/apiendpoint',
        'AI Base URL',
        'Ví dụ: http://localhost:8000 hoặc http://ai-service:8000',
        'http://localhost:8000',
        PARAM_RAW_TRIMMED
    );
    $settings->add($setting);

    // Shared Secret Key (Dùng để xác thực 2 chiều)
    $setting = new admin_setting_configpasswordunmask(
        'local_aigrading/apikey',
        'Shared Secret Key',
        'Chuỗi bí mật dùng để xác thực giữa Moodle và FastAPI. (Header: X-Secret-Key)',
        '',
        PARAM_TEXT
    );
    $settings->add($setting);
}