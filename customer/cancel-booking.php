<?php
// customer/cancel-booking.php — Hủy đặt phòng & yêu cầu hoàn tiền
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['kh_id'])) {
    header('Location: ../login.php'); exit;
}

$khId   = $_SESSION['kh_id'];
$khName = $_SESSION['kh_name'];
$maDP   = trim($_GET['dp'] ?? '');
$err    = '';

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n) { return number_format((float)$n, 0, ',', '.'); }

// ── Validate tham số ─────────────────────────────────────────────────────────
if (!$maDP) { header('Location: dashboard.php?tab=history'); exit; }

$stmt = $pdo->prepare("
    SELECT dp.*, p.LoaiPhong, p.Tang
    FROM DAT_PHONG dp
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    WHERE dp.MaDP = :dp AND dp.MaKH = :kh
");
$stmt->execute([':dp' => $maDP, ':kh' => $khId]);
$bk = $stmt->fetch();

if (!$bk) { header('Location: dashboard.php?tab=history'); exit; }

// Chỉ cho phép hủy khi 'Chờ xác nhận'
if ($bk['TrangThai'] !== 'Chờ xác nhận') {
    header('Location: dashboard.php?tab=history'); exit;
}

// ── Tính hoàn tiền theo chính sách ──────────────────────────────────────────
$tienCoc   = (float)$bk['TienCoc'];
$tongGia   = (float)$bk['TongGia'];
$checkinTs = strtotime($bk['NgayCheckIn']);
$daysLeft  = ($checkinTs - time()) / 86400;

if ($daysLeft >= 3) {
    $refundPct   = 100;
    $policyClass = 'good';
    $policyLabel = 'Hoàn 100%';
    $policyColor = '#16a34a';
    $policyBg    = '#dcfce7';
    $policyDesc  = '✅ Hủy trước <strong>3 ngày</strong> trở lên — hoàn lại <strong>100%</strong> số tiền đã thanh toán';
} elseif ($daysLeft >= 1) {
    $refundPct   = 50;
    $policyClass = 'mid';
    $policyLabel = 'Hoàn 50%';
    $policyColor = '#d97706';
    $policyBg    = '#fef3c7';
    $policyDesc  = '⚠️ Hủy trước <strong>1–2 ngày</strong> — hoàn lại <strong>50%</strong> số tiền đã thanh toán';
} else {
    $refundPct   = 0;
    $policyClass = 'bad';
    $policyLabel = 'Không hoàn';
    $policyColor = '#dc2626';
    $policyBg    = '#fee2e2';
    $policyDesc  = '❌ Hủy <strong>trong ngày</strong> check-in — <strong>không hoàn tiền</strong>';
}

$refundAmt   = (int)round($tienCoc * $refundPct / 100);
$daysLeftStr = $daysLeft >= 1
    ? number_format($daysLeft, 1) . ' ngày nữa'
    : ($daysLeft > 0 ? 'chưa đến 1 ngày' : 'đã qua ngày check-in');

// ── Xử lý POST: Xác nhận hủy ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    // Kiểm tra lại trạng thái (race-condition safe)
    $verify = $pdo->prepare("SELECT MaDP FROM DAT_PHONG WHERE MaDP=:dp AND MaKH=:kh AND TrangThai='Chờ xác nhận'");
    $verify->execute([':dp' => $maDP, ':kh' => $khId]);
    if (!$verify->fetch()) { header('Location: dashboard.php?tab=history'); exit; }

    $lyDo    = trim(substr(preg_replace('/[\[\]]/', '', $_POST['ly_do']    ?? ''), 0, 200));
    $bankStk = trim(preg_replace('/[\[\]|]/', '', $_POST['bank_stk'] ?? ''));
    $bankNH  = trim(preg_replace('/[\[\]|]/', '', $_POST['bank_nh']  ?? ''));
    $bankTen = trim(preg_replace('/[\[\]|]/', '', $_POST['bank_ten'] ?? ''));

    if ($refundAmt > 0 && (!$bankStk || !$bankNH || !$bankTen)) {
        $err = 'Vui lòng điền đầy đủ thông tin ngân hàng để nhận hoàn tiền.';
    } else {
        $ts   = date('Y-m-d H:i');
        $tags = " [HUY_KH:{$ts}][HOAN_TIEN:{$refundAmt}]";
        if ($refundAmt > 0) {
            $tags .= "[HOAN_TK:{$bankStk}|{$bankNH}|{$bankTen}]";
        }
        if ($lyDo) { $tags .= "[LY_DO:{$lyDo}]"; }

        $ghiChuMoi = trim($bk['GhiChu'] . $tags);
        $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã hủy', GhiChu=:gc WHERE MaDP=:dp AND MaKH=:kh")
            ->execute([':gc' => $ghiChuMoi, ':dp' => $maDP, ':kh' => $khId]);

        $_SESSION['cancel_success'] = [
            'maDP'      => $maDP,
            'refundAmt' => $refundAmt,
            'refundPct' => $refundPct,
        ];
        header('Location: dashboard.php?tab=history'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Hủy Đặt Phòng — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:       #1d4ed8;
  --blue-light: #3b82f6;
  --blue-dark:  #1e3a8a;
  --blue-pale:  #eff6ff;
  --bg:         #f0f6ff;
  --text:       #1e293b;
  --muted:      #64748b;
  --border:     #bfdbfe;
  --radius:     10px;
  --shadow:     0 4px 24px rgba(29,78,216,.10);
  --font:       Calibri,'Calibri Light',Arial,sans-serif;
  --serif:      'Playfair Display',Georgia,serif;
}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;
     display:flex;flex-direction:column;align-items:center;padding:0 16px 48px}

/* ── Header ── */
.page-header{width:100%;max-width:640px;padding:28px 0 18px;display:flex;align-items:center;gap:14px}
.page-header .logo{height:38px;border-radius:6px}
.page-header h1{font-family:var(--serif);font-size:1.25rem;color:var(--blue-dark)}
.page-header .back-link{margin-left:auto;font-size:.8rem;color:var(--muted);text-decoration:none;
  display:flex;align-items:center;gap:4px;padding:6px 12px;border-radius:7px;border:1px solid var(--border);
  background:#fff;transition:.2s}
.page-header .back-link:hover{border-color:var(--blue);color:var(--blue)}

/* ── Stepper ── */
.stepper{display:flex;align-items:center;gap:0;width:100%;max-width:640px;margin-bottom:24px}
.step{display:flex;align-items:center;gap:8px;flex:1}
.step-num{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-weight:700;flex-shrink:0}
.step.done .step-num{background:#dcfce7;color:#16a34a;border:2px solid #16a34a}
.step.active .step-num{background:var(--blue);color:#fff;border:2px solid var(--blue)}
.step.wait .step-num{background:#f1f5f9;color:#94a3b8;border:2px solid #cbd5e1}
.step-label{font-size:.78rem;font-weight:600;color:var(--muted)}
.step.active .step-label{color:var(--blue-dark)}
.step.done .step-label{color:#16a34a}
.step-line{flex:1;height:2px;background:#e2e8f0;margin:0 8px}
.step.done ~ .step-line,.step-line.done{background:#16a34a}

/* ── Card ── */
.card{background:#fff;border-radius:14px;box-shadow:var(--shadow);width:100%;max-width:640px;overflow:hidden;margin-bottom:16px}
.card-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:.88rem;color:var(--blue-dark);
  display:flex;align-items:center;gap:8px}

/* ── Booking summary ── */
.sum-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;padding:18px 22px}
.sum-row{display:flex;flex-direction:column;gap:3px;padding:8px 0;border-bottom:1px solid #f1f5f9}
.sum-row:last-child{border-bottom:none}
.sum-label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.sum-val{font-size:.9rem;font-weight:700;color:var(--text)}
.sum-full{grid-column:span 2}

/* ── Policy table ── */
.policy-table{width:100%;border-collapse:collapse}
.policy-table th,.policy-table td{padding:10px 16px;text-align:left;font-size:.8rem;border-bottom:1px solid #f1f5f9}
.policy-table th{background:#f8fafc;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:.72rem}
.policy-table tr.highlight td{font-weight:700}
.policy-row-good td{background:#dcfce750;color:#16a34a}
.policy-row-mid  td{background:#fef3c750;color:#d97706}
.policy-row-bad  td{background:#fee2e250;color:#dc2626}

/* ── Refund amount box ── */
.refund-box{padding:18px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.refund-label{font-size:.82rem;color:var(--muted)}
.refund-amount{font-size:1.6rem;font-weight:800}
.refund-amount.green{color:#16a34a}
.refund-amount.amber{color:#d97706}
.refund-amount.red{color:#dc2626}
.refund-sub{font-size:.75rem;color:var(--muted);margin-top:4px}

/* ── Policy banner ── */
.policy-banner{padding:12px 22px;font-size:.82rem;border-radius:0;border-left:4px solid currentColor}
.policy-banner.good{background:#dcfce7;color:#16a34a;border-color:#16a34a}
.policy-banner.mid {background:#fef3c7;color:#d97706;border-color:#d97706}
.policy-banner.bad {background:#fee2e2;color:#dc2626;border-color:#dc2626}

/* ── Form ── */
.form-body{padding:18px 22px;display:flex;flex-direction:column;gap:14px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-label{font-size:.78rem;font-weight:700;color:var(--text)}
.form-label .req{color:#dc2626}
.form-input{padding:10px 13px;border:1.5px solid var(--border);border-radius:8px;
  font-family:var(--font);font-size:.87rem;color:var(--text);background:#fff;transition:.2s;width:100%}
.form-input:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(29,78,216,.08)}
.form-hint{font-size:.73rem;color:var(--muted);margin-top:2px}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* ── Error ── */
.err-box{background:#fee2e2;border:1.5px solid #fca5a5;color:#b91c1c;border-radius:8px;
  padding:11px 16px;font-size:.83rem;margin:0 22px 4px}

/* ── Buttons ── */
.btn-row{padding:18px 22px;display:flex;gap:12px;align-items:center;border-top:1px solid #f1f5f9;flex-wrap:wrap}
.btn-confirm-cancel{
  flex:1;padding:13px;border:none;border-radius:9px;cursor:pointer;
  font-family:var(--font);font-size:.88rem;font-weight:700;
  background:linear-gradient(135deg,#b91c1c,#dc2626);color:#fff;transition:.2s;min-width:200px}
.btn-confirm-cancel:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(220,38,38,.35)}
.btn-back{
  padding:13px 22px;border:1.5px solid var(--border);border-radius:9px;cursor:pointer;
  font-family:var(--font);font-size:.85rem;font-weight:600;background:#fff;color:var(--muted);
  text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:.2s}
.btn-back:hover{border-color:var(--blue);color:var(--blue)}

/* ── No-refund no-bank note ── */
.no-refund-info{padding:16px 22px;font-size:.82rem;color:#6b7280;
  background:#f8fafc;border-radius:0 0 14px 14px;display:flex;align-items:center;gap:8px}

/* ── Warning box ── */
.warn-box{background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;
  padding:14px 18px;font-size:.82rem;color:#92400e;margin:0 22px 0;display:flex;gap:10px}
</style>
</head>
<body>

<!-- ══ Header ══ -->
<div class="page-header">
  <img src="../assets/images/logo.jpg" alt="Easyhome" class="logo">
  <h1>Hủy Đặt Phòng</h1>
  <a href="dashboard.php?tab=history" class="back-link">← Quay lại</a>
</div>

<!-- ══ Stepper ══ -->
<div class="stepper">
  <div class="step done">
    <div class="step-num">✓</div>
    <div class="step-label">Chi tiết đặt phòng</div>
  </div>
  <div class="step-line done"></div>
  <div class="step active">
    <div class="step-num">2</div>
    <div class="step-label">Xác nhận hủy</div>
  </div>
</div>

<!-- ══ Booking summary ══ -->
<div class="card">
  <div class="card-head">📋 Thông tin đặt phòng</div>
  <div class="sum-grid">
    <div class="sum-row">
      <div class="sum-label">Mã đặt phòng</div>
      <div class="sum-val"><?= esc($bk['MaDP']) ?></div>
    </div>
    <div class="sum-row">
      <div class="sum-label">Phòng</div>
      <div class="sum-val"><?= esc($bk['MaPhong']) ?> — <?= esc($bk['LoaiPhong']) ?> (Tầng <?= esc($bk['Tang']) ?>)</div>
    </div>
    <div class="sum-row">
      <div class="sum-label">Check-in</div>
      <div class="sum-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckIn'])) ?></div>
    </div>
    <div class="sum-row">
      <div class="sum-label">Check-out</div>
      <div class="sum-val"><?= date('d/m/Y H:i', strtotime($bk['NgayCheckOut'])) ?></div>
    </div>
    <div class="sum-row">
      <div class="sum-label">Tổng giá trị</div>
      <div class="sum-val" style="color:var(--blue-dark)"><?= fmt($tongGia) ?>đ</div>
    </div>
    <div class="sum-row">
      <div class="sum-label">Đã thanh toán</div>
      <div class="sum-val" style="color:<?= $tienCoc > 0 ? '#16a34a' : '#94a3b8' ?>">
        <?= $tienCoc > 0 ? fmt($tienCoc) . 'đ' : 'Chưa thanh toán' ?>
      </div>
    </div>
    <div class="sum-row sum-full">
      <div class="sum-label">Thời gian còn đến check-in</div>
      <div class="sum-val" style="color:<?= $daysLeft >= 3 ? '#16a34a' : ($daysLeft >= 1 ? '#d97706' : '#dc2626') ?>">
        <?= esc($daysLeftStr) ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ Refund policy ══ -->
<div class="card">
  <div class="card-head">📜 Chính sách hoàn tiền</div>
  <table class="policy-table">
    <thead>
      <tr><th>Thời điểm hủy</th><th>Tỷ lệ hoàn tiền</th><th>Trạng thái</th></tr>
    </thead>
    <tbody>
      <tr class="<?= $policyClass === 'good' ? 'policy-row-good highlight' : '' ?>">
        <td>Trước 3 ngày check-in trở lên</td>
        <td>100% tiền đã thanh toán</td>
        <td><?= $policyClass === 'good' ? '← Đang áp dụng' : '' ?></td>
      </tr>
      <tr class="<?= $policyClass === 'mid' ? 'policy-row-mid highlight' : '' ?>">
        <td>Trước 1–2 ngày check-in</td>
        <td>50% tiền đã thanh toán</td>
        <td><?= $policyClass === 'mid' ? '← Đang áp dụng' : '' ?></td>
      </tr>
      <tr class="<?= $policyClass === 'bad' ? 'policy-row-bad highlight' : '' ?>">
        <td>Trong ngày check-in</td>
        <td>Không hoàn tiền</td>
        <td><?= $policyClass === 'bad' ? '← Đang áp dụng' : '' ?></td>
      </tr>
    </tbody>
  </table>
  <div class="policy-banner <?= $policyClass ?>"><?= $policyDesc ?></div>

  <!-- Số tiền hoàn lại -->
  <div class="refund-box">
    <div>
      <div class="refund-label">Số tiền sẽ được hoàn lại</div>
      <div class="refund-sub">
        <?= $tienCoc > 0
            ? "Dựa trên số tiền đã thanh toán: " . fmt($tienCoc) . "đ × " . $refundPct . "%"
            : "Chưa có khoản tiền nào được thanh toán" ?>
      </div>
    </div>
    <div class="refund-amount <?= $policyClass === 'good' ? 'green' : ($policyClass === 'mid' ? 'amber' : 'red') ?>">
      <?= fmt($refundAmt) ?>đ
    </div>
  </div>
</div>

<!-- ══ Form hủy ══ -->
<form method="POST">
  <?= csrfField() ?>

  <?php if ($err): ?>
  <div class="err-box" style="margin-bottom:12px">⚠️ <?= esc($err) ?></div>
  <?php endif; ?>

  <!-- Thông tin ngân hàng (chỉ khi có tiền cần hoàn) -->
  <?php if ($refundAmt > 0): ?>
  <div class="card">
    <div class="card-head">🏦 Thông tin ngân hàng nhận hoàn tiền</div>
    <div class="form-body">
      <p style="font-size:.8rem;color:var(--muted);margin-top:-4px">
        Nhân viên sẽ chuyển <strong><?= fmt($refundAmt) ?>đ</strong> vào tài khoản bạn cung cấp trong vòng <strong>1–3 ngày làm việc</strong>.
      </p>
      <div class="form-group">
        <label class="form-label">Số tài khoản ngân hàng <span class="req">*</span></label>
        <input type="text" name="bank_stk" class="form-input" placeholder="VD: 1234567890"
               value="<?= esc($_POST['bank_stk'] ?? '') ?>" inputmode="numeric">
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Ngân hàng <span class="req">*</span></label>
          <input type="text" name="bank_nh" class="form-input" placeholder="VD: Vietcombank, MB Bank…"
                 value="<?= esc($_POST['bank_nh'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Tên chủ tài khoản <span class="req">*</span></label>
          <input type="text" name="bank_ten" class="form-input" placeholder="Viết hoa không dấu"
                 value="<?= esc($_POST['bank_ten'] ?? '') ?>">
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="no-refund-info">
      ℹ️
      <?php if ($tienCoc <= 0): ?>
        Bạn chưa thanh toán khoản nào — hủy phòng này sẽ không cần hoàn tiền.
      <?php else: ?>
        Theo chính sách, đặt phòng hủy muộn này không được hoàn tiền.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Lý do hủy -->
  <div class="card">
    <div class="card-head">💬 Lý do hủy <span style="font-weight:400;color:var(--muted)">(tuỳ chọn)</span></div>
    <div class="form-body">
      <div class="form-group">
        <textarea name="ly_do" class="form-input" rows="3" maxlength="200"
                  placeholder="Ví dụ: Thay đổi lịch trình, bận việc đột xuất…"
                  style="resize:vertical"><?= esc($_POST['ly_do'] ?? '') ?></textarea>
        <div class="form-hint">Tối đa 200 ký tự — sẽ được gửi đến nhân viên</div>
      </div>
    </div>
  </div>

  <!-- Warning -->
  <div class="warn-box" style="margin-bottom:16px">
    ⚠️ <span>Sau khi xác nhận, đặt phòng <strong><?= esc($maDP) ?></strong> sẽ bị hủy và <strong>không thể khôi phục</strong>.</span>
  </div>

  <!-- Buttons -->
  <div class="card" style="margin-bottom:0">
    <div class="btn-row">
      <a href="dashboard.php?tab=history" class="btn-back">← Giữ đặt phòng</a>
      <button type="submit" class="btn-confirm-cancel">
        🚫 Xác nhận hủy<?= $refundAmt > 0 ? ' & yêu cầu hoàn ' . fmt($refundAmt) . 'đ' : '' ?>
      </button>
    </div>
  </div>
</form>

</body>
</html>
