<?php
defined('MOODLE_INTERNAL') || die;

// Định nghĩa các loại cache mà plugin này sử dụng
$definitions = [
    // Tên định nghĩa cache: 'models'
    'models' => [
        // MODE_APPLICATION = 1
        // Dùng số thay vì hằng số class vì file này được load trước khi class được khởi tạo
        'mode' => 1, 
        
        // Thời gian sống (TTL) mặc định: 24 giờ
        'ttl' => 86400, 
        
        // Dùng key đơn giản (vì chúng ta chỉ dùng 'model_list')
        'simplekeys' => true, 
        
        // SHARING_APPLICATION = 1 (không cần thiết phải khai báo vì đây là giá trị mặc định)
        // 'sharing' => 1,
    ]
];