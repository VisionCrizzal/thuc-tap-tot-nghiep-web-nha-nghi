<?php
// customer/booking-detail.php — Chi tiết đặt phòng cho khách hàng
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['kh_id'])) {
    header('Location: ../login.php'); exit;
}

$khId   = $_SESSION['kh_id'];
$khName = $_SESSION['kh_name'];
$maDP   = trim($_GET['dp'] ?? '');

if (!$maDP) { header('Location: dashboard.php?tab=history'); exit; }

// ── Lấy thông tin đặt phòng (chỉ phòng của KH này) ─────────────────────────
$q = $pdo->prepare("
    SELECT dp.*,
           p.LoaiPhong, p.Tang, p.GiaPhong, p.MoTa AS MoTaPhong,
           kh.HoTen, kh.SoDienThoai, kh.Email, kh.CCCD, kh.TichDiem
    FROM DAT_PHONG dp
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    WHERE dp.MaDP = :dp AND dp.MaKH = :kh
");
$q->execute([':dp' => $maDP, ':kh' => $khId]);
$bk = $q->fetch();

if (!$bk) { header('Location: dashboard.php?tab=history'); exit; }

// ── Dịch vụ đã đặt ──────────────────────────────────────────────────────────
$svcQ = $pdo->prepare("
    SELECT ddv.*, dv.TenDV, dv.MoTa AS MoTaDV
    FROM DAT_DICH_VU ddv
    JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    WHERE ddv.MaDP = :dp
");
$svcQ->execute([':dp' => $maDP]);
$svcs = $svcQ->fetchAll();

// ── Hóa đơn (nếu có) ────────────────────────────────────────────────────────
$hdQ = $pdo->prepare("
    SELECT hd.*, nv.HoTen AS TenNV
    FROM HOA_DON hd
    LEFT JOIN NHAN_VIEN nv ON hd.MaNV = nv.MaNV
    WHERE hd.MaDP = :dp
    LIMIT 1
");
$hdQ->execute([':dp' => $maDP]);
$hd = $hdQ->fetch();

// ── Tính toán ────────────────────────────────────────────────────────────────
$nights  = max(1, (int)ceil((strtotime($bk['NgayCheckOut']) - strtotime($bk['NgayCheckIn'])) / 86400));
$conLai  = $hd ? max(0, $hd['TongTien'] - $hd['TienCocDaThu']) : 0;

function parseGiamGiaBD(string $note): float {
    return preg_match('/\[GIAM:(\d+)\]/', $note, $m) ? (float)$m[1] : 0;
}
function parseKMNameBD(string $note): string {
    return preg_match('/\[KM:[^\s]+ ([^\]]+)\]/', $note, $m) ? $m[1] : '';
}

$noteForDisc  = $bk['GhiChu'] ?? '';
$giamGia      = parseGiamGiaBD($noteForDisc);
$kmName       = parseKMNameBD($noteForDisc);
$diemCong     = ($hd && $hd['TrangThai'] === 'Đã thanh toán')
                ? (int)floor($hd['TongTien'] / 1000) : 0;

$status  = $bk['TrangThai'];
$statusColor = [
    'Chờ xác nhận' => ['bg'=>'#fef9ee','border'=>'#fcd34d','text'=>'#92400e','badge'=>'#f59e0b'],
    'Đã nhận phòng'=> ['bg'=>'#eff6ff','border'=>'#93c5fd','text'=>'#1e40af','badge'=>'#3b82f6'],
    'Đã trả phòng' => ['bg'=>'#f0fdf4','border'=>'#86efac','text'=>'#166534','badge'=>'#22c55e'],
    'Đã hủy'       => ['bg'=>'#f9fafb','border'=>'#d1d5db','text'=>'#6b7280','badge'=>'#6b7280'],
];
$sc = $statusColor[$status] ?? ['bg'=>'#f9fafb','border'=>'#d1d5db','text'=>'#6b7280','badge'=>'#6b7280'];

$statusIcon = [
    'Chờ xác nhận' => '⏳',
    'Đã nhận phòng'=> '🏨',
    'Đã trả phòng' => '✅',
    'Đã hủy'       => '❌',
];
$sicon = $statusIcon[$status] ?? '📋';

// ── Xử lý hủy đặt phòng ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'huy') {
    csrfVerify();
    if ($status === 'Chờ xác nhận') {
        $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã hủy' WHERE MaDP=? AND MaKH=?")
            ->execute([$maDP, $khId]);
        header("Location: dashboard.php?tab=history");
        exit;
    }
}

// Ghi chú (loại bỏ tag nội bộ [GIAM:...][KM:...])
$ghiChuHienThi = preg_replace('/\[(GIAM|KM):[^\]]+\]/', '', $bk['GhiChu'] ?? '');
$ghiChuHienThi = trim($ghiChuHienThi, ' |');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Chi Tiết Đặt Phòng <?= htmlspecialchars($maDP) ?> — Easyhome</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --shadow:0 2px 12px rgba(29,78,216,.09);--shadow-lg:0 8px 32px rgba(29,78,216,.13);
  --radius:12px;--font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
*{font-family:var(--font);color:var(--text)}
body{background:var(--bg);min-height:100vh}

/* ── TOPBAR ── */
.topbar{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:60px;box-shadow:0 2px 16px rgba(29,78,216,.3);position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.brand img{width:34px;height:34px;border-radius:8px;object-fit:cover;border:2px solid rgba(255,255,255,.4)}
.brand-name{font-family:var(--serif);font-size:1.1rem;color:#fff}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-user{font-size:.82rem;color:rgba(255,255,255,.85);font-weight:600}
.btn-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:6px 14px;border-radius:7px;font-size:.78rem;font-weight:600;
  text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.28)}
.btn-logout-top{background:transparent;border:1px solid rgba(255,255,255,.25);
  color:rgba(255,255,255,.75);padding:6px 12px;border-radius:7px;font-size:.76rem;
  text-decoration:none;transition:all .2s}
.btn-logout-top:hover{background:rgba(255,255,255,.15);color:#fff}
.btn-print-top{background:#fcd34d;border:1px solid #f59e0b;color:#92400e;
  padding:6px 14px;border-radius:7px;font-size:.78rem;font-weight:700;
  cursor:pointer;font-family:var(--font);transition:all .2s}
.btn-print-top:hover{background:#fbbf24}

/* ── LAYOUT ── */
.page-wrap{max-width:800px;margin:28px auto;padding:0 20px 60px}

/* STATUS BANNER */
.status-banner{border-radius:12px;padding:18px 22px;margin-bottom:22px;
  display:flex;align-items:center;gap:16px}
.sb-icon{font-size:2rem;flex-shrink:0}
.sb-text{}
.sb-title{font-family:var(--serif);font-size:1.1rem;font-weight:600;margin-bottom:3px}
.sb-sub{font-size:.8rem;opacity:.8}
.sb-badge{margin-left:auto;padding:5px 14px;border-radius:20px;
  font-size:.75rem;font-weight:700;color:#fff;white-space:nowrap;flex-shrink:0}

/* ── CARD ── */
.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden}
.card-head{padding:14px 20px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;gap:9px;background:var(--blue-pale)}
.ch-icon{font-size:1rem;flex-shrink:0}
.ch-title{font-weight:700;font-size:.9rem;color:var(--blue-dark)}
.card-body{padding:20px}

/* ── INFO GRID ── */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.info-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.info-item{padding:11px 14px;background:var(--blue-pale);border-radius:9px;border:1px solid var(--border)}
.info-label{font-size:.62rem;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
.info-val{font-size:.9rem;font-weight:600;color:var(--text)}
.info-val.accent{color:var(--blue-dark)}

/* ── SERVICES TABLE ── */
.svc-table{width:100%;border-collapse:collapse;font-size:.85rem}
.svc-table th{padding:9px 14px;border-bottom:2px solid var(--border);
  font-size:.65rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);text-align:left}
.svc-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9}
.svc-table tr:last-child td{border-bottom:none}
.svc-table td.ar{text-align:right;font-weight:700;color:var(--blue-dark)}
.svc-no{text-align:center;padding:20px;color:var(--muted);font-size:.85rem;
  font-style:italic;border-bottom:none !important}

/* ── INVOICE ── */
.inv-table{width:100%;border-collapse:collapse;font-size:.86rem}
.inv-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9}
.inv-table tr:last-child td{border-bottom:none}
.inv-table .row-head{background:var(--blue-pale);font-weight:700;font-size:.88rem}
.inv-table .row-sub td{padding-left:28px;color:var(--muted);font-size:.8rem}
.inv-table .row-disc td{color:#16a34a;font-weight:600}
.inv-table .row-coc td{color:#64748b}
.inv-table .row-remain{background:linear-gradient(135deg,var(--blue-dark),var(--blue));border-radius:0}
.inv-table .row-remain td{color:#fff;font-weight:700;font-size:.95rem;padding:14px}
.td-r{text-align:right}
.td-neg{color:#ef4444;font-weight:700}
.status-paid{background:#dcfce7;color:#166534;padding:2px 10px;border-radius:10px;font-size:.73rem;font-weight:700}
.status-unpaid{background:#fef9c3;color:#92400e;padding:2px 10px;border-radius:10px;font-size:.73rem;font-weight:700}

/* ── POINTS BOX ── */
.points-box{background:linear-gradient(135deg,#fefce8,#fef9c3);
  border:1.5px solid #fcd34d;border-radius:12px;padding:16px 20px;
  margin-bottom:18px;display:flex;align-items:center;gap:14px}
.pts-icon{font-size:2rem;flex-shrink:0}
.pts-label{font-size:.76rem;color:#92400e;margin-bottom:2px;text-transform:uppercase;letter-spacing:1px}
.pts-val{font-size:1.35rem;font-weight:700;color:#78350f}
.pts-sub{font-size:.74rem;color:#92400e;margin-top:2px;opacity:.8}
.pts-total{margin-left:auto;text-align:right;flex-shrink:0}
.pts-total-label{font-size:.72rem;color:#92400e;margin-bottom:2px}
.pts-total-val{font-size:1rem;font-weight:700;color:#78350f}

/* ── ACTIONS ── */
.action-bar{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px}
.btn-cancel{background:#fff0f0;border:1.5px solid #fca5a5;color:#dc2626;
  padding:11px 22px;border-radius:9px;font-size:.84rem;font-weight:700;
  cursor:pointer;transition:all .2s;font-family:var(--font)}
.btn-cancel:hover{background:#fecaca}
.btn-print-inv{background:#fcd34d;border:1.5px solid #f59e0b;color:#92400e;
  padding:11px 22px;border-radius:9px;font-size:.84rem;font-weight:700;
  cursor:pointer;transition:all .2s;font-family:var(--font)}
.btn-print-inv:hover{background:#fbbf24}
.btn-home{background:var(--blue-pale);border:1.5px solid var(--border);color:var(--blue-dark);
  padding:11px 22px;border-radius:9px;font-size:.84rem;font-weight:700;
  text-decoration:none;transition:all .2s;display:inline-block}
.btn-home:hover{border-color:var(--blue);background:var(--blue-mid)}

/* ── BOOKING STEPS ── */
.steps{display:flex;gap:0;margin-bottom:22px}
.step{flex:1;text-align:center;padding:12px 8px;border-bottom:3px solid var(--border);
  font-size:.75rem;color:var(--muted);position:relative}
.step.done{border-bottom-color:#22c55e;color:#16a34a;font-weight:700}
.step.active{border-bottom-color:var(--blue);color:var(--blue);font-weight:700}
.step.canceled{border-bottom-color:#ef4444;color:#dc2626;font-weight:700}
.step-num{width:24px;height:24px;border-radius:50%;background:var(--border);
  color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;
  justify-content:center;margin:0 auto 5px}
.step.done .step-num{background:#22c55e}
.step.active .step-num{background:var(--blue)}
.step.canceled .step-num{background:#ef4444}

/* ── PRINT ── */
@media print {
  body{background:#fff !important}
  .topbar,.action-bar,.steps,.no-print{display:none !important}
  .page-wrap{padding:0;margin:0;max-width:100%}
  .card{box-shadow:none !important;border:1px solid #e5e7eb !important}
  .status-banner{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .inv-table .row-remain{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  @page{margin:1.5cm}
}

@media(max-width:600px){
  .info-grid-3{grid-template-columns:1fr 1fr}
  .info-grid{grid-template-columns:1fr}
  .steps{display:none}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar no-print">
  <a href="../index.php" class="brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome">
    <span class="brand-name">Easyhome</span>
  </a>
  <div class="topbar-right">
    <span class="topbar-user">👤 <?= htmlspecialchars($khName) ?></span>
    <?php if ($hd && $hd['TrangThai'] === 'Đã thanh toán'): ?>
    <button class="btn-print-top" onclick="window.print()">🖨️ In PDF</button>
    <?php endif; ?>
    <a href="dashboard.php?tab=history" class="btn-back">← Lịch Sử</a>
    <a href="../logout.php" class="btn-logout-top">Đăng xuất</a>
  </div>
</div>

<div class="page-wrap">

  <!-- ── BOOKING STEPS ── -->
  <div class="steps no-print">
    <?php
    $stepDefs = [
      ['Đặt phòng', '✓'],
      ['Chờ xác nhận', '1'],
      ['Nhận phòng', '2'],
      ['Trả phòng', '3'],
    ];
    $activeStep = match($status) {
      'Chờ xác nhận'  => 1,
      'Đã nhận phòng' => 2,
      'Đã trả phòng'  => 3,
      'Đã hủy'        => -1,
      default         => 0,
    };
    foreach ($stepDefs as $i => [$label, $num]):
      if ($status === 'Đã hủy') $cls = $i === 0 ? 'done' : 'canceled';
      elseif ($i < $activeStep) $cls = 'done';
      elseif ($i === $activeStep) $cls = 'active';
      else $cls = '';
    ?>
    <div class="step <?= $cls ?>">
      <div class="step-num"><?= $i < $activeStep ? '✓' : $num ?></div>
      <?= $label ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── STATUS BANNER ── -->
  <div class="status-banner" style="background:<?= $sc['bg'] ?>;border:1.5px solid <?= $sc['border'] ?>">
    <div class="sb-icon"><?= $sicon ?></div>
    <div class="sb-text">
      <div class="sb-title" style="color:<?= $sc['text'] ?>">
        <?php if ($status === 'Chờ xác nhận'): ?>Đang chờ nhân viên xác nhận
        <?php elseif ($status === 'Đã nhận phòng'): ?>Đang lưu trú — Chào mừng đến Easyhome!
        <?php elseif ($status === 'Đã trả phòng'): ?>Đã hoàn tất — Cảm ơn quý khách!
        <?php else: ?>Đặt phòng đã bị hủy<?php endif; ?>
      </div>
      <div class="sb-sub" style="color:<?= $sc['text'] ?>">
        Mã đặt phòng: <strong><?= htmlspecialchars($maDP) ?></strong>
        &nbsp;·&nbsp; Đặt lúc <?= date('d/m/Y H:i', strtotime($bk['NgayDat'])) ?>
      </div>
    </div>
    <div class="sb-badge" style="background:<?= $sc['badge'] ?>"><?= $status ?></div>
  </div>

  <!-- ── ĐIỂM TÍCH LŨY (nếu đã thanh toán) ── -->
  <?php if ($diemCong > 0): ?>
  <div class="points-box no-print">
    <div class="pts-icon">⭐</div>
    <div>
      <div class="pts-label">Điểm tích lũy từ lần này</div>
      <div class="pts-val">+<?= number_format($diemCong) ?> điểm</div>
      <div class="pts-sub">1.000đ = 1 điểm · Tổng hóa đơn <?= number_format($hd['TongTien'],0,',','.') ?>đ</div>
    </div>
    <div class="pts-total">
      <div class="pts-total-label">Tổng điểm hiện có</div>
      <div class="pts-total-val">⭐ <?= number_format($bk['TichDiem']) ?> điểm</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── THÔNG TIN PHÒNG ── -->
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">🛏️</span>
      <span class="ch-title">Thông Tin Phòng & Lưu Trú</span>
    </div>
    <div class="card-body">
      <div class="info-grid-3">
        <div class="info-item">
          <div class="info-label">Mã Phòng</div>
          <div class="info-val accent"><?= htmlspecialchars($bk['MaPhong']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Loại Phòng</div>
          <div class="info-val"><?= htmlspecialchars($bk['LoaiPhong']) ?> — Tầng <?= $bk['Tang'] ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Số Đêm</div>
          <div class="info-val accent"><?= $nights ?> đêm</div>
        </div>
        <div class="info-item">
          <div class="info-label">Check-in</div>
          <div class="info-val"><?= date('d/m/Y', strtotime($bk['NgayCheckIn'])) ?></div>
          <div style="font-size:.75rem;color:var(--muted)"><?= date('H:i', strtotime($bk['NgayCheckIn'])) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Check-out</div>
          <div class="info-val"><?= date('d/m/Y', strtotime($bk['NgayCheckOut'])) ?></div>
          <div style="font-size:.75rem;color:var(--muted)"><?= date('H:i', strtotime($bk['NgayCheckOut'])) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Số Khách</div>
          <div class="info-val"><?= $bk['SoLuongKhach'] ?> người</div>
        </div>
      </div>
      <?php if ($ghiChuHienThi): ?>
      <div style="margin-top:12px;padding:10px 14px;background:var(--blue-pale);
                  border-radius:8px;font-size:.83rem;color:var(--muted);border:1px solid var(--border)">
        📝 <strong>Ghi chú:</strong> <?= htmlspecialchars($ghiChuHienThi) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── DỊCH VỤ ── -->
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">✨</span>
      <span class="ch-title">Dịch Vụ Thêm</span>
    </div>
    <div class="card-body" style="padding:0">
      <table class="svc-table">
        <thead>
          <tr>
            <th>Dịch vụ</th>
            <th style="text-align:right">Đơn giá</th>
            <th style="text-align:right">Số lượng</th>
            <th style="text-align:right">Thành tiền</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($svcs): ?>
          <?php foreach ($svcs as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['TenDV']) ?></td>
            <td class="ar" style="color:var(--muted)"><?= number_format($s['ThanhTien'] / max(1,$s['SoLuong']),0,',','.') ?>đ</td>
            <td class="ar" style="color:var(--muted)">×<?= $s['SoLuong'] ?></td>
            <td class="ar"><?= number_format($s['ThanhTien'],0,',','.') ?>đ</td>
          </tr>
          <?php endforeach; ?>
          <?php else: ?>
          <tr><td colspan="4" class="svc-no">Không có dịch vụ thêm</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── HÓA ĐƠN ── -->
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">🧾</span>
      <span class="ch-title">Chi Tiết Thanh Toán</span>
      <?php if ($hd): ?>
      <span style="margin-left:auto;font-size:.74rem;color:var(--muted)">Mã HĐ: <strong><?= $hd['MaHD'] ?></strong></span>
      <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0">
      <table class="inv-table">
        <?php
        $tienPhong = $hd ? $hd['TienPhong'] : ($bk['GiaPhong'] * $nights);
        $tienDV    = $hd ? $hd['TienDichVu'] : array_sum(array_column($svcs,'ThanhTien'));
        $phuPhi    = $hd ? ($hd['PhuPhi'] ?? 0) : 0;
        $tongTien  = $hd ? $hd['TongTien'] : $bk['TongGia'];
        $tienCoc   = $bk['TienCoc'];
        ?>
        <tr>
          <td>🛏️ Tiền phòng (<?= $nights ?> đêm × <?= number_format($bk['GiaPhong'],0,',','.') ?>đ)</td>
          <td class="td-r"><?= number_format($tienPhong,0,',','.') ?>đ</td>
        </tr>
        <?php if ($svcs): ?>
        <?php foreach ($svcs as $s): ?>
        <tr class="row-sub">
          <td>✨ <?= htmlspecialchars($s['TenDV']) ?></td>
          <td class="td-r"><?= number_format($s['ThanhTien'],0,',','.') ?>đ</td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($phuPhi > 0): ?>
        <tr>
          <td>📌 Phụ phí</td>
          <td class="td-r"><?= number_format($phuPhi,0,',','.') ?>đ</td>
        </tr>
        <?php endif; ?>
        <?php if ($giamGia > 0): ?>
        <tr class="row-disc">
          <td>🎁 Giảm giá<?= $kmName ? " ({$kmName})" : '' ?></td>
          <td class="td-r">-<?= number_format($giamGia,0,',','.') ?>đ</td>
        </tr>
        <?php endif; ?>
        <tr class="row-head">
          <td>Tổng Cộng</td>
          <td class="td-r"><?= number_format($tongTien,0,',','.') ?>đ</td>
        </tr>
        <tr class="row-coc">
          <td>⚡ Tiền cọc đã đặt (30%)</td>
          <td class="td-r td-neg">-<?= number_format($tienCoc,0,',','.') ?>đ</td>
        </tr>
        <?php if ($hd): ?>
        <tr class="row-remain">
          <td>
            💰
            <?= $hd['TrangThai'] === 'Đã thanh toán' ? 'Đã thanh toán đầy đủ' : 'Còn lại cần thanh toán' ?>
            <?php if ($hd['TrangThai'] === 'Đã thanh toán'): ?>
            <span style="background:rgba(255,255,255,.25);padding:2px 8px;border-radius:8px;font-size:.72rem;margin-left:6px">
              via <?= htmlspecialchars($hd['PhuongThucTT'] ?? 'Tiền mặt') ?>
            </span>
            <?php endif; ?>
          </td>
          <td class="td-r"><?= number_format($conLai,0,',','.') ?>đ</td>
        </tr>
        <?php else: ?>
        <tr>
          <td colspan="2" style="text-align:center;padding:14px;color:var(--muted);font-size:.82rem;font-style:italic">
            Hóa đơn sẽ được lập khi nhân viên xử lý check-out
          </td>
        </tr>
        <?php endif; ?>
      </table>
      <?php if ($hd): ?>
      <div style="padding:12px 20px;border-top:1px solid var(--border);
                  display:flex;justify-content:space-between;align-items:center;
                  background:var(--blue-pale);font-size:.82rem">
        <span style="color:var(--muted)">
          Trạng thái HĐ:
          <?php if ($hd['TrangThai'] === 'Đã thanh toán'): ?>
          <span class="status-paid">✓ Đã thanh toán</span>
          <?php else: ?>
          <span class="status-unpaid">⏳ Chưa thanh toán</span>
          <?php endif; ?>
        </span>
        <?php if ($hd['TenNV']): ?>
        <span style="color:var(--muted)">NV xử lý: <strong><?= htmlspecialchars($hd['TenNV']) ?></strong></span>
        <?php endif; ?>
        <span style="color:var(--muted)">Ngày lập: <strong><?= date('d/m/Y H:i', strtotime($hd['NgayLap'])) ?></strong></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── THÔNG TIN LIÊN HỆ ── -->
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">👤</span>
      <span class="ch-title">Thông Tin Khách Hàng</span>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Họ và Tên</div>
          <div class="info-val"><?= htmlspecialchars($bk['HoTen']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Số Điện Thoại</div>
          <div class="info-val"><?= htmlspecialchars($bk['SoDienThoai'] ?? '—') ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Email</div>
          <div class="info-val"><?= htmlspecialchars($bk['Email'] ?? '—') ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">CCCD / CMND</div>
          <div class="info-val"><?= htmlspecialchars($bk['CCCD'] ?? '—') ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── ACTIONS ── -->
  <div class="action-bar no-print">
    <?php if ($hd && $hd['TrangThai'] === 'Đã thanh toán'): ?>
    <button class="btn-print-inv" onclick="window.print()">🖨️ In / Lưu PDF</button>
    <?php endif; ?>
    <a href="dashboard.php?tab=history" class="btn-home">← Về Lịch Sử</a>
    <?php if ($status === 'Chờ xác nhận'): ?>
    <form method="POST" onsubmit="return confirm('Xác nhận hủy đặt phòng này? Hành động không thể khôi phục.')">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="huy">
      <button type="submit" class="btn-cancel">✕ Hủy Đặt Phòng</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- ── PRINT INVOICE FULL (chỉ khi có hóa đơn và đã thanh toán) ── -->
  <?php if ($hd && $hd['TrangThai'] === 'Đã thanh toán'): ?>
  <div style="display:none" class="print-only-block"><!-- hidden, shown only in print via @media print --></div>
  <?php endif; ?>

</div><!-- /page-wrap -->

<style>
/* Bổ sung print: in đầy đủ kể cả các card -->
@media print {
  .action-bar, .no-print { display: none !important; }
  .steps { display: none !important; }
  .points-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .status-banner { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .row-remain { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

</body>
</html>
