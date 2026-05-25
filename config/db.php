<?php
// ============================================================
//  config/db.php  —  Kết nối MySQL qua XAMPP
//  Thay đổi các hằng số bên dưới cho phù hợp với máy của bạn
// ============================================================

define('DB_HOST',     'localhost');   // Mặc định XAMPP dùng localhost
define('DB_PORT',     '3306');        // Port MySQL mặc định
define('DB_NAME',     'khachsan_db'); // Tên database đã tạo trong hotel_db.sql
define('DB_USER',     'root');        // User mặc định XAMPP
define('DB_PASS',     '');            // Mật khẩu mặc định XAMPP là rỗng

// ---- Tạo kết nối PDO ----
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT
         . ";dbname=" . DB_NAME . ";charset=utf8mb4";

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Hiển thị lỗi kết nối thân thiện
    die('<div style="font-family:sans-serif;padding:40px;background:#fff0f0;border:2px solid red;border-radius:8px;margin:40px auto;max-width:600px;">
        <h2 style="color:red">❌ Lỗi kết nối CSDL</h2>
        <p><strong>Nguyên nhân:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
        <hr>
        <p>📌 <strong>Kiểm tra lại:</strong></p>
        <ol>
          <li>XAMPP đã bật chưa? Apache &amp; MySQL phải đang chạy (màu xanh).</li>
          <li>Database <code>khachsan_db</code> đã import file <code>hotel_db.sql</code> chưa?</li>
          <li>User/Pass trong <code>config/db.php</code> có đúng không?</li>
        </ol>
    </div>');
}

// ---- Hàm tiện ích ----

/**
 * Lấy danh sách phòng còn trống
 */
function getAvailableRooms(PDO $pdo, string $checkin = '', string $checkout = ''): array {
    if ($checkin && $checkout) {
        $sql = "SELECT p.* FROM PHONG p
                WHERE p.TinhTrang = 'Trống'
                  AND p.MaPhong NOT IN (
                      SELECT MaPhong FROM DAT_PHONG
                      WHERE TrangThai NOT IN ('Đã hủy', 'Đã trả phòng')
                        AND NgayCheckIn  < :checkout
                        AND NgayCheckOut > :checkin
                  )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':checkin' => $checkin, ':checkout' => $checkout]);
    } else {
        $stmt = $pdo->query("SELECT * FROM PHONG WHERE TinhTrang = 'Trống' ORDER BY Tang, MaPhong");
    }
    return $stmt->fetchAll();
}

/**
 * Lấy tất cả phòng (cho sơ đồ phòng của lễ tân)
 */
function getAllRooms(PDO $pdo): array {
    return $pdo->query("SELECT * FROM PHONG ORDER BY Tang, MaPhong")->fetchAll();
}

/**
 * Lấy danh sách dịch vụ
 */
function getAllServices(PDO $pdo): array {
    return $pdo->query("SELECT * FROM DICH_VU WHERE TrangThai = 'Khả dụng'")->fetchAll();
}

// ── CSRF PROTECTION ─────────────────────────────────────────────────────────

/**
 * Tạo / lấy CSRF token cho session hiện tại (64 ký tự hex ngẫu nhiên)
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Trả về hidden input chứa CSRF token — đặt trong mọi <form method="POST">
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Kiểm tra CSRF token từ POST request.
 * Gọi ngay đầu mỗi khối xử lý POST — die(403) nếu token không khớp.
 */
function csrfVerify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;max-width:500px;margin:80px auto;
                background:#fff0f0;border:2px solid #fca5a5;border-radius:12px;text-align:center">
            <div style="font-size:3rem;margin-bottom:12px">⛔</div>
            <h2 style="color:#dc2626;margin-bottom:8px">Yêu cầu không hợp lệ</h2>
            <p style="color:#64748b;margin-bottom:20px">
                Token bảo mật không khớp. Có thể do phiên làm việc đã hết hạn.
            </p>
            <a href="javascript:history.back()"
               style="display:inline-block;padding:10px 24px;background:#1d4ed8;color:#fff;
                      border-radius:8px;text-decoration:none;font-weight:700">
               ← Quay lại
            </a>
        </div>');
    }
}

// ────────────────────────────────────────────────────────────────────────────

/**
 * Kiểm tra đăng nhập tài khoản nhân viên
 * Trả về mảng thông tin tài khoản hoặc false
 */
function loginStaff(PDO $pdo, string $username, string $password): array|false {
    $stmt = $pdo->prepare("
        SELECT tk.*, nv.HoTen, nv.ChucVu
        FROM TAI_KHOAN tk
        LEFT JOIN NHAN_VIEN nv ON tk.MaNV = nv.MaNV
        WHERE tk.TenTK = :username AND tk.TrangThai = 'Hoạt động'
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['MatKhau'])) {
        // Cập nhật lần đăng nhập cuối
        $pdo->prepare("UPDATE TAI_KHOAN SET LanDangNhapCuoi = NOW() WHERE TenTK = :u")
            ->execute([':u' => $username]);
        return $user;
    }
    return false;
}
