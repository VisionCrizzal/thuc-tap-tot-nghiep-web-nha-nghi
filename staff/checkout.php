<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['staff_id'])) { header('Location: ../login.php'); exit; }

$staffMaNV = $_SESSION['staff_maNV'];
$maDP  = trim($_GET['dp'] ?? '');
$done  = isset($_GET['done']);
if (!$maDP) { header('Location: dashboard.php'); exit; }

/* ── Lấy thông tin đặt phòng ─────────────────────────────────────────────── */
$q = $pdo->prepare("
    SELECT dp.*, kh.HoTen AS TenKH, kh.SoDienThoai AS SdtKH,
           kh.Email AS EmailKH, kh.CCCD,
           p.LoaiPhong, p.Tang, p.GiaPhong, p.SoNguoiToiDa
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    WHERE dp.MaDP = :id
");
$q->execute([':id' => $maDP]);
$bk = $q->fetch();
if (!$bk) { header("Location: dashboard.php"); exit; }

/* Redirect tự động nếu truy cập không hợp lệ */
if (!$done) {
    if ($bk['TrangThai'] === 'Đã trả phòng') {
        header("Location: checkout.php?dp={$maDP}&done=1"); exit;
    }
    if ($bk['TrangThai'] !== 'Đã nhận phòng') {
        header("Location: dashboard.php?tab=bookings"); exit;
    }
}

/* ── Dịch vụ đã đặt ─────────────────────────────────────────────────────── */
$svcQ = $pdo->prepare("
    SELECT ddv.*, dv.TenDV
    FROM DAT_DICH_VU ddv JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    WHERE ddv.MaDP = :id
");
$svcQ->execute([':id' => $maDP]);
$svcs = $svcQ->fetchAll();

/* ── Lấy hoặc tạo HOA_DON ────────────────────────────────────────────────── */
$fetchHD = function() use ($pdo, $maDP) {
    $hdQ = $pdo->prepare("
        SELECT hd.*, nv.HoTen AS TenNV
        FROM HOA_DON hd
        LEFT JOIN NHAN_VIEN nv ON hd.MaNV = nv.MaNV
        WHERE hd.MaDP = :id
        LIMIT 1
    ");
    $hdQ->execute([':id' => $maDP]);
    return $hdQ->fetch();
};

$hd = $fetchHD();

if (!$hd && !$done) {
    /* Check-in được xử lý qua nút cũ trong dashboard → tự tạo HOA_DON */
    $nights    = max(1, (int)ceil((strtotime($bk['NgayCheckOut']) - strtotime($bk['NgayCheckIn'])) / 86400));
    $tienPhong = $bk['GiaPhong'] * $nights;
    $tienDV    = array_sum(array_column($svcs, 'ThanhTien'));
    $tongTien  = $tienPhong + $tienDV;

    $lastHD = $pdo->query("SELECT MaHD FROM HOA_DON ORDER BY NgayLap DESC LIMIT 1")->fetchColumn();
    $hdNum  = $lastHD ? (int)preg_replace('/\D/', '', $lastHD) + 1 : 1;
    $maHD   = 'HD' . str_pad($hdNum, 3, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO HOA_DON (MaHD,MaDP,MaNV,TienPhong,TienDichVu,PhuPhi,TienCocDaThu,TongTien,TrangThai,GhiChu)
        VALUES (?,?,?,?,?,0,0,?,'Chưa thanh toán','Tự động tạo khi check-out')
    ")->execute([$maHD, $maDP, $staffMaNV, $tienPhong, $tienDV, $tongTien]);

    $hd = $fetchHD();
}

/* ── Helper: parse giảm giá từ GhiChu ───────────────────────────────────── */
function parseGiamGia(string $note): float {
    return preg_match('/\[GIAM:(\d+)\]/', $note, $m) ? (float)$m[1] : 0;
}
function parseKMName(string $note): string {
    return preg_match('/\[KM:[^\s]+ ([^\]]+)\]/', $note, $m) ? $m[1] : '';
}

$giamGiaDisp = $hd ? parseGiamGia($hd['GhiChu'] ?? '') : 0;
$kmNameDisp  = $hd ? parseKMName($hd['GhiChu'] ?? '')  : '';

$nights  = max(1, (int)ceil((strtotime($bk['NgayCheckOut']) - strtotime($bk['NgayCheckIn'])) / 86400));
$conLai  = $hd ? max(0, $hd['TongTien'] - $hd['TienCocDaThu']) : 0;

/* ── POST: xử lý thanh toán checkout ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    csrfVerify();
    $phuongThuc  = trim($_POST['phuong_thuc'] ?? 'Tiền mặt');
    $phuPhiExtra = max(0, (float)($_POST['phu_phi_extra'] ?? 0));
    $ghiChuExtra = trim($_POST['ghi_chu'] ?? '');

    $newTongTien = $hd['TongTien'] + $phuPhiExtra;
    $newConLai   = max(0, $newTongTien - $hd['TienCocDaThu']);

    $pdo->prepare("
        UPDATE HOA_DON
        SET PhuongThucTT = ?, PhuPhi = PhuPhi + ?, TongTien = ?,
            TrangThai = 'Đã thanh toán',
            GhiChu = CONCAT(COALESCE(GhiChu,''), IF(:ghi<>'', CONCAT(' | ', :ghi2), ''))
        WHERE MaDP = :dp
    ")->execute([$phuongThuc, $phuPhiExtra, $newTongTien, $ghiChuExtra, $ghiChuExtra, $maDP]);

    $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã trả phòng', MaNV_XuLy=? WHERE MaDP=?")
        ->execute([$staffMaNV, $maDP]);
    $pdo->prepare("UPDATE PHONG SET TinhTrang='Đang dọn' WHERE MaPhong=?")
        ->execute([$bk['MaPhong']]);

    // ── Cộng điểm tích lũy cho KH: 1.000đ = 1 điểm ──────────────────────────
    $diemConged = (int)floor($newTongTien / 1000);
    if ($diemConged > 0 && !empty($bk['MaKH'])) {
        $pdo->prepare("UPDATE KHACH_HANG SET TichDiem = TichDiem + ? WHERE MaKH = ?")
            ->execute([$diemConged, $bk['MaKH']]);
    }

    header("Location: checkout.php?dp={$maDP}&done=1");
    exit;
}

/* Cập nhật lại $hd sau POST nếu cần (done=1) */
if ($done) $hd = $fetchHD();
$conLai = $hd ? max(0, $hd['TongTien'] - $hd['TienCocDaThu']) : 0;
$giamGiaDisp = $hd ? parseGiamGia($hd['GhiChu'] ?? '') : 0;
$kmNameDisp  = $hd ? parseKMName($hd['GhiChu'] ?? '')  : '';
$diemCong    = ($done && $hd && $hd['TrangThai'] === 'Đã thanh toán')
               ? (int)floor($hd['TongTien'] / 1000) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Check-out <?= htmlspecialchars($maDP) ?> — Easyhome</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --shadow:0 2px 12px rgba(29,78,216,.09);--radius:12px;
  --font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
*{font-family:var(--font);color:var(--text)}
body{background:var(--bg);min-height:100vh}

/* ── TOPBAR ── */
.topbar{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:60px;box-shadow:0 2px 16px rgba(29,78,216,.3);position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.brand img{width:34px;height:34px;border-radius:8px;object-fit:cover}
.brand-name{font-family:var(--serif);font-size:1.1rem;color:#fff}
.topbar-btns{display:flex;gap:9px}
.btn-back,.btn-print{padding:6px 14px;border-radius:7px;font-size:.78rem;font-weight:600;
  text-decoration:none;transition:all .2s;cursor:pointer;border:none;font-family:var(--font)}
.btn-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff}
.btn-back:hover{background:rgba(255,255,255,.28)}
.btn-print{background:#fcd34d;color:#92400e;border:1px solid #f59e0b;font-weight:700}
.btn-print:hover{background:#fbbf24}

/* ── LAYOUT ── */
.page-head{max-width:960px;margin:24px auto 0;padding:0 20px}
.page-title{font-family:var(--serif);font-size:1.45rem;color:var(--blue-dark);margin-bottom:4px}
.page-sub{font-size:.82rem;color:var(--muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dp-badge{background:var(--blue);color:#fff;padding:2px 10px;border-radius:12px;font-size:.74rem;font-weight:700}
.done-badge{background:#22c55e;color:#fff;padding:2px 10px;border-radius:12px;font-size:.74rem;font-weight:700}

.main{max-width:960px;margin:18px auto;padding:0 20px 60px}

.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden}
.card-head{padding:13px 18px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;gap:9px;background:var(--blue-pale)}
.ch-icon{font-size:1rem}
.ch-title{font-weight:700;font-size:.88rem;color:var(--blue-dark)}
.card-body{padding:18px}

.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.info-item{padding:10px 13px;background:var(--blue-pale);border-radius:9px;border:1px solid var(--border)}
.info-label{font-size:.62rem;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
.info-val{font-size:.88rem;font-weight:600}

/* Invoice detail table */
.inv-table{width:100%;border-collapse:collapse;font-size:.85rem}
.inv-table td{padding:9px 13px;border-bottom:1px solid #f1f5f9}
.inv-table tr:last-child td{border-bottom:none}
.inv-table .row-total{background:var(--blue-pale);font-weight:700}
.inv-table .row-coc{color:var(--muted)}
.inv-table .row-remain{background:linear-gradient(135deg,var(--blue-dark),var(--blue));color:#fff;font-size:.95rem;font-weight:700}
.inv-table .row-remain td{color:#fff}
.inv-table .row-disc td{color:#16a34a}
.td-r{text-align:right;font-weight:700}
.td-neg{color:#ef4444;font-weight:700}
.td-pos{color:#1d4ed8;font-weight:700}

/* Payment methods */
.pay-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:7px;margin-bottom:12px}
.pay-card{border:2px solid var(--border);border-radius:10px;padding:10px 5px;
  text-align:center;cursor:pointer;transition:all .22s;background:#fff}
.pay-card:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.pay-card.active{border-color:var(--blue);background:var(--blue-pale)}
.pay-icon{font-size:1.4rem;margin-bottom:3px}
.pay-lbl{font-size:.63rem;font-weight:700;color:var(--text);line-height:1.3}

.pay-panel{display:none;padding:13px;background:var(--blue-pale);border-radius:9px;
  border:1.5px solid var(--border);margin-bottom:12px;animation:fadeIn .2s ease}
.pay-panel.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}

.card-types{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px}
.ctype-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:7px;
  font-size:.73rem;font-weight:700;cursor:pointer;background:#fff;transition:all .2s}
.ctype-btn:hover{border-color:var(--blue-light);color:var(--blue)}
.ctype-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}

.qr-tabs{display:flex;gap:6px;margin-bottom:12px}
.qr-tab{padding:5px 12px;border-radius:7px;border:1.5px solid var(--border);
  font-size:.73rem;font-weight:700;cursor:pointer;background:#fff;transition:all .2s}
.qr-tab:hover{border-color:var(--blue-light);color:var(--blue)}
.qr-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.qr-pane{display:none;text-align:center}
.qr-pane.active{display:block}
.qr-img{border-radius:10px;border:3px solid var(--border);width:190px;height:190px}
.qr-info{margin-top:9px;font-size:.77rem;color:var(--muted);line-height:1.7}
.qr-amt{font-size:1.1rem;font-weight:700;color:var(--blue-dark);margin-top:5px}

.wallet-row{display:flex;align-items:center;gap:11px;padding:11px;
  background:#fff;border-radius:9px;border:1.5px solid var(--border);margin-bottom:7px}
.w-icon{font-size:1.8rem;flex-shrink:0}
.w-name{font-size:.85rem;font-weight:700;margin-bottom:2px}
.w-desc{font-size:.73rem;color:var(--muted)}

.form-group{margin-bottom:13px}
.form-label{display:block;font-size:.64rem;font-weight:700;letter-spacing:1.8px;
  text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.form-input{padding:10px 13px;font-family:var(--font);font-size:.9rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:8px;outline:none;transition:all .2s}
.form-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}
.form-textarea{width:100%;padding:10px 13px;font-family:var(--font);font-size:.9rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:8px;outline:none;resize:vertical;min-height:52px;transition:all .2s}
.form-textarea:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}

.btn-row{display:flex;gap:12px;margin-top:6px}
.btn-submit{flex:1;padding:13px;font-family:var(--font);font-size:.88rem;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;color:#fff;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border:none;border-radius:10px;cursor:pointer;transition:all .3s;
  box-shadow:0 4px 18px rgba(29,78,216,.38)}
.btn-submit:hover{transform:translateY(-1px)}

/* ═══════════════════════════════════════════════
   INVOICE (done state + print)
═══════════════════════════════════════════════ */
.invoice-wrap{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);padding:0;overflow:hidden;max-width:700px;margin:0 auto}

.inv-header{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:28px 36px;color:#fff;text-align:center}
.inv-hotel-name{font-family:var(--serif);font-size:1.7rem;margin-bottom:4px}
.inv-hotel-sub{font-size:.8rem;color:rgba(255,255,255,.75);line-height:1.7}

.inv-title-bar{background:var(--blue-pale);padding:14px 36px;
  text-align:center;border-bottom:2px solid var(--border)}
.inv-title{font-family:var(--serif);font-size:1.15rem;color:var(--blue-dark);
  letter-spacing:3px;text-transform:uppercase}
.inv-meta{font-size:.78rem;color:var(--muted);margin-top:4px}

.inv-section{padding:18px 36px}
.inv-section + .inv-section{border-top:1px solid var(--border)}
.inv-section-title{font-size:.65rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:var(--blue);margin-bottom:12px}

.inv-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.inv-field{display:flex;flex-direction:column;gap:2px}
.inv-field-lbl{font-size:.65rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
.inv-field-val{font-size:.88rem;font-weight:600}

.inv-charges{width:100%;border-collapse:collapse;font-size:.86rem}
.inv-charges td,.inv-charges th{padding:8px 0;border-bottom:1px solid #f1f5f9}
.inv-charges th{font-size:.65rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:700}
.inv-charges .itr-sub{color:var(--muted);font-size:.8rem}
.inv-charges .itr-total td{font-weight:700;border-top:2px solid var(--border);
  border-bottom:none;padding-top:10px;font-size:.9rem}
.inv-charges .itr-disc td{color:#16a34a;font-weight:600}
.inv-charges .itr-coc td{color:var(--muted)}
.inv-charges td.ar{text-align:right}
.inv-remain{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:10px;padding:16px 22px;display:flex;justify-content:space-between;
  align-items:center;color:#fff;margin:16px 0 0}
.inv-remain-lbl{font-size:.88rem;color:rgba(255,255,255,.85)}
.inv-remain-val{font-size:1.35rem;font-weight:700}

.inv-pay-method{background:var(--blue-pale);border-radius:8px;padding:11px 16px;
  font-size:.85rem;margin-top:12px;border:1px solid var(--border)}
.inv-pay-lbl{font-size:.65rem;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:4px}
.inv-pay-val{font-weight:700;color:var(--blue-dark)}

.inv-footer{padding:18px 36px;background:var(--blue-pale);border-top:2px solid var(--border);text-align:center}
.inv-footer-main{font-family:var(--serif);font-size:1rem;color:var(--blue-dark);margin-bottom:6px}
.inv-footer-sub{font-size:.75rem;color:var(--muted);line-height:1.6}

.inv-sig{padding:18px 36px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
.sig-box{text-align:center}
.sig-title{font-size:.65rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:36px}
.sig-line{border-top:1px dashed var(--border);padding-top:8px;font-size:.75rem;color:var(--muted)}

.success-banner{background:#f0fdf4;border:1.5px solid #86efac;border-left:4px solid #22c55e;
  padding:14px 18px;border-radius:10px;font-size:.88rem;color:#166534;
  margin-bottom:22px;display:flex;align-items:center;gap:10px}

.action-bar{display:flex;gap:12px;justify-content:center;margin:24px 0;flex-wrap:wrap}
.btn-invoice-print{padding:12px 28px;background:#fcd34d;color:#92400e;border:1.5px solid #f59e0b;
  border-radius:9px;font-weight:700;font-size:.88rem;cursor:pointer;transition:all .2s;
  font-family:var(--font)}
.btn-invoice-print:hover{background:#fbbf24}
.btn-dashboard{padding:12px 28px;background:var(--blue-pale);color:var(--blue-dark);
  border:1.5px solid var(--border);border-radius:9px;font-weight:700;font-size:.88rem;
  text-decoration:none;transition:all .2s}
.btn-dashboard:hover{border-color:var(--blue);background:var(--blue-mid)}

/* ── PRINT STYLES ── */
@media print {
  body { background: white !important; }
  .topbar,.page-head,.action-bar,.no-print { display: none !important; }
  .main { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
  .invoice-wrap { box-shadow: none !important; border: none !important;
    border-radius: 0 !important; max-width: 100% !important; }
  .inv-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .inv-remain  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  @page { margin: 1cm; }
}

@media(max-width:640px){
  .pay-grid{grid-template-columns:repeat(3,1fr)}
  .info-grid{grid-template-columns:1fr 1fr}
  .inv-info-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="../index.php" class="brand">
    <img src="../assets/images/logo.jpg" alt="">
    <span class="brand-name">Easyhome</span>
  </a>
  <div class="topbar-btns">
    <?php if ($done): ?>
    <button class="btn-print" onclick="window.print()">🖨️ In Hóa Đơn PDF</button>
    <?php endif; ?>
    <a href="dashboard.php?tab=bookings" class="btn-back">← Dashboard</a>
  </div>
</div>

<?php if (!$done): ?>
<!-- ═══════════════════════════════════════════════════════════
     CHECKOUT FORM (payment screen)
═══════════════════════════════════════════════════════════ -->
<div class="page-head no-print">
  <div class="page-title">⬆️ Check-out Trả Phòng</div>
  <div class="page-sub">
    <span class="dp-badge"><?= htmlspecialchars($maDP) ?></span>
    Thu khoản còn lại và hoàn tất thanh toán &nbsp;·&nbsp; <?= date('d/m/Y H:i') ?>
  </div>
</div>

<div class="main no-print">

  <!-- Thông tin đặt phòng -->
  <div class="card">
    <div class="card-head"><span class="ch-icon">📋</span><span class="ch-title">Thông Tin Lưu Trú</span></div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Khách Hàng</div>
          <div class="info-val"><?= htmlspecialchars($bk['TenKH']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Phòng</div>
          <div class="info-val"><?= $bk['MaPhong'] ?> — <?= $bk['LoaiPhong'] ?> (T<?= $bk['Tang'] ?>)</div>
        </div>
        <div class="info-item">
          <div class="info-label">Thời Gian Lưu Trú</div>
          <div class="info-val"><?= $nights ?> đêm</div>
        </div>
        <div class="info-item">
          <div class="info-label">Check-in</div>
          <div class="info-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckIn'])) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Check-out</div>
          <div class="info-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckOut'])) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Mã Hóa Đơn</div>
          <div class="info-val" style="color:var(--blue-dark)"><?= $hd ? $hd['MaHD'] : '—' ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Chi tiết hóa đơn -->
  <div class="card">
    <div class="card-head"><span class="ch-icon">🧾</span><span class="ch-title">Chi Tiết Hóa Đơn</span></div>
    <div class="card-body">
      <table class="inv-table">
        <tr>
          <td>🛏️ Tiền phòng (<?= $nights ?> đêm × <?= number_format($bk['GiaPhong'],0,',','.') ?>đ)</td>
          <td class="td-r"><?= number_format($hd ? $hd['TienPhong'] : ($bk['GiaPhong']*$nights),0,',','.') ?>đ</td>
        </tr>
        <?php if ($svcs): ?>
        <?php foreach ($svcs as $s): ?>
        <tr><td style="padding-left:20px;color:var(--muted);font-size:.82rem">✨ <?= htmlspecialchars($s['TenDV']) ?></td>
            <td class="td-r" style="color:var(--muted)"><?= number_format($s['ThanhTien'],0,',','.') ?>đ</td></tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($hd && $hd['PhuPhi'] > 0): ?>
        <tr><td>📌 Phụ phí</td><td class="td-r"><?= number_format($hd['PhuPhi'],0,',','.') ?>đ</td></tr>
        <?php endif; ?>
        <?php if ($giamGiaDisp > 0): ?>
        <tr><td style="color:#16a34a">🎁 Giảm giá<?= $kmNameDisp ? " ({$kmNameDisp})" : '' ?></td>
            <td class="td-r" style="color:#16a34a">-<?= number_format($giamGiaDisp,0,',','.') ?>đ</td></tr>
        <?php endif; ?>
        <tr class="row-total">
          <td><strong>Tổng Tiền</strong></td>
          <td class="td-r"><strong><?= number_format($hd ? $hd['TongTien'] : 0,0,',','.') ?>đ</strong></td>
        </tr>
        <tr class="row-coc">
          <td style="color:var(--muted)">⚡ Tiền cọc đã thu</td>
          <td class="td-r td-neg">-<?= number_format($hd ? $hd['TienCocDaThu'] : 0,0,',','.') ?>đ</td>
        </tr>
        <tr class="row-remain">
          <td><strong>💰 CÒN LẠI PHẢI TRẢ</strong></td>
          <td class="td-r" style="font-size:1.05rem"><strong><?= number_format($conLai,0,',','.') ?>đ</strong></td>
        </tr>
      </table>
    </div>
  </div>

  <!-- Form thanh toán -->
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="checkout">
    <input type="hidden" name="phuong_thuc" id="hidPT" value="Tiền mặt">

    <!-- Phụ phí thêm -->
    <div class="card">
      <div class="card-head"><span class="ch-icon">➕</span><span class="ch-title">Phụ Phí Phát Sinh Khi Trả Phòng</span></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Phụ Phí Thêm (hư hỏng, trả muộn...)</label>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="number" name="phu_phi_extra" id="phuPhiExtra" class="form-input"
                     value="0" min="0" step="10000" style="width:160px"
                     oninput="updateConLai()">
              <span style="font-size:.8rem;color:var(--muted)">đ</span>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Ghi Chú</label>
            <textarea name="ghi_chu" class="form-textarea" placeholder="Lý do phụ phí, ghi chú..."></textarea>
          </div>
        </div>
        <div style="margin-top:14px;background:linear-gradient(135deg,var(--blue-dark),var(--blue));
                    border-radius:9px;padding:14px 18px;display:flex;justify-content:space-between;color:#fff">
          <span style="font-size:.88rem;color:rgba(255,255,255,.85)">💰 Tổng còn lại phải thu:</span>
          <span style="font-size:1.2rem;font-weight:700" id="displayConLai"><?= number_format($conLai,0,',','.') ?>đ</span>
        </div>
      </div>
    </div>

    <!-- Phương thức thanh toán -->
    <div class="card">
      <div class="card-head"><span class="ch-icon">💳</span><span class="ch-title">Phương Thức Thanh Toán</span></div>
      <div class="card-body">
        <div class="pay-grid">
          <div class="pay-card active" onclick="selectPM(this,'cash','Tiền mặt')">
            <div class="pay-icon">💵</div><div class="pay-lbl">Tiền Mặt</div>
          </div>
          <div class="pay-card" onclick="selectPM(this,'card','Quẹt thẻ')">
            <div class="pay-icon">💳</div><div class="pay-lbl">Quẹt Thẻ</div>
          </div>
          <div class="pay-card" onclick="selectPM(this,'qr','QR Code')">
            <div class="pay-icon">📱</div><div class="pay-lbl">QR Code</div>
          </div>
          <div class="pay-card" onclick="selectPM(this,'apple','Apple Pay')">
            <div class="pay-icon">🍎</div><div class="pay-lbl">Apple Pay</div>
          </div>
          <div class="pay-card" onclick="selectPM(this,'google','Google Pay')">
            <div class="pay-icon">🤖</div><div class="pay-lbl">Google Pay</div>
          </div>
          <div class="pay-card" onclick="selectPM(this,'samsung','Samsung Pay')">
            <div class="pay-icon">📲</div><div class="pay-lbl">Samsung Pay</div>
          </div>
        </div>

        <!-- Cash -->
        <div class="pay-panel active" id="pp-cash">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:center">
            <div>
              <div style="font-size:.76rem;color:var(--muted);margin-bottom:6px">Số tiền khách trả</div>
              <div style="font-size:1.2rem;font-weight:700;color:var(--blue-dark)" id="cashAmt"><?= number_format($conLai,0,',','.') ?>đ</div>
            </div>
            <div>
              <div class="form-label" style="margin-bottom:6px">Khách đưa (để tính tiền thừa)</div>
              <input type="number" id="cashGiven" class="form-input" placeholder="0"
                     min="0" step="1000" oninput="calcChange()" style="width:160px">
              <div style="font-size:.8rem;color:var(--muted);margin-top:5px">Tiền thừa trả lại:
                <strong id="cashChange" style="color:var(--blue)">—</strong>
              </div>
            </div>
          </div>
        </div>

        <!-- Card -->
        <div class="pay-panel" id="pp-card">
          <div style="font-size:.67rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:9px">Loại thẻ</div>
          <div class="card-types">
            <button type="button" class="ctype-btn active" onclick="selCardType(this,'Visa')">VISA</button>
            <button type="button" class="ctype-btn" onclick="selCardType(this,'Mastercard')">Mastercard</button>
            <button type="button" class="ctype-btn" onclick="selCardType(this,'JCB')">JCB</button>
            <button type="button" class="ctype-btn" onclick="selCardType(this,'Napas ATM')">Napas ATM</button>
            <button type="button" class="ctype-btn" onclick="selCardType(this,'Thẻ tín dụng')">Tín dụng</button>
            <button type="button" class="ctype-btn" onclick="selCardType(this,'Thẻ ghi nợ')">Ghi nợ</button>
          </div>
          <div style="background:#fef9ee;border:1px solid #fcd34d;border-radius:8px;padding:10px 13px;font-size:.79rem;color:#92400e;">
            💳 Đưa thẻ vào máy POS · Số tiền: <strong id="cardAmt"><?= number_format($conLai,0,',','.') ?>đ</strong>
          </div>
        </div>

        <!-- QR -->
        <div class="pay-panel" id="pp-qr">
          <div class="qr-tabs">
            <button type="button" class="qr-tab active" onclick="selQR(this,'momo','QR MoMo')">MoMo</button>
            <button type="button" class="qr-tab" onclick="selQR(this,'zalo','QR ZaloPay')">ZaloPay</button>
            <button type="button" class="qr-tab" onclick="selQR(this,'bank','QR Banking')">Banking</button>
          </div>
          <div class="qr-pane active" id="qp-momo">
            <img id="qr-momo" class="qr-img"
                 src="https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=<?= urlencode('MoMo|Easyhome Hotel|0768466686|'.$maDP.'|'.$conLai) ?>"
                 alt="MoMo QR">
            <div class="qr-info">📱 MoMo · SĐT: <strong>0768 466 686</strong><br>Tên: <strong>EASYHOME HOTEL</strong> · Nội dung: <strong><?= $maDP ?></strong></div>
            <div class="qr-amt" id="qa-momo"><?= number_format($conLai,0,',','.') ?>đ</div>
          </div>
          <div class="qr-pane" id="qp-zalo" style="display:none">
            <img id="qr-zalo" class="qr-img"
                 src="https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=<?= urlencode('ZaloPay|Easyhome Hotel|0768466686|'.$maDP.'|'.$conLai) ?>"
                 alt="ZaloPay QR">
            <div class="qr-info">📱 ZaloPay · SĐT: <strong>0768 466 686</strong><br>Tên: <strong>EASYHOME HOTEL</strong> · Nội dung: <strong><?= $maDP ?></strong></div>
            <div class="qr-amt" id="qa-zalo"><?= number_format($conLai,0,',','.') ?>đ</div>
          </div>
          <div class="qr-pane" id="qp-bank" style="display:none">
            <img id="qr-bank" class="qr-img"
                 src="https://img.vietqr.io/image/VCB-9999999999-compact2.png?amount=<?= $conLai ?>&addInfo=<?= urlencode($maDP) ?>&accountName=EASYHOME+HOTEL"
                 alt="VietQR">
            <div class="qr-info">🏦 Vietcombank · STK: <strong>9999 9999 99</strong><br>Tên: <strong>EASYHOME HOTEL</strong> · Nội dung: <strong><?= $maDP ?></strong></div>
            <div class="qr-amt" id="qa-bank"><?= number_format($conLai,0,',','.') ?>đ</div>
          </div>
        </div>

        <!-- Apple -->
        <div class="pay-panel" id="pp-apple">
          <div class="wallet-row">
            <div class="w-icon">🍎</div>
            <div><div class="w-name">Apple Pay</div><div class="w-desc">Khách chạm iPhone / Apple Watch vào máy POS NFC</div></div>
          </div>
          <div style="text-align:right;font-weight:700;font-size:1.1rem;color:var(--blue-dark)" id="wa-apple"><?= number_format($conLai,0,',','.') ?>đ</div>
        </div>

        <!-- Google -->
        <div class="pay-panel" id="pp-google">
          <div class="wallet-row">
            <div class="w-icon">🤖</div>
            <div><div class="w-name">Google Pay / Google Wallet</div><div class="w-desc">Khách chạm điện thoại Android vào máy POS NFC</div></div>
          </div>
          <div style="text-align:right;font-weight:700;font-size:1.1rem;color:var(--blue-dark)" id="wa-google"><?= number_format($conLai,0,',','.') ?>đ</div>
        </div>

        <!-- Samsung -->
        <div class="pay-panel" id="pp-samsung">
          <div class="wallet-row">
            <div class="w-icon">📲</div>
            <div><div class="w-name">Samsung Pay</div><div class="w-desc">Khách sử dụng Samsung Pay trên điện thoại Samsung</div></div>
          </div>
          <div style="text-align:right;font-weight:700;font-size:1.1rem;color:var(--blue-dark)" id="wa-samsung"><?= number_format($conLai,0,',','.') ?>đ</div>
        </div>
      </div>
    </div>

    <button type="submit" class="btn-submit"
            onclick="return confirm('Xác nhận thanh toán và check-out khách?')">
      💳 Xác Nhận Thanh Toán & Check-out
    </button>
  </form>

</div>

<?php else: /* ═══════════════ DONE STATE — INVOICE ═══════════════ */ ?>

<div class="page-head no-print">
  <div class="page-title">🧾 Hóa Đơn Thanh Toán</div>
  <div class="page-sub">
    <span class="done-badge">✅ ĐÃ THANH TOÁN</span>
    <?= $maDP ?> · Hoàn tất lúc <?= $hd ? date('d/m/Y H:i', strtotime($hd['NgayLap'])) : date('d/m/Y H:i') ?>
  </div>
</div>

<div class="main">
  <div class="success-banner no-print">
    ✅ Check-out thành công! Phòng <?= $bk['MaPhong'] ?> đã chuyển sang <strong>Đang dọn</strong>.
    <?php if ($diemCong > 0): ?>
    &nbsp;·&nbsp; ⭐ Khách tích được <strong><?= number_format($diemCong) ?> điểm</strong> thưởng.
    <?php endif; ?>
    Bấm <strong>In Hóa Đơn PDF</strong> hoặc nút bên dưới để in.
  </div>

  <div class="action-bar no-print">
    <button class="btn-invoice-print" onclick="window.print()">🖨️ In Hóa Đơn PDF</button>
    <a href="checkin.php?dp=<?= '' /* intentionally empty for new booking */ ?>" class="btn-dashboard" style="display:none"></a>
    <a href="dashboard.php?tab=bookings" class="btn-dashboard">← Về Dashboard</a>
  </div>

  <!-- ══════════════════ INVOICE ══════════════════ -->
  <div class="invoice-wrap">

    <div class="inv-header">
      <div class="inv-hotel-name">Easyhome Hotel</div>
      <div class="inv-hotel-sub">
        📍 TP. Huế, Việt Nam &nbsp;·&nbsp; 📞 0768.466.686<br>
        🌐 localhost/khachsan
      </div>
    </div>

    <div class="inv-title-bar">
      <div class="inv-title">Hóa Đơn Thanh Toán</div>
      <div class="inv-meta">
        Mã HĐ: <strong><?= $hd ? $hd['MaHD'] : '—' ?></strong> &nbsp;·&nbsp;
        Ngày lập: <strong><?= $hd ? date('d/m/Y H:i', strtotime($hd['NgayLap'])) : date('d/m/Y H:i') ?></strong>
      </div>
    </div>

    <div class="inv-section">
      <div class="inv-section-title">Thông Tin Khách Hàng</div>
      <div class="inv-info-grid">
        <div class="inv-field"><div class="inv-field-lbl">Họ tên</div><div class="inv-field-val"><?= htmlspecialchars($bk['TenKH']) ?></div></div>
        <div class="inv-field"><div class="inv-field-lbl">Số điện thoại</div><div class="inv-field-val"><?= htmlspecialchars($bk['SdtKH'] ?? '—') ?></div></div>
        <div class="inv-field"><div class="inv-field-lbl">CCCD/CMND</div><div class="inv-field-val"><?= htmlspecialchars(maskCCCD($bk['CCCD'] ?? '')) ?></div></div>
        <div class="inv-field"><div class="inv-field-lbl">Email</div><div class="inv-field-val"><?= htmlspecialchars($bk['EmailKH'] ?? '—') ?></div></div>
      </div>
    </div>

    <div class="inv-section">
      <div class="inv-section-title">Thông Tin Phòng</div>
      <div class="inv-info-grid">
        <div class="inv-field"><div class="inv-field-lbl">Mã đặt phòng</div><div class="inv-field-val"><?= $maDP ?></div></div>
        <div class="inv-field"><div class="inv-field-lbl">Phòng</div><div class="inv-field-val"><?= $bk['MaPhong'] ?> — <?= $bk['LoaiPhong'] ?> (Tầng <?= $bk['Tang'] ?>)</div></div>
        <div class="inv-field"><div class="inv-field-lbl">Check-in</div><div class="inv-field-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckIn'])) ?></div></div>
        <div class="inv-field"><div class="inv-field-lbl">Check-out</div><div class="inv-field-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckOut'])) ?></div></div>
        <div class="inv-field"><div class="inv-field-lbl">Thời gian lưu trú</div><div class="inv-field-val"><?= $nights ?> đêm</div></div>
        <div class="inv-field"><div class="inv-field-lbl">Số khách</div><div class="inv-field-val"><?= $bk['SoLuongKhach'] ?> người</div></div>
      </div>
    </div>

    <div class="inv-section">
      <div class="inv-section-title">Chi Tiết Thanh Toán</div>
      <table class="inv-charges">
        <thead>
          <tr>
            <th style="text-align:left">Nội dung</th>
            <th style="text-align:right">Thành tiền</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Tiền phòng (<?= $nights ?> đêm × <?= number_format($bk['GiaPhong'],0,',','.') ?>đ/đêm)</td>
            <td class="ar"><?= number_format($hd ? $hd['TienPhong'] : 0,0,',','.') ?>đ</td>
          </tr>
          <?php if ($svcs): ?>
          <?php foreach ($svcs as $s): ?>
          <tr class="itr-sub">
            <td style="padding-left:18px">✨ <?= htmlspecialchars($s['TenDV']) ?></td>
            <td class="ar"><?= number_format($s['ThanhTien'],0,',','.') ?>đ</td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($hd && $hd['PhuPhi'] > 0): ?>
          <tr><td>📌 Phụ phí</td><td class="ar"><?= number_format($hd['PhuPhi'],0,',','.') ?>đ</td></tr>
          <?php endif; ?>
          <?php if ($giamGiaDisp > 0): ?>
          <tr class="itr-disc">
            <td>🎁 Giảm giá<?= $kmNameDisp ? " ({$kmNameDisp})" : '' ?></td>
            <td class="ar">-<?= number_format($giamGiaDisp,0,',','.') ?>đ</td>
          </tr>
          <?php endif; ?>
          <tr class="itr-total">
            <td><strong>Tổng Cộng</strong></td>
            <td class="ar"><strong><?= number_format($hd ? $hd['TongTien'] : 0,0,',','.') ?>đ</strong></td>
          </tr>
          <tr class="itr-coc">
            <td>⚡ Tiền cọc đã thu (<?= $hd ? $hd['PhuongThucTT'] ?? '—' : '—' ?>)</td>
            <td class="ar" style="color:#ef4444">-<?= number_format($hd ? $hd['TienCocDaThu'] : 0,0,',','.') ?>đ</td>
          </tr>
        </tbody>
      </table>

      <div class="inv-remain">
        <span class="inv-remain-lbl">💰 Đã Thanh Toán</span>
        <span class="inv-remain-val"><?= number_format($conLai,0,',','.') ?>đ</span>
      </div>

      <div class="inv-pay-method">
        <div class="inv-pay-lbl">Phương thức thanh toán</div>
        <div class="inv-pay-val">💳 <?= htmlspecialchars($hd ? ($hd['PhuongThucTT'] ?? 'Tiền mặt') : 'Tiền mặt') ?></div>
      </div>
    </div>

    <div class="inv-sig">
      <div class="sig-box">
        <div class="sig-title">Khách Hàng Ký Tên</div>
        <div class="sig-line"><?= htmlspecialchars($bk['TenKH']) ?></div>
      </div>
      <div class="sig-box">
        <div class="sig-title">Nhân Viên Thu Tiền</div>
        <div class="sig-line"><?= htmlspecialchars($hd ? ($hd['TenNV'] ?? '') : '') ?></div>
      </div>
    </div>

    <div class="inv-footer">
      <?php if ($diemCong > 0): ?>
      <div style="background:#fefce8;border:1px solid #fcd34d;border-radius:8px;
                  padding:9px 16px;margin-bottom:12px;font-size:.82rem;color:#92400e;">
        ⭐ Quý khách đã tích lũy được <strong><?= number_format($diemCong) ?> điểm thưởng</strong>
        từ lần lưu trú này (1.000đ = 1 điểm)
      </div>
      <?php endif; ?>
      <div class="inv-footer-main">Cảm ơn quý khách đã lưu trú tại Easyhome Hotel! 🏨</div>
      <div class="inv-footer-sub">
        Mọi thắc mắc xin liên hệ: <strong>0768.466.686</strong><br>
        Chúc quý khách có những trải nghiệm tuyệt vời tại TP. Huế
      </div>
    </div>

  </div><!-- /invoice-wrap -->

  <div class="action-bar no-print" style="margin-top:20px">
    <button class="btn-invoice-print" onclick="window.print()">🖨️ In / Lưu PDF</button>
    <a href="dashboard.php?tab=bookings" class="btn-dashboard">← Về Dashboard</a>
  </div>

</div>

<?php endif; /* done */ ?>

<script>
const BASE_CONLAI = <?= $conLai ?>;

function updateConLai() {
  const extra = parseFloat(document.getElementById('phuPhiExtra')?.value || 0);
  const newConLai = Math.max(0, BASE_CONLAI + extra);
  const fmtV = newConLai.toLocaleString('vi-VN') + 'đ';

  const d = document.getElementById('displayConLai');
  if (d) d.textContent = fmtV;
  ['cashAmt','cardAmt','wa-apple','wa-google','wa-samsung'].forEach(id => {
    const el = document.getElementById(id); if(el) el.textContent = fmtV;
  });
  ['qa-momo','qa-zalo','qa-bank'].forEach(id => {
    const el = document.getElementById(id); if(el) el.textContent = fmtV;
  });
  updateQRImgs(newConLai);
}

function updateQRImgs(amt) {
  const dp = '<?= $maDP ?>';
  const g = id => document.getElementById(id);
  if(g('qr-momo')) g('qr-momo').src = `https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=${encodeURIComponent('MoMo|Easyhome Hotel|0768466686|'+dp+'|'+amt)}`;
  if(g('qr-zalo')) g('qr-zalo').src = `https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=${encodeURIComponent('ZaloPay|Easyhome Hotel|0768466686|'+dp+'|'+amt)}`;
  if(g('qr-bank')) g('qr-bank').src = `https://img.vietqr.io/image/VCB-9999999999-compact2.png?amount=${amt}&addInfo=${encodeURIComponent(dp)}&accountName=EASYHOME+HOTEL`;
}

function calcChange() {
  const given  = parseFloat(document.getElementById('cashGiven')?.value || 0);
  const extra  = parseFloat(document.getElementById('phuPhiExtra')?.value || 0);
  const remain = Math.max(0, BASE_CONLAI + extra);
  const change = given - remain;
  const el = document.getElementById('cashChange');
  if (el) {
    if (given <= 0) { el.textContent = '—'; el.style.color='var(--blue)'; return; }
    if (change < 0) { el.textContent = 'Thiếu ' + Math.abs(change).toLocaleString('vi-VN') + 'đ'; el.style.color='#ef4444'; }
    else { el.textContent = change.toLocaleString('vi-VN') + 'đ'; el.style.color='#16a34a'; }
  }
}

function selectPM(card, type, label) {
  document.querySelectorAll('.pay-card').forEach(c=>c.classList.remove('active'));
  document.querySelectorAll('.pay-panel').forEach(p=>p.classList.remove('active'));
  card.classList.add('active');
  document.getElementById('pp-'+type).classList.add('active');
  document.getElementById('hidPT').value = label;
  if (type==='card') document.getElementById('hidPT').value='Visa';
  if (type==='qr')   document.getElementById('hidPT').value='QR MoMo';
}

function selCardType(btn, type) {
  document.querySelectorAll('.ctype-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('hidPT').value = type;
}

function selQR(tab, type, label) {
  document.querySelectorAll('.qr-tab').forEach(t=>t.classList.remove('active'));
  tab.classList.add('active');
  document.querySelectorAll('.qr-pane').forEach(p=>{p.style.display='none';p.classList.remove('active');});
  const p = document.getElementById('qp-'+type);
  if(p) { p.style.display='block'; p.classList.add('active'); }
  document.getElementById('hidPT').value = label;
}
</script>
</body>
</html>
