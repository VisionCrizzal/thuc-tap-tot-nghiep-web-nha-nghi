<?php
// 404.php — Custom "Không tìm thấy trang" — Easyhome
http_response_code(404);

// Detect role for smart back link
$backLink  = 'index.php';
$backLabel = '🏠 Về Trang Chủ';

// Load session với tên EASYHOME_SID (đúng session name, tránh xung đột phpMyAdmin)
require_once __DIR__ . '/config/session.php';
if (!empty($_SESSION['staff_role'])) {
    if ($_SESSION['staff_role'] === 'admin') {
        $backLink = 'admin/dashboard.php'; $backLabel = '📊 Dashboard Admin';
    } else {
        $backLink = 'staff/dashboard.php'; $backLabel = '🗂️ Dashboard Nhân viên';
    }
} elseif (!empty($_SESSION['kh_role'])) {
    $backLink = 'customer/dashboard.php'; $backLabel = '👤 Trang Của Tôi';
}

$currentUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>404 — Không tìm thấy trang | Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --text:#1e293b;--muted:#64748b;--border:#bfdbfe;
  --shadow:0 4px 24px rgba(29,78,216,.10);--shadow-lg:0 12px 48px rgba(29,78,216,.15);
  --font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
html,body{height:100%;font-family:var(--font);color:var(--text)}
body{
  background:var(--bg);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  min-height:100vh;padding:32px 16px;
}

/* ── CARD ── */
.card{
  background:#fff;border-radius:16px;border:1.5px solid var(--border);
  box-shadow:var(--shadow-lg);padding:48px 52px;max-width:520px;width:100%;
  text-align:center;position:relative;overflow:hidden;
}
.card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:4px;
  background:linear-gradient(90deg,var(--blue-dark),var(--blue-light),var(--blue-dark));
}

/* ── LOGO ── */
.brand{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:36px}
.brand-logo{width:44px;height:44px;border-radius:10px;object-fit:cover;
  border:2px solid var(--border);box-shadow:var(--shadow)}
.brand-name{font-family:var(--serif);font-size:1.4rem;color:var(--blue-dark)}
.brand-sub{font-size:.62rem;letter-spacing:2.5px;color:var(--muted);text-transform:uppercase;margin-top:1px}

/* ── 404 NUMBER ── */
.error-num{
  font-family:var(--serif);font-size:7rem;font-weight:700;line-height:1;
  background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue-light) 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  background-clip:text;
  margin-bottom:8px;
  animation:float 3s ease-in-out infinite;
}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}

/* ── ICON ── */
.error-icon{font-size:2.5rem;margin-bottom:14px;display:block;
  animation:bounce 2s ease-in-out infinite}
@keyframes bounce{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}

/* ── TEXT ── */
.error-title{font-family:var(--serif);font-size:1.35rem;color:var(--blue-dark);margin-bottom:10px}
.error-desc{font-size:.88rem;color:var(--muted);line-height:1.6;margin-bottom:8px}
.error-url{display:inline-block;background:var(--blue-pale);border:1px solid var(--border);
  border-radius:6px;padding:4px 12px;font-size:.76rem;color:var(--muted);
  font-family:monospace;max-width:100%;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap;margin-bottom:28px}

/* ── BUTTONS ── */
.btn-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.btn-primary{
  padding:11px 24px;background:var(--blue);color:#fff;
  border-radius:9px;border:none;font-family:var(--font);font-size:.9rem;
  font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s;
  display:inline-flex;align-items:center;gap:7px;
}
.btn-primary:hover{background:var(--blue-dark);transform:translateY(-1px);
  box-shadow:0 4px 16px rgba(29,78,216,.3)}
.btn-secondary{
  padding:11px 20px;background:var(--blue-pale);color:var(--blue);
  border:1.5px solid var(--border);border-radius:9px;font-family:var(--font);
  font-size:.9rem;font-weight:700;cursor:pointer;text-decoration:none;
  transition:all .2s;display:inline-flex;align-items:center;gap:7px;
}
.btn-secondary:hover{background:var(--blue-mid);border-color:var(--blue)}

