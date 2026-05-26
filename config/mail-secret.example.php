<?php
// config/mail-secret.example.php — MẪU cấu hình SMTP
// ⚠️  Copy file này thành mail-secret.php rồi điền thông tin thật
// ⚠️  KHÔNG commit mail-secret.php lên Git (đã gitignore)

define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'your_email@gmail.com');   // Gmail gửi mail
define('MAIL_PASSWORD', 'xxxx xxxx xxxx xxxx');    // App Password (16 ký tự, không phải MK Gmail)
define('MAIL_FROM',     'your_email@gmail.com');
define('MAIL_FROM_NAME','Easyhome Hotel');

// Hướng dẫn tạo App Password:
// 1. Bật 2-Step Verification tại myaccount.google.com/security
// 2. Vào myaccount.google.com/apppasswords
// 3. Chọn "Khác (tên tùy chỉnh)" → nhập "Easyhome" → Tạo
// 4. Copy 16 ký tự vào MAIL_PASSWORD ở trên
