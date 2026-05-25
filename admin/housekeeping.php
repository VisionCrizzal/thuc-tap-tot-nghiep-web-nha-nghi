<?php
// admin/housekeeping.php — Quản lý dọn phòng & bảo trì
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'];
$msg = ''; $msgType = 'success';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']    ?? '';
    $maPhong = trim($_POST['ma_phong'] ?? '');

    if ($action === 'change_status' && $maPhong) {
        $newStatus = trim($_POST['new_status'] ?? '');
        $allowed   = ['Trống', 'Đang dọn', 'Bảo trì'];
        if (in_array($newStatus, $allowed)) {
            $pdo->prepare("UPDATE PHONG SET TinhTrang = ? WHERE MaPhong = ?")
                ->execute([$newStatus, $maPhong]);
            $labels = ['Trống' => '✅ Trống (sạch sẽ)', 'Đang dọn' => '🧹 Đang dọn', 'Bảo trì' => '🔧 Bảo trì'];
            $msg = "Phòng <strong>$maPhong</strong> → " . ($labels[$newStatus] ?? $newStatus);
        } else { $msg = "Trạng thái không hợp lệ."; $msgType = 'error'; }
    }
    if ($msgType === 'success' && $msg) {
        header("Location: housekeeping.php?msg=" . urlencode($msg)); exit;
    }
}
if (!$msg && isset($_GET['msg'])) {
    $msg = urldecode($_GET['msg']); $msgType = 'success';
}

// ── Queries ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'Đang dọn', 'Bảo trì', 'Trống', 'Đang ở'];
if (!in_array($filterStatus, $validFilters)) $filterStatus = 'all';

