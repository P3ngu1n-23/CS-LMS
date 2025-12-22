<?php
namespace local_aigrading;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Handler cho sự kiện TẠO MỚI
     */
    public static function course_module_created_handler(\core\event\course_module_created $event) {
        self::process_log("EVENT CAUGHT: course_module_created");
        self::filter_and_process($event);
    }

    /**
     * Handler cho sự kiện CẬP NHẬT
     */
    public static function course_module_updated_handler(\core\event\course_module_updated $event) {
        self::process_log("EVENT CAUGHT: course_module_updated");
        self::filter_and_process($event);
    }

    /**
     * Hàm lọc: Chỉ xử lý nếu là Resource (File)
     */
    private static function filter_and_process($event) {
        $modulename = isset($event->other['modulename']) ? $event->other['modulename'] : '';
        
        self::process_log("Checking Module Type: " . $modulename);

        if ($modulename !== 'resource') {
            return;
        }

        self::process_ingestion($event);
    }

    /**
     * Logic chính: Copy file ra temp (để có tên thật) và gửi đi
     */
    private static function process_ingestion($event) {
        global $CFG;
        try {
            $data = $event->get_data();
            $context_id = $data['contextid'];
            $course_id = $data['courseid'];
            $object_id = $event->objectid; // ID của module

            self::process_log("Processing Resource for Course $course_id, Context $context_id");

            // 1. Tìm file trong DB
            $fs = get_file_storage();
            $files = $fs->get_area_files($context_id, 'mod_resource', 'content', 0, 'sortorder DESC, id DESC', false);

            $target_file = null;
            foreach ($files as $file) {
                if ($file->is_directory() || $file->get_filesize() == 0) continue;
                $target_file = $file;
                break;
            }

            if (!$target_file) {
                self::process_log("ERROR: No file found in context $context_id");
                return;
            }

            // 2. [QUAN TRỌNG] Tạo file tạm với tên thật (Materialize)
            // Tạo thư mục riêng cho RAG để không đụng chạm đến processor
            // Cấu trúc: moodledata/temp/aigrading_rag/{course_id}/{module_id}/
            $temp_dir = $CFG->dataroot . '/temp/aigrading_rag/' . $course_id . '/' . $object_id;
            
            if (!file_exists($temp_dir)) {
                mkdir($temp_dir, 0777, true);
            }

            // Lấy tên file gốc (VD: giao_trinh_triet_hoc.pdf)
            $filename = clean_param($target_file->get_filename(), PARAM_FILE);
            $real_path = $temp_dir . '/' . $filename;

            // Copy file từ kho hash ra đường dẫn này
            $target_file->copy_content_to($real_path);
            
            self::process_log("FILE MATERIALIZED at: " . $real_path);

            // 3. Chuẩn bị payload gửi đi
            $payload = [
                'file_path' => $real_path,         // Backend đọc đường dẫn này
                'course_id' => (string)$course_id,
                'filename'  => $filename,          // Tên hiển thị
                'mime_type' => $target_file->get_mimetype()
            ];

            // 4. Gửi API
            self::send_to_ai_api($payload);

        } catch (\Exception $e) {
            self::process_log("EXCEPTION: " . $e->getMessage());
        }
    }

    private static function send_to_ai_api($payload) {
        global $CFG;
        
        $base_url = get_config('local_aigrading', 'apiendpoint');
        if (!$base_url) {
             self::process_log("API Endpoint not set");
             return;
        }
        $url = rtrim($base_url, '/') . '/api/v1/rag/ingest';
        
        $curl = new \curl();
        $headers = ['Content-Type: application/json'];
        $api_key = get_config('local_aigrading', 'apikey');
        if ($api_key) $headers[] = 'X-Secret-Key: ' . $api_key;
        $curl->setHeader($headers);

        self::process_log("Sending to API: " . $url);
        
        // Gửi Request
        $resp = $curl->post($url, json_encode($payload));
        
        $info = $curl->get_info();
        if ($curl->errno > 0) {
            self::process_log("CURL ERROR: " . $curl->error);
        } else {
            self::process_log("API RESPONSE CODE: " . $info['http_code']);
            if ($info['http_code'] >= 400) {
                 self::process_log("Response Body: " . $resp);
            }
        }
    }

    private static function process_log($msg) {
        global $CFG;
        // Ghi log vào temp để đảm bảo quyền ghi
        $log_path = $CFG->dataroot . '/temp/aigrading_rag_debug.log';
        $entry = date('Y-m-d H:i:s') . " | " . $msg . PHP_EOL;
        @file_put_contents($log_path, $entry, FILE_APPEND);
    }
}