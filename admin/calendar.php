<?php
// admin/calendar.php — Lịch đặt phòng dạng calendar
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'];

// ── Tính tháng hiển thị ───────────────────────────────────────────────────────
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$year  = max(2020, min(2035, $year));
$month = max(1, min(12, $month));

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1)  { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1;  $nextYear++; }

$firstDayTs  = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDayTs);
$startWd     = (int)date('N', $firstDayTs); // 1=Mon … 7=Sun

$monthStart = date('Y-m-01', $firstDayTs);
$monthEnd   = date('Y-m-d', mktime(0, 0, 0, $month + 1, 0, $year)); // last day

// ── Tổng số phòng ─────────────────────────────────────────────────────────────
$totalRooms = (int)$pdo->query("SELECT COUNT(*) FROM PHONG")->fetchColumn();

// ── Query tất cả đặt phòng trong tháng ───────────────────────────────────────
$bkStmt = $pdo->prepare("
    SELECT dp.MaDP, dp.MaPhong, dp.MaKH,
           DATE(dp.NgayCheckIn)  AS NgayCI,
           DATE(dp.NgayCheckOut) AS NgayCO,
           dp.TrangThai,
           kh.HoTen  AS TenKH,
           p.LoaiPhong
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    JOIN PHONG p       ON dp.MaPhong = p.MaPhong
    WHERE dp.TrangThai NOT IN ('Đã hủy')
      AND dp.NgayCheckIn  < :nextMonthStart
      AND dp.NgayCheckOut > :monthStart
    ORDER BY dp.NgayCheckIn
");
$bkStmt->execute([
    ':monthStart'     => $monthStart,
    ':nextMonthStart' => date('Y-m-01', mktime(0, 0, 0, $month + 1, 1, $year)),
]);
$allBookings = $bkStmt->fetchAll();

// ── Xây dựng map: ngày → danh sách booking ───────────────────────────────────
$dayMap = []; // day_num => [bookings active on this day]
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = date('Y-m-d', mktime(0, 0, 0, $month, $d, $year));
    $dayMap[$d] = [];
    foreach ($allBookings as $bk) {
        if ($bk['NgayCI'] <= $dateStr && $bk['NgayCO'] > $dateStr) {
            $dayMap[$d][] = $bk;
        }
    }
}

// ── Ngày được chọn ────────────────────────────────────────────────────────────
$selDay = isset($_GET['day']) ? (int)$_GET['day'] : (int)date('j');
$selDay = max(1, min($daysInMonth, $selDay));

// ── Stats tháng ───────────────────────────────────────────────────────────────
$totalBkMonth  = count($allBookings);
$occupiedDays  = array_sum(array_map('count', $dayMap));
$avgOccupancy  = $daysInMonth > 0 ? round($occupiedDays / $daysInMonth, 1) : 0;
$busyDay       = 0; $busyCount = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    if (count($dayMap[$d]) > $busyCount) { $busyCount = count($dayMap[$d]); $busyDay = $d; }
}

$adminProfile = $pdo->prepare("SELECT nv.MaNV FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV WHERE tk.TenTK=:tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

function esc($s)  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n)  { return number_format((float)$n, 0, ',', '.'); }

$thangVi = ['', 'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
               'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'];

// Màu ô calendar theo % lấp đầy
function cellGrade(int $cnt, int $total): string {
    if ($cnt === 0)              return 'day-empty';
    $pct = $total > 0 ? $cnt / $total : 0;
    if ($pct <= 0.25)            return 'day-low';
    if ($pct <= 0.60)            return 'day-mid';
    if ($pct < 1.00)             return 'day-high';
    return 'day-full';
}
// Room type colors
function loaiPhongColor(string $loai): string {
    return match($loai) {
        'Đơn'     => '#3b82f6',
        'Đôi'     => '#8b5cf6',
        'Gia đình'=> '#10b981',
        'VIP'     => '#f59e0b',
        default   => '#94a3b8',
    };
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lịch Đặt Phòng — Easyhome Admin</title>
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

/* ── STATS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.stat-card{background:#fff;border-radius:var(--radius);padding:16px 18px;
  border:1.5px solid var(--border);box-shadow:var(--shadow);display:flex;align-items:center;gap:12px;transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-icon{width:42px;height:42px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.stat-value{font-size:1.6rem;font-weight:700;color:var(--blue-dark);line-height:1}
.stat-label{font-size:.65rem;color:var(--muted);letter-spacing:.4px;text-transform:uppercase;margin-top:3px}

/* ── CALENDAR HEADER ── */
.cal-header{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);padding:16px 24px;margin-bottom:16px;
  display:flex;align-items:center;justify-content:space-between}