/* ── QUICK LINKS ── */
.quick-links{margin-top:28px;padding-top:20px;border-top:1px solid var(--border)}
.ql-title{font-size:.7rem;text-transform:uppercase;letter-spacing:1.5px;
  color:var(--muted);font-weight:700;margin-bottom:12px}
.ql-grid{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.ql-link{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;
  border-radius:20px;border:1.5px solid var(--border);background:#fff;
  font-size:.76rem;font-weight:600;color:var(--text);text-decoration:none;
  transition:all .2s;white-space:nowrap}
.ql-link:hover{border-color:var(--blue);background:var(--blue-pale);color:var(--blue)}

/* ── FOOTER ── */
.page-footer{margin-top:28px;font-size:.72rem;color:var(--muted);text-align:center;line-height:1.7}
.page-footer a{color:var(--blue);text-decoration:none;font-weight:600}
.page-footer a:hover{text-decoration:underline}

/* ── RESPONSIVE ── */
@media(max-width:480px){
  .card{padding:32px 24px}
  .error-num{font-size:5rem}
  .error-title{font-size:1.1rem}
}
</style>
</head>
<body>

<div class="card">
  <!-- Brand -->
  <div class="brand">
    <img src="assets/images/logo.jpg" alt="Easyhome" class="brand-logo"
         onerror="this.style.display='none'">
    <div>
      <div class="brand-name">Easyhome</div>
      <div class="brand-sub">Hotel Management</div>
    </div>
  </div>

  <!-- 404 hero -->
  <div class="error-num">404</div>
  <span class="error-icon">🔍</span>

  <h1 class="error-title">Trang không tồn tại</h1>
  <p class="error-desc">
    Trang bạn đang tìm kiếm không tồn tại, đã bị di chuyển,
    hoặc bạn không có quyền truy cập.
  </p>

  <?php if ($currentUrl && $currentUrl !== '/404.php'): ?>
    <div class="error-url" title="<?= $currentUrl ?>"><?= $currentUrl ?></div>
  <?php endif; ?>

  <!-- Action buttons -->
  <div class="btn-row">
    <a href="<?= htmlspecialchars($backLink) ?>" class="btn-primary">
      <?= $backLabel ?>
    </a>
    <a href="javascript:history.back()" class="btn-secondary">
      ← Quay lại
    </a>
  </div>

  <!-- Quick links -->
  <div class="quick-links">
    <div class="ql-title">Bạn muốn đến đâu?</div>
    <div class="ql-grid">
      <a href="index.php"                class="ql-link">🏠 Trang chủ</a>
      <a href="rooms.php"                class="ql-link">🛏️ Tìm phòng</a>
      <a href="login.php"                class="ql-link">🔑 Đăng nhập</a>
      <a href="register.php"             class="ql-link">📝 Đăng ký</a>
      <a href="customer/dashboard.php"   class="ql-link">👤 Trang cá nhân</a>
    </div>
  </div>
</div>

<!-- Footer -->
<div class="page-footer">
  <div>
    <strong>Easyhome</strong> — Nhà nghỉ theo giờ, TP. Huế
    &nbsp;·&nbsp; Hotline: <a href="tel:0768466686">0768.466.686</a>
  </div>
  <div style="margin-top:4px">
    Nếu bạn nghĩ đây là lỗi, hãy liên hệ nhân viên lễ tân.
  </div>
</div>

<script>
// Countdown auto-redirect to home (15 giây)
let countdown = 15;
const homeUrl = '<?= htmlspecialchars($backLink) ?>';

// Thêm countdown hiển thị vào footer
const footer = document.querySelector('.page-footer');
const countEl = document.createElement('div');
countEl.style.cssText = 'margin-top:8px;font-size:.7rem;opacity:.6';
footer.appendChild(countEl);

const timer = setInterval(() => {
  countEl.textContent = `Tự động chuyển hướng sau ${countdown} giây…`;
  if (--countdown <= 0) {
    clearInterval(timer);
    window.location.href = homeUrl;
  }
}, 1000);

// Cancel redirect if user interacts
document.addEventListener('click', () => {
  clearInterval(timer);
  countEl.remove();
}, { once: true });
document.addEventListener('keydown', () => {
  clearInterval(timer);
  countEl.remove();
}, { once: true });
</script>
</body>
</html>
