<?php
// reset-password.php — Đặt lại mật khẩu từ link email
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

// Nếu đã đăng nhập → về trang chủ
if (isset($_SESSION['kh_id']) || isset($_SESSION['staff_id'])) {
    header('Location: index.php'); exit;
}

$token   = trim($_GET['token'] ?? '');
$msg     = '';
$msgType = '';
$valid   = false;
$kh      = null;

// ── Xác minh token ──────────────────────────────────────────────────────────
if ($token) {
    $stmt = $pdo->prepare("
        SELECT MaKH, HoTen, TenTaiKhoan, ResetToken, ResetExpires
        FROM KHACH_HANG
        WHERE ResetToken = :tok AND ResetExpires > NOW()
        LIMIT 1
    ");
    $stmt->execute([':tok' => $token]);
    $kh = $stmt->fetch();
    $valid = (bool)$kh;
}

// ── Xử lý POST: đặt mật khẩu mới ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    csrfVerify();

    $newPw  = $_POST['new_password']     ?? '';
    $confPw = $_POST['confirm_password'] ?? '';

    if (strlen($newPw) < 6) {
        $msg = 'Mật khẩu phải có ít nhất 6 ký tự.';
        $msgType = 'error';
    } elseif ($newPw !== $confPw) {
        $msg = 'Mật khẩu xác nhận không khớp.';
        $msgType = 'error';
    } else {
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        // Cập nhật MK + xoá token (dùng 1 lần)
        $pdo->prepare("
            UPDATE KHACH_HANG
            SET MatKhau=:pw, ResetToken=NULL, ResetExpires=NULL
            WHERE MaKH=:id
        ")->execute([':pw' => $hash, ':id' => $kh['MaKH']]);

        // Redirect về login với thông báo thành công
        header('Location: login.php?reset=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đặt Lại Mật Khẩu — Easyhome</title>
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
h2{font-family:var(--serif);color:var(--blue-dark);font-size:22px;margin-bottom:8px}
.desc{color:var(--muted);font-size:14px;line-height:1.6;margin-bottom:24px}

.form-group{margin-bottom:18px}
.form-label{display:block;font-size:13px;font-weight:600;
             color:var(--text);margin-bottom:7px}
.input-wrap{position:relative}
.input-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);
             font-size:16px;pointer-events:none}
.toggle-pw{
  position:absolute;right:10px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:16px;padding:4px;
  color:var(--muted);
}
.form-input{
  width:100%;padding:11px 40px 11px 40px;border:1.5px solid var(--border);
  border-radius:8px;font-size:15px;font-family:var(--font);
  background:var(--blue-pale);color:var(--text);outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.form-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(29,78,216,.10)}

/* Password strength bar */
.pw-strength{margin-top:8px}
.pw-bar-wrap{height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-bottom:4px}
.pw-bar{height:100%;width:0;border-radius:3px;transition:width .3s,background .3s}
.pw-label{font-size:12px;color:var(--muted)}

.btn-submit{
  width:100%;padding:13px;background:var(--blue);color:#fff;
  border:none;border-radius:8px;font-size:16px;font-family:var(--serif);
  font-weight:600;cursor:pointer;transition:background .2s,transform .1s;
  letter-spacing:.3px;margin-top:6px;
}
.btn-submit:hover{background:var(--blue-dark)}
.btn-submit:active{transform:scale(.99)}

.msg{
  padding:12px 16px;border-radius:8px;font-size:14px;
  margin-bottom:20px;display:flex;align-items:center;gap:10px;
}
.msg.error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.msg.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a}

/* Token hết hạn / không hợp lệ */
.expired-box{text-align:center;padding:8px 0}
.expired-icon{font-size:52px;margin-bottom:16px}
.expired-title{font-family:var(--serif);color:#dc2626;font-size:22px;margin-bottom:10px}
.expired-desc{color:var(--muted);font-size:14px;line-height:1.7;margin-bottom:24px}

.divider{display:flex;align-items:center;gap:12px;margin:24px 0}
.divider-line{flex:1;height:1px;background:var(--border)}
.divider-text{font-size:12px;color:var(--muted);white-space:nowrap}
.back-link{
  display:block;text-align:center;color:var(--blue);font-size:14px;
  text-decoration:none;padding:10px;border-radius:8px;transition:background .15s;
}
.back-link:hover{background:var(--blue-pale)}

.user-chip{
  display:inline-flex;align-items:center;gap:8px;background:var(--blue-pale);
  border:1px solid var(--border);border-radius:20px;padding:6px 14px;
  font-size:13px;color:var(--blue-dark);margin-bottom:20px;
}
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div class="logo">🏨 Easyhome</div>
    <div class="sub">Nhà nghỉ theo giờ — TP. Huế</div>
  </div>

  <div class="card-body">

  <?php if (!$token || !$valid): ?>
    <!-- ── Token không hợp lệ / hết hạn ── -->
    <div class="expired-box">
      <div class="expired-icon">⏰</div>
      <div class="expired-title">Link đã hết hạn</div>
      <p class="expired-desc">
        Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn (sau 1 giờ).<br>
        Vui lòng gửi lại yêu cầu.
      </p>
      <a href="forgot-password.php" class="btn-submit" style="display:block;text-decoration:none;text-align:center">
        🔄 Gửi Lại Email
      </a>
    </div>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-text">hoặc</div>
      <div class="divider-line"></div>
    </div>
    <a href="login.php" class="back-link">← Quay lại đăng nhập</a>

  <?php else: ?>
    <!-- ── Form đặt mật khẩu mới ── -->
    <div class="step-label">🔑 Bước 2/2 — Tạo mật khẩu mới</div>

    <div class="user-chip">
      👤 <?= htmlspecialchars($kh['HoTen']) ?>
      <span style="color:var(--muted)">(<?= htmlspecialchars($kh['TenTaiKhoan'] ?? '') ?>)</span>
    </div>

    <h2>Đặt Mật Khẩu Mới</h2>
    <p class="desc">Mật khẩu mới phải có ít nhất 6 ký tự.</p>

    <?php if ($msg): ?>
    <div class="msg <?= $msgType ?>">
      <?= $msgType === 'error' ? '⚠️' : '✅' ?> <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="reset-password.php?token=<?= urlencode($token) ?>">
      <?= csrfField() ?>

      <div class="form-group">
        <label class="form-label">Mật Khẩu Mới</label>
        <div class="input-wrap">
          <span class="input-icon">🔒</span>
          <input type="password" id="new_password" name="new_password"
                 class="form-input" placeholder="Tối thiểu 6 ký tự"
                 autocomplete="new-password" autofocus required
                 oninput="checkStrength(this.value)">
          <button type="button" class="toggle-pw" onclick="togglePw('new_password')" title="Hiện/ẩn">👁</button>
        </div>
        <div class="pw-strength">
          <div class="pw-bar-wrap"><div class="pw-bar" id="pwBar"></div></div>
          <div class="pw-label" id="pwLabel">Nhập mật khẩu để xem độ mạnh</div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Xác Nhận Mật Khẩu</label>
        <div class="input-wrap">
          <span class="input-icon">🔒</span>
          <input type="password" id="confirm_password" name="confirm_password"
                 class="form-input" placeholder="Nhập lại mật khẩu mới"
                 autocomplete="new-password" required>
          <button type="button" class="toggle-pw" onclick="togglePw('confirm_password')" title="Hiện/ẩn">👁</button>
        </div>
      </div>

      <button type="submit" class="btn-submit">✅ Cập Nhật Mật Khẩu</button>
    </form>

    <div class="divider">
      <div class="divider-line"></div>
      <div class="divider-text">Quay lại</div>
      <div class="divider-line"></div>
    </div>
    <a href="login.php" class="back-link">← Về trang đăng nhập</a>

  <?php endif; ?>

  </div>
</div>

<script>
function togglePw(id) {
  const el = document.getElementById(id);
  if (el) el.type = el.type === 'password' ? 'text' : 'password';
}

function checkStrength(pw) {
  const bar   = document.getElementById('pwBar');
  const label = document.getElementById('pwLabel');
  let score = 0;
  if (pw.length >= 6)  score++;
  if (pw.length >= 10) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const levels = [
    { w:'0%',   bg:'#e2e8f0', txt:'Nhập mật khẩu để xem độ mạnh' },
    { w:'20%',  bg:'#ef4444', txt:'⚠️ Rất yếu' },
    { w:'40%',  bg:'#f97316', txt:'😐 Yếu' },
    { w:'60%',  bg:'#eab308', txt:'🙂 Trung bình' },
    { w:'80%',  bg:'#22c55e', txt:'💪 Mạnh' },
    { w:'100%', bg:'#16a34a', txt:'🔥 Rất mạnh' },
  ];
  const l = pw.length === 0 ? levels[0] : levels[Math.min(score, 5)];
  bar.style.width      = l.w;
  bar.style.background = l.bg;
  label.textContent    = l.txt;
}
</script>
</body>
</html>
