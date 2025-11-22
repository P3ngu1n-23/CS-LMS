*Project Structure*

/local/ai_grading/
├── classes/                    # Nơi chứa tất cả các lớp PHP (PSR-4)
│   ├── controller/             # (Tùy chọn) Chứa các lớp điều khiển
│   ├── engine/                 # Nơi đặt Processing Engine
│   │   ├── processor.php       # Lớp xử lý chính
│   │   └── prompt_generator.php# Lớp tạo prompt
│   ├── external/               # Các lớp giao tiếp API bên ngoài
│   │   ├── llm_client.php      # Lớp gọi API LLM
│   │   └── ocr_client.php      # Lớp gọi API OCR
│   └── task/                   # Các lớp cho tác vụ nền (cron job)
│       └── grade_submission.php
│
├── db/                         # Mọi thứ liên quan đến cơ sở dữ liệu
│   ├── access.php              # Định nghĩa quyền truy cập (capabilities)
│   ├── events.php              # Đăng ký các "event listener"
│   ├── install.xml             # Cấu trúc bảng CSDL khi cài đặt
│   └── upgrade.php             # Nâng cấp CSDL khi có phiên bản mới
│
├── lang/                       # Chứa các file ngôn ngữ
│   └── en/                     # Tiếng Anh là bắt buộc
│       └── local_aigrading.php # File chuỗi ngôn ngữ
│
├── templates/                  # (Tùy chọn) Chứa các file template Mustache
│
├── settings.php                # Định nghĩa trang cài đặt cho admin
├── lib.php                     # Chứa các hàm cốt lõi của plugin
├── version.php                 # Thông tin phiên bản (quan trọng nhất)
├── index.php                   # (Tùy chọn) Trang chính của plugin, ví dụ: Dashboard
└── README.md                   # Mô tả về plugin