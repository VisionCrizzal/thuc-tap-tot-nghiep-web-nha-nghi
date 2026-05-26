<?php
// admin/reports.php — Báo Cáo Doanh Thu — Easyhome v25
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'] ?? $adminId;

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt(mixed $n): string  { return number_format((float)$n, 0, ',', '.'); }
function esc(mixed $s): string  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDt(?string $d): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts !== false ? date('d/m/Y', $ts) : '—';
}

// ── Filters ───────────────────────────────────────────────────────────────────
$selYear   = (int)($_GET['year']   ?? date('Y'));
$selPeriod = in_array($_GET['period'] ?? '', ['month','quarter']) ? $_GET['period'] : 'month';
$exportCsv = (($_GET['export'] ?? '') === 'csv');

// ── Years list ────────────────────────────────────────────────────────────────
$yearList = $pdo->query("
    SELECT DISTINCT YEAR(NgayLap) AS yr FROM HOA_DON
    UNION SELECT YEAR(CURDATE())
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (empty($yearList)) $yearList = [date('Y')];

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminProfile = $pdo->prepare("
    SELECT nv.MaNV FROM TAI_KHOAN tk
    LEFT JOIN NHAN_VIEN nv ON tk.MaNV = nv.MaNV
    WHERE tk.TenTK = :tk
");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

// ════════════════════════════════════════════════════════════════════════════════
// ── DATA QUERIES ─────────────────────────────────────────────────────────────
// ════════════════════════════════════════════════════════════════════════════════

// 1. Overall stats for selected year (paid invoices)
$sy = $pdo->prepare("
    SELECT
        COUNT(*)                 AS tong_hd,
        COALESCE(SUM(TongTien),0)    AS tong_dt,
        COALESCE(SUM(TienPhong),0)   AS dt_phong,
        COALESCE(SUM(TienDichVu),0)  AS dt_dv,
        COALESCE(SUM(PhuPhi),0)      AS dt_phu,
        COUNT(DISTINCT MaNV)     AS so_nv
    FROM HOA_DON
    WHERE TrangThai = 'Đã thanh toán' AND YEAR(NgayLap) = :y
");
$sy->execute([':y' => $selYear]);
$sy = $sy->fetch();

// 2. Total bookings (non-cancelled) for year
$totalBk = $pdo->prepare("
    SELECT COUNT(*) FROM DAT_PHONG
    WHERE TrangThai != 'Đã hủy' AND YEAR(NgayCheckIn) = :y
");
$totalBk->execute([':y' => $selYear]);
$totalBk = (int)$totalBk->fetchColumn();

// Avg revenue per invoice
$avgRev = $sy['tong_hd'] > 0 ? $sy['tong_dt'] / $sy['tong_hd'] : 0;

// 3. Revenue by month (12 months)
$revByMonth = array_fill(1, 12, 0);
$cntByMonth = array_fill(1, 12, 0);
$revRows = $pdo->prepare("
    SELECT MONTH(NgayLap) AS mo,
           SUM(TongTien)  AS dt,
           COUNT(*)       AS cnt
    FROM HOA_DON
    WHERE TrangThai = 'Đã thanh toán' AND YEAR(NgayLap) = :y
    GROUP BY mo ORDER BY mo
");
$revRows->execute([':y' => $selYear]);
foreach ($revRows->fetchAll() as $r) {
    $revByMonth[(int)$r['mo']] = (float)$r['dt'];
    $cntByMonth[(int)$r['mo']] = (int)$r['cnt'];
}

// 4. Bookings by month
$bkByMonth = array_fill(1, 12, 0);
$bkRows = $pdo->prepare("
    SELECT MONTH(NgayCheckIn) AS mo, COUNT(*) AS cnt
    FROM DAT_PHONG
    WHERE TrangThai != 'Đã hủy' AND YEAR(NgayCheckIn) = :y
    GROUP BY mo ORDER BY mo
");
$bkRows->execute([':y' => $selYear]);
foreach ($bkRows->fetchAll() as $r) {
    $bkByMonth[(int)$r['mo']] = (int)$r['cnt'];
}

// 5. Revenue by quarter
$revByQuarter = [1=>0, 2=>0, 3=>0, 4=>0];
$bkByQuarter  = [1=>0, 2=>0, 3=>0, 4=>0];
for ($mo = 1; $mo <= 12; $mo++) {
    $q = (int)ceil($mo / 3);
    $revByQuarter[$q] += $revByMonth[$mo];
    $bkByQuarter[$q]  += $bkByMonth[$mo];
}

// 6. Top 5 phòng (by bookings count)
$topRooms = $pdo->prepare("
    SELECT p.MaPhong, p.LoaiPhong,
           COUNT(dp.MaDP)     AS so_luot,
           COALESCE(SUM(dp.TongGia),0) AS doanh_thu
    FROM PHONG p
    JOIN DAT_PHONG dp ON p.MaPhong = dp.MaPhong
    WHERE dp.TrangThai != 'Đã hủy' AND YEAR(dp.NgayCheckIn) = :y
    GROUP BY p.MaPhong, p.LoaiPhong
    ORDER BY so_luot DESC, doanh_thu DESC
    LIMIT 5
");
$topRooms->execute([':y' => $selYear]);
$topRooms = $topRooms->fetchAll();

// 7. Top 5 dịch vụ (by revenue)
$topSvcs = $pdo->prepare("
    SELECT dv.TenDV, dv.BieuTuong,
           SUM(ddv.SoLuong)    AS tong_sl,
           COALESCE(SUM(ddv.ThanhTien),0) AS doanh_thu
    FROM DICH_VU dv
    JOIN DAT_DICH_VU ddv ON dv.MaDV = ddv.MaDV
    JOIN DAT_PHONG dp    ON ddv.MaDP = dp.MaDP
    WHERE YEAR(dp.NgayCheckIn) = :y AND dp.TrangThai != 'Đã hủy'
    GROUP BY dv.MaDV, dv.TenDV, dv.BieuTuong
    ORDER BY doanh_thu DESC
    LIMIT 5
");
$topSvcs->execute([':y' => $selYear]);
$topSvcs = $topSvcs->fetchAll();

// 8. Top 5 nhân viên (by revenue collected)
$topStaff = $pdo->prepare("
    SELECT nv.HoTen,
           COUNT(hd.MaHD)        AS so_hd,
           COALESCE(SUM(hd.TongTien),0) AS doanh_thu
    FROM NHAN_VIEN nv
    JOIN HOA_DON hd ON nv.MaNV = hd.MaNV
    WHERE hd.TrangThai = 'Đã thanh toán' AND YEAR(hd.NgayLap) = :y
    GROUP BY nv.MaNV, nv.HoTen
    ORDER BY doanh_thu DESC
    LIMIT 5
");
$topStaff->execute([':y' => $selYear]);
$topStaff = $topStaff->fetchAll();

// ── CSV EXPORT ────────────────────────────────────────────────────────────────
if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bao-cao-doanh-thu-' . $selYear . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM for Excel Vietnamese UTF-8
    fwrite($out, "\xEF\xBB\xBF");

    // Header section
    fputcsv($out, ["BÁO CÁO DOANH THU NĂM $selYear — EASYHOME"]);
    fputcsv($out, ["Xuất lúc: " . date('d/m/Y H:i')]);
    fputcsv($out, []);

    // Monthly revenue
    fputcsv($out, ['DOANH THU THEO THÁNG']);
    fputcsv($out, ['Tháng', 'Số hóa đơn', 'Doanh thu (đ)', 'Lượt đặt phòng']);
    $mNames = ['','Tháng 1','Tháng 2','Tháng 3','Tháng 4','Tháng 5','Tháng 6',
                  'Tháng 7','Tháng 8','Tháng 9','Tháng 10','Tháng 11','Tháng 12'];
    for ($mo = 1; $mo <= 12; $mo++) {
        fputcsv($out, [$mNames[$mo], $cntByMonth[$mo], $revByMonth[$mo], $bkByMonth[$mo]]);
    }
    fputcsv($out, ['TỔNG', array_sum($cntByMonth), array_sum($revByMonth), array_sum($bkByMonth)]);
    fputcsv($out, []);

    // Top rooms
    fputcsv($out, ['TOP PHÒNG']);
    fputcsv($out, ['Mã phòng', 'Loại phòng', 'Số lượt', 'Doanh thu (đ)']);
    foreach ($topRooms as $r) {
        fputcsv($out, [$r['MaPhong'], $r['LoaiPhong'], $r['so_luot'], $r['doanh_thu']]);
    }
    fputcsv($out, []);

    // Top services
    fputcsv($out, ['TOP DỊCH VỤ']);
    fputcsv($out, ['Dịch vụ', 'Tổng số lượng', 'Doanh thu (đ)']);
    foreach ($topSvcs as $s) {
        fputcsv($out, [$s['TenDV'], $s['tong_sl'], $s['doanh_thu']]);
    }
    fputcsv($out, []);

    // Top staff
    fputcsv($out, ['TOP NHÂN VIÊN']);
    fputcsv($out, ['Nhân viên', 'Số hóa đơn', 'Doanh thu (đ)']);
    foreach ($topStaff as $nv) {
        fputcsv($out, [$nv['HoTen'], $nv['so_hd'], $nv['doanh_thu']]);
    }

    fclose($out); exit;
}

// ── Prepare Chart.js data ─────────────────────────────────────────────────────
$monthLabels  = json_encode(['T1','T2','T3','T4','T5','T6','T7','T8','T9','T10','T11','T12']);
$chartRevData = json_encode(array_values($revByMonth));
$chartBkData  = json_encode(array_values($bkByMonth));
$quarterLabels= json_encode(['Quý 1','Quý 2','Quý 3','Quý 4']);
$chartQRevData= json_encode(array_values($revByQuarter));
$chartQBkData = json_encode(array_values($bkByQuarter));

$maxRevMonth  = max(array_values($revByMonth) ?: [0]);
$maxBkMonth   = max(array_values($bkByMonth) ?: [0]);

$totalRev = array_sum($revByMonth);
$totalBkSum = array_sum($bkByMonth);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Báo Cáo Doanh Thu — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
.topbar-actions{display:flex;align-items:center;gap:10px}
.content{padding:24px 28px;flex:1}

/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  padding:14px 18px;margin-bottom:22px;box-shadow:var(--shadow)}
.filter-bar label{font-size:.72rem;font-weight:700;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--muted);white-space:nowrap}
.filter-select{padding:7px 12px;border:1.5px solid var(--border);border-radius:8px;
  font-family:var(--font);font-size:.85rem;color:var(--text);
  background:var(--blue-pale);outline:none;cursor:pointer;transition:border-color .2s}
.filter-select:focus{border-color:var(--blue)}
.period-group{display:flex;border:1.5px solid var(--border);border-radius:8px;overflow:hidden}
.period-btn{padding:7px 16px;font-family:var(--font);font-size:.82rem;font-weight:600;
  border:none;cursor:pointer;transition:all .2s;background:var(--blue-pale);color:var(--muted)}
.period-btn.active{background:var(--blue);color:#fff}
.btn-apply{padding:8px 18px;background:var(--blue);color:#fff;border:none;border-radius:8px;
  font-family:var(--font);font-size:.83rem;font-weight:700;cursor:pointer;
  transition:all .2s;letter-spacing:.5px}
.btn-apply:hover{background:var(--blue-dark)}
.btn-export{padding:8px 18px;background:linear-gradient(135deg,#059669,#10b981);color:#fff;
  border:none;border-radius:8px;font-family:var(--font);font-size:.83rem;font-weight:700;
  cursor:pointer;transition:all .2s;text-decoration:none;display:flex;align-items:center;gap:6px}
.btn-export:hover{opacity:.88}
.spacer{flex:1}

/* ── STAT CARDS ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px}
.stat-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  padding:20px;box-shadow:var(--shadow);position:relative;overflow:hidden;
  transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.stat-card.blue::before{background:linear-gradient(90deg,var(--blue-dark),var(--blue))}
.stat-card.green::before{background:linear-gradient(90deg,#059669,#10b981)}
.stat-card.amber::before{background:linear-gradient(90deg,#d97706,#f59e0b)}
.stat-card.purple::before{background:linear-gradient(90deg,#7c3aed,#a78bfa)}
.stat-icon{font-size:1.8rem;margin-bottom:10px}
.stat-val{font-size:1.6rem;font-weight:700;color:var(--blue-dark);line-height:1;margin-bottom:4px}
.stat-val.green{color:#059669}
.stat-label{font-size:.72rem;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.stat-sub{font-size:.75rem;color:var(--muted);margin-top:6px}

/* ── CHARTS ── */
.charts-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:22px}
.chart-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  padding:20px;box-shadow:var(--shadow)}
.chart-title{font-family:var(--serif);font-size:1rem;color:var(--blue-dark);
  margin-bottom:16px;display:flex;align-items:center;gap:8px}
.chart-wrap{position:relative;height:220px}

/* ── TABLES SECTION ── */
.tops-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:22px}
.top-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  padding:0;box-shadow:var(--shadow);overflow:hidden}
.top-card-header{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:14px 18px;color:#fff;font-family:var(--serif);font-size:.95rem;
  display:flex;align-items:center;gap:8px}
.top-tbl{width:100%;border-collapse:collapse}
.top-tbl th{font-size:.68rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--muted);padding:10px 16px;background:var(--blue-pale);text-align:left;border-bottom:1.5px solid var(--border)}
.top-tbl td{padding:11px 16px;font-size:.85rem;border-bottom:1px solid var(--border);vertical-align:middle}
.top-tbl tr:last-child td{border-bottom:none}
.top-tbl tr:hover td{background:var(--blue-pale)}
.rank-badge{display:inline-flex;align-items:center;justify-content:center;
  width:22px;height:22px;border-radius:50%;font-size:.72rem;font-weight:700;
  background:var(--blue-mid);color:var(--blue-dark)}
.rank-badge.gold{background:#fef3c7;color:#92400e}
.rank-badge.silver{background:#f1f5f9;color:#475569}
.rank-badge.bronze{background:#fef3c7;color:#b45309}
.td-r{text-align:right;font-weight:600;color:var(--blue-dark)}
.loai-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.7rem;font-weight:600}
.loai-badge.don{background:#dbeafe;color:#1e40af}
.loai-badge.doi{background:#dcfce7;color:#166534}
.loai-badge.vip{background:#f3e8ff;color:#6b21a8}
.loai-badge.giadinh{background:#fef9c3;color:#713f12}

/* ── MONTHLY TABLE ── */
.section-title{font-family:var(--serif);font-size:1.1rem;color:var(--blue-dark);
  display:flex;align-items:center;gap:8px;margin-bottom:14px}
.month-tbl-wrap{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  box-shadow:var(--shadow);overflow:hidden;margin-bottom:22px}
.month-tbl{width:100%;border-collapse:collapse}
.month-tbl th{font-size:.68rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--muted);padding:12px 16px;background:var(--blue-pale);text-align:left;
  border-bottom:1.5px solid var(--border)}
.month-tbl th.ar,.month-tbl td.ar{text-align:right}
.month-tbl td{padding:12px 16px;font-size:.88rem;border-bottom:1px solid var(--border)}
.month-tbl tr:last-child td{border-bottom:none;background:var(--blue-mid);font-weight:700;color:var(--blue-dark)}
.month-tbl tr:not(:last-child):hover td{background:var(--blue-pale)}
.rev-bar-wrap{display:flex;align-items:center;gap:8px}
.rev-bar{height:8px;border-radius:4px;background:linear-gradient(90deg,var(--blue),var(--blue-light));
  min-width:3px;transition:width .3s}
.rev-bar-val{font-size:.82rem;font-weight:600;color:var(--blue-dark);white-space:nowrap}
.no-data{text-align:center;padding:32px;color:var(--muted);font-size:.9rem}

/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .stat-grid{grid-template-columns:repeat(2,1fr)}
  .charts-row,.tops-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .content{padding:16px 14px}
  .stat-grid{grid-template-columns:1fr 1fr}
  .filter-bar{flex-direction:column;align-items:flex-start}
}

/* ── PRINT ── */
@media print{
  .sidebar,.topbar,.filter-bar,.btn-export,.btn-apply{display:none!important}
  body,.main{display:block;background:#fff}
  .content{padding:0}
  .chart-card,.top-card,.month-tbl-wrap{break-inside:avoid;box-shadow:none;border-color:#ccc}
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
      <?php if (!empty($adminProfile['MaNV'])): ?>
        <div class="sb-admin-row">👤 <?= esc($adminProfile['MaNV']) ?></div>
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
    <a href="housekeeping.php"              class="sb-nav-item"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                  class="sb-nav-item"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                class="sb-nav-item"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <a href="reports.php"                   class="sb-nav-item active"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
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

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-title">📈 Báo Cáo Doanh Thu</div>
    <div class="topbar-actions">
      <span style="font-size:.8rem;color:var(--muted)">Năm <?= $selYear ?></span>
      <a href="?year=<?= $selYear ?>&period=<?= esc($selPeriod) ?>&export=csv" class="btn-export">
        ⬇️ Xuất CSV
      </a>
      <button onclick="window.print()" style="padding:8px 16px;background:var(--blue-pale);
        border:1.5px solid var(--border);border-radius:8px;cursor:pointer;
        font-family:var(--font);font-size:.82rem;font-weight:600;color:var(--blue-dark)">
        🖨️ In báo cáo
      </button>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- ── FILTER BAR ── -->
    <form method="GET" action="reports.php">
      <div class="filter-bar">
        <label>Năm</label>
        <select name="year" class="filter-select" onchange="this.form.submit()">
          <?php foreach ($yearList as $yr): ?>
            <option value="<?= $yr ?>" <?= $yr == $selYear ? 'selected' : '' ?>><?= $yr ?></option>
          <?php endforeach; ?>
        </select>

        <label>Xem theo</label>
        <div class="period-group">
          <button type="button" class="period-btn <?= $selPeriod==='month'?'active':'' ?>"
            onclick="setPeriod('month')">Tháng</button>
          <button type="button" class="period-btn <?= $selPeriod==='quarter'?'active':'' ?>"
            onclick="setPeriod('quarter')">Quý</button>
        </div>
        <input type="hidden" name="period" id="periodInput" value="<?= esc($selPeriod) ?>">

        <div class="spacer"></div>
        <button type="submit" class="btn-apply">🔍 Lọc</button>
      </div>
    </form>

    <!-- ── STAT CARDS ── -->
    <div class="stat-grid">
      <div class="stat-card blue">
        <div class="stat-icon">💰</div>
        <div class="stat-val"><?= fmt($sy['tong_dt']) ?><small style="font-size:.7em">đ</small></div>
        <div class="stat-label">Tổng Doanh Thu</div>
        <div class="stat-sub">Năm <?= $selYear ?> (đã thanh toán)</div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon">🧾</div>
        <div class="stat-val green"><?= number_format((int)$sy['tong_hd']) ?></div>
        <div class="stat-label">Hóa Đơn Thanh Toán</div>
        <div class="stat-sub">TB <?= fmt($avgRev) ?>đ / hóa đơn</div>
      </div>
      <div class="stat-card amber">
        <div class="stat-icon">🛏️</div>
        <div class="stat-val" style="color:#d97706"><?= number_format($totalBk) ?></div>
        <div class="stat-label">Lượt Đặt Phòng</div>
        <div class="stat-sub">Không kể đặt phòng đã hủy</div>
      </div>
      <div class="stat-card purple">
        <div class="stat-icon">🛎️</div>
        <div class="stat-val" style="color:#7c3aed"><?= fmt($sy['dt_dv']) ?><small style="font-size:.7em">đ</small></div>
        <div class="stat-label">DT Dịch Vụ</div>
        <div class="stat-sub">Phòng: <?= fmt($sy['dt_phong']) ?>đ</div>
      </div>
    </div>

    <!-- ── CHARTS ── -->
    <div class="charts-row">
      <!-- Revenue Chart -->
      <div class="chart-card">
        <div class="chart-title">
          💰 Doanh Thu
          <span style="font-size:.72rem;color:var(--muted);font-family:var(--font);font-weight:normal">
            — <?= $selPeriod === 'quarter' ? 'Theo quý' : 'Theo tháng' ?>
          </span>
        </div>
        <div class="chart-wrap">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>

      <!-- Bookings Chart -->
      <div class="chart-card">
        <div class="chart-title">
          🛏️ Lượt Đặt Phòng
          <span style="font-size:.72rem;color:var(--muted);font-family:var(--font);font-weight:normal">
            — <?= $selPeriod === 'quarter' ? 'Theo quý' : 'Theo tháng' ?>
          </span>
        </div>
        <div class="chart-wrap">
          <canvas id="bookingChart"></canvas>
        </div>
      </div>
    </div>

    <!-- ── TOP TABLES ── -->
    <div class="tops-grid">

      <!-- Top Phòng -->
      <div class="top-card">
        <div class="top-card-header">🏆 Top 5 Phòng</div>
        <?php if ($topRooms): ?>
        <table class="top-tbl">
          <thead><tr>
            <th>#</th><th>Phòng</th><th>Loại</th><th class="ar">Lượt</th><th class="ar">DT (đ)</th>
          </tr></thead>
          <tbody>
          <?php foreach ($topRooms as $i => $r): ?>
            <?php
              $rankClass = match($i) { 0=>'gold', 1=>'silver', 2=>'bronze', default=>'' };
              $loaiClass = match($r['LoaiPhong']) {
                'Đơn'      => 'don',
                'Đôi'      => 'doi',
                'VIP'      => 'vip',
                'Gia đình' => 'giadinh',
                default    => 'don'
              };
            ?>
            <tr>
              <td><span class="rank-badge <?= $rankClass ?>"><?= $i+1 ?></span></td>
              <td><strong><?= esc($r['MaPhong']) ?></strong></td>
              <td><span class="loai-badge <?= $loaiClass ?>"><?= esc($r['LoaiPhong']) ?></span></td>
              <td class="td-r"><?= (int)$r['so_luot'] ?></td>
              <td class="td-r"><?= fmt($r['doanh_thu']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="no-data">📭 Chưa có dữ liệu năm <?= $selYear ?></div>
        <?php endif; ?>
      </div>

      <!-- Top Dịch Vụ -->
      <div class="top-card">
        <div class="top-card-header">🛎️ Top 5 Dịch Vụ</div>
        <?php if ($topSvcs): ?>
        <table class="top-tbl">
          <thead><tr>
            <th>#</th><th>Dịch vụ</th><th class="ar">SL</th><th class="ar">DT (đ)</th>
          </tr></thead>
          <tbody>
          <?php foreach ($topSvcs as $i => $s): ?>
            <?php $rankClass = match($i) { 0=>'gold', 1=>'silver', 2=>'bronze', default=>'' }; ?>
            <tr>
              <td><span class="rank-badge <?= $rankClass ?>"><?= $i+1 ?></span></td>
              <td><?= esc($s['BieuTuong'] ?? '') ?> <?= esc($s['TenDV']) ?></td>
              <td class="td-r"><?= (int)$s['tong_sl'] ?></td>
              <td class="td-r"><?= fmt($s['doanh_thu']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="no-data">📭 Chưa có dữ liệu năm <?= $selYear ?></div>
        <?php endif; ?>
      </div>

      <!-- Top Nhân Viên -->
      <div class="top-card">
        <div class="top-card-header">👥 Top 5 Nhân Viên</div>
        <?php if ($topStaff): ?>
        <table class="top-tbl">
          <thead><tr>
            <th>#</th><th>Nhân viên</th><th class="ar">HĐ</th><th class="ar">DT (đ)</th>
          </tr></thead>
          <tbody>
          <?php foreach ($topStaff as $i => $nv): ?>
            <?php $rankClass = match($i) { 0=>'gold', 1=>'silver', 2=>'bronze', default=>'' }; ?>
            <tr>
              <td><span class="rank-badge <?= $rankClass ?>"><?= $i+1 ?></span></td>
              <td><?= esc($nv['HoTen']) ?></td>
              <td class="td-r"><?= (int)$nv['so_hd'] ?></td>
              <td class="td-r"><?= fmt($nv['doanh_thu']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="no-data">📭 Chưa có dữ liệu năm <?= $selYear ?></div>
        <?php endif; ?>
      </div>

    </div><!-- /tops-grid -->

    <!-- ── MONTHLY DETAIL TABLE ── -->
    <div class="section-title">📅 Chi Tiết Theo Tháng — Năm <?= $selYear ?></div>
    <div class="month-tbl-wrap">
      <?php
        $maxRev = max(array_values($revByMonth) ?: [1]);
        $mFullNames = ['','Tháng 1','Tháng 2','Tháng 3','Tháng 4','Tháng 5','Tháng 6',
                          'Tháng 7','Tháng 8','Tháng 9','Tháng 10','Tháng 11','Tháng 12'];
      ?>
      <table class="month-tbl">
        <thead>
          <tr>
            <th>Tháng</th>
            <th>Hóa đơn</th>
            <th>Lượt đặt phòng</th>
            <th class="ar">Doanh thu phòng</th>
            <th class="ar">Doanh thu DV</th>
            <th>Biểu đồ doanh thu</th>
            <th class="ar">Tổng (đ)</th>
          </tr>
        </thead>
        <tbody>
        <?php
          $sumHD = $sumBk = $sumPhong = $sumDV = $sumTotal = 0;
          for ($mo = 1; $mo <= 12; $mo++):
            // Get per-month breakdown from DB
            $moStats = $pdo->prepare("
              SELECT COALESCE(SUM(TienPhong),0)  AS tp,
                     COALESCE(SUM(TienDichVu),0) AS tdv
              FROM HOA_DON
              WHERE TrangThai='Đã thanh toán' AND YEAR(NgayLap)=:y AND MONTH(NgayLap)=:m
            ");
            $moStats->execute([':y'=>$selYear,':m'=>$mo]);
            $moStats = $moStats->fetch();
            $barPct = $maxRev > 0 ? min(100, round($revByMonth[$mo] / $maxRev * 100)) : 0;
            $sumHD    += $cntByMonth[$mo];
            $sumBk    += $bkByMonth[$mo];
            $sumPhong += $moStats['tp'];
            $sumDV    += $moStats['tdv'];
            $sumTotal += $revByMonth[$mo];
        ?>
        <tr>
          <td><strong><?= $mFullNames[$mo] ?></strong></td>
          <td><?= $cntByMonth[$mo] > 0 ? $cntByMonth[$mo] : '<span style="color:var(--muted)">—</span>' ?></td>
          <td><?= $bkByMonth[$mo] > 0 ? $bkByMonth[$mo] : '<span style="color:var(--muted)">—</span>' ?></td>
          <td class="ar"><?= $moStats['tp'] > 0 ? fmt($moStats['tp']) : '—' ?></td>
          <td class="ar"><?= $moStats['tdv'] > 0 ? fmt($moStats['tdv']) : '—' ?></td>
          <td>
            <?php if ($revByMonth[$mo] > 0): ?>
            <div class="rev-bar-wrap">
              <div class="rev-bar" style="width:<?= $barPct ?>%"></div>
              <span class="rev-bar-val"><?= fmt($revByMonth[$mo]) ?>đ</span>
            </div>
            <?php else: ?>
              <span style="color:var(--muted);font-size:.82rem">—</span>
            <?php endif; ?>
          </td>
          <td class="ar"><strong><?= $revByMonth[$mo] > 0 ? fmt($revByMonth[$mo]) : '—' ?></strong></td>
        </tr>
        <?php endfor; ?>
        <!-- TOTAL ROW -->
        <tr>
          <td>TỔNG NĂM <?= $selYear ?></td>
          <td><?= $sumHD ?></td>
          <td><?= $sumBk ?></td>
          <td class="ar"><?= fmt($sumPhong) ?></td>
          <td class="ar"><?= fmt($sumDV) ?></td>
          <td></td>
          <td class="ar"><?= fmt($sumTotal) ?>đ</td>
        </tr>
        </tbody>
      </table>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<script>
// ── Period toggle ─────────────────────────────────────────────────────────────
function setPeriod(p) {
  document.getElementById('periodInput').value = p;
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
}

// ── Chart.js data ─────────────────────────────────────────────────────────────
const period     = '<?= $selPeriod ?>';
const mLabels    = <?= $monthLabels ?>;
const qLabels    = <?= $quarterLabels ?>;
const mRevData   = <?= $chartRevData ?>;
const qRevData   = <?= $chartQRevData ?>;
const mBkData    = <?= $chartBkData ?>;
const qBkData    = <?= $chartQBkData ?>;

const labels  = period === 'quarter' ? qLabels  : mLabels;
const revData = period === 'quarter' ? qRevData : mRevData;
const bkData  = period === 'quarter' ? qBkData  : mBkData;

// Formatter: VNĐ
function fmtVnd(v) {
  if (v >= 1e9) return (v/1e9).toFixed(1) + ' tỷ';
  if (v >= 1e6) return (v/1e6).toFixed(1) + ' tr';
  return v.toLocaleString('vi-VN') + 'đ';
}

// Revenue Chart (Line)
const revCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revCtx, {
  type: 'line',
  data: {
    labels: labels,
    datasets: [{
      label: 'Doanh thu (đ)',
      data: revData,
      borderColor: '#1d4ed8',
      backgroundColor: 'rgba(29,78,216,0.08)',
      borderWidth: 2.5,
      pointBackgroundColor: '#1d4ed8',
      pointRadius: 4,
      pointHoverRadius: 6,
      fill: true,
      tension: 0.35,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => ' ' + fmtVnd(ctx.raw)
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(29,78,216,0.06)' },
        ticks: {
          font: { family: 'Calibri', size: 11 },
          callback: v => fmtVnd(v)
        }
      },
      x: {
        grid: { display: false },
        ticks: { font: { family: 'Calibri', size: 11 } }
      }
    }
  }
});

// Booking Chart (Bar)
const bkCtx = document.getElementById('bookingChart').getContext('2d');
new Chart(bkCtx, {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [{
      label: 'Lượt đặt phòng',
      data: bkData,
      backgroundColor: 'rgba(59,130,246,0.72)',
      borderColor: '#1d4ed8',
      borderWidth: 1.5,
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: { label: ctx => ' ' + ctx.raw + ' lượt' }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(29,78,216,0.06)' },
        ticks: {
          font: { family: 'Calibri', size: 11 },
          stepSize: 1,
          callback: v => Number.isInteger(v) ? v : ''
        }
      },
      x: {
        grid: { display: false },
        ticks: { font: { family: 'Calibri', size: 11 } }
      }
    }
  }
});
</script>

</body>
</html>
