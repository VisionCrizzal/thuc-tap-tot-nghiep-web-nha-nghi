<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

if (!isset($_SESSION['kh_id'])) {
    header('Location: ../login.php'); exit;
}
if (!isset($_SESSION['pending_booking'])) {
    header('Location: dashboard.php?tab=booking'); exit;
}

// ── Hủy & quay lại ──────────────────────────────────────────────────────────
if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_booking']);
    header('Location: dashboard.php?tab=booking'); exit;
}

$khId   = $_SESSION['kh_id'];
$khName = $_SESSION['kh_name'];
$stmt = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE MaKH = :id");
$stmt->execute([':id' => $khId]);
$kh = $stmt->fetch();

// ── Thông tin ngân hàng ─────────────────────────────────────────────────────
// ⚠️ Đổi thành tài khoản thật của khách sạn
const BANK_BIN     = '970422';
const BANK_ACCOUNT = '1234567890';
const BANK_NAME    = 'NHA NGHI EASYHOME';
const BANK_LABEL   = 'MB Bank (Ngân hàng Quân đội)';

$msg = ''; $msgType = 'error';

// ── Xử lý POST xác nhận ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $phuongThuc = trim($_POST['phuong_thuc'] ?? '');
    $payType    = trim($_POST['pay_type']    ?? 'deposit'); // 'deposit' | 'full'
    $validMethods = ['tien_mat', 'chuyen_khoan', 'qr_vietqr'];

    if (!in_array($phuongThuc, $validMethods)) {
        $msg = 'Vui lòng chọn hình thức thanh toán trước khi tiếp tục.';
    } elseif (!in_array($payType, ['deposit', 'full'])) {
        $msg = 'Dữ liệu không hợp lệ.';
    } else {
        $b = $_SESSION['pending_booking'];

        // Re-check phòng còn trống
        $recheck = $pdo->prepare("
            SELECT MaPhong FROM PHONG
            WHERE MaPhong = :ph AND TinhTrang != 'Bảo trì'
              AND MaPhong NOT IN (
                SELECT MaPhong FROM DAT_PHONG
                WHERE TrangThai NOT IN ('Đã hủy','Đã trả phòng')
                  AND NgayCheckIn < :out AND NgayCheckOut > :in
              )
        ");
        $recheck->execute([':ph' => $b['maPhong'], ':in' => $b['checkin'], ':out' => $b['checkout']]);

        if (!$recheck->fetch()) {
            $msg = 'Rất tiếc, phòng này vừa có người đặt. Vui lòng quay lại và chọn thời gian khác.';
            unset($_SESSION['pending_booking']);
        } else {
            // Xác định số tiền lưu vào TienCoc
            // full = thanh toán ngay toàn bộ → TienCoc = TongGia
            $finalCoc = $payType === 'full' ? $b['tongGia'] : $b['tienCoc'];

            // Sinh MaDP
            $lastDP = $pdo->query("SELECT MaDP FROM DAT_PHONG ORDER BY NgayDat DESC LIMIT 1")->fetchColumn();
            $dpNum  = $lastDP ? intval(preg_replace('/\D/', '', $lastDP)) + 1 : 1;
            $maDP   = 'DP' . str_pad($dpNum, 3, '0', STR_PAD_LEFT);

            $ptNames = [
                'tien_mat'     => 'Tiền mặt khi đến',
                'chuyen_khoan' => 'Chuyển khoản ngân hàng',
                'qr_vietqr'    => 'QR VietQR',
            ];
            $ptTag    = $payType === 'full' ? "[PT_FULL:{$phuongThuc}]" : "[PT_COC:{$phuongThuc}]";
            $fullNote = trim($b['ghiChu'] . $b['kmNote'] . " {$ptTag}");

            // Insert DAT_PHONG
            $pdo->prepare("
                INSERT INTO DAT_PHONG
                    (MaDP,MaKH,MaPhong,NgayCheckIn,NgayCheckOut,SoLuongKhach,TienCoc,TongGia,GhiChu)
                VALUES (:dp,:kh,:ph,:in,:out,:sk,:coc,:tong,:ghi)
            ")->execute([
                ':dp'  => $maDP, ':kh'  => $khId,  ':ph'  => $b['maPhong'],
                ':in'  => $b['checkin'], ':out' => $b['checkout'],
                ':sk'  => $b['soKhach'], ':coc' => $finalCoc,
                ':tong'=> $b['tongGia'], ':ghi' => $fullNote,
            ]);

            // Insert DAT_DICH_VU
            foreach ($b['dvList'] as $d) {
                $pdo->prepare("INSERT INTO DAT_DICH_VU (MaDP,MaDV,SoLuong,ThanhTien) VALUES (?,?,1,?)")
                    ->execute([$maDP, $d['MaDV'], $d['GiaDV']]);
            }

            // Gửi email xác nhận
            if (!empty($kh['Email'])) {
                sendBookingConfirmation($kh['Email'], $khName, [
                    'maDP'      => $maDP,
                    'loaiPhong' => "Phòng {$b['loai']} ({$b['maPhong']})",
                    'checkin'   => date('d/m/Y H:i', strtotime($b['checkin'])),
                    'checkout'  => date('d/m/Y H:i', strtotime($b['checkout'])),
                    'tongGia'   => $b['tongGia'],
                ]);
            }

            $paidLabel = $payType === 'full'
                ? "Đã thanh toán toàn bộ <strong>" . number_format($b['tongGia'],0,',','.') . "đ</strong>"
                : "Tiền cọc: <strong>" . number_format($finalCoc,0,',','.') . "đ</strong>. Phần còn lại thanh toán khi nhận phòng";

            $_SESSION['booking_success'] = [
                'maDP'    => $maDP,
                'maPhong' => $b['maPhong'],
                'tienCoc' => $finalCoc,
                'ptNote'  => "{$paidLabel}. Hình thức: <strong>{$ptNames[$phuongThuc]}</strong>.",
            ];
            unset($_SESSION['pending_booking']);
            header('Location: dashboard.php?tab=history');
            exit;
        }
    }
}

