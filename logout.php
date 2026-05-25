<?php
// logout.php — Đăng xuất tập trung
// Dùng file này thay vì redirect thẳng sang login.php (KHÔNG destroy session)

require_once __DIR__ . '/config/session.php';

// 1. Xóa toàn bộ biến session trong $_SESSION
$_SESSION = [];

// 2. Xóa cookie session nếu đang dùng cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Hủy session server-side
session_destroy();

// 4. Redirect về trang đăng nhập với thông báo
header('Location: login.php?logout=1');
exit;
