<?php
// config/session.php — Khởi tạo session tập trung
// Include file này THAY VÌ gọi session_start() trực tiếp
if (session_status() === PHP_SESSION_NONE) {
    // Tên session riêng, tránh xung đột với phpMyAdmin (cũng dùng PHPSESSID)
    session_name('EASYHOME_SID');

    // Cấu hình cookie rõ ràng
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