// ── Dữ liệu hiển thị ────────────────────────────────────────────────────────
$b = $_SESSION['pending_booking'];
$checkinFmt  = date('d/m/Y H:i', strtotime($b['checkin']));
$checkoutFmt = date('d/m/Y H:i', strtotime($b['checkout']));

$qrDesc     = "Dat coc " . $b['maPhong'] . " Easyhome";
$qrBaseUrl  = "https://img.vietqr.io/image/" . BANK_BIN . "-" . BANK_ACCOUNT . "-compact2.png"
            . "?addInfo=" . urlencode($qrDesc)
            . "&accountName=" . urlencode(BANK_NAME);
$qrUrlDeposit = $qrBaseUrl . "&amount=" . $b['tienCoc'];
$qrUrlFull    = $qrBaseUrl . "&amount=" . $b['tongGia'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Thanh Toán — Easyhome</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --green:#10b981;--amber:#f59e0b;--red:#ef4444;
  --shadow:0 4px 24px rgba(29,78,216,.10);
  --radius:12px;--font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
html,body{min-height:100%;font-family:var(--font);color:var(--text)}
body{background:var(--bg)}

/* TOP BAR */
.topbar{
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:62px;position:sticky;top:0;z-index:100;
  box-shadow:0 2px 12px rgba(29,78,216,.25)
}
.topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.topbar-brand img{width:34px;height:34px;border-radius:6px;object-fit:cover}
.topbar-brand span{color:#fff;font-family:var(--serif);font-size:1.25rem;font-weight:600}
.topbar-user{display:flex;align-items:center;gap:8px;color:#fff;font-size:.9rem}
.topbar-user a{color:rgba(255,255,255,.75);text-decoration:none;font-size:.82rem;
  padding:4px 10px;border:1px solid rgba(255,255,255,.35);border-radius:6px;transition:.2s}
.topbar-user a:hover{background:rgba(255,255,255,.18);color:#fff}

/* PAGE */
.page{max-width:1000px;margin:0 auto;padding:36px 20px 60px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--blue);
  font-size:.88rem;font-weight:600;text-decoration:none;margin-bottom:20px;transition:.2s}
.back-link:hover{color:var(--blue-dark);gap:8px}

/* STEPPER */
.stepper{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:32px}
.step{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;color:var(--muted)}
.step-num{width:28px;height:28px;border-radius:50%;border:2px solid var(--border);
  display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;flex-shrink:0}
.step.done .step-num{background:var(--green);border-color:var(--green);color:#fff}
.step.done{color:var(--green)}
.step.active .step-num{background:var(--blue);border-color:var(--blue);color:#fff}
.step.active{color:var(--blue)}
.step-line{flex:1;height:2px;background:var(--border);max-width:80px;margin:0 6px}
.step-line.done{background:var(--green)}

.page-title{font-family:var(--serif);font-size:1.55rem;color:var(--blue-dark);
  text-align:center;margin-bottom:26px}

/* LAYOUT */
.pay-grid{display:grid;grid-template-columns:360px 1fr;gap:24px;align-items:start}

/* SUMMARY CARD */
.summary-card{background:#fff;border-radius:var(--radius);
  box-shadow:var(--shadow);border:1px solid var(--border);
  overflow:hidden;position:sticky;top:82px}
.summary-head{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:16px 20px;color:#fff}
.summary-head h3{font-family:var(--serif);font-size:1.05rem;margin-bottom:2px}
.summary-head p{font-size:.78rem;opacity:.8}
.summary-body{padding:18px 20px}
.sum-room{display:flex;align-items:center;gap:10px;background:var(--blue-pale);
  border-radius:8px;padding:10px 14px;margin-bottom:14px}
.sum-room-icon{font-size:1.8rem}
.sum-room-info h4{font-size:.92rem;font-weight:700;color:var(--blue-dark)}
.sum-room-info p{font-size:.76rem;color:var(--muted);margin-top:1px}
.sum-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.sum-date{background:var(--bg);border-radius:7px;padding:8px 10px;text-align:center}
.sum-date label{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;display:block;margin-bottom:2px}
.sum-date span{font-size:.82rem;font-weight:700;color:var(--text)}
.sum-divider{border:none;border-top:1px solid var(--border);margin:12px 0}
.sum-row{display:flex;justify-content:space-between;align-items:center;padding:3px 0;font-size:.83rem}
.sum-row .lbl{color:var(--muted)}
.sum-row .val{font-weight:600;color:var(--text)}
.sum-row.vat .lbl,.sum-row.vat .val{color:var(--muted);font-size:.78rem}
.sum-row.discount .val{color:var(--green)}
.sum-total{display:flex;justify-content:space-between;align-items:center;
  background:var(--blue-pale);border-radius:8px;padding:10px 12px;margin:10px 0 6px}
.sum-total .lbl{font-weight:700;font-size:.88rem;color:var(--blue-dark)}
.sum-total .val{font-family:var(--serif);font-size:1.15rem;font-weight:700;color:var(--blue)}
.sum-coc{display:flex;justify-content:space-between;align-items:center;
  border-radius:8px;padding:12px 14px;color:#fff;transition:background .3s}
.sum-coc.deposit-mode{background:linear-gradient(90deg,#1d4ed8,#3b82f6)}
.sum-coc.full-mode{background:linear-gradient(90deg,#059669,#10b981)}
.sum-coc .lbl{font-size:.82rem;font-weight:700}
.sum-coc .lbl small{display:block;font-size:.7rem;opacity:.8;font-weight:400;margin-top:1px}
.sum-coc .val{font-family:var(--serif);font-size:1.18rem;font-weight:700}
.sum-dv-list{margin:8px 0 2px;font-size:.77rem;color:var(--muted)}
.sum-dv-list span{display:inline-block;background:var(--blue-mid);color:var(--blue);
  border-radius:99px;padding:2px 9px;margin:2px 2px 2px 0;font-size:.73rem;font-weight:600}

/* PAYMENT CARD */
.pay-card{background:#fff;border-radius:var(--radius);
  box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.pay-card-head{padding:18px 24px 14px;border-bottom:1px solid var(--border)}
.pay-card-head h3{font-family:var(--serif);font-size:1.1rem;color:var(--blue-dark)}
.pay-card-head p{font-size:.8rem;color:var(--muted);margin-top:3px}
.pay-card-body{padding:20px 24px}

/* PAYMENT TYPE TOGGLE */
.pay-type-toggle{
  display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;
  background:var(--bg);border-radius:10px;padding:6px
}
.pt-btn{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:4px;padding:12px 10px;border:2px solid transparent;border-radius:8px;
  background:#fff;cursor:pointer;transition:.25s;font-family:var(--font)
}
.pt-btn:hover{border-color:var(--border)}
.pt-btn .pt-icon{font-size:1.4rem}
.pt-btn .pt-label{font-size:.78rem;font-weight:700;color:var(--muted)}
.pt-btn .pt-amount{font-family:var(--serif);font-size:.9rem;font-weight:700;color:var(--muted)}
.pt-btn.active{border-color:var(--blue);background:var(--blue-pale)}
.pt-btn.active .pt-label{color:var(--blue)}
.pt-btn.active .pt-amount{color:var(--blue-dark)}
.pt-btn.full-active{border-color:var(--green);background:#f0fdf4}
.pt-btn.full-active .pt-label{color:#065f46}
.pt-btn.full-active .pt-amount{color:#047857}
.pt-divider{text-align:center;font-size:.73rem;color:var(--muted);
  margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border)}

/* METHOD CARDS */
.method-list{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}
.method-label{display:flex;align-items:center;gap:14px;border:2px solid var(--border);
  border-radius:10px;padding:13px 16px;cursor:pointer;transition:.2s;position:relative}
.method-label:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.method-label input[type=radio]{width:18px;height:18px;accent-color:var(--blue);
  flex-shrink:0;cursor:pointer}
.method-icon{font-size:1.55rem;flex-shrink:0}
.method-content strong{font-size:.9rem;display:block;color:var(--text)}
.method-content span{font-size:.75rem;color:var(--muted);line-height:1.4}
.method-label.selected{border-color:var(--blue);background:var(--blue-pale)}
.method-label.selected .method-content strong{color:var(--blue)}
.method-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);
  background:var(--blue);color:#fff;font-size:.63rem;font-weight:700;
  padding:2px 7px;border-radius:99px}
/* METHOD DETAILS */
.method-detail{border:1px solid var(--border);border-radius:10px;padding:18px 20px;
  margin-bottom:18px;display:none;animation:fadeIn .25s ease}
.method-detail.visible{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.bank-info{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.bank-field{background:var(--bg);border-radius:8px;padding:10px 14px}
.bank-field label{font-size:.68rem;color:var(--muted);font-weight:600;
  text-transform:uppercase;display:block;margin-bottom:3px}
.bank-field span{font-size:.9rem;font-weight:700;color:var(--text)}
.bank-field.full{grid-column:1/-1}
.copy-btn{background:var(--blue-pale);border:1px solid var(--border);color:var(--blue);
  font-size:.72rem;font-weight:600;padding:3px 9px;border-radius:6px;
  cursor:pointer;margin-left:8px;transition:.2s}
.copy-btn:hover{background:var(--blue-mid)}
.bank-note{margin-top:12px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;
  padding:10px 14px;font-size:.78rem;color:#92400e;line-height:1.6}
.qr-wrap{text-align:center}
.qr-img{width:220px;height:220px;object-fit:contain;border-radius:10px;
  border:2px solid var(--border);padding:8px;background:#fff;
  display:block;margin:0 auto 12px}
.qr-amount{font-family:var(--serif);font-size:1.2rem;font-weight:700;
  color:var(--blue);margin-bottom:4px}
.qr-desc{font-size:.78rem;color:var(--muted)}
.arrival-box{display:flex;align-items:flex-start;gap:14px;
  background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px}
.arrival-box .ai{font-size:1.8rem}
.arrival-box p{font-size:.83rem;color:#166534;line-height:1.65}
.arrival-box strong{display:block;font-size:.9rem;margin-bottom:4px;color:#14532d}

/* ACTIONS */
.pay-actions{display:flex;align-items:center;justify-content:space-between;
  gap:12px;padding-top:8px;border-top:1px solid var(--border);margin-top:4px}
.btn-back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);
  font-size:.88rem;font-weight:600;text-decoration:none;padding:10px 16px;
  border:1px solid var(--border);border-radius:8px;background:#fff;transition:.2s}
.btn-back:hover{border-color:var(--blue-light);color:var(--blue);background:var(--blue-pale)}
.btn-confirm{flex:1;background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  color:#fff;font-size:.9rem;font-weight:700;padding:13px 24px;border:none;
  border-radius:9px;cursor:pointer;transition:.2s;
  display:flex;align-items:center;justify-content:center;gap:8px}
.btn-confirm:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(29,78,216,.35)}
.btn-confirm.full-mode{background:linear-gradient(135deg,#047857,#10b981)}
.btn-confirm.full-mode:hover{box-shadow:0 6px 20px rgba(5,150,105,.35)}

/* ALERT */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;
  font-size:.86rem;display:flex;align-items:center;gap:10px}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}

/* RESPONSIVE */
@media(max-width:740px){
  .pay-grid{grid-template-columns:1fr}
  .summary-card{position:static}
  .bank-info{grid-template-columns:1fr}
  .bank-field.full{grid-column:auto}
  .pay-type-toggle{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>

<nav class="topbar">
  <a href="../index.php" class="topbar-brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome">
    <span>Easyhome</span>
  </a>
  <div class="topbar-user">
    <span>👤 <?= htmlspecialchars($khName) ?></span>
    <a href="../logout.php">Đăng xuất</a>
  </div>
</nav>

<div class="page">

  <a href="?cancel=1" class="back-link"
     onclick="return confirm('Hủy và quay lại chọn phòng?')">← Quay lại chọn phòng</a>

  <!-- Stepper -->
  <div class="stepper">
    <div class="step done"><div class="step-num">✓</div><span>Điền thông tin</span></div>
    <div class="step-line done"></div>
    <div class="step active"><div class="step-num">2</div><span>Thanh toán</span></div>
    <div class="step-line"></div>
    <div class="step"><div class="step-num">3</div><span>Hoàn tất</span></div>
  </div>

  <h2 class="page-title">💳 Thanh Toán Đặt Phòng</h2>

  <?php if ($msg): ?>
  <div class="alert error">
    ⚠️ <?= $msg ?>
    <?php if (!isset($_SESSION['pending_booking'])): ?>
      <a href="dashboard.php?tab=booking" style="color:inherit;font-weight:700;margin-left:8px">→ Đặt phòng mới</a>
    <?php endif ?>
  </div>
  <?php endif ?>

  <?php if (isset($_SESSION['pending_booking'])): ?>
  <div class="pay-grid">

    <!-- ── TÓM TẮT ── -->
    <div class="summary-card">
      <div class="summary-head">
        <h3>📋 Tóm Tắt Đặt Phòng</h3>
        <p>Kiểm tra kỹ trước khi xác nhận</p>
      </div>
      <div class="summary-body">
        <div class="sum-room">
          <div class="sum-room-icon">
            <?= $b['loai']==='Đơn'?'🛏️':($b['loai']==='VIP'?'👑':($b['loai']==='Gia đình'?'👨‍👩‍👧':'🛏️🛏️')) ?>
          </div>
          <div class="sum-room-info">
            <h4>Phòng <?= htmlspecialchars($b['loai']) ?></h4>
            <p>Phòng <?= htmlspecialchars($b['maPhong']) ?> · <?= $b['soKhach'] ?> khách · <?= $b['days'] ?> đêm</p>
          </div>
        </div>
        <div class="sum-dates">
          <div class="sum-date"><label>Check-in</label><span><?= $checkinFmt ?></span></div>
          <div class="sum-date"><label>Check-out</label><span><?= $checkoutFmt ?></span></div>
        </div>
        <?php if (!empty($b['dvList'])): ?>
        <div class="sum-dv-list">🛎️&nbsp;
          <?php foreach ($b['dvList'] as $d): ?>
            <span><?= htmlspecialchars($d['TenDV']) ?></span>
          <?php endforeach ?>
        </div>
        <?php endif ?>
        <hr class="sum-divider">
        <div class="sum-row">
          <span class="lbl">Tiền phòng (<?= $b['days'] ?> đêm)</span>
          <span class="val"><?= number_format($b['tienPhong'],0,',','.') ?>đ</span>
        </div>
        <?php if ($b['tienDV'] > 0): ?>
        <div class="sum-row">
          <span class="lbl">Dịch vụ</span>
          <span class="val"><?= number_format($b['tienDV'],0,',','.') ?>đ</span>
        </div>
        <?php endif ?>
        <?php if ($b['giamGia'] > 0): ?>
        <div class="sum-row discount">
          <span class="lbl">🎁 Khuyến mãi</span>
          <span class="val">-<?= number_format($b['giamGia'],0,',','.') ?>đ</span>
        </div>
        <?php endif ?>
        <div class="sum-row vat">
          <span class="lbl">🧾 Thuế VAT (1%)</span>
          <span class="val"><?= number_format($b['vatAmt'],0,',','.') ?>đ</span>
        </div>
        <div class="sum-total">
          <span class="lbl">Tổng cộng <small style="font-weight:400;font-size:.7rem;color:var(--muted)">(đã gồm VAT)</small></span>
          <span class="val"><?= number_format($b['tongGia'],0,',','.') ?>đ</span>
        </div>
        <!-- Sum-coc: cập nhật bởi JS -->
        <div class="sum-coc deposit-mode" id="sumCocBox">
          <div class="lbl">
            <span id="sumCocLabel">⚡ Đặt cọc trước (30%)</span>
            <small id="sumCocSub">Phần còn lại thanh toán khi nhận phòng</small>
          </div>
          <div class="val" id="sumCocVal"><?= number_format($b['tienCoc'],0,',','.') ?>đ</div>
        </div>
      </div>
    </div>

    <!-- ── FORM THANH TOÁN ── -->
    <div>
      <form method="POST" id="payForm">
        <?= csrfField() ?>
        <input type="hidden" name="pay_type" id="payTypeInput" value="deposit">

        <div class="pay-card">
          <div class="pay-card-head">
            <h3>💰 Chọn Mức Thanh Toán</h3>
            <p id="paySubtitle">Bạn muốn thanh toán bao nhiêu ngay bây giờ?</p>
          </div>
          <div class="pay-card-body">

            <!-- TOGGLE: Cọc / Toàn bộ -->
            <div class="pay-type-toggle">
              <button type="button" class="pt-btn active" id="btnDeposit" onclick="setPayType('deposit')">
                <span class="pt-icon">⚡</span>
                <span class="pt-label">Chỉ đặt cọc</span>
                <span class="pt-amount" id="amtDeposit"><?= number_format($b['tienCoc'],0,',','.') ?>đ</span>
                <span style="font-size:.68rem;color:var(--muted)">(30% — trả còn lại khi đến)</span>
              </button>
              <button type="button" class="pt-btn" id="btnFull" onclick="setPayType('full')">
                <span class="pt-icon">💎</span>
                <span class="pt-label">Thanh toán toàn bộ</span>
                <span class="pt-amount" id="amtFull"><?= number_format($b['tongGia'],0,',','.') ?>đ</span>
                <span style="font-size:.68rem;color:var(--muted)">(100% — miễn lo khi nhận phòng)</span>
              </button>
            </div>
            <div class="pt-divider" id="payAmountNote">
              Bạn sẽ thanh toán: <strong id="payAmountLabel"><?= number_format($b['tienCoc'],0,',','.') ?>đ</strong> ngay bây giờ
            </div>

            <!-- PHƯƠNG THỨC -->
            <div style="font-size:.82rem;font-weight:700;color:var(--blue-dark);margin-bottom:10px">
              Chọn hình thức thanh toán:
            </div>
            <div class="method-list">

              <!-- Tiền mặt — chỉ hiện khi chọn cọc 30% -->
              <label class="method-label" id="lbl-tien_mat" onclick="selectMethod('tien_mat')">
                <input type="radio" name="phuong_thuc" value="tien_mat">
                <span class="method-icon">💵</span>
                <div class="method-content">
                  <strong>Tiền mặt khi đến</strong>
                  <span>Thanh toán trực tiếp tại quầy lễ tân khi nhận phòng</span>
                </div>
                <span class="method-badge">Phổ biến</span>
              </label>

              <!-- Chuyển khoản -->
              <label class="method-label" id="lbl-chuyen_khoan" onclick="selectMethod('chuyen_khoan')">
                <input type="radio" name="phuong_thuc" value="chuyen_khoan">
                <span class="method-icon">🏦</span>
                <div class="method-content">
                  <strong>Chuyển khoản ngân hàng</strong>
                  <span>Chuyển tiền vào tài khoản ngân hàng của khách sạn</span>
                </div>
              </label>

              <!-- QR -->
              <label class="method-label" id="lbl-qr_vietqr" onclick="selectMethod('qr_vietqr')">
                <input type="radio" name="phuong_thuc" value="qr_vietqr">
                <span class="method-icon">📱</span>
                <div class="method-content">
                  <strong>QR VietQR</strong>
                  <span>Quét mã QR bằng ứng dụng ngân hàng — nhanh &amp; tiện lợi</span>
                </div>
              </label>

            </div>

            <!-- Detail: Tiền mặt -->
            <div class="method-detail" id="detail-tien_mat">
              <div class="arrival-box">
                <div class="ai">🏨</div>
                <div>
                  <strong>Thanh toán tại khách sạn</strong>
                  <p>Đến đúng giờ check-in, nhân viên sẽ thu
                     <strong id="arrivalAmt"><?= number_format($b['tienCoc'],0,',','.') ?>đ</strong>
                     trực tiếp tại quầy.<br><br>
                     📍 <strong>Địa chỉ:</strong> Easyhome, TP. Huế<br>
                     ☎️ <strong>Hotline:</strong> 0768.466.686
                  </p>
                </div>
              </div>
            </div>

            <!-- Detail: Chuyển khoản -->
            <div class="method-detail" id="detail-chuyen_khoan">
              <div class="bank-info">
                <div class="bank-field">
                  <label>Ngân hàng</label>
                  <span><?= BANK_LABEL ?></span>
                </div>
                <div class="bank-field">
                  <label>Số tài khoản</label>
                  <span><?= BANK_ACCOUNT ?></span>
                  <button type="button" class="copy-btn" onclick="copyText('<?= BANK_ACCOUNT ?>', this)">Sao chép</button>
                </div>
                <div class="bank-field full">
                  <label>Chủ tài khoản</label>
                  <span><?= BANK_NAME ?></span>
                </div>
                <div class="bank-field full">
                  <label>Số tiền chuyển</label>
                  <span style="color:var(--blue);font-size:1rem" id="bankAmt"><?= number_format($b['tienCoc'],0,',','.') ?>đ</span>
                  <button type="button" class="copy-btn" id="bankAmtCopyBtn" onclick="copyText(currentPayAmt.toString(), this)">Sao chép</button>
                </div>
                <div class="bank-field full">
                  <label>Nội dung chuyển khoản</label>
                  <span><?= htmlspecialchars($qrDesc) ?></span>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($qrDesc, ENT_QUOTES) ?>', this)">Sao chép</button>
                </div>
              </div>
              <div class="bank-note">
                ⚠️ <strong>Lưu ý:</strong> Chuyển trong vòng <strong>2 giờ</strong> sau khi đặt, ghi đúng nội dung.
                Nhân viên sẽ xác nhận sau khi nhận được tiền.
              </div>
            </div>

            <!-- Detail: QR -->
            <div class="method-detail" id="detail-qr_vietqr">
              <div class="qr-wrap">
                <img id="qrImg" src="<?= $qrUrlDeposit ?>" alt="VietQR" class="qr-img"
                     onerror="this.style.opacity='.4'">
                <div class="qr-amount" id="qrAmt"><?= number_format($b['tienCoc'],0,',','.') ?>đ</div>
                <div class="qr-desc">
                  📱 Mở app ngân hàng → Quét QR → Xác nhận<br>
                  <span style="font-size:.72rem;color:var(--muted)">Hỗ trợ tất cả ngân hàng Việt Nam</span>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="pay-actions">
              <a href="?cancel=1" class="btn-back"
                 onclick="return confirm('Hủy và quay lại chọn phòng?')">← Quay lại</a>
              <button type="submit" class="btn-confirm" id="btnConfirm">
                🏨 Xác Nhận Đặt Phòng
              </button>
            </div>

          </div>
        </div>
      </form>
    </div>

  </div>
  <?php endif ?>

</div>

<script>
const DEPOSIT_AMT = <?= $b['tienCoc'] ?>;
const FULL_AMT    = <?= $b['tongGia'] ?>;
const QR_DEPOSIT  = '<?= $qrUrlDeposit ?>';
const QR_FULL     = '<?= $qrUrlFull ?>';
let currentPayAmt = DEPOSIT_AMT;
let currentPayType = 'deposit';

function fmt(n) {
  return n.toLocaleString('vi-VN') + 'đ';
}

function setPayType(type) {
  currentPayType = type;
  currentPayAmt  = type === 'full' ? FULL_AMT : DEPOSIT_AMT;
  document.getElementById('payTypeInput').value = type;

  const isDeposit = type === 'deposit';
  const amtFmt = fmt(currentPayAmt);

  // Toggle buttons
  const btnD = document.getElementById('btnDeposit');
  const btnF = document.getElementById('btnFull');
  btnD.classList.toggle('active', isDeposit);
  btnD.classList.remove('full-active');
  btnF.classList.toggle('full-active', !isDeposit);
  btnF.classList.remove('active');

  // Subtitle
  document.getElementById('payAmountLabel').textContent = amtFmt;

  // Summary coc box
  const box = document.getElementById('sumCocBox');
  box.className = 'sum-coc ' + (isDeposit ? 'deposit-mode' : 'full-mode');
  document.getElementById('sumCocLabel').textContent = isDeposit ? '⚡ Đặt cọc trước (30%)' : '💎 Thanh toán toàn bộ';
  document.getElementById('sumCocSub').textContent  = isDeposit
    ? 'Phần còn lại thanh toán khi nhận phòng'
    : 'Hoàn tất — không cần lo phần còn lại khi đến';
  document.getElementById('sumCocVal').textContent  = amtFmt;

  // Bank amount
  const bankAmt = document.getElementById('bankAmt');
  if (bankAmt) bankAmt.textContent = amtFmt;

  // QR
  const qrImg = document.getElementById('qrImg');
  if (qrImg) qrImg.src = isDeposit ? QR_DEPOSIT : QR_FULL;
  const qrAmt = document.getElementById('qrAmt');
  if (qrAmt) qrAmt.textContent = amtFmt;

  // Arrival text
  const arr = document.getElementById('arrivalAmt');
  if (arr) arr.textContent = amtFmt;

  // Hide/show "Tiền mặt" option if full payment
  const tienMatLabel = document.getElementById('lbl-tien_mat');
  if (!isDeposit) {
    tienMatLabel.style.opacity = '0.3';
    tienMatLabel.style.pointerEvents = 'none';
    // Deselect if currently chosen
    const radio = tienMatLabel.querySelector('input[type=radio]');
    if (radio && radio.checked) {
      radio.checked = false;
      tienMatLabel.classList.remove('selected');
      document.getElementById('detail-tien_mat').classList.remove('visible');
    }
  } else {
    tienMatLabel.style.opacity = '';
    tienMatLabel.style.pointerEvents = '';
  }

  // Confirm button style
  const btn = document.getElementById('btnConfirm');
  if (!isDeposit) {
    btn.className = 'btn-confirm full-mode';
    btn.innerHTML = `💎 Xác Nhận & Thanh Toán ${amtFmt}`;
  } else {
    btn.className = 'btn-confirm';
    btn.innerHTML = '🏨 Xác Nhận Đặt Phòng';
  }
}

function selectMethod(val) {
  document.querySelectorAll('.method-label').forEach(l => l.classList.remove('selected'));
  document.getElementById('lbl-' + val)?.classList.add('selected');
  document.querySelectorAll('.method-detail').forEach(d => d.classList.remove('visible'));
  document.getElementById('detail-' + val)?.classList.add('visible');
}

function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✓ Đã chép';
    btn.style.background = '#d1fae5';
    setTimeout(() => { btn.textContent = orig; btn.style.background = ''; }, 1800);
  }).catch(() => alert('Vui lòng chép thủ công: ' + text));
}

// Restore on error re-display
const checkedRadio = document.querySelector('input[name=phuong_thuc]:checked');
if (checkedRadio) selectMethod(checkedRadio.value);

// Prevent double-submit
document.getElementById('payForm')?.addEventListener('submit', function(e) {
  const radio = this.querySelector('input[name=phuong_thuc]:checked');
  if (!radio) { e.preventDefault(); alert('Vui lòng chọn hình thức thanh toán!'); return; }
  const btn = document.getElementById('btnConfirm');
  btn.disabled = true;
  btn.innerHTML = '⏳ Đang xử lý...';
});
</script>

</body>
</html>
