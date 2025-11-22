<?php
defined('MOODLE_INTERNAL') || die;

$tasks = [
    [
        // Tên lớp PHP sẽ thực thi
        'classname' => 'local_aigrading\task\grade_submission', 
        
        // Không chặn các cron khác
        'blocking' => 0, 
        
        // Chạy vào phút thứ 0 và 30 của mỗi giờ
        'minute' => '0,30', 
        
        'hour' => '*', // Mỗi giờ
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ]
];