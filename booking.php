<?php
// booking.php — Trang trung gian: "Đặt Ngay" từ trang chủ
// Nếu đã đăng nhập → redirect vào customer/dashboard.php?tab=booking&room_type=...
// Nếu chưa đăng nhập → hiển thị thông tin phòng + nút đăng nhập / đăng ký
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

$maPhong = trim($_GET['room'] ?? '');

// Lấy thông tin phòng
$room = null;
if ($maPhong) {
    $stmt = $pdo->prepare("SELECT * FROM PHONG WHERE MaPhong = :id");
    $stmt->execute([':id' => $maPhong]);
    $room = $stmt->fetch();
}

// ── Nếu KH đã đăng nhập → redirect thẳng đến tab đặt phòng ─────────────────
if (isset($_SESSION['kh_id'])) {
    $url = 'customer/dashboard.php?tab=booking';
    if ($room) {
        $url .= '&room_type=' . urlencode($room['LoaiPhong']);
    }
    header('Location: ' . $url);
    exit;
}

// ── Nếu NV/Admin đã đăng nhập → về dashboard của họ ────────────────────────
if (isset($_SESSION['staff_id'])) {
    $dest = ($_SESSION['staff_role'] === 'admin') ? 'admin/dashboard.php' : 'staff/dashboard.php';
    header('Location: ' . $dest);
    exit;
}

// ── Chưa đăng nhập → chuẩn bị URL redirect sau khi login ───────────────────
$nextUrl = 'customer/dashboard.php?tab=booking';
if ($room) {
    $nextUrl .= '&room_type=' . urlencode($room['LoaiPhong']);
}
$loginUrl    = 'login.php?next=' . urlencode($nextUrl);
$registerUrl = 'register.php';

// Giá hiển thị
$price = $room ? number_format($room['GiaPhong'], 0, ',', '.') : '';
$loai  = $room['LoaiPhong'] ?? '';
$tang  = $room['Tang']      ?? '';
$mota  = $room['MoTa']      ?? '';

