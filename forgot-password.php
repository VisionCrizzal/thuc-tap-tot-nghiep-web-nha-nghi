<?php
// forgot-password.php — Quên mật khẩu: nhập email → nhận link reset
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mailer.php';

// Nếu đã đăng nhập → về trang chủ
if (isset($_SESSION['kh_id']) || isset($_SESSION['staff_id'])) {
    header('Location: index.php'); exit;
}

$msg     = '';
$msgType = '';
$sent    = isset($_GET['sent']);

// ── Xử lý POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg     = 'Vui lòng nhập địa chỉ email hợp lệ.';
        $msgType = 'error';
    } else {
        // Tìm KH theo email
        $stmt = $pdo->prepare("SELECT MaKH, HoTen, Email FROM KHACH_HANG WHERE Email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $kh = $stmt->fetch();

        if ($kh) {
            // Tạo token ngẫu nhiên 64 ký tự
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // hết hạn sau 1 giờ

            // Lưu token vào DB
            $pdo->prepare("UPDATE KHACH_HANG SET ResetToken=:tok, ResetExpires=:exp WHERE MaKH=:id")
                ->execute([':tok' => $token, ':exp' => $expires, ':id' => $kh['MaKH']]);

            // Tạo link reset
            $baseUrl   = 'http://' . $_SERVER['HTTP_HOST'] . '/khachsan';
            $resetLink = $baseUrl . '/reset-password.php?token=' . urlencode($token);

            // Gửi email (lỗi gửi mail không block UX)
            sendPasswordReset($kh['Email'], $kh['HoTen'], $resetLink);
        }

        // Luôn redirect với ?sent=1 (không tiết lộ email có tồn tại không)
        header('Location: forgot-password.php?sent=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quên Mật Khẩu — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --text:#1e293b;--muted:#64748b;--border:#bfdbfe;
  --shadow:0 4px 24px rgba(29,78,216,.10);--radius:10px;
  --font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
body{font-family:var(--font);background:var(--bg);min-height:100vh;
     display:flex;align-items:center;justify-content:center;padding:24px}

.card{
  background:#fff;border-radius:16px;box-shadow:var(--shadow);
  width:100%;max-width:420px;overflow:hidden;
}
.card-header{
  background:var(--blue-dark);padding:32px 36px 28px;text-align:center;
}
.card-header .logo{
  font-family:var(--serif);color:#fff;font-size:26px;font-weight:700;
  letter-spacing:.5px;margin-bottom:6px;
}
.card-header .sub{color:#bfdbfe;font-size:14px}

.card-body{padding:32px 36px}

.step-label{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--blue-pale);border:1px solid var(--border);
  border-radius:20px;padding:6px 14px;font-size:13px;
  color:var(--blue);margin-bottom:20px;
}

h2{font-family:var(--serif);color:var(--blue-dark);font-size:22px;
   margin-bottom:8px}
.desc{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:24px}

.form-group{margin-bottom:20px}
.form-label{display:block;font-size:13px;font-weight:600;
             color:var(--text);margin-bottom:7px}
.input-wrap{position:relative}
.input-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);
             font-size:16px;pointer-events:none}
.form-input{
  width:100%;padding:11px 14px 11px 40px;border:1.5px solid var(--border);
  border-radius:8px;font-size:15px;font-family:var(--font);
  background:var(--blue-pale);color:var(--text);outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.form-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(29,78,216,.10)}

.btn-submit{
  width:100%;padding:13px;background:var(--blue);color:#fff;
  border:none;border-radius:8px;font-size:16px;font-family:var(--serif);
  font-weight:600;cursor:pointer;transition:background .2s,transform .1s;
  letter-spacing:.3px;
}
.btn-submit:hover{background:var(--blue-dark)}
.btn-submit:active{transform:scale(.99)}

.msg{
  padding:12px 16px;border-radius:8px;font-size:14px;
  margin-bottom:20px;display:flex;align-items:center;gap:10px;
}
.msg.error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}

/* Màn hình thành công */
.sent-box{text-align:center;padding:8px 0}
.sent-icon{font-size:52px;margin-bottom:16px}
.sent-title{font-family:var(--serif);color:var(--blue-dark);font-size:22px;
             margin-bottom:10px}
.sent-desc{color:var(--muted);font-size:14px;line-height:1.7}
.sent-note{
  background:var(--blue-pale);border:1px solid var(--border);border-radius:8px;
  padding:12px 16px;font-size:13px;color:var(--muted);margin:20px 0;
  text-align:left;line-height:1.6;
}

.divider{display:flex;align-items:center;gap:12px;margin:24px 0}
.divider-line{flex:1;height:1px;background:var(--border)}
.divider-text{font-size:12px;color:var(--muted);white-space:nowrap}

.back-link{
  display:block;text-align:center;color:var(--blue);font-size:14px;
  text-decoration:none;padding:10px;border-radius:8px;
  transition:background .15s;
}
.back-link:hover{background:var(--blue-pale)}

.links{margin-top:20px;text-align:center;font-size:14px;color:var(--muted)}
.links a{color:var(--blue);text-decoration:none;font-weight:600}
.links a:hover{text-decoration:underline}
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div class="logo">🏨 Easyhome</div>
    <div class="sub">Nhà nghỉ theo giờ — TP. Huế</div>
  </div>

  <div class="card-body">

  <?php if ($sent): ?>
    <!-- ── Màn hình đã gửi ── -->
    <div class="sent-box">
      <div class="sent-icon">📬</div>
      <div class="sent-title">Kiểm tra hộp thư!</div>
      <p class="sent-desc">
        Nếu email của bạn có trong hệ thống, chúng tôi đã gửi link đặt lại mật khẩu.
      </p>
      <div class="sent-note">
        📌 <strong>Lưu ý:</strong><br>
        • Link có hiệu lực trong <strong>1 giờ</strong><br>
        • Kiểm tra cả thư mục <strong>Spam / Junk</strong><br>
        • Mỗi lần gửi tạo link mới — link cũ hết hiệu lực
      </div>
    </div>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-text">hoặc</div>
      <div class="divider-line"></div>
    </div>
    <a href="forgot-password.php" class="back-link">🔄 Gửi lại email</a>
    <a href="login.php" class="back-link">← Quay lại đăng nhập</a>

  <?php else: ?>
    <!-- ── Form nhập email ── -->
    <div class="step-label">🔑 Bước 1/2 — Xác minh email</div>
    <h2>Quên Mật Khẩu?</h2>
    <p class="desc">Nhập địa chỉ email đã đăng ký. Chúng tôi sẽ gửi link tạo mật khẩu mới.</p>

    <?php if ($msg): ?>
    <div class="msg <?= $msgType ?>">
      <?= $msgType === 'error' ? '⚠️' : '✅' ?>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="forgot-password.php">
      <?= csrfField() ?>

      <div class="form-group">
        <label class="form-label">Địa chỉ Email</label>
        <div class="input-wrap">
          <span class="input-icon">✉️</span>
          <input type="email" name="email" class="form-input"
                 placeholder="example@gmail.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 autocomplete="email" autofocus required>
        </div>
      </div>

      <button type="submit" class="btn-submit">→ Gửi Link Đặt Lại Mật Khẩu</button>
    </form>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-text">Quay lại</div>
      <div class="divider-line"></div>
    </div>
    <a href="login.php" class="back-link">← Về trang đăng nhập</a>

    <div class="links">
      Chưa có tài khoản? <a href="register.php">Đăng ký ngay →</a>
    </div>
  <?php endif; ?>

  </div>
</div>

</body>
</html>
