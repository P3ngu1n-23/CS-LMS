<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

global $DB, $USER, $PAGE, $OUTPUT;

// 1. Thiết lập tham số
$assignid = required_param('assignid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

// 2. Kiểm tra quyền và Context
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$assign = $DB->get_record('assign', ['id' => $assignid], '*', MUST_EXIST);
$context = \context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/assign:grade', $context);

// 3. Cấu hình trang
$PAGE->set_url(new moodle_url('/local/aigrading/trigger.php', ['assignid' => $assignid, 'cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title('Chấm điểm AI: ' . $assign->name);
$PAGE->set_heading('Chấm điểm AI: ' . $assign->name);

// =================================================================================
// PHẦN 1: XỬ LÝ LOGIC KHI BẤM NÚT (POST REQUEST)
// =================================================================================

if (optional_param('submit_grading', false, PARAM_BOOL) && data_submitted() && confirm_sesskey()) {
    
    $selected_submissions = optional_param_array('submissions', [], PARAM_INT);

    if (!empty($selected_submissions)) {
        $count = local_aigrading_add_to_queue($assignid, $USER, $selected_submissions);
        
        if ($count > 0) {
            \core\notification::add("Đã thêm thành công $count bài vào hàng đợi chấm điểm. Hệ thống sẽ xử lý ngầm.", 'notifysuccess');
        } else {
            \core\notification::add("Các bài đã chọn đều đã nằm trong hàng đợi hoặc đã được chấm xong.", 'notifywarning');
        }
    } else {
        \core\notification::add("Vui lòng chọn ít nhất một bài nộp.", 'notifyproblem');
    }

    // Quay về trang Assignment
    redirect(new moodle_url('/mod/assign/view.php', ['id' => $cmid]));
    exit;
}

// =================================================================================
// PHẦN 2: HIỂN THỊ GIAO DIỆN (GET REQUEST)
// =================================================================================

echo $OUTPUT->header();

// A. Lấy danh sách học sinh
$sql = "SELECT s.id, 
               u.firstname, u.lastname, u.email, 
               u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.alternatename, u.imagealt, u.picture,
               s.timemodified
        FROM {assign_submission} s
        JOIN {user} u ON u.id = s.userid
        WHERE s.assignment = :assignid 
          AND s.status = :status 
          AND s.latest = 1
        ORDER BY u.lastname, u.firstname";

$submissions = $DB->get_records_sql($sql, ['assignid' => $assignid, 'status' => 'submitted']);

// B. Lấy trạng thái hàng đợi
$task_sql = "SELECT id, submissionid, status, parsed_grade 
             FROM {local_aigrading_tasks} 
             WHERE assignmentid = :assignid 
             ORDER BY id ASC";
$all_tasks = $DB->get_records_sql($task_sql, ['assignid' => $assignid]);

$queued_status = [];
$queued_grades = [];
foreach ($all_tasks as $task) {
    $queued_status[$task->submissionid] = $task->status;
    if ($task->status == 2) {
        $queued_grades[$task->submissionid] = $task->parsed_grade;
    }
}

echo '<div class="p-3 bg-white border rounded">';
echo '<h4 class="mb-3">Danh sách bài nộp chờ chấm</h4>';

if (empty($submissions)) {
    echo $OUTPUT->notification('Chưa có bài nộp nào (Status: Submitted).', 'info');
    echo $OUTPUT->continue_button(new moodle_url('/mod/assign/view.php', ['id' => $cmid]));
} else {
    echo '<form action="" method="post" id="grading-form">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="submit_grading" value="1">';

    // --- THANH CÔNG CỤ ---
    echo '<div class="mb-3 d-flex justify-content-between align-items-center bg-light p-2 rounded border">';
    
    // Nhóm nút chọn nhanh
    echo '<div>';
    echo '<span class="font-weight-bold mr-2">Chọn nhanh:</span>';
    echo '<button type="button" class="btn btn-sm btn-outline-primary mr-1" id="btn-select-ungraded">Chưa chấm</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" id="btn-select-all-rows">Tất cả</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-danger" id="btn-deselect-all">Bỏ chọn</button>';
    echo '</div>';

    // Nút Quay lại
    echo '<div>';
    echo '<a href="' . new moodle_url('/mod/assign/view.php', ['id' => $cmid]) . '" class="btn btn-sm btn-secondary">Quay lại Assignment</a>';
    echo '</div>';
    
    echo '</div>'; // End toolbar

    $table = new html_table();
    $table->head = [
        '<input type="checkbox" id="select-all-header" title="Chọn tất cả">', 
        'Học sinh', 
        'Email', 
        'Ngày nộp', 
        'Trạng thái AI'
    ];

    foreach ($submissions as $sub) {
        $status_code = isset($queued_status[$sub->id]) ? $queued_status[$sub->id] : -1;
        
        $badge = '<span class="badge badge-secondary">Chưa chấm</span>';
        $row_class = '';
        
        // 0: Pending, 1: Processing, 2: Success, 3: Error
        if ($status_code == 0) {
            $badge = '<span class="badge badge-warning">Đang chờ</span>';
        } elseif ($status_code == 1) {
            $badge = '<span class="badge badge-info">Đang xử lý</span>';
        } elseif ($status_code == 2) {
            
            // --- SỬA LỖI HIỂN THỊ SỐ 0 Ở ĐÂY ---
            $raw_grade = isset($queued_grades[$sub->id]) ? $queued_grades[$sub->id] : 0;
            
            // Dùng hàm round(số, 1) để làm tròn 1 chữ số thập phân.
            // Ví dụ: 8.56 -> 8.6 | 9.000 -> 9
            $grade = round($raw_grade, 1);

            $badge = '<span class="badge badge-success">Hoàn tất (' . $grade . 'đ)</span>';
            $row_class = 'table-success'; 
        } elseif ($status_code == 3) {
            $badge = '<span class="badge badge-danger">Lỗi</span>';
        }

        $checkbox = '<input type="checkbox" name="submissions[]" value="' . $sub->id . '" class="submission-check" data-status="' . $status_code . '">';

        $row = [
            $checkbox,
            fullname($sub),
            $sub->email,
            userdate($sub->timemodified),
            $badge
        ];
        $table->rowclasses[] = $row_class;
        $table->data[] = $row;
    }

    echo html_writer::table($table);

    echo '<div class="mt-3">';
    echo '<button type="submit" class="btn btn-primary"><i class="fa fa-magic"></i> Gửi đi chấm AI</button>';
    echo '</div>';

    echo '</form>';
}

echo '</div>';

// --- JAVASCRIPT ---
echo "
<script>
document.addEventListener('DOMContentLoaded', function() {
    var checkboxes = document.querySelectorAll('.submission-check');
    
    var btnUngraded = document.getElementById('btn-select-ungraded');
    if (btnUngraded) {
        btnUngraded.addEventListener('click', function() {
            checkboxes.forEach(function(cb) {
                var status = parseInt(cb.getAttribute('data-status'));
                if (status !== 2) cb.checked = true; else cb.checked = false;
            });
        });
    }

    var btnAll = document.getElementById('btn-select-all-rows');
    if (btnAll) {
        btnAll.addEventListener('click', function() {
            checkboxes.forEach(function(cb) { cb.checked = true; });
        });
    }

    var btnDeselect = document.getElementById('btn-deselect-all');
    if (btnDeselect) {
        btnDeselect.addEventListener('click', function() {
            checkboxes.forEach(function(cb) { cb.checked = false; });
            var headerCb = document.getElementById('select-all-header');
            if(headerCb) headerCb.checked = false;
        });
    }

    var selectAllHeader = document.getElementById('select-all-header');
    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAllHeader.checked; });
        });
    }
});
</script>
";

echo $OUTPUT->footer();