function statusColor(string $s): string {
    return match($s) {
        'Trống'    => '#3b82f6',
        'Đang ở'   => '#ef4444',
        'Đang dọn' => '#f59e0b',
        'Bảo trì'  => '#6b7280',
        default    => '#94a3b8',
    };
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đặt Phòng<?= $room ? ' — Phòng ' . htmlspecialchars($maPhong) : '' ?> — Easyhome</title>
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
  --radius:     10px;
  --font:       Calibri,'Calibri Light',Arial,sans-serif;
  --serif:      'Playfair Display',Georgia,serif;
}
html,body{min-height:100vh;font-family:var(--font);background:
  radial-gradient(ellipse 80% 60% at 15% 10%, rgba(59,130,246,.18) 0%, transparent 55%),
  radial-gradient(ellipse 60% 50% at 85% 90%, rgba(29,78,216,.14) 0%, transparent 50%),
  linear-gradient(150deg,#e0f0ff 0%,#f0f6ff 40%,#e8f0fe 100%);}
body{display:flex;align-items:center;justify-content:center;padding:24px;}

/* Wrap */
.wrap{width:100%;max-width:860px;display:flex;gap:28px;flex-wrap:wrap;justify-content:center;
  animation:cardIn .55s cubic-bezier(.34,1.56,.64,1) both}
@keyframes cardIn{from{transform:translateY(24px) scale(.97);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}

/* Brand header */
.brand-bar{width:100%;text-align:center;margin-bottom:4px}
.brand-bar a{display:inline-flex;align-items:center;gap:12px;text-decoration:none}
.brand-logo{width:44px;height:44px;border-radius:10px;object-fit:cover;
  border:2.5px solid var(--blue-light);box-shadow:0 4px 16px rgba(29,78,216,.25)}
.brand-name{font-family:var(--serif);font-size:1.7rem;font-weight:600;color:var(--blue-dark)}
.brand-sub{font-size:.62rem;font-weight:700;letter-spacing:4px;text-transform:uppercase;
  color:var(--blue-light);display:block}

/* Room card */
.room-card{background:#fff;border-radius:18px;border:1.5px solid var(--border);
  box-shadow:var(--shadow-lg);overflow:hidden;flex:1 1 320px;max-width:400px}
.room-header{
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:22px 24px 18px;color:#fff;
}
.room-type-tag{font-size:.65rem;font-weight:700;letter-spacing:3px;text-transform:uppercase;
  background:rgba(255,255,255,.18);border-radius:20px;padding:3px 12px;display:inline-block;margin-bottom:10px}
.room-title{font-family:var(--serif);font-size:1.55rem;font-weight:600;line-height:1.2}
.room-code-line{font-size:.72rem;color:rgba(255,255,255,.65);margin-top:6px}
.status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle}

.room-body{padding:22px 24px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:18px}
.info-item{}
.info-label{font-size:.62rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:var(--muted);margin-bottom:4px}
.info-value{font-size:.93rem;font-weight:700;color:var(--text)}
.room-desc{font-size:.84rem;color:var(--muted);line-height:1.5;
  border-top:1px solid var(--border);padding-top:14px;margin-top:4px}

.price-area{display:flex;align-items:flex-end;gap:6px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.price-amt{font-family:var(--serif);font-size:2rem;font-weight:600;color:var(--blue)}
.price-vnd{font-size:.8rem;font-weight:700;color:var(--blue);margin-bottom:4px}
.price-unit{font-size:.72rem;color:var(--muted);margin-bottom:6px}

/* Action card */
.action-card{background:#fff;border-radius:18px;border:1.5px solid var(--border);
  box-shadow:var(--shadow-lg);flex:1 1 280px;max-width:380px;display:flex;flex-direction:column}
.action-header{
  background:linear-gradient(135deg,#1e3a8a,#1d4ed8);
  padding:22px 24px 18px;
}
.action-icon{width:52px;height:52px;background:rgba(255,255,255,.15);border-radius:14px;
  display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:12px}
.action-title{font-family:var(--serif);font-size:1.22rem;font-weight:600;color:#fff;line-height:1.3}
.action-desc{font-size:.75rem;color:rgba(255,255,255,.65);margin-top:6px}
.action-body{padding:24px;display:flex;flex-direction:column;gap:12px;flex:1}

.btn{display:block;width:100%;padding:14px 20px;border-radius:10px;
  font-family:var(--font);font-size:.92rem;font-weight:700;text-align:center;
  cursor:pointer;text-decoration:none;transition:all .2s;border:none}
.btn-primary{background:linear-gradient(135deg,var(--blue-dark),var(--blue));color:#fff;
  box-shadow:0 6px 20px rgba(29,78,216,.35)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(29,78,216,.45)}
.btn-primary:active{transform:translateY(0)}
.btn-outline{background:#fff;color:var(--blue);border:2px solid var(--blue-mid)}
.btn-outline:hover{background:var(--blue-pale);border-color:var(--blue-light)}

.divider{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:.78rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}

.notice{font-size:.75rem;color:var(--muted);text-align:center;line-height:1.5;padding:0 4px}
.notice a{color:var(--blue-light);text-decoration:none;font-weight:600}
.notice a:hover{text-decoration:underline}

.back-link{display:block;text-align:center;margin-top:18px;font-size:.78rem;
  color:var(--muted);text-decoration:none}
.back-link:hover{color:var(--blue)}

/* Không tìm thấy phòng */
.not-found{background:#fff;border-radius:18px;border:1.5px solid var(--border);
  box-shadow:var(--shadow-lg);padding:52px 32px;text-align:center;max-width:480px;width:100%}
.not-found-icon{font-size:3.5rem;margin-bottom:16px}
.not-found-title{font-family:var(--serif);font-size:1.6rem;color:var(--blue-dark);margin-bottom:10px}
.not-found-desc{color:var(--muted);font-size:.9rem;line-height:1.6;margin-bottom:24px}

@media(max-width:640px){
  .wrap{gap:20px}
  .room-card,.action-card{max-width:100%;flex:1 1 100%}
}
</style>
</head>
<body>
<div class="wrap">

  <!-- Brand -->
  <div class="brand-bar">
    <a href="index.php">
      <img src="assets/images/logo.jpg" alt="Easyhome" class="brand-logo">
      <div>
        <span class="brand-name">Easyhome</span>
        <span class="brand-sub">Hotel & Retreat</span>
      </div>
    </a>
  </div>

<?php if ($room): ?>

  <!-- Thông tin phòng -->
  <div class="room-card">
    <div class="room-header">
      <div class="room-type-tag">🛏 <?= htmlspecialchars($loai) ?></div>
      <div class="room-title"><?= htmlspecialchars($loai) ?> Room</div>
      <div class="room-code-line">
        <span class="status-dot" style="background:<?= statusColor($room['TinhTrang']) ?>"></span>
        Phòng <?= htmlspecialchars($maPhong) ?> &nbsp;·&nbsp; Tầng <?= (int)$tang ?>
        &nbsp;·&nbsp; <?= htmlspecialchars($room['TinhTrang']) ?>
      </div>
    </div>
    <div class="room-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Sức chứa</div>
          <div class="info-value">👥 <?= (int)$room['SoNguoiToiDa'] ?> người</div>
        </div>
        <div class="info-item">
          <div class="info-label">Tầng</div>
          <div class="info-value">🏢 Tầng <?= (int)$tang ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Loại phòng</div>
          <div class="info-value"><?= htmlspecialchars($loai) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Tiện nghi</div>
          <div class="info-value">📽 Máy chiếu</div>
        </div>
      </div>
      <?php if ($mota): ?>
      <div class="room-desc">📝 <?= htmlspecialchars($mota) ?></div>
      <?php endif; ?>
      <div class="price-area">
        <div class="price-amt"><?= $price ?></div>
        <div class="price-vnd">₫</div>
        <div class="price-unit">/ đêm</div>
      </div>
    </div>
  </div>

  <!-- Hành động -->
  <div class="action-card">
    <div class="action-header">
      <div class="action-icon">🔑</div>
      <div class="action-title">Đăng nhập để đặt phòng</div>
      <div class="action-desc">Sau khi đăng nhập, bạn sẽ được chuyển thẳng đến trang đặt phòng này.</div>
    </div>
    <div class="action-body">
      <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-primary">
        → Đăng Nhập Ngay
      </a>

      <div class="divider">hoặc</div>

      <a href="<?= htmlspecialchars($registerUrl) ?>" class="btn btn-outline">
        ✨ Tạo Tài Khoản Mới
      </a>

      <p class="notice">
        Đã có tài khoản? <a href="<?= htmlspecialchars($loginUrl) ?>">Đăng nhập</a> để đặt phòng
        <strong><?= htmlspecialchars($maPhong) ?></strong>.<br>
        Chưa có? Đăng ký miễn phí và nhận ngay ưu đãi thành viên!
      </p>
    </div>
  </div>

<?php else: ?>

  <!-- Phòng không tồn tại -->
  <div class="not-found">
    <div class="not-found-icon">🔍</div>
    <div class="not-found-title">Không tìm thấy phòng</div>
    <div class="not-found-desc">
      Phòng <strong><?= htmlspecialchars($maPhong ?: '(không rõ)') ?></strong> không tồn tại
      hoặc hiện đang bảo trì.<br>Vui lòng chọn phòng khác.
    </div>
    <a href="index.php#rooms" class="btn btn-primary" style="max-width:220px;margin:0 auto">
      ← Xem Phòng Trống
    </a>
  </div>

<?php endif; ?>

  <a href="index.php" class="back-link">← Quay về trang chủ</a>

</div>
</body>
</html>
