<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['kh_id'])) {
    header('Location: customer/dashboard.php'); exit;
}

$error = '';
$form  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'hoTen'   => trim($_POST['ho_ten']   ?? ''),
        'tenTK'   => trim($_POST['ten_tk']   ?? ''),
        'matKhau' => $_POST['mat_khau']      ?? '',
        'xacNhan' => $_POST['xac_nhan']      ?? '',
        'cccd'    => trim($_POST['cccd']     ?? ''),
        'sdt'     => trim($_POST['sdt']      ?? ''),
        'email'   => trim($_POST['email']    ?? ''),
    ];

    if (!$form['hoTen'] || !$form['tenTK'] || !$form['matKhau'] || !$form['sdt']) {
        $error = 'Vui lòng nhập đầy đủ các trường bắt buộc (*)';
    } elseif (mb_strlen($form['matKhau']) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự.';
    } elseif ($form['matKhau'] !== $form['xacNhan']) {
        $error = 'Mật khẩu xác nhận không khớp.';
    } else {
        $dup = $pdo->prepare("SELECT MaKH FROM KHACH_HANG WHERE TenTaiKhoan = :u");
        $dup->execute([':u' => $form['tenTK']]);
        if ($dup->fetch()) {
            $error = 'Tên đăng nhập đã tồn tại. Vui lòng chọn tên khác.';
        } else {
            $last = $pdo->query("SELECT MaKH FROM KHACH_HANG ORDER BY NgayTao DESC LIMIT 1")->fetchColumn();
            $num  = $last ? intval(substr($last, 2)) + 1 : 1;
            $maKH = 'KH' . str_pad($num, 3, '0', STR_PAD_LEFT);

            $ins = $pdo->prepare("INSERT INTO KHACH_HANG (MaKH,HoTen,TenTaiKhoan,MatKhau,CCCD,SoDienThoai,Email,TichDiem) VALUES (:id,:ten,:tk,:mk,:cccd,:sdt,:email,0)");
            $ins->execute([
                ':id'   => $maKH,
                ':ten'  => $form['hoTen'],
                ':tk'   => $form['tenTK'],
                ':mk'   => password_hash($form['matKhau'], PASSWORD_DEFAULT),
                ':cccd' => $form['cccd'],
                ':sdt'  => $form['sdt'],
                ':email'=> $form['email'],
            ]);

            $_SESSION['kh_id']   = $maKH;
            $_SESSION['kh_name'] = $form['hoTen'];
            $_SESSION['kh_role'] = 'khachhang';
            header('Location: customer/dashboard.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Đăng Ký — Easyhome</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --shadow-lg:0 20px 60px rgba(29,78,216,.18);
  --font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
html,body{min-height:100%;font-family:var(--font)}
body{
  display:flex;align-items:flex-start;justify-content:center;
  padding:32px 20px 40px;
  background:radial-gradient(ellipse 80% 60% at 15% 10%,rgba(59,130,246,.18) 0%,transparent 55%),
             radial-gradient(ellipse 60% 50% at 85% 90%,rgba(29,78,216,.14) 0%,transparent 50%),
             linear-gradient(150deg,#e0f0ff 0%,#f0f6ff 40%,#e8f0fe 100%);
  min-height:100vh;
}
.wrap{width:100%;max-width:480px;animation:up .55s cubic-bezier(.34,1.56,.64,1) both}
@keyframes up{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}

.brand{text-align:center;margin-bottom:22px}
.brand img{width:56px;height:56px;border-radius:13px;object-fit:cover;
  border:3px solid var(--blue-light);box-shadow:0 4px 18px rgba(29,78,216,.28);margin-bottom:10px}
.brand-name{font-family:var(--serif);font-size:1.85rem;color:var(--blue-dark);letter-spacing:1px}
.brand-sub{font-size:.67rem;font-weight:600;letter-spacing:4px;text-transform:uppercase;color:var(--blue-light);margin-top:3px}

.card{background:#fff;border-radius:18px;border:1.5px solid var(--border);box-shadow:var(--shadow-lg);overflow:hidden}
.card-head{background:linear-gradient(135deg,var(--blue-dark),var(--blue));padding:20px 28px;
  display:flex;align-items:center;gap:12px}
.head-icon{width:42px;height:42px;background:rgba(255,255,255,.15);border-radius:11px;
  display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.head-title{font-family:var(--serif);font-size:1.1rem;color:#fff}
.head-desc{font-size:.7rem;color:rgba(255,255,255,.65);letter-spacing:1px;margin-top:2px}
.card-body{padding:26px 28px 28px}

.alert-error{display:flex;align-items:flex-start;gap:9px;background:#fff0f0;
  border:1.5px solid #fca5a5;border-left:4px solid #ef4444;color:#dc2626;
  padding:11px 13px;border-radius:8px;font-size:.83rem;line-height:1.5;margin-bottom:20px;
  animation:shake .4s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-4px)}50%{transform:translateX(4px)}}

.row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:.67rem;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);margin-bottom:7px}
.required{color:#ef4444;margin-left:2px}
.input-wrap{position:relative}
.input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);
  font-size:.95rem;color:var(--blue-light);pointer-events:none}
.form-input{width:100%;padding:11px 12px 11px 37px;font-family:var(--font);font-size:.91rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:9px;outline:none;transition:all .2s}
.form-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.11)}
.form-input::placeholder{color:#94a3b8}
#pw,#pw2{padding-right:38px}
.toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:.95rem;color:var(--muted);
  padding:4px;border-radius:5px;transition:color .2s}
