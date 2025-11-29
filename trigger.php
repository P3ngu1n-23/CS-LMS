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
        // Gọi hàm xử lý mới
        $result = local_aigrading_add_to_queue($assignid, $USER, $selected_submissions);
        
        $msg_parts = [];
        $type = 'notifysuccess';

        if ($result['queued'] > 0) {
            $msg_parts[] = "Đã xếp hàng <b>{$result['queued']}</b> yêu cầu chấm điểm.";
        }
        
        if ($result['timeout_reset'] > 0) {
            $msg_parts[] = "Đã khởi động lại <b>{$result['timeout_reset']}</b> bài bị treo quá 5 phút.";
        }

        if ($result['skipped'] > 0) {
            $msg_parts[] = "Đã bỏ qua <b>{$result['skipped']}</b> bài vì đang được AI xử lý (vui lòng chờ kết quả).";
            if ($result['queued'] == 0 && $result['timeout_reset'] == 0) {
                $type = 'notifywarning'; // Nếu chỉ toàn bài bị skip thì cảnh báo
            }
        }

        $final_msg = implode('<br>', $msg_parts);
        if (empty($final_msg)) $final_msg = "Không có thay đổi nào.";

        \core\notification::add($final_msg, $type);
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

// A. Lấy danh sách học sinh đã nộp bài
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

// B. LẤY TRẠNG THÁI HÀNG ĐỢI (LOGIC MỚI: ƯU TIÊN THÀNH CÔNG)
$task_sql = "SELECT id, submissionid, status, parsed_grade 
             FROM {local_aigrading_tasks} 
             WHERE assignmentid = :assignid 
             ORDER BY id ASC"; // Duyệt từ cũ đến mới
$all_tasks = $DB->get_records_sql($task_sql, ['assignid' => $assignid]);

// Mảng lưu trạng thái tốt nhất cho từng submission
$best_tasks = [];

foreach ($all_tasks as $task) {
    $sid = $task->submissionid;
    
    if (!isset($best_tasks[$sid])) {
        // Chưa có thì lấy luôn
        $best_tasks[$sid] = $task;
    } else {
        $current_best = $best_tasks[$sid];
        
        // LOGIC ƯU TIÊN:
        // 1. Nếu task mới là Success (2) -> Luôn lấy (Ghi đè cũ).
        // 2. Nếu task cũ KHÔNG PHẢI Success (0,1,3) -> Lấy task mới (bất kể status gì) để cập nhật trạng thái mới nhất.
        // 3. Nếu task cũ LÀ Success (2) và task mới KHÔNG PHẢI Success -> GIỮ NGUYÊN task cũ.
        
        if ($task->status == 2) {
            $best_tasks[$sid] = $task;
        } elseif ($current_best->status != 2) {
            $best_tasks[$sid] = $task;
        }
        // Trường hợp còn lại: Cũ là Success, Mới là Pending/Error -> Giữ cái cũ.
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
    
    echo '<div>';
    echo '<span class="font-weight-bold mr-2">Chọn nhanh:</span>';
    echo '<button type="button" class="btn btn-sm btn-outline-primary mr-1" id="btn-select-ungraded">Chưa chấm</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" id="btn-select-all-rows">Tất cả</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-danger" id="btn-deselect-all">Bỏ chọn</button>';
    echo '</div>';

    echo '<div>';
    echo '<a href="' . new moodle_url('/mod/assign/view.php', ['id' => $cmid]) . '" class="btn btn-sm btn-secondary">Quay lại Assignment</a>';
    echo '</div>';
    
    echo '</div>';

    $table = new html_table();
    $table->head = [
        '<input type="checkbox" id="select-all-header" title="Chọn tất cả">', 
        'Học sinh', 
        'Email', 
        'Ngày nộp', 
        'Trạng thái AI'
    ];

    foreach ($submissions as $sub) {
        // Lấy task "tốt nhất" đã lọc ở trên
        $task = isset($best_tasks[$sub->id]) ? $best_tasks[$sub->id] : null;
        $status_code = $task ? $task->status : -1;
        
        $badge = '<span class="badge badge-secondary">Chưa chấm</span>';
        $row_class = '';
        
        if ($status_code == 0) {
            $badge = '<span class="badge badge-warning">Đang chờ</span>';
        } elseif ($status_code == 1) {
            $badge = '<span class="badge badge-info">Đang xử lý</span>';
        } elseif ($status_code == 2) {
            $raw_grade = isset($task->parsed_grade) ? $task->parsed_grade : 0;
            $grade = round($raw_grade, 1);
            $badge = '<span class="badge badge-success">Hoàn tất (' . $grade . 'đ)</span>';
            $row_class = 'table-success'; 
        } elseif ($status_code == 3) {
            $badge = '<span class="badge badge-danger">Lỗi</span>';
        }

        // data-status dùng cho JS chọn nhanh
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