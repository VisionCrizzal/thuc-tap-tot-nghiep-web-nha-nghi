<?php
// login.php — Đăng nhập thống nhất, tự nhận diện vai trò
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

$error = '';

// ── ?next= redirect sau khi đăng nhập (chỉ cho phép đường dẫn nội bộ) ──────
function sanitizeNext(string $next): string {
    $next = trim($next);
    if ($next === '') return '';
    if (preg_match('#^[a-z]+:|^//#i', $next)) return '';
    if (str_contains($next, '..')) return '';
    return $next;
}
$next = sanitizeNext($_GET['next'] ?? $_POST['next'] ?? '');

// ── BRUTE-FORCE PROTECTION ───────────────────────────────────────────────────
const BF_MAX_ATTEMPTS = 5;
const BF_LOCKOUT_SECS = 900;   // 15 phút
const BF_WINDOW_SECS  = 3600;  // 1 giờ: auto-reset nếu không thử nữa

// Khóa theo IP (hash md5 để không lưu IP gốc)
function bfKey(): string {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']
                          ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')[0]);
    return 'ip_' . md5($ip);
}

// Đọc record từ temp file
function bfGet(string $k): array {
    $f = sys_get_temp_dir() . '/easyhome_bf.json';
    if (!is_file($f)) return [];
    $d = @json_decode(file_get_contents($f), true) ?: [];
    return $d[$k] ?? [];
}

// Ghi record + dọn entries hết hạn
function bfSave(string $k, array $rec): void {
    $f   = sys_get_temp_dir() . '/easyhome_bf.json';
    $d   = is_file($f) ? (@json_decode(file_get_contents($f), true) ?: []) : [];
    $now = time();
    foreach ($d as $ck => $cr)                              // prune expired
        if (($cr['last'] ?? 0) + BF_WINDOW_SECS < $now) unset($d[$ck]);
    $d[$k] = $rec;
    @file_put_contents($f, json_encode($d), LOCK_EX);
}

// Reset sau login thành công
function bfReset(string $k): void {
    bfSave($k, ['count' => 0, 'until' => 0, 'last' => 0]);
}

// ── Khởi tạo trạng thái hiện tại ────────────────────────────────────────────
$bfKey    = bfKey();
$bfRec    = bfGet($bfKey);
$bfCount  = (int)($bfRec['count'] ?? 0);
$bfUntil  = (int)($bfRec['until'] ?? 0);
$bfLast   = (int)($bfRec['last']  ?? 0);

// Auto-reset nếu không thử trong BF_WINDOW_SECS
if ($bfLast > 0 && (time() - $bfLast) > BF_WINDOW_SECS) {
    $bfCount = 0; $bfUntil = 0;
}

$isLocked   = ($bfUntil > time());
$lockRemain = $isLocked ? max(0, $bfUntil - time()) : 0;

