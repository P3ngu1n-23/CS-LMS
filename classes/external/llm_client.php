<?php
// Namespace phải được khai báo ngay sau <?php
namespace local_aigrading\external;

// Các câu lệnh 'use' phải nằm NGAY SAU namespace
use curl; // <-- Sử dụng curl class của Moodle
use moodle_exception; 

defined('MOODLE_INTERNAL') || die;

class llm_client {

    /** @var string API key được lưu */
    private $apikey;

    /** @var string API endpoint URL */
    private $base_url;

    /** @var string Tên model */
    private $model_name;

    /**
     * Khởi tạo client, đọc cài đặt từ config
     */
    public function __construct() {
        $config = get_config('local_aigrading');
        
        $this->apikey = !empty($config->apikey) ? $config->apikey : null;
        $this->base_url = !empty($config->apiendpoint) ? rtrim($config->apiendpoint, '/') : null;
        $this->model_name = !empty($config->modelname) ? $config->modelname : null;
    }

    /**
     * Kiểm tra xem các cài đặt cơ bản đã được cấu hình chưa
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->apikey) && !empty($this->base_url) && !empty($this->model_name);
    }

    /**
     * Xây dựng URL cho một hành động POST (ví dụ: generateContent)
     *
     * @param string $action Hành động (generateContent, countTokens, v.v.)
     * @return string URL đầy đủ
     */
    private function get_action_url($action = 'generateContent') {
        // Định dạng URL của Gemini:
        // https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=YOUR_API_KEY
        return $this->base_url . '/v1/models/' . $this->model_name . ':' . $action . '?key=' . $this->apikey;
    }

    /**
     * Thực hiện kiểm tra kết nối đầy đủ bằng cách gửi một prompt "Hello".
     *
     * @return array Trả về một mảng chứa [bool success, string message]
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('settings_not_complete', 'local_aigrading')
            ];
        }

        try {
            // 1. Khởi tạo curl object
            $curl = new curl();
            $url = $this->get_action_url('generateContent');

            // 2. Chuẩn bị dữ liệu POST
            $post_data = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => 'Hello']
                        ]
                    ]
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 50 
                ]
            ];

            // 3. Set headers
            $curl->setHeader([
                'Content-Type: application/json'
            ]);

            // 4. Gửi yêu cầu POST với JSON data
            $response = $curl->post($url, json_encode($post_data));

            // 5. Lấy HTTP status code
            $info = $curl->get_info();
            $status_code = $info['http_code'];

            // 6. Phân tích phản hồi HTTP
            if ($status_code != 200) {
                $error_body = json_decode($response);
                $error_message = isset($error_body->error->message) ? $error_body->error->message : $response;
                
                return [
                    'success' => false,
                    'message' => "Lỗi HTTP {$status_code}: " . $error_message
                ];
            }

            // 7. Phân tích nội dung (body)
            $body = json_decode($response);
            if (empty($body) || !isset($body->candidates[0]->content->parts[0]->text)) {
                return [
                    'success' => false,
                    'message' => 'Lỗi: Phản hồi thành công (200) nhưng cấu trúc dữ liệu không hợp lệ.'
                ];
            }

            // 8. THÀNH CÔNG!
            $reply = $body->candidates[0]->content->parts[0]->text;
            
            return [
                'success' => true,
                'message' => get_string('connection_success', 'local_aigrading') . " (Phản hồi AI: " . $reply . ")"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Ngoại lệ: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Lấy danh sách các model khả dụng từ API
     *
     * @return array|null Trả về một mảng [tên => tên_hiển_thị] hoặc null nếu lỗi
     */
    public function get_available_models() {
        if (!$this->is_configured()) {
            return null; // Không thể fetch nếu chưa cấu hình
        }

        try {
            // Endpoint để "Liệt kê Models" là một GET request
            $url = $this->base_url . '/v1/models?key=' . $this->apikey;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, false); // Đây là GET request
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                curl_close($ch);
                return null; // Lỗi cURL
            }
            curl_close($ch);

