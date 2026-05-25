<?php
// login.php — Đăng nhập thống nhất, tự nhận diện vai trò
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$username || !$password) {
        $error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
    } else {
        // 1. Thử khách hàng (bảng KHACH_HANG)
        $stmt = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE TenTaiKhoan = :u");
        $stmt->execute([':u' => $username]);
        $kh = $stmt->fetch();

        if ($kh && password_verify($password, $kh['MatKhau'])) {
            // Kiểm tra tài khoản KH có bị khóa không (cột TrangThai thêm từ admin/accounts.php)
            $khStatus = $kh['TrangThai'] ?? 'Hoạt động';
            if ($khStatus === 'Khóa') {
                $error = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ khách sạn để được hỗ trợ.';
            } else {
                $_SESSION['kh_id']   = $kh['MaKH'];
                $_SESSION['kh_name'] = $kh['HoTen'];
                $_SESSION['kh_role'] = 'khachhang';
                header('Location: customer/dashboard.php');
                exit;
            }
        }

        // 2. Thử nhân viên / quản lý (bảng TAI_KHOAN)
        $user = loginStaff($pdo, $username, $password);
        if ($user) {
            $_SESSION['staff_id']   = $user['TenTK'];
            $_SESSION['staff_name'] = $user['HoTen'] ?? $user['TenTK'];
            $_SESSION['staff_role'] = $user['VaiTro'];
            $_SESSION['staff_maNV'] = $user['MaNV'];
            $dest = ($user['VaiTro'] === 'admin') ? 'admin/dashboard.php' : 'staff/dashboard.php';
            header('Location: ' . $dest);
            exit;
        }

        $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng Nhập — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:       #1d4ed8;
  --blue-light: #3b82f6;
  --blue-dark:  #1e3a8a;
  --blue-pale:  #eff6ff;
  --blue-mid:   #dbeafe;
  --bg:         #f0f6ff;
  --text:       #1e293b;
  --muted:      #64748b;
  --border:     #bfdbfe;
  --shadow-lg:  0 20px 60px rgba(29,78,216,.18);
  --font:       Calibri,'Calibri Light',Arial,sans-serif;
  --serif:      'Playfair Display',Georgia,serif;
}
html,body{height:100%;font-family:var(--font)}
body{
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  padding:20px;
  background:
    radial-gradient(ellipse 80% 60% at 15% 10%, rgba(59,130,246,.18) 0%, transparent 55%),
    radial-gradient(ellipse 60% 50% at 85% 90%, rgba(29,78,216,.14) 0%, transparent 50%),
    linear-gradient(150deg,#e0f0ff 0%,#f0f6ff 40%,#e8f0fe 100%);
}
body::before{content:'';position:fixed;top:-120px;left:-120px;width:400px;height:400px;
  background:radial-gradient(circle,rgba(59,130,246,.15),transparent 70%);border-radius:50%;pointer-events:none}
body::after{content:'';position:fixed;bottom:-80px;right:-80px;width:300px;height:300px;
  background:radial-gradient(circle,rgba(29,78,216,.12),transparent 70%);border-radius:50%;pointer-events:none}

.login-wrap{width:100%;max-width:440px;animation:cardIn .6s cubic-bezier(.34,1.56,.64,1) both}
@keyframes cardIn{from{transform:translateY(28px) scale(.97);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}

/* Brand */
.brand-area{text-align:center;margin-bottom:24px}
.brand-logo{width:60px;height:60px;border-radius:14px;object-fit:cover;
  border:3px solid var(--blue-light);box-shadow:0 4px 20px rgba(29,78,216,.28);margin-bottom:12px}
.brand-name{font-family:var(--serif);font-size:2rem;font-weight:600;color:var(--blue-dark);letter-spacing:1px}
.brand-sub{font-size:0.68rem;font-weight:600;letter-spacing:4px;text-transform:uppercase;color:var(--blue-light);margin-top:4px}

/* Card */
.card{background:#fff;border-radius:18px;border:1.5px solid var(--border);box-shadow:var(--shadow-lg);overflow:hidden}

/* Card header */
.card-header{
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:22px 30px 20px;
  display:flex;align-items:center;gap:14px;
}
.header-icon{width:44px;height:44px;background:rgba(255,255,255,.15);border-radius:12px;
  display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.header-title{font-family:var(--serif);font-size:1.15rem;font-weight:600;color:#fff;line-height:1.2}
.header-desc{font-size:0.71rem;color:rgba(255,255,255,.65);letter-spacing:1px;margin-top:3px}

/* Body */
.card-body{padding:28px 30px 30px}

/* Error */
.alert-error{
  display:flex;align-items:flex-start;gap:10px;
  background:#fff0f0;border:1.5px solid #fca5a5;border-left:4px solid #ef4444;
  color:#dc2626;padding:12px 14px;border-radius:9px;
  font-size:0.83rem;line-height:1.5;margin-bottom:22px;
  animation:shake .4s ease
}
@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-4px)}40%,80%{transform:translateX(4px)}}

/* Form */
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:0.68rem;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.input-wrap{position:relative}
.input-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);
  font-size:1rem;color:var(--blue-light);pointer-events:none}
