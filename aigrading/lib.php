<?php
defined('MOODLE_INTERNAL') || die();

function local_aigrading_extend_navigation_user_settings($parentnode, $user, $context, $course, $coursecontext) {
    global $USER, $DB;

    if ($user->id != $USER->id) {
        return;
    }

    if (is_siteadmin()) {
        $should_show = true;
    } else {
        
        $sql = "SELECT COUNT(ra.id)
                FROM {role_assignments} ra
                JOIN {role} r ON ra.roleid = r.id
                WHERE ra.userid = :userid
                AND r.archetype IN ('manager', 'coursecreator', 'editingteacher', 'teacher')";
        
        $count = $DB->count_records_sql($sql, ['userid' => $user->id]);
        
        $should_show = ($count > 0);
    }

    if (!$should_show) {
        return;
    }

    $url = new moodle_url('/local/aigrading/index.php');
    $node = $parentnode->add(
        get_string('pluginname', 'local_aigrading'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_aigrading'
    );
    $node->showinflatnavigation = true;
}