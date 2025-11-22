<?php
require_once(__DIR__ . '/../../config.php');
// Nạp thư viện của Module Assignment để dùng các hàm chuẩn (save_grade)
require_once($CFG->dirroot . '/mod/assign/locallib.php');

global $DB, $OUTPUT, $PAGE;

// 1. Lấy tham số
$assignid = required_param('id', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

// 2. Xác thực
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$assign_record = $DB->get_record('assign', ['id' => $assignid], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = \context_module::instance($cm->id);
require_capability('mod/assign:grade', $context);

// Khởi tạo đối tượng Assign (API chuẩn của Moodle)
$assign_instance = new assign($context, $cm, $course);

// 3. Cấu hình trang
$PAGE->set_url(new moodle_url('/local/aigrading/report.php', ['id' => $assignid, 'cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title('Kết quả chấm điểm AI: ' . $assign_record->name);
$PAGE->set_heading('Kết quả chấm điểm AI');

// =================================================================================
// PHẦN 1: XỬ LÝ LOGIC "XÁC NHẬN & ĐẨY ĐIỂM" (POST REQUEST)
// =================================================================================

if (optional_param('push_grades', false, PARAM_BOOL) && data_submitted() && confirm_sesskey()) {
    
    // Lấy tất cả các task đã hoàn thành (Status = 2) hoặc đã được GV sửa
    $sql = "SELECT * FROM {local_aigrading_tasks} 
            WHERE assignmentid = :assignid 
            AND (status = 2 OR teacher_grade IS NOT NULL)";
    
    $tasks = $DB->get_records_sql($sql, ['assignid' => $assignid]);
    
    $count_success = 0;
    $count_error = 0;

    foreach ($tasks as $task) {
        // --- LOGIC ƯU TIÊN ĐIỂM ---
        // 1. Nếu GV đã chốt điểm, dùng điểm GV.
        // 2. Nếu chưa, dùng điểm AI.
        $final_grade = isset($task->teacher_grade) ? $task->teacher_grade : $task->parsed_grade;
        $final_feedback = isset($task->teacher_feedback) ? $task->teacher_feedback : $task->parsed_feedback;

        // Bỏ qua nếu không có điểm (trường hợp lỗi dữ liệu)
        if ($final_grade === null) continue;

        // Chuẩn bị dữ liệu để gọi API Assignment
        $grade_data = new stdClass();
        $grade_data->grade = $final_grade;
        $grade_data->attemptnumber = -1; // -1 nghĩa là lần nộp mới nhất

        // Cập nhật Feedback Comment (Cần plugin feedback 'comments' được bật)
        $grade_data->assignfeedbackcomments_editor = [
            'text' => $final_feedback,
            'format' => FORMAT_HTML
        ];

        try {
            // GỌI API CHUẨN CỦA MOODLE
            // Hàm này sẽ tự động: Lưu vào gradebook, cập nhật log, gửi thông báo cho HS (nếu bật)
            $assign_instance->save_grade($task->userid, $grade_data);
            $count_success++;
        } catch (Exception $e) {
            $count_error++;
            // Ghi log lỗi nếu cần
        }
    }

    if ($count_success > 0) {
        \core\notification::add("Đã cập nhật thành công điểm cho $count_success học sinh vào Sổ điểm.", 'notifysuccess');
    }
    if ($count_error > 0) {
        \core\notification::add("Có $count_error lỗi xảy ra khi cập nhật.", 'notifyproblem');
    }

    // Redirect để tránh gửi lại form
    redirect(new moodle_url('/local/aigrading/report.php', ['id' => $assignid, 'cmid' => $cmid]));
}

// =================================================================================
// PHẦN 2: HIỂN THỊ GIAO DIỆN
// =================================================================================

echo $OUTPUT->header();
echo $OUTPUT->heading('Danh sách kết quả từ AI');

// Toolbar
echo '<div class="d-flex justify-content-between mb-3 bg-light p-3 rounded">';
echo '<div>';
echo $OUTPUT->single_button(new moodle_url('/mod/assign/view.php', ['id' => $cmid]), 'Quay lại Assignment', 'get');
echo '</div>';

// NÚT XÁC NHẬN ĐIỂM (MỚI)
// Chúng ta bọc trong Form để POST dữ liệu an toàn
echo '<div>';
echo '<form method="post" action="" class="d-inline" onsubmit="return confirm(\'Bạn có chắc chắn muốn cập nhật điểm này vào Sổ điểm chính thức không?\');">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="push_grades" value="1">';
echo '<button type="submit" class="btn btn-success font-weight-bold">';
echo '<i class="fa fa-check-circle"></i> Xác nhận & Cập nhật vào Sổ điểm';
echo '</button>';
echo '</form>';
echo '</div>';
echo '</div>';

// 4. Lấy dữ liệu hiển thị
$sql = "SELECT t.*, 
               u.firstname, u.lastname, u.email,
               u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.alternatename, u.imagealt, u.picture
        FROM {local_aigrading_tasks} t
        JOIN {user} u ON u.id = t.userid
        WHERE t.assignmentid = :assignid
        ORDER BY t.id DESC";

$tasks = $DB->get_records_sql($sql, ['assignid' => $assignid]);

if (empty($tasks)) {
    echo $OUTPUT->notification('Chưa có dữ liệu chấm điểm AI nào.', 'info');
} else {
    
    $table = new html_table();
    $table->head = ['Học sinh', 'Trạng thái AI', 'Điểm AI', 'Điểm GV (Chốt)', 'Sẽ nhập vào sổ', 'Hành động'];
    
    foreach ($tasks as $task) {
        // Badge Trạng thái
        $status_label = '';
        switch ($task->status) {
            case 0: $status_label = '<span class="badge badge-secondary">Pending</span>'; break;
            case 1: $status_label = '<span class="badge badge-warning">Processing</span>'; break;
            case 2: $status_label = '<span class="badge badge-success">AI Done</span>'; break;
            case 3: $status_label = '<span class="badge badge-danger">Error</span>'; break;
        }

        // Logic Điểm
        $ai_grade = ($task->status == 2) ? round($task->parsed_grade, 1) : '-';
        
        // Điểm GV
        $teacher_grade_display = '<span class="text-muted">-</span>';
        $row_class = '';
        
        // Điểm Cuối cùng (Sẽ nhập sổ)
        $final_grade_preview = $ai_grade;

        if (isset($task->teacher_grade)) {
            $teacher_grade_display = '<strong>' . round($task->teacher_grade, 1) . '</strong>';
            $final_grade_preview = '<span class="text-primary font-weight-bold">' . round($task->teacher_grade, 1) . '</span>';
            
            if (abs($task->teacher_grade - $task->parsed_grade) > 0.1) {
                $teacher_grade_display .= ' <i class="fa fa-pen text-info" title="Đã chỉnh sửa"></i>';
            }
            $row_class = 'table-primary';
        }

        // Nếu chưa xong thì không có điểm final
        if ($task->status != 2 && !isset($task->teacher_grade)) {
            $final_grade_preview = '-';
        }

        // Nút Hành động
        $url_view = new moodle_url('/local/aigrading/grade_view.php', ['id' => $task->id]);
        $btn_action = '';
        
        if ($task->status == 2 || isset($task->teacher_grade)) {
            $btn_action = html_writer::link($url_view, 'Xem & Sửa', ['class' => 'btn btn-sm btn-primary']);
        } else {
            $btn_action = '<span class="text-muted">...</span>';
        }

        $row = [
            fullname($task),
            $status_label,
            $ai_grade,
            $teacher_grade_display,
            $final_grade_preview, // Cột mới: Xem trước điểm sẽ nhập
            $btn_action
        ];
        
        $table->rowclasses[] = $row_class;
        $table->data[] = $row;
    }
    
    echo html_writer::table($table);
}

echo $OUTPUT->footer();