.cal-nav{display:flex;align-items:center;gap:8px}
.cal-nav-btn{padding:7px 16px;border-radius:8px;border:1.5px solid var(--border);
  background:var(--blue-pale);color:var(--blue-dark);font-family:var(--font);
  font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s}
.cal-nav-btn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.cal-title{font-family:var(--serif);font-size:1.3rem;color:var(--blue-dark);
  padding:0 16px;font-weight:700}
.cal-today-btn{padding:7px 14px;border-radius:8px;border:1.5px solid var(--blue);
  background:var(--blue);color:#fff;font-family:var(--font);font-size:.78rem;
  font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s}
.cal-today-btn:hover{background:var(--blue-dark)}

/* ── CALENDAR LEGEND ── */
.cal-legend{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.leg-item{display:flex;align-items:center;gap:5px;font-size:.72rem;color:var(--muted)}
.leg-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}

/* ── CALENDAR GRID ── */
.cal-wrap{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr)}
.cal-dow{padding:10px 6px;text-align:center;font-size:.7rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.5px;color:var(--muted);
  background:var(--blue-dark);color:rgba(255,255,255,.7)}
.cal-dow:first-child,.cal-dow:nth-child(6),.cal-dow:nth-child(7){color:rgba(255,255,255,.5)}

/* Day cells */
.cal-day{min-height:88px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);
  padding:6px 8px;cursor:pointer;transition:all .2s;position:relative;display:flex;flex-direction:column}
.cal-day:nth-child(7n){border-right:none}
.cal-day:hover:not(.cal-empty){background:var(--blue-pale);transform:scale(1.02);z-index:2;box-shadow:var(--shadow)}
.cal-day.selected{background:var(--blue-mid)!important;border-color:var(--blue)!important;z-index:3}
.cal-day.today .day-num{background:var(--blue);color:#fff;border-radius:50%;width:24px;height:24px;
  display:flex;align-items:center;justify-content:center;font-size:.8rem}
