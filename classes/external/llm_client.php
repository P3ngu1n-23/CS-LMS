<?php
namespace local_aigrading\external;

defined('MOODLE_INTERNAL') || die;

class llm_client {

    /**
     * Gửi request dạng JSON sang FastAPI
     * @param array $payload Mảng dữ liệu đã chuẩn hóa (khớp với Pydantic Model)
     */
    public function send_grading_request($payload) {
        global $CFG;

        $api_base = get_config('local_aigrading', 'apiendpoint');
        $url = rtrim($api_base, '/') . '/api/v1/grading/async-batch';

        // 1. Mã hóa JSON
        $json_data = json_encode($payload);
        if ($json_data === false) {
            return ['success' => false, 'message' => 'JSON Encode Error: ' . json_last_error_msg()];
        }

        // 2. Cấu hình cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Cấu hình SSL (Tắt cho môi trường Dev)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // 3. Headers (Bắt buộc Content-Type: application/json)
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json_data)
        ];
        
        $apikey = get_config('local_aigrading', 'apikey');
        if (!empty($apikey)) {
            $headers[] = 'X-Secret-Key: ' . $apikey;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // 4. Thực thi
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error_msg = curl_error($ch);
        curl_close($ch);

        if ($error_msg) {
            return ['success' => false, 'message' => "cURL Error: $error_msg"];
        }

        if ($http_code >= 200 && $http_code < 300) {
            return ['success' => true, 'message' => 'Request sent successfully'];
        }

        return ['success' => false, 'message' => "API Error ($http_code): $response"];
    }
    
    public function is_configured() {
        $endpoint = get_config('local_aigrading', 'apiendpoint');
        return !empty($endpoint);
    }
}