.form-input{
  width:100%;padding:13px 13px 13px 40px;
  font-family:var(--font);font-size:0.93rem;color:var(--text);
  background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:10px;outline:none;transition:all .25s;
}
.form-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.12)}
.form-input::placeholder{color:#94a3b8;font-family:var(--font)}

/* Toggle mật khẩu */
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:1rem;color:var(--muted);
  padding:4px;border-radius:6px;transition:color .2s}
.toggle-pw:hover{color:var(--blue)}
#password{padding-right:40px}

/* Submit */
.btn-login{
  width:100%;padding:14px;font-family:var(--font);font-size:0.9rem;font-weight:700;
  letter-spacing:2px;text-transform:uppercase;color:#fff;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border:none;border-radius:10px;cursor:pointer;transition:all .3s;
  box-shadow:0 4px 18px rgba(29,78,216,.38);margin-top:6px;
  position:relative;overflow:hidden;
}
.btn-login::after{content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,transparent,rgba(255,255,255,.1),transparent);
  transform:translateX(-100%);transition:transform .4s}
.btn-login:hover::after{transform:translateX(100%)}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 6px 26px rgba(29,78,216,.48)}
.btn-login:active{transform:translateY(0)}

/* Register link */
.register-line{
  text-align:center;margin-top:18px;
  font-size:0.82rem;color:var(--muted);
}
.register-line a{color:var(--blue);font-weight:600;text-decoration:none;transition:color .25s}
.register-line a:hover{color:var(--blue-dark)}

/* Divider */
.divider{display:flex;align-items:center;gap:10px;margin:18px 0 0}
.divider-line{flex:1;height:1px;background:var(--border)}
.divider-text{font-size:0.67rem;font-weight:600;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--muted);white-space:nowrap}

/* Back */
.back-link{display:flex;align-items:center;justify-content:center;gap:6px;
  margin-top:14px;font-size:0.8rem;color:var(--muted);
  text-decoration:none;transition:color .25s;font-weight:500}
.back-link:hover{color:var(--blue)}
</style>
</head>
<body>

<div class="login-wrap">

  <!-- BRAND -->
  <div class="brand-area">
    <img src="assets/images/logo.jpg" alt="Easyhome" class="brand-logo">
    <div class="brand-name">Easyhome</div>
    <div class="brand-sub">Đăng Nhập Hệ Thống</div>
  </div>

  <div class="card">

    <!-- HEADER -->
    <div class="card-header">
      <div class="header-icon">🔑</div>
      <div>
        <div class="header-title">Đăng Nhập</div>
        <div class="header-desc">Nhập thông tin tài khoản của bạn</div>
      </div>
    </div>

    <!-- BODY -->
    <div class="card-body">

      <?php if ($error): ?>
      <div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">

        <div class="form-group">
          <label class="form-label">Tên Đăng Nhập</label>
          <div class="input-wrap">
            <span class="input-icon">👤</span>
            <input type="text" name="username" class="form-input"
                   placeholder="Nhập tên đăng nhập"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" autofocus>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Mật Khẩu</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" id="password" name="password" class="form-input"
                   placeholder="••••••••" autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="togglePw()" title="Hiện/ẩn mật khẩu">👁</button>
          </div>
        </div>

        <button type="submit" class="btn-login">→ Đăng Nhập</button>
      </form>

      <div class="register-line">
        Chưa có tài khoản? <a href="register.php">Đăng ký ngay →</a>
      </div>

      <div class="divider">
        <div class="divider-line"></div>
        <div class="divider-text">Quay lại</div>
        <div class="divider-line"></div>
      </div>
      <a href="index.php" class="back-link">← Về Trang Chủ Easyhome</a>

    </div>
  </div>
</div>

<script>
function togglePw() {
  const el = document.getElementById('password');
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>

</body>
</html>