.cal-day.today{border-top:2px solid var(--blue)}
.cal-empty{background:#fafbfc;cursor:default}
.day-num{font-size:.85rem;font-weight:700;color:var(--text);margin-bottom:4px;width:24px;height:24px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0}
.day-dots{display:flex;flex-wrap:wrap;gap:2px;flex:1;align-content:flex-start}
.day-dot{width:8px;height:8px;border-radius:50%}
.day-count{font-size:.65rem;font-weight:700;margin-top:auto;padding-top:2px}
/* occupancy colors */
.day-empty{background:#fff}
.day-low{background:#ecfdf5}
.day-mid{background:#fef9ee}
.day-high{background:#fff3e0}
.day-full{background:#fff0f0}

/* ── DAY DETAIL ── */
.day-detail{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);overflow:hidden}
.dd-header{padding:16px 20px;background:linear-gradient(90deg,var(--blue-dark),var(--blue));
  display:flex;align-items:center;justify-content:space-between}
.dd-title{font-family:var(--serif);font-size:1rem;color:#fff}
.dd-sub{font-size:.72rem;color:rgba(255,255,255,.7)}
.dd-badge{background:rgba(255,255,255,.2);color:#fff;padding:4px 12px;border-radius:20px;
  font-size:.72rem;font-weight:700}

/* ── TABLE ── */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{background:var(--blue-pale);color:var(--blue-dark);padding:10px 14px;
  font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;font-weight:700;text-align:left;
  border-bottom:2px solid var(--border)}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--blue-pale)}
td{padding:11px 14px;font-size:.84rem;vertical-align:middle}
.status-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700}
.s-cho{background:#fef9ee;color:#92400e;border:1px solid #fcd34d60}
.s-nhan{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.s-tra{background:#ecfdf5;color:#065f46;border:1px solid #10b98140}
.loai-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:700;color:#fff}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-icon{font-size:2.5rem;margin-bottom:10px;opacity:.4;display:block}

/* ── OCCUPANCY BAR ── */
.occ-bar-wrap{margin-top:6px}
.occ-bar-bg{height:4px;background:var(--border);border-radius:2px;overflow:hidden}
.occ-bar-fill{height:100%;border-radius:2px;transition:width .4s ease}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .cal-day{min-height:60px}
}
@media(max-width:600px){
  .content{padding:16px}
  .cal-day{min-height:50px;padding:4px}
  .day-dots{display:none}
  .stats-grid{grid-template-columns:1fr 1fr}
}
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
    <a href="calendar.php"                  class="sb-nav-item active"><span class="sb-nav-icon">📅</span><span>Lịch Đặt Phòng</span></a>
    <a href="dashboard.php?tab=new-booking" class="sb-nav-item"><span class="sb-nav-icon">➕</span><span>Đặt Phòng Mới</span></a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php"                     class="sb-nav-item"><span class="sb-nav-icon">🛏️</span><span>Quản Lý Phòng</span></a>
    <a href="housekeeping.php"              class="sb-nav-item"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                  class="sb-nav-item"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                class="sb-nav-item"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"       class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                  class="sb-nav-item"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
    <a href="dashboard.php?tab=report"      class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <div class="topbar">
    <div class="topbar-title">📅 Lịch Đặt Phòng</div>
    <div class="topbar-meta">
      <span><?= $thangVi[$month] ?> <?= $year ?></span>
      <span>📦 <?= $totalBkMonth ?> đặt phòng</span>
      <span style="color:var(--muted)"><?= date('d/m/Y H:i') ?></span>
    </div>
  </div>

  <div class="content">

    <!-- Stats tháng -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-pale)">📦</div>
        <div>
          <div class="stat-value"><?= $totalBkMonth ?></div>
          <div class="stat-label">Đặt phòng tháng này</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb">📊</div>
        <div>
          <div class="stat-value" style="color:#d97706"><?= $avgOccupancy ?></div>
          <div class="stat-label">TB phòng/ngày</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff0f0">🔴</div>
        <div>
          <div class="stat-value" style="color:#dc2626"><?= $busyCount ?></div>
          <div class="stat-label">Đỉnh: ngày <?= $busyDay ?>/<?= $month ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">🏨</div>
        <div>
          <div class="stat-value" style="color:#059669"><?= $totalRooms ?></div>
          <div class="stat-label">Tổng phòng</div>
        </div>
      </div>
    </div>

    <!-- Calendar header: navigation -->
    <div class="cal-header">
      <div class="cal-nav">
        <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&day=1"
           class="cal-nav-btn">‹ <?= $thangVi[$prevMonth] ?></a>
        <div class="cal-title"><?= $thangVi[$month] ?> <?= $year ?></div>
        <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&day=1"
           class="cal-nav-btn"><?= $thangVi[$nextMonth] ?> ›</a>
      </div>
      <div style="display:flex;align-items:center;gap:12px">
        <div class="cal-legend">
          <div class="leg-item"><div class="leg-dot" style="background:#ecfdf5;border:1px solid #10b981"></div>1-25%</div>
          <div class="leg-item"><div class="leg-dot" style="background:#fef9ee;border:1px solid #f59e0b"></div>26-60%</div>
          <div class="leg-item"><div class="leg-dot" style="background:#fff3e0;border:1px solid #ea580c"></div>61-99%</div>
          <div class="leg-item"><div class="leg-dot" style="background:#fff0f0;border:1px solid #ef4444"></div>Đầy</div>
        </div>
        <a href="?year=<?= date('Y') ?>&month=<?= date('n') ?>&day=<?= date('j') ?>"
           class="cal-today-btn">Hôm nay</a>
      </div>
    </div>

    <!-- Calendar grid -->
    <div class="cal-wrap">
      <div class="cal-grid">
        <!-- Header days of week -->
        <?php foreach (['T2','T3','T4','T5','T6','T7','CN'] as $dow): ?>
          <div class="cal-dow"><?= $dow ?></div>
        <?php endforeach; ?>

        <!-- Empty cells before first day -->
        <?php for ($i = 1; $i < $startWd; $i++): ?>
          <div class="cal-day cal-empty"></div>
        <?php endfor; ?>

        <!-- Day cells -->
        <?php
          $todayStr = date('Y-m-d');
          for ($d = 1; $d <= $daysInMonth; $d++):
            $dateStr  = date('Y-m-d', mktime(0, 0, 0, $month, $d, $year));
            $bks      = $dayMap[$d];
            $cnt      = count($bks);
            $grade    = cellGrade($cnt, $totalRooms);
            $isToday  = ($dateStr === $todayStr);
            $isSel    = ($d === $selDay);
            $pct      = $totalRooms > 0 ? round($cnt / $totalRooms * 100) : 0;
            $barColor = $pct == 0 ? '#94a3b8' : ($pct <= 25 ? '#10b981' : ($pct <= 60 ? '#f59e0b' : ($pct < 100 ? '#ea580c' : '#ef4444')));
            $classes  = "cal-day $grade" . ($isToday ? ' today' : '') . ($isSel ? ' selected' : '');
            $url      = "?year=$year&month=$month&day=$d";
        ?>
        <div class="<?= $classes ?>"
             onclick="window.location='<?= $url ?>'"
             title="<?= $d . '/' . $month . '/' . $year ?> — <?= $cnt ?>/<?= $totalRooms ?> phòng">
          <div class="day-num"><?= $d ?></div>
          <?php if ($cnt > 0): ?>
            <div class="day-dots">
              <?php
                $shown = 0;
                foreach ($bks as $bk):
                  if ($shown >= 6) { echo '<div class="day-dot" style="background:#94a3b8" title="+' . ($cnt - 6) . ' phòng khác"></div>'; break; }
                  echo '<div class="day-dot" style="background:' . loaiPhongColor($bk['LoaiPhong']) . '" title="' . esc($bk['MaPhong']) . ' — ' . esc($bk['TenKH']) . '"></div>';
                  $shown++;
                endforeach;
              ?>
            </div>
            <div class="day-count" style="color:<?= $barColor ?>">
              <?= $cnt ?>/<?= $totalRooms ?>
              <div class="occ-bar-wrap">
                <div class="occ-bar-bg">
                  <div class="occ-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>

        <!-- Empty cells after last day -->
        <?php
          $lastWd  = (int)date('N', mktime(0, 0, 0, $month, $daysInMonth, $year));
          $padEnd  = $lastWd < 7 ? 7 - $lastWd : 0;
          for ($i = 0; $i < $padEnd; $i++):
        ?>
          <div class="cal-day cal-empty"></div>
        <?php endfor; ?>
      </div><!-- /cal-grid -->
    </div><!-- /cal-wrap -->

    <!-- Day detail panel -->
    <?php
      $selDateStr = date('Y-m-d', mktime(0, 0, 0, $month, $selDay, $year));
      $selDateVi  = date('d', mktime(0, 0, 0, $month, $selDay, $year)) . '/' . $month . '/' . $year;
      $selBks     = $dayMap[$selDay] ?? [];
      $selCnt     = count($selBks);
      $selPct     = $totalRooms > 0 ? round($selCnt / $totalRooms * 100) : 0;
    ?>
    <div class="day-detail">
      <div class="dd-header">
        <div>
          <div class="dd-title">📅 Ngày <?= $selDateVi ?> <?= $selDateStr === date('Y-m-d') ? '(Hôm nay)' : '' ?></div>
          <div class="dd-sub"><?= $thangVi[$month] ?> <?= $year ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="text-align:right;font-size:.72rem;color:rgba(255,255,255,.7)">
            Công suất: <?= $selPct ?>%<br>
            <span style="font-size:.65rem"><?= $selCnt ?>/<?= $totalRooms ?> phòng</span>
          </div>
          <div class="dd-badge"><?= $selCnt ?> đặt phòng</div>
        </div>
      </div>

      <?php if ($selBks): ?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Mã ĐP</th>
              <th>Phòng</th>
              <th>Loại</th>
              <th>Khách hàng</th>
              <th>Check-in</th>
              <th>Check-out</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selBks as $i => $bk):
              $stClass = match($bk['TrangThai']) {
                'Chờ xác nhận' => 's-cho',
                'Đã nhận phòng'=> 's-nhan',
                'Đã trả phòng' => 's-tra',
                default        => '',
              };
            ?>
            <tr>
              <td style="color:var(--muted);font-size:.75rem"><?= $i + 1 ?></td>
              <td><strong style="color:var(--blue-dark)"><?= esc($bk['MaDP']) ?></strong></td>
              <td><strong><?= esc($bk['MaPhong']) ?></strong></td>
              <td>
                <span class="loai-badge"
                      style="background:<?= loaiPhongColor($bk['LoaiPhong']) ?>">
                  <?= esc($bk['LoaiPhong']) ?>
                </span>
              </td>
              <td><?= esc($bk['TenKH']) ?></td>
              <td><?= date('d/m/Y', strtotime($bk['NgayCI'])) ?></td>
              <td><?= date('d/m/Y', strtotime($bk['NgayCO'])) ?></td>
              <td><span class="status-badge <?= $stClass ?>"><?= esc($bk['TrangThai']) ?></span></td>
              <td>
                <a href="dashboard.php?tab=bookings&search=<?= urlencode($bk['MaDP']) ?>"
                   style="font-size:.76rem;color:var(--blue);text-decoration:none;font-weight:700;
                          padding:4px 10px;border-radius:6px;border:1.5px solid var(--border);
                          background:var(--blue-pale);display:inline-block;transition:all .2s"
                   onmouseover="this.style.background='var(--blue)';this.style.color='#fff'"
                   onmouseout="this.style.background='var(--blue-pale)';this.style.color='var(--blue)'">
                  🔍 Xem
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <span class="empty-icon">📭</span>
        <p>Không có đặt phòng nào vào ngày <strong><?= $selDateVi ?></strong>.</p>
        <p style="margin-top:6px;font-size:.78rem">
          <a href="dashboard.php?tab=new-booking" style="color:var(--blue);font-weight:700">+ Tạo đặt phòng mới</a>
        </p>
      </div>
      <?php endif; ?>
    </div><!-- /day-detail -->

  </div><!-- /content -->
</div><!-- /main -->

<script>
// Keyboard nav: ← → to move between days
document.addEventListener('keydown', e => {
  const url = new URL(window.location.href);
  const day  = parseInt(url.searchParams.get('day') || <?= $selDay ?>);
  const max  = <?= $daysInMonth ?>;
  if (e.key === 'ArrowRight' && day < max) {
    url.searchParams.set('day', day + 1);
    window.location = url.toString();
  } else if (e.key === 'ArrowLeft' && day > 1) {
    url.searchParams.set('day', day - 1);
    window.location = url.toString();
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    const pm = <?= $prevMonth ?>, py = <?= $prevYear ?>;
    window.location = `calendar.php?year=${py}&month=${pm}&day=1`;
  } else if (e.key === 'ArrowDown') {
    e.preventDefault();
    const nm = <?= $nextMonth ?>, ny = <?= $nextYear ?>;
    window.location = `calendar.php?year=${ny}&month=${nm}&day=1`;
  }
});
</script>
</body>
</html>