// ── Xử lý POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    // Chặn ngay nếu đang bị khóa
    if ($isLocked) {
        $m = (int)floor($lockRemain / 60);
        $s = $lockRemain % 60;
        $error = 'Đăng nhập tạm bị khóa do thử sai quá nhiều lần. '
               . 'Vui lòng thử lại sau '
               . ($m > 0 ? "{$m} phút {$s} giây" : "{$s} giây") . '.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$username || !$password) {
            $error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
        } else {
            $credMatch = false; // true nếu credentials khớp (dù account có thể bị khóa)

            // 1. Thử khách hàng (bảng KHACH_HANG)
            $stmt = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE TenTaiKhoan = :u");
            $stmt->execute([':u' => $username]);
            $kh = $stmt->fetch();

            if ($kh && password_verify($password, $kh['MatKhau'])) {
                $credMatch = true;
                $khStatus  = $kh['TrangThai'] ?? 'Hoạt động';
                if ($khStatus === 'Khóa') {
                    $error = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ khách sạn để được hỗ trợ.';
                } else {
                    bfReset($bfKey); // ✅ Xóa lịch sử thất bại
                    $_SESSION['kh_id']   = $kh['MaKH'];
                    $_SESSION['kh_name'] = $kh['HoTen'];
                    $_SESSION['kh_role'] = 'khachhang';
                    $dest = ($next !== '') ? $next : 'customer/dashboard.php';
                    header('Location: ' . $dest);
                    exit;
                }
            }

            // 2. Thử nhân viên / quản lý (bảng TAI_KHOAN)
            if (!$credMatch) {
                $user = loginStaff($pdo, $username, $password);
                if ($user) {
                    bfReset($bfKey); // ✅ Xóa lịch sử thất bại
                    $_SESSION['staff_id']   = $user['TenTK'];
                    $_SESSION['staff_name'] = $user['HoTen'] ?? $user['TenTK'];
                    $_SESSION['staff_role'] = $user['VaiTro'];
                    $_SESSION['staff_maNV'] = $user['MaNV'];
                    $dest = ($user['VaiTro'] === 'admin') ? 'admin/dashboard.php' : 'staff/dashboard.php';
                    header('Location: ' . $dest);
                    exit;
                }

                // ❌ Sai thông tin — ghi nhận lần thất bại
                $bfCount++;
                $newUntil = ($bfCount >= BF_MAX_ATTEMPTS) ? time() + BF_LOCKOUT_SECS : 0;
                bfSave($bfKey, ['count' => $bfCount, 'until' => $newUntil, 'last' => time()]);

                if ($newUntil > 0) {
                    $isLocked   = true;
                    $lockRemain = BF_LOCKOUT_SECS;
                    $error = 'Đăng nhập tạm bị khóa 15 phút do thử sai quá nhiều lần.';
                } elseif ($bfCount >= 3) {
                    $left  = BF_MAX_ATTEMPTS - $bfCount;
                    $error = "Sai thông tin đăng nhập. Bạn còn {$left} lần thử trước khi bị khóa 15 phút.";
                } else {
                    $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
                }
            }
        }
    }
}

// Detect booking context (từ booking.php)
$isBookingContext = ($next !== '' && str_starts_with($next, 'customer/dashboard.php?tab=booking'));
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