$rooms = $pdo->query("
    SELECT p.*,
           MAX(dp.NgayCheckOut) AS LanCheckOutCuoi,
           nv.HoTen             AS TenNVCuoiXuLy,
           nv.ChucVu            AS ChucVuNV
    FROM PHONG p
    LEFT JOIN DAT_PHONG dp ON p.MaPhong = dp.MaPhong
         AND dp.TrangThai IN ('Đã trả phòng','Đã hủy')
    LEFT JOIN NHAN_VIEN nv ON dp.MaNV_XuLy = nv.MaNV
         AND dp.NgayCheckOut = (
             SELECT MAX(dp2.NgayCheckOut) FROM DAT_PHONG dp2
             WHERE dp2.MaPhong = p.MaPhong AND dp2.TrangThai IN ('Đã trả phòng','Đã hủy')
         )
    GROUP BY p.MaPhong
    ORDER BY FIELD(p.TinhTrang,'Đang dọn','Bảo trì','Đang ở','Trống'), p.Tang, p.MaPhong
")->fetchAll();

$stats = ['trong' => 0, 'dangO' => 0, 'dangDon' => 0, 'baoTri' => 0];
foreach ($rooms as $r) {
    match ($r['TinhTrang']) {
        'Trống'    => $stats['trong']++,
        'Đang ở'   => $stats['dangO']++,
        'Đang dọn' => $stats['dangDon']++,
        'Bảo trì'  => $stats['baoTri']++,
        default    => null,
    };
}

$staffList = $pdo->query("
    SELECT nv.MaNV, nv.HoTen, nv.ChucVu
    FROM NHAN_VIEN nv
    JOIN TAI_KHOAN tk ON nv.MaNV = tk.MaNV
    WHERE nv.TrangThai = 'Đang làm việc' AND tk.TrangThai = 'Hoạt động'
    ORDER BY nv.ChucVu, nv.HoTen
")->fetchAll();

$adminProfile = $pdo->prepare("SELECT nv.MaNV FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV WHERE tk.TenTK=:tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function statusColor(string $s): string {
    return match($s) {
        'Trống'    => '#3b82f6',
        'Đang ở'   => '#ef4444',
        'Đang dọn' => '#f59e0b',
        'Bảo trì'  => '#6b7280',
        default    => '#94a3b8',
    };
}
function statusBg(string $s): string {
    return match($s) {
        'Trống'    => '#eff6ff',
        'Đang ở'   => '#fff0f0',
        'Đang dọn' => '#fffbeb',
        'Bảo trì'  => '#f8fafc',
        default    => '#f1f5f9',
    };
}
function statusIcon(string $s): string {
    return match($s) {
        'Trống'    => '✅',
        'Đang ở'   => '🏠',
        'Đang dọn' => '🧹',
        'Bảo trì'  => '🔧',
        default    => '❓',
    };
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dọn Phòng — Easyhome Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --text:#1e293b;--muted:#64748b;--border:#bfdbfe;
  --shadow:0 4px 24px rgba(29,78,216,.10);--shadow-lg:0 12px 48px rgba(29,78,216,.15);
  --radius:10px;--font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;--sidebar-w:260px;
}
html,body{height:100%;font-family:var(--font);font-size:15px;color:var(--text)}
body{display:flex;background:var(--bg);overflow-x:hidden}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);min-height:100vh;flex-shrink:0;
  background:linear-gradient(175deg,var(--blue-dark) 0%,#1a3070 60%,#16275e 100%);
  display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(29,78,216,.25);
  position:sticky;top:0;height:100vh;overflow-y:auto}
.sb-brand{padding:24px 20px 16px;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:12px}
.sb-logo{width:40px;height:40px;border-radius:10px;object-fit:cover;border:2px solid rgba(255,255,255,.3);flex-shrink:0}
.sb-site-name{font-family:var(--serif);font-size:1.2rem;color:#fff;line-height:1.1}
.sb-site-sub{font-size:.62rem;letter-spacing:2px;color:rgba(255,255,255,.5);text-transform:uppercase;margin-top:2px}
.sb-admin{padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.1)}
.sb-admin-label{font-size:.6rem;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:10px}
.sb-admin-card{background:rgba(255,255,255,.08);border-radius:10px;padding:12px 14px;border:1px solid rgba(255,255,255,.12)}
.sb-admin-name{font-size:.9rem;font-weight:700;color:#fff;margin-bottom:4px}
.sb-admin-row{font-size:.72rem;color:rgba(255,255,255,.55);display:flex;align-items:center;gap:5px;margin-top:3px}
.sb-admin-badge{display:inline-block;padding:2px 8px;background:rgba(251,191,36,.2);color:#fbbf24;border-radius:20px;font-size:.62rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-top:6px}
.sb-nav{flex:1;padding:12px 0}
.sb-nav-item{display:flex;align-items:center;gap:10px;padding:12px 20px;cursor:pointer;text-decoration:none;
  font-size:.82rem;color:rgba(255,255,255,.65);font-weight:600;letter-spacing:.3px;
  transition:all .2s;border-left:3px solid transparent}
.sb-nav-item:hover{background:rgba(255,255,255,.07);color:#fff;border-left-color:rgba(255,255,255,.3)}
.sb-nav-item.active{background:rgba(255,255,255,.12);color:#fff;border-left-color:#60a5fa}
.sb-nav-icon{font-size:1rem;width:22px;text-align:center;flex-shrink:0}
.sb-nav-divider{height:1px;background:rgba(255,255,255,.07);margin:8px 20px}
.sb-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.1)}
.btn-logout{width:100%;padding:10px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);
  color:#fca5a5;border-radius:8px;cursor:pointer;font-family:var(--font);
  font-size:.78rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  transition:all .2s;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-logout:hover{background:rgba(239,68,68,.28);color:#fff}

/* ── MAIN ── */
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{background:#fff;border-bottom:1.5px solid var(--border);padding:14px 28px;
  display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 2px 8px rgba(29,78,216,.06);position:sticky;top:0;z-index:10}
.topbar-title{font-family:var(--serif);font-size:1.2rem;color:var(--blue-dark);display:flex;align-items:center;gap:8px}
.topbar-meta{font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:14px}
.content{padding:24px 28px;flex:1}

/* ── ALERT ── */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:.85rem;
  display:flex;align-items:center;gap:10px;border-left:4px solid}
.alert-success{background:#ecfdf5;color:#065f46;border-color:#10b981}
.alert-error{background:#fff7ed;color:#92400e;border-color:#f59e0b;animation:shake .35s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}

/* ── STATS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#fff;border-radius:var(--radius);padding:18px 20px;
  border:1.5px solid var(--border);box-shadow:var(--shadow);display:flex;
  align-items:center;gap:14px;transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;
  justify-content:center;font-size:1.4rem;flex-shrink:0}
.stat-value{font-size:1.8rem;font-weight:700;color:var(--blue-dark);line-height:1}
.stat-label{font-size:.68rem;color:var(--muted);letter-spacing:.4px;text-transform:uppercase;margin-top:3px}

/* ── FILTER ── */
.filter-bar{display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.flt-btn{padding:7px 16px;border-radius:20px;border:1.5px solid var(--border);
  background:#fff;font-family:var(--font);font-size:.78rem;font-weight:700;
  color:var(--muted);cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap}
.flt-btn.active,.flt-btn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.flt-count{display:inline-block;background:rgba(255,255,255,.3);border-radius:20px;
  padding:1px 6px;font-size:.68rem;margin-left:4px}

/* ── ROOM GRID ── */
.rooms-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.room-card{background:#fff;border-radius:12px;border:1.5px solid var(--border);
  box-shadow:var(--shadow);overflow:hidden;transition:transform .2s,box-shadow .2s}
.room-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.room-card-top{padding:16px 18px 12px;display:flex;align-items:flex-start;gap:12px}
.room-num-badge{width:48px;height:48px;border-radius:10px;display:flex;flex-direction:column;
  align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:.72rem;line-height:1.1}
.room-num{font-size:1.1rem;font-weight:700;line-height:1}
.room-floor{font-size:.6rem;opacity:.7;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.room-info{flex:1;min-width:0}
.room-title{font-weight:700;color:var(--blue-dark);font-size:.92rem;margin-bottom:4px}
.room-meta{font-size:.72rem;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap}
.room-status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;
  border-radius:20px;font-size:.72rem;font-weight:700;margin-top:6px;border:1.5px solid transparent}
.room-card-body{padding:0 18px 12px;border-top:1px solid var(--blue-pale)}
.room-last-info{font-size:.72rem;color:var(--muted);padding:10px 0 6px;display:flex;
  flex-direction:column;gap:4px}
.room-card-actions{padding:10px 18px 14px;display:flex;gap:8px;flex-wrap:wrap}
.act-btn{padding:6px 13px;border-radius:7px;font-size:.76rem;font-weight:700;
  border:none;cursor:pointer;font-family:var(--font);transition:all .2s;
  display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.act-clean{background:#ecfdf5;color:#059669;border:1.5px solid #10b98130}
.act-clean:hover{background:#059669;color:#fff}
.act-maint{background:#fef3c7;color:#92400e;border:1.5px solid #f59e0b30}
.act-maint:hover{background:#d97706;color:#fff}
.act-done{background:#eff6ff;color:#1d4ed8;border:1.5px solid #bfdbfe}
.act-done:hover{background:#1d4ed8;color:#fff}
.act-start{background:#fefce8;color:#854d0e;border:1.5px solid #fcd34d50}
.act-start:hover{background:#f59e0b;color:#fff}

/* ── STAFF PANEL ── */
.layout-2col{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
.staff-panel{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);overflow:hidden;position:sticky;top:80px}
.staff-panel-head{padding:14px 18px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:8px}
.staff-panel-title{font-family:var(--serif);font-size:.95rem;color:var(--blue-dark)}
.staff-item{padding:10px 18px;display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--blue-pale)}
.staff-item:last-child{border-bottom:none}
.staff-avatar{width:36px;height:36px;border-radius:50%;background:var(--blue-mid);
  display:flex;align-items:center;justify-content:center;font-size:.85rem;
  font-weight:700;color:var(--blue-dark);flex-shrink:0}
.staff-name{font-size:.82rem;font-weight:700;color:var(--text)}
.staff-role{font-size:.68rem;color:var(--muted);margin-top:2px}
.online-dot{width:8px;height:8px;background:#22c55e;border-radius:50%;
  margin-left:auto;flex-shrink:0;box-shadow:0 0 0 2px #dcfce7}

/* ── EMPTY ── */
.empty-state{text-align:center;padding:64px 20px;color:var(--muted);grid-column:1/-1}
.empty-icon{font-size:3.5rem;margin-bottom:14px;opacity:.4;display:block}

/* ── RESPONSIVE ── */
@media(max-width:1100px){.layout-2col{grid-template-columns:1fr}}
@media(max-width:900px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
}
@media(max-width:600px){.content{padding:16px}.rooms-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- ════ SIDEBAR ════ -->
<aside class="sidebar">
  <div class="sb-brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome" class="sb-logo">
    <div>
      <div class="sb-site-name">Easyhome</div>
      <div class="sb-site-sub">Admin Panel</div>
    </div>
  </div>

  <div class="sb-admin">
    <div class="sb-admin-label">Đang đăng nhập</div>
    <div class="sb-admin-card">
      <div class="sb-admin-name"><?= esc($adminName) ?></div>
      <?php if ($adminProfile): ?>
        <div class="sb-admin-row">👤 <?= esc($adminProfile['MaNV'] ?? $adminId) ?></div>
      <?php endif; ?>
      <div class="sb-admin-badge">👑 Quản Lý</div>
    </div>
  </div>

  <nav class="sb-nav">
    <a href="dashboard.php?tab=overview"    class="sb-nav-item"><span class="sb-nav-icon">📊</span><span>Tổng Quan</span></a>
    <a href="dashboard.php?tab=bookings"    class="sb-nav-item"><span class="sb-nav-icon">📋</span><span>Quản Lý Đặt Phòng</span></a>
    <a href="calendar.php"                  class="sb-nav-item"><span class="sb-nav-icon">📅</span><span>Lịch Đặt Phòng</span></a>
    <a href="dashboard.php?tab=new-booking" class="sb-nav-item"><span class="sb-nav-icon">➕</span><span>Đặt Phòng Mới</span></a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php"                     class="sb-nav-item"><span class="sb-nav-icon">🛏️</span><span>Quản Lý Phòng</span></a>
    <a href="housekeeping.php"              class="sb-nav-item active"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                  class="sb-nav-item"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                class="sb-nav-item"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"       class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                  class="sb-nav-item"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <div class="topbar">
    <div class="topbar-title">🧹 Quản Lý Dọn Phòng</div>
    <div class="topbar-meta">
      <span>🧹 Cần dọn: <strong style="color:#f59e0b"><?= $stats['dangDon'] ?></strong></span>
      <span>🔧 Bảo trì: <strong style="color:#6b7280"><?= $stats['baoTri'] ?></strong></span>
      <span>✅ Sẵn sàng: <strong style="color:#059669"><?= $stats['trong'] ?></strong></span>
      <span style="color:var(--muted)"><?= date('d/m/Y H:i') ?></span>
    </div>
  </div>

  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>">
      <?= $msgType === 'success' ? '✅' : '⚠️' ?>
      <span><?= $msg ?></span>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb">🧹</div>
        <div>
          <div class="stat-value" style="color:#d97706"><?= $stats['dangDon'] ?></div>
          <div class="stat-label">Đang dọn</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f9fafb">🔧</div>
        <div>
          <div class="stat-value" style="color:#6b7280"><?= $stats['baoTri'] ?></div>
          <div class="stat-label">Bảo trì</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">✅</div>
        <div>
          <div class="stat-value" style="color:#059669"><?= $stats['trong'] ?></div>
          <div class="stat-label">Sẵn sàng (trống)</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff0f0">🏠</div>
        <div>
          <div class="stat-value" style="color:#dc2626"><?= $stats['dangO'] ?></div>
          <div class="stat-label">Đang có khách</div>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
      <span style="font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px">Lọc:</span>
      <?php
        $fltDef = [
          'all'      => ['Tất Cả', count($rooms)],
          'Đang dọn' => ['🧹 Đang dọn', $stats['dangDon']],
          'Bảo trì'  => ['🔧 Bảo trì',  $stats['baoTri']],
          'Đang ở'   => ['🏠 Đang ở',   $stats['dangO']],
          'Trống'    => ['✅ Trống',     $stats['trong']],
        ];
        foreach ($fltDef as $key => [$label, $cnt]):
          $active = ($filterStatus === $key) ? 'active' : '';
          $url    = '?filter=' . urlencode($key);
      ?>
        <a href="<?= $url ?>" class="flt-btn <?= $active ?>">
          <?= $label ?><span class="flt-count"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Layout 2 cột: room grid + staff panel -->
    <div class="layout-2col">

      <!-- Room grid -->
      <div class="rooms-grid">
        <?php
          $shown = 0;
          foreach ($rooms as $r):
            if ($filterStatus !== 'all' && $r['TinhTrang'] !== $filterStatus) continue;
            $shown++;
            $status = $r['TinhTrang'];
            $color  = statusColor($status);
            $bg     = statusBg($status);
            $icon   = statusIcon($status);
            $tang   = 'Tầng ' . $r['Tang'];
        ?>
        <div class="room-card" style="border-color:<?= $color ?>30">
          <div class="room-card-top">
            <div class="room-num-badge" style="background:<?= $bg ?>;color:<?= $color ?>">
              <div class="room-num"><?= esc($r['MaPhong']) ?></div>
              <div class="room-floor"><?= $tang ?></div>
            </div>
            <div class="room-info">
              <div class="room-title"><?= esc($r['LoaiPhong']) ?></div>
              <div class="room-meta">
                <span>👤 Tối đa <?= $r['SoNguoiToiDa'] ?> người</span>
              </div>
              <div class="room-status-badge"
                   style="background:<?= $bg ?>;color:<?= $color ?>;border-color:<?= $color ?>40">
                <?= $icon ?> <?= esc($status) ?>
              </div>
            </div>
          </div>

          <div class="room-card-body">
            <div class="room-last-info">
              <?php if ($r['LanCheckOutCuoi']): ?>
                <span>🕐 Check-out cuối: <strong><?= date('d/m/Y H:i', strtotime($r['LanCheckOutCuoi'])) ?></strong></span>
              <?php else: ?>
                <span style="opacity:.5">Chưa có lịch sử</span>
              <?php endif; ?>
              <?php if ($r['TenNVCuoiXuLy']): ?>
                <span>👤 NV xử lý: <strong><?= esc($r['TenNVCuoiXuLy']) ?></strong>
                  <span style="opacity:.6">(<?= esc($r['ChucVuNV'] ?? '') ?>)</span>
                </span>
              <?php endif; ?>
              <?php if ($r['MoTa']): ?>
                <span style="opacity:.7;font-style:italic">📝 <?= esc(mb_substr($r['MoTa'], 0, 60)) ?><?= mb_strlen($r['MoTa']) > 60 ? '…' : '' ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Action buttons theo trạng thái -->
          <div class="room-card-actions">
            <?php if ($status === 'Đang dọn'): ?>
              <form method="POST" style="margin:0">
                <input type="hidden" name="action"     value="change_status">
                <input type="hidden" name="ma_phong"   value="<?= esc($r['MaPhong']) ?>">
                <input type="hidden" name="new_status" value="Trống">
                <button type="submit" class="act-btn act-clean">✅ Dọn xong — Trống</button>
              </form>
              <form method="POST" style="margin:0"
                    onsubmit="return confirm('Chuyển phòng <?= esc($r['MaPhong']) ?> sang Bảo trì?')">
                <input type="hidden" name="action"     value="change_status">
                <input type="hidden" name="ma_phong"   value="<?= esc($r['MaPhong']) ?>">
                <input type="hidden" name="new_status" value="Bảo trì">
                <button type="submit" class="act-btn act-maint">🔧 Cần Bảo trì</button>
              </form>

            <?php elseif ($status === 'Bảo trì'): ?>
              <form method="POST" style="margin:0"
                    onsubmit="return confirm('Hoàn thành bảo trì — chuyển phòng <?= esc($r['MaPhong']) ?> về Trống?')">
                <input type="hidden" name="action"     value="change_status">
                <input type="hidden" name="ma_phong"   value="<?= esc($r['MaPhong']) ?>">
                <input type="hidden" name="new_status" value="Trống">
                <button type="submit" class="act-btn act-done">✅ Hoàn thành — Trống</button>
              </form>
              <form method="POST" style="margin:0">
                <input type="hidden" name="action"     value="change_status">
                <input type="hidden" name="ma_phong"   value="<?= esc($r['MaPhong']) ?>">
                <input type="hidden" name="new_status" value="Đang dọn">
                <button type="submit" class="act-btn act-start">🧹 Đưa vào Dọn</button>
              </form>

            <?php elseif ($status === 'Trống'): ?>
              <form method="POST" style="margin:0"
                    onsubmit="return confirm('Chuyển phòng <?= esc($r['MaPhong']) ?> sang Bảo trì?')">
                <input type="hidden" name="action"     value="change_status">
                <input type="hidden" name="ma_phong"   value="<?= esc($r['MaPhong']) ?>">
                <input type="hidden" name="new_status" value="Bảo trì">
                <button type="submit" class="act-btn act-maint">🔧 Báo Bảo trì</button>
              </form>
              <form method="POST" style="margin:0">
                <input type="hidden" name="action"     value="change_status">
                <input type="hidden" name="ma_phong"   value="<?= esc($r['MaPhong']) ?>">
                <input type="hidden" name="new_status" value="Đang dọn">
                <button type="submit" class="act-btn act-start">🧹 Bắt đầu dọn</button>
              </form>

            <?php elseif ($status === 'Đang ở'): ?>
              <span style="font-size:.75rem;color:var(--muted);font-style:italic;padding:4px 2px">
                🏠 Phòng đang có khách — không thể thay đổi
              </span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if ($shown === 0): ?>
        <div class="empty-state">
          <span class="empty-icon">🏨</span>
          <p>Không có phòng nào trong trạng thái này.</p>
        </div>
        <?php endif; ?>
      </div><!-- /rooms-grid -->

      <!-- Staff panel -->
      <div class="staff-panel">
        <div class="staff-panel-head">
          <span>👥</span>
          <div class="staff-panel-title">Nhân viên trực ca</div>
          <span style="margin-left:auto;font-size:.72rem;color:var(--muted)"><?= count($staffList) ?> NV</span>
        </div>
        <?php if ($staffList): ?>
          <?php foreach ($staffList as $nv): ?>
          <div class="staff-item">
            <div class="staff-avatar"><?= mb_substr($nv['HoTen'], 0, 1) ?></div>
            <div>
              <div class="staff-name"><?= esc($nv['HoTen']) ?></div>
              <div class="staff-role"><?= esc($nv['ChucVu']) ?></div>
            </div>
            <div class="online-dot" title="Đang hoạt động"></div>
          </div>
          <?php endforeach; ?>
          <div style="padding:10px 18px 14px;font-size:.7rem;color:var(--muted);border-top:1px solid var(--blue-pale)">
            💡 Phân công trực tiếp hoặc qua điện thoại nội bộ
          </div>
        <?php else: ?>
          <div style="padding:24px 18px;text-align:center;color:var(--muted);font-size:.82rem">
            Không có nhân viên nào đang hoạt động
          </div>
        <?php endif; ?>

        <!-- Quick status legend -->
        <div style="padding:14px 18px;background:var(--blue-pale);border-top:1px solid var(--border)">
          <div style="font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Chú thích trạng thái</div>
          <?php
            $legend = [
              ['🧹', 'Đang dọn', '#f59e0b', 'Chờ dọn xong'],
              ['🔧', 'Bảo trì',  '#6b7280', 'Đang sửa chữa'],
              ['✅', 'Trống',    '#059669', 'Sẵn sàng đón khách'],
              ['🏠', 'Đang ở',   '#dc2626', 'Đang có khách'],
            ];
            foreach ($legend as [$ic, $lb, $cl, $desc]):
          ?>
          <div style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:.73rem">
            <span><?= $ic ?></span>
            <span style="color:<?= $cl ?>;font-weight:700;min-width:70px"><?= $lb ?></span>
            <span style="color:var(--muted)"><?= $desc ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /layout-2col -->
  </div><!-- /content -->
</div><!-- /main -->

<script>
// Auto-hide alert after 4s
setTimeout(() => {
  const a = document.querySelector('.alert');
  if (a) { a.style.transition='opacity .5s'; a.style.opacity='0'; setTimeout(()=>a.remove(),500); }
}, 4000);
</script>
</body>
</html>
