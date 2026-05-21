<?php
// staff/login.php — Đăng nhập Nhân viên & Quản lý (Easyhome Blue Theme)
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username && $password) {
        $user = loginStaff($pdo, $username, $password);
        if ($user) {
            $_SESSION['staff_id']   = $user['TenTK'];
            $_SESSION['staff_name'] = $user['HoTen'] ?? $user['TenTK'];
            $_SESSION['staff_role'] = $user['VaiTro'];
            $_SESSION['staff_maNV'] = $user['MaNV'];
            header('Location: ' . ($user['VaiTro'] === 'admin' ? '../admin/dashboard.php' : 'dashboard.php'));
            exit;
        }
        $error = 'Tên đăng nhập hoặc mật khẩu không đúng!';
    } else {
        $error = 'Vui lòng nhập đầy đủ thông tin.';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng Nhập Nội Bộ — Easyhome</title>
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
  --shadow:     0 8px 32px rgba(29,78,216,.13);
  --shadow-lg:  0 20px 60px rgba(29,78,216,.18);
  --font:       Calibri,'Calibri Light',Arial,sans-serif;
  --serif:      'Playfair Display',Georgia,serif;
}

html,body{
  height:100%;
  font-family:var(--font);
}

body{
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
  background:
    radial-gradient(ellipse 80% 60% at 20% 10%, rgba(59,130,246,.18) 0%, transparent 55%),
    radial-gradient(ellipse 60% 50% at 80% 90%, rgba(29,78,216,.14) 0%, transparent 50%),
    linear-gradient(150deg, #e0f0ff 0%, #f0f6ff 40%, #e8f0fe 100%);
  animation: bgShift 8s ease-in-out infinite alternate;
}
@keyframes bgShift{
  from{background-position:0% 50%}
  to{background-position:100% 50%}
}

/* Decorative circles */
body::before{
  content:'';
  position:fixed;top:-120px;left:-120px;
  width:400px;height:400px;
  background:radial-gradient(circle, rgba(59,130,246,.15), transparent 70%);
  border-radius:50%;pointer-events:none;
}
body::after{
  content:'';
  position:fixed;bottom:-80px;right:-80px;
  width:300px;height:300px;
  background:radial-gradient(circle, rgba(29,78,216,.12), transparent 70%);
  border-radius:50%;pointer-events:none;
}

/* Card wrapper */
.login-wrap{
  width:100%;max-width:440px;
  animation:cardIn .6s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes cardIn{
  from{transform:translateY(28px) scale(0.97);opacity:0}
  to{transform:translateY(0) scale(1);opacity:1}
}

/* Logo / Brand area */
.brand-area{
  text-align:center;
  margin-bottom:28px;
}
.brand-logo{
  width:56px;height:56px;
  border-radius:12px;
  object-fit:cover;
  border:3px solid var(--blue-light);
  box-shadow:0 4px 16px rgba(29,78,216,.25);
  margin-bottom:14px;
}
.brand-name{
  font-family:var(--serif);
  font-size:2rem;
  font-weight:600;
  color:var(--blue-dark);
  letter-spacing:1px;
}
.brand-sub{
  font-size:0.7rem;
  font-weight:600;
  letter-spacing:4px;
  text-transform:uppercase;
  color:var(--blue-light);
  margin-top:4px;
}

/* Card */
.card{
  background:#fff;
  border-radius:16px;
  border:1.5px solid var(--border);
  box-shadow:var(--shadow-lg);
  overflow:hidden;
}

/* Card header stripe */
.card-header{
  background:linear-gradient(135deg, var(--blue-dark), var(--blue));
  padding:22px 32px 20px;
  display:flex;
  align-items:center;
  gap:12px;
}
.header-icon{
  width:40px;height:40px;
  background:rgba(255,255,255,.15);
  border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.2rem;
  flex-shrink:0;
}
.header-title{
  font-family:var(--serif);
  font-size:1.15rem;
  font-weight:600;
  color:#fff;
  line-height:1.2;
}
.header-desc{
  font-size:0.72rem;
  color:rgba(255,255,255,.65);
  letter-spacing:1px;
  margin-top:2px;
}

/* Card body */
.card-body{padding:28px 32px 32px}

/* Error message */
.alert-error{
  display:flex;align-items:flex-start;gap:10px;
  background:#fff0f0;
  border:1.5px solid #fca5a5;
  border-left:4px solid #ef4444;
  color:#dc2626;
  padding:12px 14px;
  border-radius:8px;
  font-size:0.83rem;
  line-height:1.5;
  margin-bottom:22px;
  animation:shake .4s ease;
}
@keyframes shake{
  0%,100%{transform:translateX(0)}
  20%,60%{transform:translateX(-4px)}
  40%,80%{transform:translateX(4px)}
}
.alert-icon{font-size:1rem;flex-shrink:0;margin-top:1px}

/* Form groups */
.form-group{margin-bottom:20px}
.form-label{
  display:block;
  font-size:0.7rem;
  font-weight:700;
  letter-spacing:2px;
  text-transform:uppercase;
  color:var(--muted);
  margin-bottom:8px;
}
.input-wrap{position:relative}
.input-icon{
  position:absolute;left:13px;top:50%;transform:translateY(-50%);
  font-size:1rem;color:var(--blue-light);pointer-events:none;
}
.form-input{
  width:100%;
  padding:12px 14px 12px 40px;
  font-family:var(--font);
  font-size:0.93rem;
  color:var(--text);
  background:var(--blue-pale);
  border:1.5px solid var(--border);
  border-radius:9px;
  outline:none;
  transition:all .25s;
}
.form-input:focus{
  border-color:var(--blue);
  background:#fff;
  box-shadow:0 0 0 3px rgba(29,78,216,.12);
}
.form-input::placeholder{color:#94a3b8;font-family:var(--font)}

/* Submit button */
.btn-login{
  width:100%;
  padding:13px;
  font-family:var(--font);
  font-size:0.9rem;
  font-weight:700;
  letter-spacing:2px;
  text-transform:uppercase;
  color:#fff;
  background:linear-gradient(135deg, var(--blue-dark), var(--blue));
  border:none;
  border-radius:9px;
  cursor:pointer;
  transition:all .3s;
  box-shadow:0 4px 16px rgba(29,78,216,.35);
  margin-top:8px;
  position:relative;
  overflow:hidden;
}
.btn-login::after{
  content:'';
  position:absolute;inset:0;
  background:linear-gradient(135deg,transparent 0%,rgba(255,255,255,.1) 50%,transparent 100%);
  transform:translateX(-100%);
  transition:transform .4s;
}
.btn-login:hover::after{transform:translateX(100%)}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(29,78,216,.45)}
.btn-login:active{transform:translateY(0)}



/* Divider */
.divider{
  display:flex;align-items:center;gap:10px;
  margin:20px 0 0;
}
.divider-line{flex:1;height:1px;background:var(--border)}
.divider-text{
  font-size:0.68rem;font-weight:600;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--muted);white-space:nowrap;
}

/* Back link */
.back-link{
  display:flex;align-items:center;justify-content:center;gap:6px;
  margin-top:16px;
  font-size:0.8rem;
  color:var(--muted);
  text-decoration:none;
  transition:color .25s;
  font-weight:500;
}
.back-link:hover{color:var(--blue)}

/* Role badges */
.role-badges{
  display:flex;gap:8px;justify-content:center;
  margin-top:20px;
}
.role-badge{
  font-size:0.66rem;font-weight:600;letter-spacing:1px;
  text-transform:uppercase;padding:4px 12px;border-radius:20px;
}
.badge-admin{background:rgba(29,78,216,.1);color:var(--blue);border:1px solid var(--border)}
.badge-staff{background:rgba(59,130,246,.08);color:var(--blue-light);border:1px solid #bfdbfe}
</style>
</head>
<body>

<div class="login-wrap">

  <!-- BRAND -->
  <div class="brand-area">
    <img src="../assets/images/logo.jpg" alt="Easyhome" class="brand-logo">
    <div class="brand-name">Easyhome</div>
    <div class="brand-sub">Hệ Thống Nội Bộ</div>
  </div>

  <!-- CARD -->
  <div class="card">

    <!-- Header -->
    <div class="card-header">
      <div class="header-icon">🔐</div>
      <div class="header-text">
        <div class="header-title">Đăng Nhập Nhân Viên</div>
        <div class="header-desc">Dành cho Lễ tân & Quản lý</div>
      </div>
    </div>

    <!-- Body -->
    <div class="card-body">

      <?php if ($error): ?>
      <div class="alert-error">
        <span class="alert-icon">⚠</span>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">

        <div class="form-group">
          <label class="form-label">Tên Đăng Nhập</label>
          <div class="input-wrap">
            <span class="input-icon">👤</span>
            <input type="text" name="username" class="form-input"
                   placeholder="Nhập tên đăng nhập..."
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   autocomplete="username" autofocus>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Mật Khẩu</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" name="password" class="form-input"
                   placeholder="••••••••"
                   autocomplete="current-password">
          </div>
        </div>

        <button type="submit" class="btn-login">→ Đăng Nhập</button>

      </form>

      <!-- Role badges -->
      <div class="role-badges">
        <span class="role-badge badge-admin">Admin</span>
        <span class="role-badge badge-staff">Lễ Tân</span>
      </div>
        <strong>Admin:</strong> <code>admin</code> / <code>password</code><br>
        <strong>Lễ tân:</strong> <code>letan01</code> / <code>password</code><br>
        <strong>Lễ tân:</strong> <code>letan02</code> / <code>password</code>
      </div>

      <!-- Divider + Back -->
      <div class="divider">
        <div class="divider-line"></div>
        <div class="divider-text">Quay lại</div>
        <div class="divider-line"></div>
      </div>
      <a href="../index.php" class="back-link">← Về Trang Chủ Easyhome</a>

    </div>
  </div>

</div>

</body>
</html>