.toggle-pw:hover{color:var(--blue)}

.divider-label{text-align:center;font-size:.67rem;font-weight:600;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);margin:18px 0 16px;
  display:flex;align-items:center;gap:10px}
.divider-label::before,.divider-label::after{content:'';flex:1;height:1px;background:var(--border)}

.btn-submit{width:100%;padding:13px;font-family:var(--font);font-size:.88rem;font-weight:700;
  letter-spacing:2px;text-transform:uppercase;color:#fff;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));border:none;
  border-radius:10px;cursor:pointer;transition:all .3s;
  box-shadow:0 4px 18px rgba(29,78,216,.38);position:relative;overflow:hidden;margin-top:4px}
.btn-submit::after{content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,transparent,rgba(255,255,255,.1),transparent);
  transform:translateX(-100%);transition:transform .4s}
.btn-submit:hover::after{transform:translateX(100%)}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(29,78,216,.48)}

.login-line{text-align:center;margin-top:18px;font-size:.82rem;color:var(--muted)}
.login-line a{color:var(--blue);font-weight:600;text-decoration:none}
.login-line a:hover{color:var(--blue-dark)}
.back-link{display:flex;align-items:center;justify-content:center;gap:5px;
  margin-top:12px;font-size:.79rem;color:var(--muted);text-decoration:none;transition:color .2s}
.back-link:hover{color:var(--blue)}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <img src="assets/images/logo.jpg" alt="Easyhome">
    <div class="brand-name">Easyhome</div>
    <div class="brand-sub">Tạo Tài Khoản Mới</div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="head-icon">📝</div>
      <div>
        <div class="head-title">Đăng Ký Khách Hàng</div>
        <div class="head-desc">Tạo tài khoản để đặt phòng trực tuyến</div>
      </div>
    </div>
    <div class="card-body">
      <?php if ($error): ?>
      <div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="row-2">
          <div class="form-group">
            <label class="form-label">Họ và Tên <span class="required">*</span></label>
            <div class="input-wrap">
              <span class="input-icon">👤</span>
              <input type="text" name="ho_ten" class="form-input" placeholder="Nguyễn Văn A"
                     value="<?= htmlspecialchars($form['hoTen'] ?? '') ?>" autocomplete="name">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Tên Đăng Nhập <span class="required">*</span></label>
            <div class="input-wrap">
              <span class="input-icon">🔖</span>
              <input type="text" name="ten_tk" class="form-input" placeholder="vana123"
                     value="<?= htmlspecialchars($form['tenTK'] ?? '') ?>" autocomplete="username">
            </div>
          </div>
        </div>

        <div class="row-2">
          <div class="form-group">
            <label class="form-label">Mật Khẩu <span class="required">*</span></label>
            <div class="input-wrap">
              <span class="input-icon">🔒</span>
              <input type="password" id="pw" name="mat_khau" class="form-input" placeholder="Tối thiểu 6 ký tự">
              <button type="button" class="toggle-pw" onclick="toggle('pw',this)">👁</button>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Xác Nhận MK <span class="required">*</span></label>
            <div class="input-wrap">
              <span class="input-icon">🔒</span>
              <input type="password" id="pw2" name="xac_nhan" class="form-input" placeholder="Nhập lại mật khẩu">
              <button type="button" class="toggle-pw" onclick="toggle('pw2',this)">👁</button>
            </div>
          </div>
        </div>

        <div class="divider-label">Thông tin liên hệ</div>

        <div class="row-2">
          <div class="form-group">
            <label class="form-label">Số Điện Thoại <span class="required">*</span></label>
            <div class="input-wrap">
              <span class="input-icon">📱</span>
              <input type="tel" name="sdt" class="form-input" placeholder="0901234567"
                     value="<?= htmlspecialchars($form['sdt'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">CCCD / CMND</label>
            <div class="input-wrap">
              <span class="input-icon">🪪</span>
              <input type="text" name="cccd" class="form-input" placeholder="012345678901"
                     value="<?= htmlspecialchars($form['cccd'] ?? '') ?>">
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email</label>
          <div class="input-wrap">
            <span class="input-icon">✉️</span>
            <input type="email" name="email" class="form-input" placeholder="email@example.com"
                   value="<?= htmlspecialchars($form['email'] ?? '') ?>" autocomplete="email">
          </div>
        </div>

        <button type="submit" class="btn-submit">✓ Tạo Tài Khoản</button>
      </form>

      <div class="login-line">Đã có tài khoản? <a href="login.php">Đăng nhập →</a></div>
      <a href="index.php" class="back-link">← Về Trang Chủ Easyhome</a>
    </div>
  </div>
</div>
<script>
function toggle(id, btn) {
  const el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
