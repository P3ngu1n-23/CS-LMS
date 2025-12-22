<?php
// local/aigrading/db/events.php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Đây chính là event bạn nhìn thấy trong log
        'eventname' => '\core\event\course_module_created',
        'callback'  => 'local_aigrading\observer::course_module_created_handler',
    ],
    // Bắt thêm event update nếu giáo viên sửa file
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => 'local_aigrading\observer::course_module_updated_handler',
    ],
];