/* Attempt dots indicator */
.attempts-bar{
  display:flex;align-items:center;gap:8px;
  background:var(--blue-pale);border:1px solid var(--border);border-radius:8px;
  padding:8px 12px;margin-bottom:16px;font-size:0.78rem;color:var(--muted);
}
.attempts-dots{display:flex;gap:5px;margin-left:auto}
.attempts-dot{width:11px;height:11px;border-radius:50%;background:#e2e8f0;transition:background .3s}
.attempts-dot.used{background:#ef4444;box-shadow:0 0 0 2px rgba(239,68,68,.25)}
.attempts-dot.warn{background:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,.25)}

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
.form-input:disabled{opacity:.6;cursor:not-allowed}

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

/* Locked button */
.btn-locked{
  width:100%;padding:14px;font-family:var(--font);font-size:0.88rem;font-weight:700;
  color:rgba(255,255,255,.85);
  background:linear-gradient(135deg,#64748b,#94a3b8);
  border:none;border-radius:10px;cursor:not-allowed;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.1);margin-top:6px;
  display:flex;align-items:center;justify-content:center;gap:10px;
}
.countdown-badge{
  display:inline-block;background:rgba(255,255,255,.18);
  border-radius:6px;padding:2px 10px;font-variant-numeric:tabular-nums;
  font-size:0.92rem;letter-spacing:1px;min-width:60px;text-align:center;
}
.lockout-note{
  text-align:center;margin-top:12px;
  font-size:0.76rem;color:var(--muted);line-height:1.5;
}

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
      <div class="header-icon"><?= $isBookingContext ? '🏨' : ($isLocked ? '🔒' : '🔑') ?></div>
      <div>
        <div class="header-title">
          <?= $isLocked ? 'Tài Khoản Tạm Bị Khóa' : ($isBookingContext ? 'Đăng Nhập Để Đặt Phòng' : 'Đăng Nhập') ?>
        </div>
        <div class="header-desc">
          <?php if ($isLocked): ?>
            Vui lòng chờ để được đăng nhập lại
          <?php elseif ($isBookingContext): ?>
            Sau khi đăng nhập bạn sẽ được chuyển thẳng đến trang đặt phòng
          <?php else: ?>
            Nhập thông tin tài khoản của bạn
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- BODY -->
    <div class="card-body">

      <?php if ($isBookingContext && !$isLocked): ?>
      <div style="display:flex;align-items:center;gap:10px;background:var(--blue-pale);
        border:1.5px solid var(--blue-mid);border-radius:9px;padding:11px 14px;margin-bottom:20px;font-size:.84rem;color:var(--blue-dark)">
        🏨 <span>Bạn đang đặt phòng — đăng nhập tài khoản khách hàng để tiếp tục.</span>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php
      // Hiển thị dots indicator khi đã có ít nhất 1 lần thất bại và chưa bị khóa
      if (!$isLocked && $bfCount > 0):
        $warnThresh = BF_MAX_ATTEMPTS - 2; // tô vàng 2 chấm cuối trước khi đỏ
      ?>
      <div class="attempts-bar">
        <span>🛡 Số lần đăng nhập sai:</span>
        <div class="attempts-dots">
          <?php for ($i = 0; $i < BF_MAX_ATTEMPTS; $i++): ?>
          <div class="attempts-dot <?= $i < $bfCount ? ($i >= $warnThresh ? 'warn' : 'used') : '' ?>"></div>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($isLocked): ?>
      <!-- Locked state: chỉ hiện nút countdown, ẩn form -->
      <div style="text-align:center;padding:10px 0 6px">
        <div style="font-size:3rem;margin-bottom:12px">⏱</div>
        <div style="font-size:0.9rem;color:var(--muted);margin-bottom:20px;line-height:1.6">
          Hệ thống đã phát hiện nhiều lần đăng nhập sai liên tiếp.<br>
          Tài khoản từ địa chỉ IP này sẽ được mở lại sau:
        </div>
      </div>
      <button type="button" class="btn-locked" disabled>
        🔒 Tạm Khóa &nbsp;&mdash;&nbsp; còn <span id="countdown" class="countdown-badge"><?= sprintf('%02d:%02d', floor($lockRemain / 60), $lockRemain % 60) ?></span>
      </button>
      <p class="lockout-note">
        Nếu bạn bị khóa nhầm, vui lòng liên hệ:<br>
        <strong style="color:var(--blue)">Hotline: 0768.466.686</strong>
      </p>

      <?php else: ?>
      <!-- Normal login form -->
      <form method="POST">
        <?= csrfField() ?>
        <?php if ($next !== ''): ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <?php endif; ?>

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
      <?php endif; ?>

      <?php if (!$isLocked): ?>
      <div class="register-line">
        Chưa có tài khoản? <a href="register.php">Đăng ký ngay →</a>
      </div>
      <?php endif; ?>

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
  if (el) el.type = el.type === 'password' ? 'text' : 'password';
}

<?php if ($isLocked && $lockRemain > 0): ?>
// Đếm ngược countdown
(function () {
  let t = <?= (int)$lockRemain ?>;
  const el = document.getElementById('countdown');
  const iv = setInterval(function () {
    t--;
    if (t <= 0) { clearInterval(iv); location.reload(); return; }
    const m = String(Math.floor(t / 60)).padStart(2, '0');
    const s = String(t % 60).padStart(2, '0');
    el.textContent = m + ':' + s;
  }, 1000);
})();
<?php endif; ?>
</script>

</body>
</html>