            if ($http_code != 200) {
                return null; // Lỗi API
            }

            $body = json_decode($response_body);

            // Xử lý và lọc danh sách
            $model_list = [];
            if (!empty($body->models)) {
                foreach ($body->models as $model) {
                    // Chỉ lấy các model hỗ trợ 'generateContent'
                    if (isset($model->supportedGenerationMethods) && 
                        in_array('generateContent', $model->supportedGenerationMethods)) {
                        
                        // Tên model trả về có dạng "models/gemini-pro"
                        // Chúng ta cần trích xuất phần "gemini-pro"
                        $short_name = str_replace('models/', '', $model->name);
                        
                        // Tên hiển thị (ví dụ: "Gemini Pro")
                        $display_name = $model->displayName ?? $short_name;
                        
                        $model_list[$short_name] = $display_name;
                    }
                }
            }
            
            // Sắp xếp danh sách theo tên cho đẹp
            ksort($model_list);
            return $model_list;

        } catch (\Exception $e) {
            return null; // Lỗi ngoại lệ
        }
    }

    /**
     * Gửi prompt đến Gemini và nhận phản hồi văn bản.
     *
     * @param string $prompt_text Nội dung prompt cần gửi
     * @return array ['success' => bool, 'message' => string]
     */
    /**
     * Gửi prompt đến Gemini và nhận phản hồi (Có cơ chế tự động thử lại khi Overloaded)
     */
    public function generate_content($prompt_text) {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('settings_not_complete', 'local_aigrading')
            ];
        }

        $url = $this->get_action_url('generateContent');
        
        // Cấu hình dữ liệu gửi đi
        $post_data = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt_text]]]
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE']
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 4096,
            ]
        ];

        // --- CƠ CHẾ RETRY (THỬ LẠI) ---
        $max_retries = 3; // Thử tối đa 3 lần
        $attempt = 0;
        $response_body = null;
        $http_code = 0;
        $curl_error = '';

        do {
            $attempt++;
            
            // Khởi tạo cURL mỗi lần lặp để đảm bảo sạch sẽ
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            // (Tùy chọn: Nếu bạn vẫn muốn giữ bypass SSL cho chắc ăn)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response_body = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            // Nếu thành công (200) thì thoát vòng lặp ngay
            if ($http_code == 200 && empty($curl_error)) {
                break;
            }

            // Nếu lỗi 503 (Overloaded) VÀ chưa hết số lần thử
            if ($http_code == 503 && $attempt < $max_retries) {
                // Ghi log báo đang chờ
                mtrace("  -> Gặp lỗi 503 (Overloaded). Đang đợi 3 giây để thử lại (Lần $attempt/$max_retries)...");
                sleep(3); // Ngủ 3 giây trước khi thử lại
            } else {
                // Nếu lỗi khác (400, 401...) thì không thử lại, thoát luôn
                break;
            }

        } while ($attempt < $max_retries);
        // ------------------------------

        // Xử lý kết quả sau khi vòng lặp kết thúc
        if (!empty($curl_error)) {
            return ['success' => false, 'message' => 'Lỗi kết nối (cURL): ' . $curl_error];
        }

        if ($http_code != 200) {
            $error_obj = json_decode($response_body);
            $api_msg = $error_obj->error->message ?? $response_body;
            return ['success' => false, 'message' => "Lỗi API (HTTP $http_code): " . $api_msg];
        }

        $body = json_decode($response_body);

        if (isset($body->candidates[0]->content->parts[0]->text)) {
            return [
                'success' => true,
                'message' => $body->candidates[0]->content->parts[0]->text
            ];
        } 
        
        if (isset($body->candidates[0]->finishReason)) {
             return ['success' => false, 'message' => "AI từ chối trả lời. Lý do: " . $body->candidates[0]->finishReason];
        }

        return ['success' => false, 'message' => 'Phản hồi không hợp lệ.'];
    }
}