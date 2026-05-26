<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['kh_id'])) {
    header('Location: ../login.php'); exit;
}

$khId   = $_SESSION['kh_id'];
$khName = $_SESSION['kh_name'];

// ── Thông tin ngân hàng ──────────────────────────────────────────────────────
// ⚠️ Đổi thành tài khoản thật của khách sạn
const BANK_BIN     = '970422';
const BANK_ACCOUNT = '1234567890';
const BANK_NAME    = 'NHA NGHI EASYHOME';
const BANK_LABEL   = 'MB Bank (Ngân hàng Quân đội)';

// ── Load booking ────────────────────────────────────────────────────────────
$maDP = trim($_GET['dp'] ?? '');
if (!$maDP) { header('Location: dashboard.php?tab=history'); exit; }

$stmt = $pdo->prepare("
    SELECT dp.*, p.LoaiPhong, p.Tang,
           GROUP_CONCAT(dv.TenDV SEPARATOR ', ') AS DichVu
    FROM DAT_PHONG dp
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    LEFT JOIN DAT_DICH_VU ddv ON dp.MaDP = ddv.MaDP
    LEFT JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    WHERE dp.MaDP = :dp AND dp.MaKH = :kh
    GROUP BY dp.MaDP
");
$stmt->execute([':dp' => $maDP, ':kh' => $khId]);
$bk = $stmt->fetch();

if (!$bk) { header('Location: dashboard.php?tab=history'); exit; }

// Kiểm tra điều kiện cho phép thanh toán phần còn lại
$remaining = $bk['TongGia'] - $bk['TienCoc'];
$eligible  = $remaining > 0
    && in_array($bk['TrangThai'], ['Chờ xác nhận', 'Đã nhận phòng']);

if (!$eligible) { header('Location: dashboard.php?tab=history'); exit; }

// Lấy thông tin khách
$khStmt = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE MaKH = :id");
$khStmt->execute([':id' => $khId]);
$kh = $khStmt->fetch();

$msg = ''; $msgType = 'error';

// ── Xử lý POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $phuongThuc = trim($_POST['phuong_thuc'] ?? '');
    $validMethods = ['chuyen_khoan', 'qr_vietqr'];

    if (!in_array($phuongThuc, $validMethods)) {
        $msg = 'Vui lòng chọn hình thức thanh toán.';
    } else {
        // Ghi nhận phương thức và đánh dấu đã thanh toán đủ
        $ptNames = [
            'chuyen_khoan' => 'Chuyển khoản ngân hàng',
            'qr_vietqr'    => 'QR VietQR',
        ];

        $ghiChuCu   = $bk['GhiChu'] ?? '';
        $ghiChuMoi  = trim($ghiChuCu . " [BALANCE_PT:{$phuongThuc}]");

        // Cập nhật TienCoc = TongGia (đã thanh toán đủ) + ghi nhận PT
        $pdo->prepare("
            UPDATE DAT_PHONG
            SET TienCoc = TongGia, GhiChu = :ghi
            WHERE MaDP = :dp AND MaKH = :kh
        ")->execute([':ghi' => $ghiChuMoi, ':dp' => $maDP, ':kh' => $khId]);

        $_SESSION['balance_success'] = [
            'maDP'   => $maDP,
            'soTien' => number_format($remaining, 0, ',', '.') . 'đ',
            'ptNote' => $ptNames[$phuongThuc],
        ];
        header('Location: dashboard.php?tab=history'); exit;
    }
}

// ── Build QR ─────────────────────────────────────────────────────────────────
$qrDesc = "Con lai " . $maDP . " Easyhome";
$qrUrl  = "https://img.vietqr.io/image/" . BANK_BIN . "-" . BANK_ACCOUNT . "-compact2.png"
        . "?amount=" . $remaining
        . "&addInfo=" . urlencode($qrDesc)
        . "&accountName=" . urlencode(BANK_NAME);

$checkinFmt  = date('d/m/Y H:i', strtotime($bk['NgayCheckIn']));
$checkoutFmt = date('d/m/Y H:i', strtotime($bk['NgayCheckOut']));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Thanh Toán Phần Còn Lại — Easyhome</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --green:#10b981;
  --shadow:0 4px 24px rgba(29,78,216,.10);
  --radius:12px;--font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
html,body{min-height:100%;font-family:var(--font);color:var(--text)}
body{background:var(--bg)}

.topbar{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:62px;position:sticky;top:0;z-index:100;
  box-shadow:0 2px 12px rgba(29,78,216,.25)}
.topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.topbar-brand img{width:34px;height:34px;border-radius:6px;object-fit:cover}
.topbar-brand span{color:#fff;font-family:var(--serif);font-size:1.25rem;font-weight:600}
.topbar-user{display:flex;align-items:center;gap:8px;color:#fff;font-size:.9rem}
.topbar-user a{color:rgba(255,255,255,.75);text-decoration:none;font-size:.82rem;
  padding:4px 10px;border:1px solid rgba(255,255,255,.35);border-radius:6px;transition:.2s}
.topbar-user a:hover{background:rgba(255,255,255,.18);color:#fff}

.page{max-width:900px;margin:0 auto;padding:36px 20px 60px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--blue);
  font-size:.88rem;font-weight:600;text-decoration:none;margin-bottom:20px;transition:.2s}
.back-link:hover{color:var(--blue-dark);gap:8px}
.page-title{font-family:var(--serif);font-size:1.5rem;color:var(--blue-dark);
  text-align:center;margin-bottom:26px}

/* LAYOUT */
.pay-grid{display:grid;grid-template-columns:340px 1fr;gap:24px;align-items:start}

/* SUMMARY */
.summary-card{background:#fff;border-radius:var(--radius);
  box-shadow:var(--shadow);border:1px solid var(--border);
  overflow:hidden;position:sticky;top:82px}
.sum-head{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:16px 20px;color:#fff}
.sum-head h3{font-family:var(--serif);font-size:1rem;margin-bottom:2px}
.sum-head p{font-size:.78rem;opacity:.8}
.sum-body{padding:18px 20px}
.sum-booking-id{
  background:var(--blue-pale);border-radius:8px;padding:8px 14px;margin-bottom:14px;
  font-size:.88rem;font-weight:700;color:var(--blue-dark);display:flex;
  align-items:center;gap:8px
}
.sum-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.sum-date{background:var(--bg);border-radius:7px;padding:8px 10px;text-align:center}
.sum-date label{font-size:.68rem;color:var(--muted);font-weight:600;
  text-transform:uppercase;display:block;margin-bottom:2px}
.sum-date span{font-size:.82rem;font-weight:700;color:var(--text)}
.sum-divider{border:none;border-top:1px solid var(--border);margin:12px 0}
.sum-row{display:flex;justify-content:space-between;padding:4px 0;font-size:.83rem}
.sum-row .lbl{color:var(--muted)}
.sum-row .val{font-weight:600;color:var(--text)}
.sum-row.paid .val{color:var(--green)}
.sum-total-box{background:var(--blue-pale);border-radius:8px;padding:10px 12px;margin:10px 0 6px}
.sum-total-box .row{display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:4px}
.sum-total-box .row:last-child{margin-bottom:0}
.sum-remaining{
  display:flex;justify-content:space-between;align-items:center;
  background:linear-gradient(90deg,#f59e0b,#fbbf24);
  border-radius:8px;padding:12px 14px;color:#fff
}
.sum-remaining .lbl{font-size:.82rem;font-weight:700}
.sum-remaining .lbl small{display:block;font-size:.7rem;opacity:.85;font-weight:400;margin-top:1px}
.sum-remaining .val{font-family:var(--serif);font-size:1.18rem;font-weight:700}

/* PAY CARD */
.pay-card{background:#fff;border-radius:var(--radius);
  box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.pay-card-head{padding:18px 24px 14px;border-bottom:1px solid var(--border)}
.pay-card-head h3{font-family:var(--serif);font-size:1.1rem;color:var(--blue-dark)}
.pay-card-head p{font-size:.8rem;color:var(--muted);margin-top:3px}
.pay-card-body{padding:20px 24px}

/* METHODS */
.method-list{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}
.method-label{display:flex;align-items:center;gap:14px;border:2px solid var(--border);
  border-radius:10px;padding:13px 16px;cursor:pointer;transition:.2s}
.method-label:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.method-label input[type=radio]{width:18px;height:18px;accent-color:var(--blue);flex-shrink:0}
.method-icon{font-size:1.55rem;flex-shrink:0}
.method-content strong{font-size:.9rem;display:block;color:var(--text)}
.method-content span{font-size:.75rem;color:var(--muted);line-height:1.4}
.method-label.selected{border-color:var(--blue);background:var(--blue-pale)}
.method-label.selected .method-content strong{color:var(--blue)}

/* DETAIL PANELS */
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
  color:var(--amber,#f59e0b);margin-bottom:4px}
.qr-desc{font-size:.78rem;color:var(--muted)}

/* ACTIONS */
.pay-actions{display:flex;align-items:center;justify-content:space-between;
  gap:12px;padding-top:8px;border-top:1px solid var(--border);margin-top:4px}
.btn-back{display:inline-flex;align-items:center;gap:6px;color:var(--muted);
  font-size:.88rem;font-weight:600;text-decoration:none;padding:10px 16px;
  border:1px solid var(--border);border-radius:8px;background:#fff;transition:.2s}
.btn-back:hover{border-color:var(--blue-light);color:var(--blue);background:var(--blue-pale)}
.btn-confirm{flex:1;background:linear-gradient(135deg,#b45309,#f59e0b);
  color:#fff;font-size:.9rem;font-weight:700;padding:13px 24px;border:none;
  border-radius:9px;cursor:pointer;transition:.2s;
  display:flex;align-items:center;justify-content:center;gap:8px}
.btn-confirm:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(245,158,11,.4)}

.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;
  font-size:.86rem;display:flex;align-items:center;gap:10px}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}

@media(max-width:700px){
  .pay-grid{grid-template-columns:1fr}
  .summary-card{position:static}
  .bank-info{grid-template-columns:1fr}
  .bank-field.full{grid-column:auto}
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

  <a href="dashboard.php?tab=history" class="back-link">← Quay lại lịch sử</a>

  <h2 class="page-title">💰 Thanh Toán Phần Còn Lại</h2>

  <?php if ($msg): ?>
  <div class="alert error">⚠️ <?= htmlspecialchars($msg) ?></div>
  <?php endif ?>

  <div class="pay-grid">

    <!-- ── TÓM TẮT ── -->
    <div class="summary-card">
      <div class="sum-head">
        <h3>📋 Thông Tin Đặt Phòng</h3>
        <p>Thanh toán phần còn lại cho đơn này</p>
      </div>
      <div class="sum-body">

        <div class="sum-booking-id">
          🏷️ Đơn <?= htmlspecialchars($maDP) ?>
          <span style="margin-left:auto;font-size:.73rem;background:var(--blue-mid);
            color:var(--blue);padding:2px 8px;border-radius:99px;font-weight:600">
            <?= htmlspecialchars($bk['TrangThai']) ?>
          </span>
        </div>

        <div style="margin-bottom:12px;font-size:.83rem;font-weight:600;color:var(--text)">
          🏨 Phòng <?= htmlspecialchars($bk['LoaiPhong']) ?> — <?= htmlspecialchars($bk['MaPhong']) ?>
          (Tầng <?= htmlspecialchars($bk['Tang']) ?>)
        </div>

        <div class="sum-dates">
          <div class="sum-date"><label>Check-in</label><span><?= $checkinFmt ?></span></div>
          <div class="sum-date"><label>Check-out</label><span><?= $checkoutFmt ?></span></div>
        </div>

        <?php if ($bk['DichVu']): ?>
        <div style="font-size:.77rem;color:var(--muted);margin-bottom:12px">
          🛎️ <?= htmlspecialchars($bk['DichVu']) ?>
        </div>
        <?php endif ?>

        <hr class="sum-divider">

        <div class="sum-total-box">
          <div class="row">
            <span style="color:var(--muted)">Tổng đơn</span>
            <span style="font-weight:700"><?= number_format($bk['TongGia'],0,',','.') ?>đ</span>
          </div>
          <div class="row">
            <span style="color:var(--green)">✓ Đã thanh toán (cọc)</span>
            <span style="font-weight:700;color:var(--green)">-<?= number_format($bk['TienCoc'],0,',','.') ?>đ</span>
          </div>
        </div>

        <div class="sum-remaining">
          <div class="lbl">
            💳 Phần còn lại
            <small>Thanh toán ngay bây giờ</small>
          </div>
          <div class="val"><?= number_format($remaining,0,',','.') ?>đ</div>
        </div>

      </div>
    </div>

    <!-- ── FORM ── -->
    <div>
      <form method="POST" id="payForm">
        <?= csrfField() ?>
        <div class="pay-card">
          <div class="pay-card-head">
            <h3>🏦 Chọn Hình Thức Thanh Toán</h3>
            <p>Chuyển khoản <strong><?= number_format($remaining,0,',','.') ?>đ</strong> phần còn lại của đơn <strong><?= htmlspecialchars($maDP) ?></strong></p>
          </div>
          <div class="pay-card-body">

            <div class="method-list">

              <!-- Chuyển khoản -->
              <label class="method-label" id="lbl-chuyen_khoan" onclick="selectMethod('chuyen_khoan')">
                <input type="radio" name="phuong_thuc" value="chuyen_khoan">
                <span class="method-icon">🏦</span>
                <div class="method-content">
                  <strong>Chuyển khoản ngân hàng</strong>
                  <span>Chuyển tiền vào tài khoản ngân hàng của khách sạn</span>
                </div>
              </label>

              <!-- QR VietQR -->
              <label class="method-label" id="lbl-qr_vietqr" onclick="selectMethod('qr_vietqr')">
                <input type="radio" name="phuong_thuc" value="qr_vietqr">
                <span class="method-icon">📱</span>
                <div class="method-content">
                  <strong>QR VietQR</strong>
                  <span>Quét mã QR — hỗ trợ tất cả ngân hàng Việt Nam</span>
                </div>
              </label>

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
                  <span style="color:#f59e0b;font-size:1rem;font-weight:800"><?= number_format($remaining,0,',','.') ?>đ</span>
                  <button type="button" class="copy-btn" onclick="copyText('<?= $remaining ?>', this)">Sao chép</button>
                </div>
                <div class="bank-field full">
                  <label>Nội dung chuyển khoản</label>
                  <span><?= htmlspecialchars($qrDesc) ?></span>
                  <button type="button" class="copy-btn" onclick="copyText('<?= htmlspecialchars($qrDesc, ENT_QUOTES) ?>', this)">Sao chép</button>
                </div>
              </div>
              <div class="bank-note">
                ⚠️ <strong>Lưu ý:</strong> Ghi đúng nội dung chuyển khoản để nhân viên dễ xác nhận.
                Nhân viên sẽ cập nhật tình trạng thanh toán sau khi kiểm tra.
              </div>
            </div>

            <!-- Detail: QR -->
            <div class="method-detail" id="detail-qr_vietqr">
              <div class="qr-wrap">
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="VietQR" class="qr-img"
                     onerror="this.style.opacity='.4'">
                <div class="qr-amount"><?= number_format($remaining,0,',','.') ?>đ</div>
                <div class="qr-desc">
                  📱 Mở app ngân hàng → Quét QR → Xác nhận<br>
                  <span style="font-size:.72rem">Nội dung: <strong><?= htmlspecialchars($qrDesc) ?></strong></span>
                </div>
              </div>
            </div>

            <div class="pay-actions">
              <a href="dashboard.php?tab=history" class="btn-back">← Quay lại</a>
              <button type="submit" class="btn-confirm" id="btnConfirm">
                💰 Xác Nhận Thanh Toán <?= number_format($remaining,0,',','.') ?>đ
              </button>
            </div>

          </div>
        </div>
      </form>
    </div>

  </div>

</div>

<script>
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
