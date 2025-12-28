define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    return {
        init: function(assignId, sessKey) {
            
            // DEBUG: Log này sẽ hiện trong F12 Console
            console.log("--- [AI Grading] JS Module Initialized ---");
            console.log("AssignID:", assignId);

            var pollInterval = null;

            // 1. BẮT SỰ KIỆN CLICK (Dùng Event Delegation để chắc chắn bắt được)
            $(document).on('click', '#btn-start-grading', function(e) {
                e.preventDefault(); // Chặn hành vi mặc định
                console.log("--> Button Clicked!");

                // Lấy các ID được chọn
                var selectedIds = [];
                $('.submission-check:checked').each(function() {
                    selectedIds.push($(this).val());
                });

                console.log("Selected IDs:", selectedIds);

                if (selectedIds.length === 0) {
                    alert('Vui lòng chọn ít nhất một bài để chấm.');
                    return;
                }

                // Khóa giao diện
                var $btn = $(this);
                $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang gửi...');
                $('#global-status').text('Đang gửi yêu cầu vào hàng đợi...');

                // Đổi trạng thái badge tạm thời
                $.each(selectedIds, function(i, id) {
                    $('#badge-' + id).removeClass().addClass('badge badge-warning').text('Queueing...');
                });

                // Gửi AJAX
                console.log("Sending AJAX to ajax.php...");
                $.ajax({
                    url: M.cfg.wwwroot + '/local/aigrading/ajax.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'submit',
                        assignid: assignId,
                        sesskey: sessKey,
                        submissions: selectedIds
                    },
                    success: function(resp) {
                        console.log("AJAX Success:", resp);
                        if (resp.status === 'success') {
                            $btn.text('Đang xử lý ngầm...');
                            $('#global-status').text('Hệ thống đang chấm điểm...');
                            // Bắt đầu vòng lặp kiểm tra
                            startPolling();
                        } else {
                            alert('Lỗi Server: ' + resp.message);
                            $btn.prop('disabled', false).text('Thử lại');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error:", xhr.responseText);
                        alert('Lỗi kết nối AJAX: ' + error);
                        $btn.prop('disabled', false).text('Thử lại');
                    }
                });
            });

            // 2. HÀM POLLING (Kiểm tra trạng thái 3s/lần)
            function startPolling() {
                if (pollInterval) return;
                console.log("Starting Polling...");

                pollInterval = setInterval(function() {
                    $.ajax({
                        url: M.cfg.wwwroot + '/local/aigrading/ajax.php',
                        type: 'POST',
                        data: { action: 'poll', assignid: assignId, sesskey: sessKey },
                        success: function(resp) {
                            if (resp.status === 'success') {
                                updateUI(resp.data);
                            }
                        }
                    });
                }, 3000);
            }

            // 3. HÀM CẬP NHẬT UI
            function updateUI(data) {
                var pendingCount = 0;
                
                // Duyệt qua dữ liệu trả về
                $.each(data, function(id, info) {
                    var $badge = $('#badge-' + id);
                    if ($badge.length) {
                        if (info.code == 0) { // Pending
                            $badge.attr('class', 'badge badge-warning').text('Pending');
                            pendingCount++;
                        } 
                        else if (info.code == 1) { // Processing
                            $badge.attr('class', 'badge badge-info').html('<i class="fa fa-spinner fa-spin"></i> Processing');
                            pendingCount++;
                        } 
                        else if (info.code == 2) { // Done
                            $badge.attr('class', 'badge badge-success').html('<i class="fa fa-check"></i> Done (' + info.grade + ')');
                            // Bỏ check dòng đã xong
                            $('.submission-check[value="' + id + '"]').prop('checked', false);
                        } 
                        else if (info.code == 3) { // Error
                            $badge.attr('class', 'badge badge-danger').text('Error');
                        }
                    }
                });

                // Nếu không còn bài nào pending/processing thì dừng poll
                if (pendingCount === 0) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    console.log("Polling stopped (All done).");
                    $('#btn-start-grading').prop('disabled', false).html('<i class="fa fa-magic"></i> Chấm AI các bài đã chọn');
                    $('#global-status').text('Hoàn tất!');
                }
            }

            // Logic chọn tất cả
            $(document).on('change', '#select-all', function() {
                $('.submission-check').prop('checked', $(this).prop('checked'));
            });

            // Tự động poll khi vào trang (để cập nhật trạng thái cũ nếu có)
            startPolling();
        }
    };
});