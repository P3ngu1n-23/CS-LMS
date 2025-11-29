<?php
defined('MOODLE_INTERNAL') || die;

$tasks = [
    [
        // SỬA LỖI: Đổi tên class cũ 'grade_submission' thành class mới 'auto_grade_cron'
        'classname' => 'local_aigrading\task\auto_grade_cron', 
        
        // Các cấu hình thời gian (Ví dụ: Chạy mỗi giờ)
        'blocking' => 0,
        'minute' => '0', 
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ]
];