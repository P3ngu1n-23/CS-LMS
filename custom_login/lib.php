<?php
defined('MOODLE_INTERNAL') || die();

function theme_custom_login_get_main_scss_content($theme) {
    global $CFG;
    
    // Lấy CSS gốc của theme cha (Boost)
    $content = theme_boost_get_main_scss_content($theme);
    
    return $content;
}