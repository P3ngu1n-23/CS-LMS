<?php
require_once(__DIR__ . '/../../config.php');

global $DB, $PAGE, $OUTPUT, $USER;

// 1. Lấy tham số Task ID
$taskid = required_param('id', PARAM_INT);

// 2. Lấy dữ liệu từ DB
$task = $DB->get_record('local_aigrading_tasks', ['id' => $taskid], '*', MUST_EXIST);
$assign = $DB->get_record('assign', ['id' => $task->assignmentid], '*', MUST_EXIST);

// Lấy Course Module (CM) và Course
$cm = get_coursemodule_from_instance('assign', $assign->id);
$course = $DB->get_record('course', ['id' => $assign->course], '*', MUST_EXIST);
$context = \context_module::instance($cm->id);

// 3. Kiểm tra quyền
require_login($course, false, $cm); 
require_capability('mod/assign:grade', $context);

// 4. Xử lý Form Submit (Lưu điểm) - KHÔNG ĐỔI
if (optional_param('save_grade', false, PARAM_BOOL) && data_submitted() && confirm_sesskey()) {
    
    $new_grade = required_param('teacher_grade', PARAM_FLOAT);
    $new_feedback = required_param('teacher_feedback', PARAM_RAW);
    $notes = optional_param('teacher_notes', '', PARAM_TEXT);

    $update_obj = new stdClass();
    $update_obj->id = $task->id;
    $update_obj->teacher_grade = $new_grade;
    $update_obj->teacher_feedback = $new_feedback;
    $update_obj->teacher_notes = $notes;
    $update_obj->timemodified = time();

    $DB->update_record('local_aigrading_tasks', $update_obj);

    \core\notification::add('Đã lưu đánh giá thành công!', 'notifysuccess');
    redirect(new moodle_url('/local/aigrading/grade_view.php', ['id' => $taskid]));
}

// 5. Cấu hình trang
$url = new moodle_url('/local/aigrading/grade_view.php', ['id' => $taskid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_cm($cm); 

$PAGE->set_title('Chi tiết chấm điểm AI');
$PAGE->set_heading('Chi tiết & Điều chỉnh điểm số');

$PAGE->navbar->add('Kết quả chấm AI', new moodle_url('/local/aigrading/report.php', ['id' => $assign->id, 'cmid' => $cm->id]));
$PAGE->navbar->add('Xem chi tiết');

echo $OUTPUT->header();

// Lấy thông tin chi tiết bài làm
$submission = $DB->get_record('assign_submission', ['id' => $task->submissionid]);
$user = $DB->get_record('user', ['id' => $task->userid]);
$onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $task->submissionid]);
$student_content = $onlinetext ? $onlinetext->onlinetext : '<i>(Không có nội dung văn bản trực tuyến)</i>';

// Xác định giá trị hiển thị cho FORM
$current_grade = isset($task->teacher_grade) ? $task->teacher_grade : $task->parsed_grade;
$current_feedback = isset($task->teacher_feedback) ? $task->teacher_feedback : $task->parsed_feedback;
$current_notes = $task->teacher_notes;

?>

<div class="container-fluid p-0">
    <div class="mb-3">
        <a href="<?php echo new moodle_url('/local/aigrading/report.php', ['id' => $assign->id, 'cmid' => $cm->id]); ?>" class="btn btn-secondary">
            <i class="fa fa-arrow-left"></i> Quay lại danh sách
        </a>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <strong>Bài làm của: <?php echo fullname($user); ?></strong>
                </div>
                <div class="card-body" style="max-height: 500px; overflow-y: auto; background-color: #f9f9f9;">
                    <?php echo format_text($student_content, FORMAT_HTML); ?>
                </div>
            </div>

            <div class="card border-info mb-3">
                <div class="card-header bg-info text-white">
                    <i class="fa fa-robot"></i> <strong>Kết quả gốc từ AI (Tham khảo)</strong>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Điểm đề xuất:</strong> 
                        <span class="badge badge-light p-2" style="font-size: 1.2em"><?php echo round($task->parsed_grade, 1); ?></span>
                    </div>
                    <hr>
                    <div>
                        <strong>Nhận xét:</strong><br>
                        <div class="p-2 bg-light border rounded">
                            <?php 
                            // --- BẮT ĐẦU SỬA ĐỔI ---
                            if (!empty($task->error_message)) {
                                // Nếu có lỗi, hiển thị text đỏ
                                echo '<div class="text-danger">';
                                echo '<i class="fa fa-exclamation-triangle"></i> <strong>Lỗi xử lý AI:</strong><br>';
                                echo format_text($task->error_message, FORMAT_HTML);
                                echo '</div>';
                            } else {
                                // Nếu không lỗi, hiển thị feedback bình thường
                                echo format_text($task->parsed_feedback, FORMAT_MARKDOWN);
                            }
                            // --- KẾT THÚC SỬA ĐỔI ---
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <form action="" method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="save_grade" value="1">

                <div class="card border-primary shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <i class="fa fa-edit"></i> <strong>Chốt điểm & Phản hồi</strong>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info text-small mb-3">
                            <i class="fa fa-info-circle"></i> Bạn có thể điều chỉnh kết quả nếu AI chưa chính xác.
                        </div>

                        <div class="form-group">
                            <label for="grade"><strong>Điểm số chốt:</strong></label>
                            <input type="number" step="0.1" min="0" max="100" class="form-control form-control-lg font-weight-bold text-primary" 
                                   name="teacher_grade" id="grade" value="<?php echo round($current_grade, 1); ?>">
                        </div>

                        <div class="form-group">
                            <label for="feedback"><strong>Lời phê (Gửi cho học sinh):</strong></label>
                            <textarea class="form-control" name="teacher_feedback" id="feedback" rows="6"><?php echo strip_tags($current_feedback); ?></textarea>
                        </div>

                        <hr>
                        
                        <div class="form-group bg-light p-2 rounded border border-warning">
                            <label for="notes" class="text-danger mb-1"><strong><i class="fa fa-bug"></i> Feedback cải thiện AI (Tùy chọn):</strong></label>
                            <textarea class="form-control" name="teacher_notes" id="notes" rows="2" 
                                      placeholder="Ghi chú lỗi sai của AI để hệ thống học hỏi..."><?php echo $current_notes; ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-3">
                            <i class="fa fa-save"></i> Lưu thay đổi
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();