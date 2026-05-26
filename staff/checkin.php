<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['staff_id'])) { header('Location: ../login.php'); exit; }

$staffMaNV = $_SESSION['staff_maNV'];
$maDP = trim($_GET['dp'] ?? '');
if (!$maDP) { header('Location: dashboard.php'); exit; }

/* ── Lấy thông tin đặt phòng ─────────────────────────────────────────────── */
$q = $pdo->prepare("
    SELECT dp.*, kh.HoTen AS TenKH, kh.SoDienThoai AS SdtKH,
           kh.Email AS EmailKH, kh.CCCD, kh.DiaChi,
           p.LoaiPhong, p.Tang, p.GiaPhong, p.SoNguoiToiDa
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    WHERE dp.MaDP = :id AND dp.TrangThai = 'Chờ xác nhận'
");
$q->execute([':id' => $maDP]);
$bk = $q->fetch();
if (!$bk) { header("Location: dashboard.php?tab=bookings"); exit; }

/* ── Dịch vụ đã đặt ─────────────────────────────────────────────────────── */
$svcQ = $pdo->prepare("
    SELECT ddv.MaDV, ddv.ThanhTien, dv.TenDV
    FROM DAT_DICH_VU ddv JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    WHERE ddv.MaDP = :id
");
$svcQ->execute([':id' => $maDP]);
$bookedSvcs   = $svcQ->fetchAll();
$bookedSvcIds = array_column($bookedSvcs, 'MaDV');

/* ── Tất cả dịch vụ có thể thêm ──────────────────────────────────────────── */
$allSvcs = getAllServices($pdo);

/* ── Khuyến mãi đang hoạt động ───────────────────────────────────────────── */
$promos = $pdo->query("
    SELECT * FROM KHUYEN_MAI
    WHERE TrangThai = 'Đang áp dụng'
      AND NgayBatDau <= CURDATE() AND NgayKetThuc >= CURDATE()
    ORDER BY GiaTriKM DESC
")->fetchAll();

/* ── Tính tiền cơ bản ────────────────────────────────────────────────────── */
$nights    = max(1, (int)ceil((strtotime($bk['NgayCheckOut']) - strtotime($bk['NgayCheckIn'])) / 86400));
$tienPhong = $bk['GiaPhong'] * $nights;
$tienDV    = array_sum(array_column($bookedSvcs, 'ThanhTien'));
$defCoc    = round(($tienPhong + $tienDV) * 0.3);

/* ── POST: xử lý check-in ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    csrfVerify();
    $addSvcs    = array_filter($_POST['add_svcs'] ?? []);
    $phuongThuc = trim($_POST['phuong_thuc'] ?? 'Tiền mặt');
    $maKM       = trim($_POST['ma_km'] ?? '');
    $phuPhi     = max(0, (float)($_POST['phu_phi'] ?? 0));
    $tienCocThu = max(0, (float)($_POST['tien_coc'] ?? $defCoc));
    $ghiChu     = trim($_POST['ghi_chu'] ?? '');

    /* Dịch vụ thêm tại check-in */
    $extraTotal = 0; $extraList = [];
    if ($addSvcs) {
        $ph = implode(',', array_fill(0, count($addSvcs), '?'));
        $sq = $pdo->prepare("SELECT * FROM DICH_VU WHERE MaDV IN ($ph)");
        $sq->execute(array_values($addSvcs));
        $extraList  = $sq->fetchAll();
        foreach ($extraList as $e) $extraTotal += $e['GiaDV'];
    }
    $totalDV = $tienDV + $extraTotal;

    /* Khuyến mãi */
    $giamGia = 0; $kmNote = '';
    if ($maKM) {
        $kmQ = $pdo->prepare("
            SELECT * FROM KHUYEN_MAI
            WHERE MaKM = :k AND TrangThai = 'Đang áp dụng'
              AND NgayBatDau <= CURDATE() AND NgayKetThuc >= CURDATE()
        ");
        $kmQ->execute([':k' => $maKM]);
        $km = $kmQ->fetch();
        if ($km) {
            $giamGia = $km['LoaiKM'] === 'PhanTram'
                ? round(($tienPhong + $totalDV) * $km['GiaTriKM'] / 100)
                : min($km['GiaTriKM'], $tienPhong + $totalDV);
            $kmNote = " [GIAM:{$giamGia}][KM:{$km['MaKM']} {$km['TenKM']}]";
        }
    }

    $tongTien = max(0, $tienPhong + $totalDV + $phuPhi - $giamGia);

    /* Tạo mã hóa đơn */
    $lastHD = $pdo->query("SELECT MaHD FROM HOA_DON ORDER BY NgayLap DESC LIMIT 1")->fetchColumn();
    $hdNum  = $lastHD ? (int)preg_replace('/\D/', '', $lastHD) + 1 : 1;
    $maHD   = 'HD' . str_pad($hdNum, 3, '0', STR_PAD_LEFT);

    /* Insert HOA_DON */
    $pdo->prepare("
        INSERT INTO HOA_DON
            (MaHD,MaDP,MaNV,TienPhong,TienDichVu,PhuPhi,TienCocDaThu,TongTien,PhuongThucTT,TrangThai,GhiChu)
        VALUES (?,?,?,?,?,?,?,?,?,'Chưa thanh toán',?)
    ")->execute([$maHD, $maDP, $staffMaNV, $tienPhong, $totalDV, $phuPhi, $tienCocThu, $tongTien, $phuongThuc, $ghiChu.$kmNote]);

    /* Insert dịch vụ thêm */
    foreach ($extraList as $e) {
        $pdo->prepare("INSERT INTO DAT_DICH_VU (MaDP,MaDV,SoLuong,ThanhTien) VALUES (?,?,1,?)")
            ->execute([$maDP, $e['MaDV'], $e['GiaDV']]);
    }

    /* Cập nhật trạng thái */
    $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã nhận phòng', MaNV_XuLy=? WHERE MaDP=?")
        ->execute([$staffMaNV, $maDP]);
    $pdo->prepare("UPDATE PHONG SET TinhTrang='Đang ở' WHERE MaPhong=?")
        ->execute([$bk['MaPhong']]);

    header("Location: dashboard.php?tab=bookings&checkin_ok=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Check-in <?= htmlspecialchars($maDP) ?> — Easyhome</title>
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

.topbar{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:60px;box-shadow:0 2px 16px rgba(29,78,216,.3);position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.brand img{width:34px;height:34px;border-radius:8px;object-fit:cover;border:2px solid rgba(255,255,255,.35)}
.brand-name{font-family:var(--serif);font-size:1.1rem;color:#fff}
.btn-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:6px 14px;border-radius:7px;font-size:.78rem;font-weight:600;
  text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.28)}

.page-head{max-width:1200px;margin:24px auto 0;padding:0 20px}
.page-title{font-family:var(--serif);font-size:1.45rem;color:var(--blue-dark);margin-bottom:4px}
.page-sub{font-size:.82rem;color:var(--muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.dp-badge{background:var(--blue);color:#fff;padding:2px 10px;border-radius:12px;font-size:.74rem;font-weight:700}

.content{max-width:1200px;margin:18px auto;padding:0 20px 60px;
  display:grid;grid-template-columns:1fr 390px;gap:20px;align-items:start}

.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:16px;overflow:hidden}
.card-head{padding:13px 18px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;gap:9px;background:var(--blue-pale)}
.ch-icon{font-size:1rem}
.ch-title{font-weight:700;font-size:.88rem;color:var(--blue-dark)}
.card-body{padding:16px}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.info-item{padding:10px 13px;background:var(--blue-pale);border-radius:9px;border:1px solid var(--border)}
.info-label{font-size:.62rem;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
.info-val{font-size:.87rem;font-weight:600}

.svc-list{display:flex;flex-direction:column;gap:7px}
.svc-row{display:flex;justify-content:space-between;align-items:center;
  padding:9px 13px;background:var(--blue-pale);border-radius:8px;border:1px solid var(--border)}
.svc-nm{font-size:.83rem;font-weight:600}
.svc-pr{font-size:.83rem;font-weight:700;color:var(--blue-dark)}

.add-svc-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.add-svc-card{border:2px solid var(--border);border-radius:9px;padding:10px 12px;
  cursor:pointer;transition:all .22s;background:#fff;display:flex;align-items:center;gap:9px;position:relative}
.add-svc-card:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.add-svc-card.selected{border-color:var(--blue);background:var(--blue-pale)}
.add-svc-card input{position:absolute;opacity:0;width:0;height:0}
.asc-chk{width:18px;height:18px;border:2px solid var(--border);border-radius:4px;
  display:flex;align-items:center;justify-content:center;font-size:.68rem;flex-shrink:0;transition:all .2s}
.add-svc-card.selected .asc-chk{background:var(--blue);border-color:var(--blue);color:#fff}
.asc-nm{font-size:.77rem;font-weight:600;line-height:1.2}
.asc-pr{font-size:.72rem;color:var(--blue);font-weight:700;margin-top:2px}

.form-group{margin-bottom:13px}
.form-label{display:block;font-size:.64rem;font-weight:700;letter-spacing:1.8px;
  text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.form-input{width:100%;padding:10px 13px;font-family:var(--font);font-size:.9rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:8px;outline:none;transition:all .2s}
.form-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}
.form-textarea{width:100%;padding:10px 13px;font-family:var(--font);font-size:.9rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:8px;outline:none;resize:vertical;min-height:56px;transition:all .2s}
.form-textarea:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}

/* Price box */
.price-box{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:10px;padding:16px 18px;color:#fff;margin-bottom:14px}
.pr{display:flex;justify-content:space-between;align-items:center;font-size:.82rem;margin-bottom:6px}
.pr.total{border-top:1px solid rgba(255,255,255,.22);padding-top:8px;margin-top:4px;font-size:.93rem;font-weight:700}
.pr.discount{color:#fcd34d}
.pl{color:rgba(255,255,255,.8)}
.pv{font-weight:700}
.coc-wrap{background:rgba(255,255,255,.13);border-radius:8px;
  padding:10px 14px;display:flex;align-items:center;justify-content:space-between;margin-top:11px}
.coc-lbl{font-size:.74rem;color:rgba(255,255,255,.85)}
.coc-inp{background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.38);
  color:#fff;padding:5px 10px;border-radius:6px;font-family:var(--font);font-size:.9rem;
  font-weight:700;width:130px;text-align:right;outline:none}
.coc-inp:focus{border-color:#fff;background:rgba(255,255,255,.28)}
.coc-hint{text-align:right;font-size:.68rem;color:rgba(255,255,255,.58);margin-top:3px}

/* Promo */
.promo-row{display:flex;gap:8px;margin-bottom:10px}
.promo-inp{flex:1;padding:9px 12px;font-family:var(--font);font-size:.87rem;
  background:var(--blue-pale);border:1.5px solid var(--border);border-radius:8px;
  outline:none;transition:all .2s;text-transform:uppercase}
.promo-inp:focus{border-color:var(--blue);background:#fff}
.btn-apply{padding:9px 15px;background:var(--blue);color:#fff;border:none;
  border-radius:8px;font-size:.77rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:all .2s}
.btn-apply:hover{background:var(--blue-dark)}
.promo-list{display:flex;flex-direction:column;gap:7px}
.promo-item{display:flex;align-items:center;justify-content:space-between;
  padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;
  background:#fff;cursor:pointer;transition:all .2s}
.promo-item:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.promo-item.applied{border-color:#22c55e;background:#f0fdf4}
.promo-code{font-size:.74rem;font-weight:700;color:var(--blue-dark)}
.promo-cond{font-size:.71rem;color:var(--muted);margin-top:2px}
.promo-badge{font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:10px;
  background:var(--blue-mid);color:var(--blue-dark);white-space:nowrap;flex-shrink:0;margin-left:8px}
.promo-badge.on{background:#dcfce7;color:#166534}

/* Payment methods */
.pay-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.pay-card{border:2px solid var(--border);border-radius:10px;padding:11px 7px;
  text-align:center;cursor:pointer;transition:all .22s;background:#fff}
.pay-card:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.pay-card.active{border-color:var(--blue);background:var(--blue-pale);
  box-shadow:0 2px 10px rgba(29,78,216,.15)}
.pay-icon{font-size:1.5rem;margin-bottom:4px}
.pay-lbl{font-size:.68rem;font-weight:700;color:var(--text);line-height:1.3}

.pay-panel{display:none;padding:13px;background:var(--blue-pale);border-radius:9px;
  border:1.5px solid var(--border);margin-bottom:12px;animation:fadeIn .2s ease}
.pay-panel.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}

.card-types{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:11px}
.ctype-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:7px;
  font-size:.73rem;font-weight:700;cursor:pointer;background:#fff;transition:all .2s}
.ctype-btn:hover{border-color:var(--blue-light);color:var(--blue)}
.ctype-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}

.qr-tabs{display:flex;gap:6px;margin-bottom:12px}
.qr-tab{padding:5px 13px;border-radius:7px;border:1.5px solid var(--border);
  font-size:.73rem;font-weight:700;cursor:pointer;background:#fff;transition:all .2s}
.qr-tab:hover{border-color:var(--blue-light);color:var(--blue)}
.qr-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.qr-pane{display:none;text-align:center}
.qr-pane.active{display:block;animation:fadeIn .2s ease}
.qr-img{border-radius:10px;border:3px solid var(--border);width:190px;height:190px}
.qr-info{margin-top:9px;font-size:.77rem;color:var(--muted);line-height:1.7}
.qr-amt{font-size:1.05rem;font-weight:700;color:var(--blue-dark);margin-top:5px}

.wallet-row{display:flex;align-items:center;gap:11px;padding:11px;
  background:#fff;border-radius:9px;border:1.5px solid var(--border);margin-bottom:7px}
.w-icon{font-size:1.8rem;flex-shrink:0}
.w-name{font-size:.85rem;font-weight:700;margin-bottom:2px}
.w-desc{font-size:.73rem;color:var(--muted)}

.btn-submit{width:100%;padding:13px;font-family:var(--font);font-size:.88rem;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;color:#fff;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border:none;border-radius:10px;cursor:pointer;transition:all .3s;
  box-shadow:0 4px 18px rgba(29,78,216,.38)}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(29,78,216,.48)}

.note-box{background:#fef9ee;border:1px solid #fcd34d;border-radius:8px;
  padding:10px 13px;font-size:.82rem;color:#92400e;margin-top:10px}

@media(max-width:900px){
  .content{grid-template-columns:1fr}
  .add-svc-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="topbar">
  <a href="../index.php" class="brand">
    <img src="../assets/images/logo.jpg" alt="">
    <span class="brand-name">Easyhome</span>
  </a>
  <a href="dashboard.php?tab=bookings" class="btn-back">← Quay lại</a>
</div>

<div class="page-head">
  <div class="page-title">✅ Check-in Nhận Phòng</div>
  <div class="page-sub">
    <span class="dp-badge"><?= htmlspecialchars($maDP) ?></span>
    Xác nhận nhận phòng và thu tiền cọc &nbsp;·&nbsp;
    <?= date('d/m/Y H:i') ?>
  </div>
</div>

<form method="POST">
  <?= csrfField() ?>
<input type="hidden" name="action" value="checkin">
<input type="hidden" name="ma_km"  id="hidKM"  value="">
<input type="hidden" name="phuong_thuc" id="hidPT" value="Tiền mặt">

<div class="content">

  <!-- ══════════ LEFT ══════════ -->
  <div>

    <!-- Thông tin đặt phòng -->
    <div class="card">
      <div class="card-head"><span class="ch-icon">📋</span><span class="ch-title">Thông Tin Đặt Phòng</span></div>
      <div class="card-body">
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Khách Hàng</div>
            <div class="info-val"><?= htmlspecialchars($bk['TenKH']) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Số Điện Thoại</div>
            <div class="info-val"><?= htmlspecialchars($bk['SdtKH'] ?? '—') ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">CCCD / CMND</div>
            <div class="info-val"><?= htmlspecialchars(decryptCCCD($bk['CCCD'] ?? '') ?? '—') ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Email</div>
            <div class="info-val" style="font-size:.82rem"><?= htmlspecialchars($bk['EmailKH'] ?? '—') ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Phòng</div>
            <div class="info-val"><?= $bk['MaPhong'] ?> — <?= $bk['LoaiPhong'] ?> (Tầng <?= $bk['Tang'] ?>)</div>
          </div>
          <div class="info-item">
            <div class="info-label">Số Khách</div>
            <div class="info-val"><?= $bk['SoLuongKhach'] ?> người (max <?= $bk['SoNguoiToiDa'] ?>)</div>
          </div>
          <div class="info-item">
            <div class="info-label">Check-in</div>
            <div class="info-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckIn'])) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Check-out</div>
            <div class="info-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckOut'])) ?></div>
          </div>
        </div>
        <?php if ($bk['GhiChu']): ?>
        <div class="note-box" style="margin-top:12px">📝 <?= htmlspecialchars($bk['GhiChu']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Dịch vụ đã đặt -->
    <?php if ($bookedSvcs): ?>
    <div class="card">
      <div class="card-head"><span class="ch-icon">✨</span><span class="ch-title">Dịch Vụ Đã Đặt</span></div>
      <div class="card-body">
        <div class="svc-list">
          <?php foreach ($bookedSvcs as $s): ?>
          <div class="svc-row">
            <span class="svc-nm">✓ <?= htmlspecialchars($s['TenDV']) ?></span>
            <span class="svc-pr"><?= number_format($s['ThanhTien'],0,',','.') ?>đ</span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Thêm dịch vụ tại check-in -->
    <?php $extraSvcs = array_filter($allSvcs, fn($s) => !in_array($s['MaDV'], $bookedSvcIds)); ?>
    <?php if ($extraSvcs): ?>
    <div class="card">
      <div class="card-head"><span class="ch-icon">➕</span><span class="ch-title">Thêm Dịch Vụ Tại Check-in</span></div>
      <div class="card-body">
        <div class="add-svc-grid">
          <?php foreach ($extraSvcs as $s): ?>
          <label class="add-svc-card" onclick="toggleAddSvc(this)">
            <input type="checkbox" name="add_svcs[]" value="<?= $s['MaDV'] ?>" data-price="<?= $s['GiaDV'] ?>">
            <div class="asc-chk"></div>
            <div>
              <div class="asc-nm"><?= htmlspecialchars($s['TenDV']) ?></div>
              <div class="asc-pr"><?= number_format($s['GiaDV'],0,',','.') ?>đ</div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Phụ thu & ghi chú -->
    <div class="card">
      <div class="card-head"><span class="ch-icon">📝</span><span class="ch-title">Phụ Thu & Ghi Chú</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Phụ Thu (nếu có)</label>
          <div style="display:flex;align-items:center;gap:10px">
            <input type="number" name="phu_phi" id="phuPhiInp" class="form-input"
                   value="0" min="0" step="10000" oninput="updateCalc()" style="width:180px">
            <span style="font-size:.8rem;color:var(--muted)">đồng</span>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Ghi Chú Nội Bộ</label>
          <textarea name="ghi_chu" class="form-textarea"
                    placeholder="Ghi chú về khách, yêu cầu đặc biệt..."></textarea>
        </div>
      </div>
    </div>

  </div><!-- /left -->

  <!-- ══════════ RIGHT ══════════ -->
  <div>

    <!-- Tính tiền -->
    <div class="card">
      <div class="card-head"><span class="ch-icon">💰</span><span class="ch-title">Tính Tiền</span></div>
      <div class="card-body">
        <div class="price-box">
          <div class="pr">
            <span class="pl">🛏️ Tiền phòng (<?= $nights ?> đêm × <?= number_format($bk['GiaPhong'],0,',','.') ?>đ)</span>
            <span class="pv"><?= number_format($tienPhong,0,',','.') ?>đ</span>
          </div>
          <?php if ($tienDV > 0): ?>
          <div class="pr">
            <span class="pl">✨ Dịch vụ đã đặt</span>
            <span class="pv"><?= number_format($tienDV,0,',','.') ?>đ</span>
          </div>
          <?php endif; ?>
          <div class="pr" id="rowExtra" style="display:none">
            <span class="pl">➕ Dịch vụ thêm</span>
            <span class="pv" id="extraAmt">0đ</span>
          </div>
          <div class="pr" id="rowPhuPhi" style="display:none">
            <span class="pl">📌 Phụ thu</span>
            <span class="pv" id="phuPhiAmt">0đ</span>
          </div>
          <div class="pr discount" id="rowDiscount" style="display:none">
            <span class="pl">🎁 Giảm giá <span id="discLbl" style="font-size:.75rem"></span></span>
            <span class="pv" id="discAmt">0đ</span>
          </div>
          <div class="pr total">
            <span class="pl">Tổng Tiền</span>
            <span class="pv" id="totalAmt"><?= number_format($tienPhong+$tienDV,0,',','.') ?>đ</span>
          </div>
          <div class="coc-wrap">
            <span class="coc-lbl">⚡ Tiền cọc thu:</span>
            <input type="number" name="tien_coc" id="tienCocInp" class="coc-inp"
                   value="<?= $defCoc ?>" min="0" step="10000" onchange="updateAmtDisplays()">
            <span style="font-size:.8rem;color:rgba(255,255,255,.75)">đ</span>
          </div>
          <div class="coc-hint">Gợi ý 30% = <?= number_format($defCoc,0,',','.') ?>đ</div>
        </div>

        <!-- Khuyến mãi -->
        <div style="font-size:.67rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
                    color:var(--blue);margin-bottom:10px;display:flex;align-items:center;gap:8px">
          🎁 Khuyến Mãi
          <span style="flex:1;height:1px;background:var(--border);display:block"></span>
        </div>
        <div class="promo-row">
          <input type="text" id="promoInp" class="promo-inp" placeholder="Nhập mã KM..."
                 oninput="this.value=this.value.toUpperCase()">
          <button type="button" class="btn-apply" onclick="applyPromo(document.getElementById('promoInp').value)">Áp dụng</button>
        </div>
        <div id="promoMsg" style="font-size:.77rem;margin-bottom:9px;display:none"></div>
        <?php if ($promos): ?>
        <div class="promo-list">
          <?php foreach ($promos as $pm): ?>
          <div class="promo-item" id="pi-<?= $pm['MaKM'] ?>" onclick="applyPromo('<?= $pm['MaKM'] ?>')">
            <div>
              <div class="promo-code"><?= $pm['MaKM'] ?> — <?= htmlspecialchars($pm['TenKM']) ?></div>
              <div class="promo-cond"><?= htmlspecialchars(mb_substr($pm['DieuKien'] ?? '', 0, 55)) ?></div>
            </div>
            <div class="promo-badge" id="pb-<?= $pm['MaKM'] ?>">
              <?= $pm['LoaiKM']==='PhanTram' ? '-'.$pm['GiaTriKM'].'%' : '-'.number_format($pm['GiaTriKM'],0,',','.').'đ' ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Phương thức thanh toán cọc -->
    <div class="card">
      <div class="card-head"><span class="ch-icon">💳</span><span class="ch-title">Phương Thức Thanh Toán Cọc</span></div>
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
          <div style="text-align:center;padding:8px 0">
            <div style="font-size:2rem;margin-bottom:6px">💵</div>
            <div style="font-size:.8rem;color:var(--muted);margin-bottom:6px">Thu tiền mặt từ khách</div>
            <div style="font-size:1.25rem;font-weight:700;color:var(--blue-dark)" id="cashAmt"><?= number_format($defCoc,0,',','.') ?>đ</div>
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
            💳 Đưa thẻ vào máy POS tại quầy để thanh toán
            <strong id="cardAmt"><?= number_format($defCoc,0,',','.') ?>đ</strong>
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
                 src="https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=<?= urlencode('MoMo|Easyhome Hotel|0768466686|'.$maDP.'|'.$defCoc) ?>"
                 alt="MoMo QR">
            <div class="qr-info">📱 Quét mã MoMo<br>SĐT: <strong>0768 466 686</strong><br>Tên: <strong>EASYHOME HOTEL</strong><br>Nội dung: <strong><?= $maDP ?></strong></div>
            <div class="qr-amt" id="qa-momo"><?= number_format($defCoc,0,',','.') ?>đ</div>
          </div>
          <div class="qr-pane" id="qp-zalo" style="display:none">
            <img id="qr-zalo" class="qr-img"
                 src="https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=<?= urlencode('ZaloPay|Easyhome Hotel|0768466686|'.$maDP.'|'.$defCoc) ?>"
                 alt="ZaloPay QR">
            <div class="qr-info">📱 Quét mã ZaloPay<br>SĐT: <strong>0768 466 686</strong><br>Tên: <strong>EASYHOME HOTEL</strong><br>Nội dung: <strong><?= $maDP ?></strong></div>
            <div class="qr-amt" id="qa-zalo"><?= number_format($defCoc,0,',','.') ?>đ</div>
          </div>
          <div class="qr-pane" id="qp-bank" style="display:none">
            <img id="qr-bank" class="qr-img"
                 src="https://img.vietqr.io/image/VCB-9999999999-compact2.png?amount=<?= $defCoc ?>&addInfo=<?= urlencode($maDP) ?>&accountName=EASYHOME+HOTEL"
                 alt="VietQR">
            <div class="qr-info">🏦 Chuyển khoản ngân hàng<br>NH: <strong>Vietcombank</strong><br>STK: <strong>9999 9999 99</strong><br>Tên: <strong>EASYHOME HOTEL</strong><br>Nội dung: <strong><?= $maDP ?></strong></div>
            <div class="qr-amt" id="qa-bank"><?= number_format($defCoc,0,',','.') ?>đ</div>
          </div>
        </div>

        <!-- Apple Pay -->
        <div class="pay-panel" id="pp-apple">
          <div class="wallet-row">
            <div class="w-icon">🍎</div>
            <div><div class="w-name">Apple Pay</div><div class="w-desc">Khách chạm iPhone / Apple Watch vào máy POS NFC</div></div>
          </div>
          <div style="text-align:center;font-size:1.05rem;font-weight:700;color:var(--blue-dark)" id="wa-apple"><?= number_format($defCoc,0,',','.') ?>đ</div>
        </div>

        <!-- Google Pay -->
        <div class="pay-panel" id="pp-google">
          <div class="wallet-row">
            <div class="w-icon">🤖</div>
            <div><div class="w-name">Google Pay / Google Wallet</div><div class="w-desc">Khách chạm điện thoại Android vào máy POS NFC</div></div>
          </div>
          <div style="text-align:center;font-size:1.05rem;font-weight:700;color:var(--blue-dark)" id="wa-google"><?= number_format($defCoc,0,',','.') ?>đ</div>
        </div>

        <!-- Samsung Pay -->
        <div class="pay-panel" id="pp-samsung">
          <div class="wallet-row">
            <div class="w-icon">📲</div>
            <div><div class="w-name">Samsung Pay</div><div class="w-desc">Khách sử dụng Samsung Pay trên điện thoại Samsung</div></div>
          </div>
          <div style="text-align:center;font-size:1.05rem;font-weight:700;color:var(--blue-dark)" id="wa-samsung"><?= number_format($defCoc,0,',','.') ?>đ</div>
        </div>
      </div>
    </div>

    <button type="submit" class="btn-submit"
            onclick="return confirm('Xác nhận check-in và thu tiền cọc?')">
      ✅ Xác Nhận Check-in & Thu Cọc
    </button>

  </div><!-- /right -->
</div><!-- /content -->
</form>

<script>
const BASE_PHONG = <?= $tienPhong ?>;
const BASE_DV    = <?= $tienDV ?>;
let extraSvc  = 0;
let currentKM = null;
let giamGia   = 0;
let curQRSub  = 'momo';

const promoData = <?= json_encode(array_combine(array_column($promos,'MaKM'), $promos)) ?>;

/* Toggle add-service */
function toggleAddSvc(card) {
  const cb  = card.querySelector('input');
  const chk = card.querySelector('.asc-chk');
  setTimeout(() => {
    if (cb.checked) { card.classList.add('selected'); chk.textContent='✓'; }
    else { card.classList.remove('selected'); chk.textContent=''; }
    recalcExtra();
  }, 0);
}
function recalcExtra() {
  extraSvc = 0;
  document.querySelectorAll('.add-svc-card input:checked').forEach(cb => {
    extraSvc += parseFloat(cb.dataset.price || 0);
  });
  updateCalc();
}

/* Main calculation */
function updateCalc() {
  const phuPhi = parseFloat(document.getElementById('phuPhiInp').value || 0);
  const base   = BASE_PHONG + BASE_DV + extraSvc;

  if (currentKM && promoData[currentKM]) {
    const km = promoData[currentKM];
    giamGia = km.LoaiKM === 'PhanTram'
      ? Math.round(base * km.GiaTriKM / 100)
      : Math.min(km.GiaTriKM, base);
  } else giamGia = 0;

  const total  = Math.max(0, base + phuPhi - giamGia);
  const defCoc = Math.round(total * 0.3);

  /* Show/hide rows */
  toggleRow('rowExtra',   extraSvc > 0, 'extraAmt', fmt(extraSvc));
  toggleRow('rowPhuPhi',  phuPhi  > 0,  'phuPhiAmt', fmt(phuPhi));
  if (giamGia > 0) {
    document.getElementById('rowDiscount').style.display='';
    document.getElementById('discAmt').textContent = '-'+fmt(giamGia);
    if (currentKM && promoData[currentKM])
      document.getElementById('discLbl').textContent = '('+promoData[currentKM].TenKM+')';
  } else document.getElementById('rowDiscount').style.display='none';

  document.getElementById('totalAmt').textContent = fmt(total);

  const inp = document.getElementById('tienCocInp');
  if (!inp.dataset.manual) inp.value = defCoc;
  updateAmtDisplays();
}

function toggleRow(rowId, show, valId, txt) {
  const el = document.getElementById(rowId);
  if (el) { el.style.display = show ? '' : 'none'; }
  if (show && valId) document.getElementById(valId).textContent = txt;
}

function updateAmtDisplays() {
  const coc = parseFloat(document.getElementById('tienCocInp').value || 0);
  document.getElementById('tienCocInp').dataset.manual = 'true';
  const fmtCoc = fmt(coc);
  ['cashAmt','cardAmt','wa-apple','wa-google','wa-samsung'].forEach(id => {
    const el = document.getElementById(id); if(el) el.textContent = fmtCoc;
  });
  updateQRImages(coc);
  ['qa-momo','qa-zalo','qa-bank'].forEach(id => {
    const el = document.getElementById(id); if(el) el.textContent = fmtCoc;
  });
}

function updateQRImages(amt) {
  const dp = '<?= $maDP ?>';
  const g = id => document.getElementById(id);
  if(g('qr-momo')) g('qr-momo').src = `https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=${encodeURIComponent('MoMo|Easyhome Hotel|0768466686|'+dp+'|'+amt)}`;
  if(g('qr-zalo')) g('qr-zalo').src = `https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=${encodeURIComponent('ZaloPay|Easyhome Hotel|0768466686|'+dp+'|'+amt)}`;
  if(g('qr-bank')) g('qr-bank').src = `https://img.vietqr.io/image/VCB-9999999999-compact2.png?amount=${amt}&addInfo=${encodeURIComponent(dp)}&accountName=EASYHOME+HOTEL`;
}

const fmt = n => n.toLocaleString('vi-VN') + 'đ';

/* Promo */
function applyPromo(code) {
  code = (code||'').toUpperCase().trim();
  document.getElementById('promoInp').value = code;
  const msg = document.getElementById('promoMsg');
  msg.style.display = 'block';

  document.querySelectorAll('.promo-item').forEach(el => el.classList.remove('applied'));
  document.querySelectorAll('.promo-badge').forEach(el => el.classList.remove('on'));

  if (!code) {
    currentKM = null; giamGia = 0;
    document.getElementById('hidKM').value = '';
    msg.style.display = 'none';
    updateCalc(); return;
  }
  if (promoData[code]) {
    const km = promoData[code];
    currentKM = code;
    document.getElementById('hidKM').value = code;
    const item = document.getElementById('pi-'+code);
    const badge = document.getElementById('pb-'+code);
    if (item)  item.classList.add('applied');
    if (badge) badge.classList.add('on');
    msg.style.color='#166534';
    msg.textContent='✓ Đã áp dụng: '+km.TenKM;
    updateCalc();
  } else {
    currentKM = null; giamGia = 0;
    document.getElementById('hidKM').value = '';
    msg.style.color='#dc2626';
    msg.textContent='✕ Mã khuyến mãi không hợp lệ hoặc đã hết hạn';
    updateCalc();
  }
}

/* Payment methods */
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
  p.style.display='block'; p.classList.add('active');
  document.getElementById('hidPT').value = label;
  curQRSub = type;
}

updateCalc();
</script>
</body>
</html>
