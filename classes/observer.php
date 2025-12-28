<?php
namespace local_aigrading;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Handler cho sự kiện TẠO MỚI
     */
    public static function course_module_created_handler(\core\event\course_module_created $event) {
        self::process_log("EVENT: Created");
        self::filter_and_process($event);
    }

    /**
     * Handler cho sự kiện CẬP NHẬT
     */
    public static function course_module_updated_handler(\core\event\course_module_updated $event) {
        self::process_log("EVENT: Updated");
        self::filter_and_process($event);
    }

    private static function filter_and_process($event) {
        $modulename = isset($event->other['modulename']) ? $event->other['modulename'] : '';
        if ($modulename !== 'resource') {
            return;
        }
        self::process_ingestion($event);
    }

    /**
     * Logic xử lý file: Copy ra thư mục temp với tên sạch (Giống processor)
     */
    private static function process_ingestion($event) {
        global $CFG;
        try {
            $data = $event->get_data();
            $context_id = $data['contextid'];
            $course_id = $data['courseid'];

            // 1. Tìm file gốc trong DB
            $fs = get_file_storage();
            $files = $fs->get_area_files($context_id, 'mod_resource', 'content', 0, 'sortorder DESC, id DESC', false);

            $target_file = null;
            foreach ($files as $file) {
                if ($file->is_directory() || $file->get_filesize() == 0) continue;
                $target_file = $file;
                break;
            }

            if (!$target_file) {
                return;
            }

            // 2. Tạo thư mục temp trong dataroot (Nơi processor đang chạy tốt)
            // Cấu trúc: moodledata/local_aigrading_temp/{request_id}/
            $request_id = $event->objectid . '_' . time();
            $temp_dir = $CFG->dataroot . '/local_aigrading_temp/' . $request_id;
            
            if (!file_exists($temp_dir)) {
                if (!mkdir($temp_dir, 0777, true) && !is_dir($temp_dir)) {
                    self::process_log("ERROR: Không thể tạo thư mục temp tại $temp_dir");
                    return;
                }
            }

            // 3. Làm sạch tên file (Quan trọng để tránh lỗi JSON Encode)
            $filename_clean = clean_param($target_file->get_filename(), PARAM_FILE);
            $real_path = $temp_dir . '/' . $filename_clean;

            // 4. Copy file
            $target_file->copy_content_to($real_path);
            self::process_log("FILE READY: " . $real_path);

            // 5. Chuẩn bị Payload
            $payload = [
                'file_path' => $real_path,
                'course_id' => (string)$course_id,
                'filename'  => $filename_clean, // Dùng tên đã làm sạch
                'mime_type' => $target_file->get_mimetype()
            ];

            // 6. Gửi API bằng Native cURL
            self::send_to_ai_api($payload);

        } catch (\Exception $e) {
            self::process_log("EXCEPTION: " . $e->getMessage());
        }
    }

    /**
     * Gửi request bằng Native PHP cURL (Copy logic từ llm_client)
     * Để bypass Moodle Security trên Production
     */
    private static function send_to_ai_api($payload) {
        global $CFG;

        // 1. Cấu hình URL
        $base_url = get_config('local_aigrading', 'apiendpoint');
        if (empty($base_url)) {
            self::process_log("ERROR: API Endpoint chưa được cấu hình");
            return;
        }
        $url = rtrim($base_url, '/') . '/api/v1/rag/ingest';

        // 2. Mã hóa JSON
        $json_data = json_encode($payload);
        if ($json_data === false) {
            self::process_log("ERROR: JSON Encode thất bại. " . json_last_error_msg());
            return;
        }

        self::process_log("CONNECTING (Native cURL) to: " . $url);

        // 3. Khởi tạo Native cURL (Giống hệt llm_client)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // --- QUAN TRỌNG: Bypass SSL & Security ---
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Timeout cho việc upload file (tăng lên 60s)
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // 4. Headers
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json_data)
        ];
        
        $api_key = get_config('local_aigrading', 'apikey');
        if (!empty($api_key)) {
            $headers[] = 'X-Secret-Key: ' . $api_key;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // 5. Thực thi
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errno = curl_errno($ch);
        $error_msg = curl_error($ch);
        
        curl_close($ch);

        // 6. Log kết quả
        if ($curl_errno > 0) {
            self::process_log("❌ cURL ERROR ($curl_errno): $error_msg");
        } else {
            self::process_log("✅ API RESPONSE CODE: $http_code");
            if ($http_code >= 400) {
                self::process_log("   Body: " . substr($response, 0, 500));
            }
        }
    }

    private static function process_log($msg) {
        global $CFG;
        // Ghi log vào temp để dễ debug
        $log_path = $CFG->dataroot . '/temp/aigrading_rag_debug.log';
        $entry = date('Y-m-d H:i:s') . " | " . $msg . PHP_EOL;
        @file_put_contents($log_path, $entry, FILE_APPEND);
    }
}