<?php
namespace local_aigrading\external;

defined('MOODLE_INTERNAL') || die;

class llm_client {

    private $base_url;
    private $api_key;

    // Endpoint chấm điểm của FastAPI
    const ENDPOINT_GRADING = '/api/v1/grading/async-batch';

    public function __construct() {
        $config = get_config('local_aigrading');
        $this->base_url = rtrim($config->apiendpoint, '/');
        $this->api_key = $config->apikey;
    }

    /**
     * Gửi yêu cầu chấm điểm (Async)
     * @param array $text_data Dữ liệu text (content, instructions, callback_url...)
     * @param array $file_data Dữ liệu file (Moodle stored_file objects)
     */
    public function send_grading_request($text_data, $file_data) {
        $url = $this->base_url . self::ENDPOINT_GRADING;
        return $this->send_multipart($url, $text_data, $file_data);
    }

    private function send_multipart($url, $text_data, $file_data) {
        $curl = curl_init();
        $post_fields = [];
        $temp_files = [];

        // 1. Pack Text Data
        foreach ($text_data as $key => $value) {
            $post_fields[$key] = $value;
        }

        // 2. Pack File Data (Convert Moodle File -> CURLFile)
        foreach ($file_data as $field_name => $files) {
            if (empty($files)) continue;
            foreach ($files as $index => $stored_file) {
                // Tạo file tạm vật lý
                $temp_dir = make_request_directory();
                $temp_path = $temp_dir . '/' . $stored_file->get_filename();
                $stored_file->copy_content_to($temp_path);
                $temp_files[] = $temp_path;

                // Tạo CURLFile
                $cfile = new \CURLFile($temp_path, $stored_file->get_mimetype(), $stored_file->get_filename());
                // FastAPI nhận list file: field_name (không cần index nếu dùng List[UploadFile])
                // PHP Curl yêu cầu array key nếu gửi nhiều file cùng tên field
                $post_fields["{$field_name}[{$index}]"] = $cfile;
            }
        }

        // 3. Headers & Security
        $headers = ['Content-Type: multipart/form-data'];
        if (!empty($this->api_key)) {
            // Gửi Shared Secret để FastAPI biết request này từ Moodle chính chủ
            $headers[] = 'X-Secret-Key: ' . $this->api_key;
        }

        // 4. Config CURL
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30, // Timeout ngắn vì chỉ cần gửi đi (FastAPI trả về 202 Accepted ngay)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        // Cleanup temp files
        foreach ($temp_files as $path) {
            if (file_exists($path)) @unlink($path);
        }

        if ($err) return ['success' => false, 'message' => "cURL Error: $err"];
        
        // Chấp nhận mã 200 (OK) hoặc 202 (Accepted)
        if ($http_code >= 200 && $http_code < 300) {
            return ['success' => true, 'message' => 'Request sent successfully'];
        }

        return ['success' => false, 'message' => "API Error ($http_code): $response"];
    }
}