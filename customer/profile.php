<?php
// customer/profile.php — Khách hàng chỉnh sửa hồ sơ & đổi mật khẩu
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['kh_id'])) {
    header('Location: ../login.php?next=customer/profile.php'); exit;
}

$khId   = $_SESSION['kh_id'];
$msg    = ''; $msgType = 'success';

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function val(mixed $s): string { return esc($s); } // shorthand for input value=

// ── Lấy thông tin KH ─────────────────────────────────────────────────────────
function fetchKH(PDO $pdo, string $khId): array|false {
    $q = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE MaKH = :id");
    $q->execute([':id' => $khId]);
    return $q->fetch();
}
$kh = fetchKH($pdo, $khId);
if (!$kh) { header('Location: ../logout.php'); exit; }

// ── Khôi phục msg từ PRG redirect ────────────────────────────────────────────
if (isset($_GET['msg'])) {
    $msg     = esc($_GET['msg']);
    $msgType = $_GET['mtype'] ?? 'success';
}

// ════════════════════════════════════════════════════════════════════════════════
// ── XỬ LÝ POST ───────────────────────────────────────────────────────────────
// ════════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    // ────────────────────────────────────────────────────────────────────────
    // ACTION 1: Cập nhật thông tin cá nhân
    // ────────────────────────────────────────────────────────────────────────
    if ($action === 'cap_nhat_tt') {
        $hoTen   = trim($_POST['ho_ten']    ?? '');
        $sdt     = trim($_POST['sdt']        ?? '');
        $email   = trim($_POST['email']      ?? '');
        $diaChi  = trim($_POST['dia_chi']    ?? '');
        $gioiTinh= trim($_POST['gioi_tinh'] ?? '');
        $ngaySinh= trim($_POST['ngay_sinh'] ?? '');

        // CCCD: chỉ lưu khi user chủ động cung cấp
        $coungCccd = isset($_POST['cung_cap_cccd']) && $_POST['cung_cap_cccd'] === '1';
        $cccd = $coungCccd ? trim($_POST['cccd'] ?? '') : null;

        // ── Validate ───────────────────────────────────────────────────────
        $errors = [];
        if ($hoTen === '') {
            $errors[] = 'Họ và tên không được để trống.';
        } elseif (mb_strlen($hoTen) < 2) {
            $errors[] = 'Họ và tên phải có ít nhất 2 ký tự.';
        }
        if ($sdt !== '' && !preg_match('/^[0-9]{9,11}$/', $sdt)) {
            $errors[] = 'Số điện thoại không hợp lệ (9–11 chữ số).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Địa chỉ email không hợp lệ.';
        }
        if ($cccd !== null && $cccd !== '' && !preg_match('/^[0-9]{9,12}$/', $cccd)) {
            $errors[] = 'CCCD/CMND không hợp lệ (9–12 chữ số).';
        }
        if ($ngaySinh !== '') {
            $ts = strtotime($ngaySinh);
            if ($ts === false || $ts > time()) {
                $errors[] = 'Ngày sinh không hợp lệ.';
            }
        }

        if ($errors) {
            $msg = '❌ ' . implode('<br>❌ ', $errors);
            $msgType = 'error';
        } else {
            // Nếu user KHÔNG cung cấp CCCD → giữ nguyên giá trị cũ (NULL hoặc đã có)
            // Chỉ cập nhật CCCD khi checkbox được check
            if ($coungCccd) {
                $newCccd = $cccd !== '' ? encryptCCCD($cccd) : null;
            } else {
                $newCccd = null; // xóa CCCD nếu bỏ chọn
            }

            $pdo->prepare("
                UPDATE KHACH_HANG
                SET HoTen       = ?,
                    SoDienThoai = ?,
                    Email       = ?,
                    CCCD        = ?,
                    DiaChi      = ?,
                    GioiTinh    = ?,
                    NgaySinh    = ?
                WHERE MaKH = ?
            ")->execute([
                $hoTen,
                $sdt ?: null,
                $email ?: null,
                $newCccd,
                $diaChi ?: null,
                $gioiTinh ?: null,
                $ngaySinh ?: null,
                $khId,
            ]);

            // Cập nhật tên trong session
            $_SESSION['kh_name'] = $hoTen;

            // PRG
            header('Location: profile.php?msg=' . urlencode('✅ Cập nhật thông tin thành công!') . '&mtype=success');
            exit;
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // ACTION 2: Đổi mật khẩu
    // ────────────────────────────────────────────────────────────────────────
    if ($action === 'doi_matkhau') {
        $oldPw = $_POST['old_password']     ?? '';
        $newPw = $_POST['new_password']     ?? '';
        $cfmPw = $_POST['confirm_password'] ?? '';

        $tkQ = $pdo->prepare("SELECT MatKhau FROM KHACH_HANG WHERE MaKH = ?");
        $tkQ->execute([$khId]);
        $tk = $tkQ->fetch();

        if (!$tk) {
            $msg = '❌ Không tìm thấy tài khoản.'; $msgType = 'error';
        } elseif (!password_verify($oldPw, $tk['MatKhau'])) {
            $msg = '❌ Mật khẩu hiện tại không đúng.'; $msgType = 'error';
        } elseif (strlen($newPw) < 6) {
            $msg = '❌ Mật khẩu mới phải có ít nhất 6 ký tự.'; $msgType = 'error';
        } elseif ($newPw !== $cfmPw) {
            $msg = '❌ Xác nhận mật khẩu không khớp.'; $msgType = 'error';
        } elseif ($newPw === $oldPw) {
            $msg = '❌ Mật khẩu mới phải khác mật khẩu hiện tại.'; $msgType = 'error';
        } else {
            $pdo->prepare("UPDATE KHACH_HANG SET MatKhau = ? WHERE MaKH = ?")
                ->execute([password_hash($newPw, PASSWORD_DEFAULT), $khId]);
            header('Location: profile.php?msg=' . urlencode('✅ Đổi mật khẩu thành công!') . '&mtype=success');
            exit;
        }
    }

    // Reload KH data nếu có lỗi (không redirect)
    $kh = fetchKH($pdo, $khId);
}

// ── Điền lại form từ POST nếu có lỗi ─────────────────────────────────────────
$fHoTen    = esc($_POST['ho_ten']    ?? $kh['HoTen']        ?? '');
$fSdt      = esc($_POST['sdt']        ?? $kh['SoDienThoai']  ?? '');
$fEmail    = esc($_POST['email']      ?? $kh['Email']         ?? '');
$fDiaChi   = esc($_POST['dia_chi']   ?? $kh['DiaChi']        ?? '');
$fGioiTinh = $_POST['gioi_tinh']     ?? $kh['GioiTinh']      ?? '';
$fNgaySinh = $_POST['ngay_sinh']     ?? ($kh['NgaySinh'] ? date('Y-m-d', strtotime($kh['NgaySinh'])) : '');
$fCccd     = esc($_POST['cccd']       ?? decryptCCCD($kh['CCCD'] ?? '') ?? '');
$hasCccd   = isset($_POST['cung_cap_cccd'])
             ? ($_POST['cung_cap_cccd'] === '1')
             : !empty($kh['CCCD']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Chỉnh Sửa Hồ Sơ — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --green:#22c55e;--red:#ef4444;
  --shadow:0 4px 24px rgba(29,78,216,.10);--shadow-lg:0 12px 40px rgba(29,78,216,.14);
  --radius:12px;--font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
html,body{min-height:100%;font-family:var(--font);color:var(--text)}
body{background:var(--bg)}

/* ── TOPBAR ── */
.topbar{
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:60px;box-shadow:0 2px 16px rgba(29,78,216,.3);position:sticky;top:0;z-index:100;
}
.topbar-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.topbar-brand img{width:34px;height:34px;border-radius:8px;object-fit:cover;border:2px solid rgba(255,255,255,.4)}
.topbar-brand span{font-family:var(--serif);font-size:1.15rem;color:#fff;letter-spacing:.5px}
.topbar-right{display:flex;align-items:center;gap:12px}
.topbar-user{font-size:.82rem;color:rgba(255,255,255,.85);font-weight:600}
.btn-topbar{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:6px 14px;border-radius:7px;font-family:var(--font);
  font-size:.78rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-topbar:hover{background:rgba(255,255,255,.25)}

/* ── MAIN ── */
.main{max-width:780px;margin:32px auto;padding:0 20px 60px}

/* ── BACK LINK ── */
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:.82rem;
  color:var(--muted);text-decoration:none;font-weight:600;margin-bottom:22px;
  padding:7px 14px;border-radius:8px;background:#fff;border:1.5px solid var(--border);
  transition:all .2s;box-shadow:var(--shadow)}
.back-link:hover{color:var(--blue);border-color:var(--blue-light)}

/* ── PAGE HEADER ── */
.page-hero{
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:var(--radius);padding:24px 28px;margin-bottom:24px;
  color:#fff;display:flex;align-items:center;gap:18px;
}
.hero-avatar{width:62px;height:62px;border-radius:50%;background:rgba(255,255,255,.18);
  display:flex;align-items:center;justify-content:center;font-size:1.8rem;
  border:2px solid rgba(255,255,255,.35);flex-shrink:0}
.hero-name{font-family:var(--serif);font-size:1.3rem;font-weight:600;margin-bottom:4px}
.hero-sub{font-size:.78rem;color:rgba(255,255,255,.7);letter-spacing:.5px}
.hero-points{display:inline-flex;align-items:center;gap:5px;margin-top:8px;
  background:rgba(255,255,255,.15);border-radius:20px;padding:3px 12px;
  font-size:.8rem;font-weight:600}

/* ── ALERT ── */
.alert{padding:13px 18px;border-radius:10px;font-size:.88rem;line-height:1.6;
  margin-bottom:22px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:#f0fdf4;border:1.5px solid #86efac;border-left:4px solid var(--green);color:#166534}
.alert-error{background:#fff0f0;border:1.5px solid #fca5a5;border-left:4px solid var(--red);
  color:#dc2626;animation:shake .4s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-4px)}50%{transform:translateX(4px)}}

/* ── CARD ── */
.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:22px;overflow:hidden}
.card-head{padding:16px 22px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;gap:10px;background:var(--blue-pale)}
.card-head-icon{font-size:1.15rem}
.card-head-title{font-weight:700;font-size:.95rem;color:var(--blue-dark)}
.card-head-sub{font-size:.72rem;color:var(--muted);margin-left:auto}
.card-body{padding:24px}

/* ── FORM GRID ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-grid.single{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:.68rem;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted)}
.form-label .optional{font-size:.62rem;letter-spacing:1px;font-weight:600;
  color:#94a3b8;margin-left:6px;text-transform:uppercase}
.form-label .privacy{font-size:.62rem;font-weight:600;color:#6b7280;
  background:#f1f5f9;border-radius:4px;padding:1px 6px;margin-left:5px}
.input-wrap{position:relative}
.input-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);
  font-size:.95rem;pointer-events:none}
.form-input{
  width:100%;padding:11px 12px 11px 36px;
  font-family:var(--font);font-size:.9rem;color:var(--text);
  background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:9px;outline:none;transition:all .25s;
}
.form-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}
.form-input::placeholder{color:#94a3b8}
.form-input:disabled{opacity:.45;cursor:not-allowed;background:#f8fafc}
.form-input.no-icon{padding-left:12px}
.form-select{
  width:100%;padding:11px 12px;
  font-family:var(--font);font-size:.9rem;color:var(--text);
  background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:9px;outline:none;transition:all .25s;cursor:pointer;
}
.form-select:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}
.form-hint{font-size:.72rem;color:var(--muted);line-height:1.4}
.form-hint.warn{color:#b45309;background:#fef9c3;border-radius:6px;padding:4px 8px}

/* ── CCCD TOGGLE ── */
.cccd-toggle{
  display:flex;align-items:flex-start;gap:10px;
  background:#f8fafc;border:1.5px solid var(--border);
  border-radius:9px;padding:12px 14px;cursor:pointer;
  transition:all .2s;user-select:none;
}
.cccd-toggle:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.cccd-toggle input[type=checkbox]{
  width:17px;height:17px;accent-color:var(--blue);cursor:pointer;flex-shrink:0;margin-top:1px;
}
.cccd-toggle-text{flex:1}
.cccd-toggle-title{font-size:.87rem;font-weight:700;color:var(--text);margin-bottom:2px}
.cccd-toggle-desc{font-size:.74rem;color:var(--muted);line-height:1.4}
.cccd-field{margin-top:12px;display:none}
.cccd-field.visible{display:block}
.cccd-privacy-note{
  display:flex;align-items:center;gap:7px;margin-top:10px;
  background:#f0fdf4;border:1px solid #86efac;border-radius:8px;
  padding:9px 13px;font-size:.77rem;color:#166534;line-height:1.45;
}

/* ── PW TOGGLE ── */
.pw-wrap{position:relative}
.pw-wrap .form-input{padding-right:40px}
.toggle-pw{position:absolute;right:11px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:.9rem;color:var(--muted);
  padding:4px;border-radius:5px;transition:color .2s}
.toggle-pw:hover{color:var(--blue)}

/* ── STRENGTH BAR ── */
.pw-strength{height:4px;border-radius:2px;margin-top:6px;background:#e2e8f0;overflow:hidden}
.pw-strength-bar{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0}
.pw-strength-label{font-size:.7rem;color:var(--muted);margin-top:3px}

/* ── SUBMIT ── */
.btn-save{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:12px 28px;background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  color:#fff;border:none;border-radius:9px;font-family:var(--font);
  font-size:.88rem;font-weight:700;letter-spacing:1px;cursor:pointer;
  transition:all .28s;box-shadow:0 4px 14px rgba(29,78,216,.32);
}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(29,78,216,.44)}
.btn-save:active{transform:translateY(0)}
.btn-cancel{
  display:inline-flex;align-items:center;gap:6px;
  padding:12px 22px;background:#fff;color:var(--muted);
  border:1.5px solid var(--border);border-radius:9px;font-family:var(--font);
  font-size:.88rem;font-weight:600;cursor:pointer;text-decoration:none;
  transition:all .2s;
}
.btn-cancel:hover{border-color:var(--blue-light);color:var(--blue)}
.form-actions{display:flex;align-items:center;gap:12px;margin-top:22px;padding-top:18px;
  border-top:1.5px solid var(--border);flex-wrap:wrap}

/* ── INFO BADGE ── */
.info-readonly{
  display:flex;align-items:center;gap:8px;
  background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:9px;padding:11px 14px;font-size:.88rem;
}
.info-readonly .label{font-size:.68rem;font-weight:700;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--muted);min-width:100px}
.info-readonly .val{color:var(--text);font-weight:600}
.info-readonly .lock-icon{margin-left:auto;font-size:.8rem;color:#94a3b8}

/* ── DIVIDER ── */
.form-divider{height:1px;background:var(--border);margin:20px 0}

/* ── RESPONSIVE ── */
@media(max-width:600px){
  .form-grid{grid-template-columns:1fr}
  .form-group.full{grid-column:auto}
  .main{margin:20px auto}
}
</style>
</head>
<body>

<!-- ════ TOPBAR ════ -->
<nav class="topbar">
  <a href="../index.php" class="topbar-brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome">
    <span>Easyhome</span>
  </a>
  <div class="topbar-right">
    <span class="topbar-user">👤 <?= esc($kh['HoTen']) ?></span>
    <a href="dashboard.php" class="btn-topbar">🏠 Tài khoản</a>
    <a href="../logout.php" class="btn-topbar">🚪 Đăng xuất</a>
  </div>
</nav>

<!-- ════ MAIN ════ -->
<div class="main">

  <a href="dashboard.php" class="back-link">← Quay về Tài khoản</a>

  <!-- Hero -->
  <div class="page-hero">
    <div class="hero-avatar">
      <?= mb_strtoupper(mb_substr($kh['HoTen'], 0, 1, 'UTF-8'), 'UTF-8') ?>
    </div>
    <div>
      <div class="hero-name"><?= esc($kh['HoTen']) ?></div>
      <div class="hero-sub">@<?= esc($kh['TenTaiKhoan']) ?> &nbsp;·&nbsp; Mã KH: <?= esc($kh['MaKH']) ?></div>
      <div class="hero-points">⭐ <?= number_format((float)$kh['TichDiem']) ?> điểm tích lũy</div>
    </div>
  </div>

  <!-- Alert -->
  <?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>">
    <?php if ($msgType === 'success'): ?>✅<?php else: ?>⚠️<?php endif; ?>
    <div><?= $msg ?></div>
  </div>
  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════
       FORM 1 — Thông tin cá nhân
       ════════════════════════════════════════════════════════════════════ -->
  <div class="card">
    <div class="card-head">
      <span class="card-head-icon">📋</span>
      <span class="card-head-title">Thông Tin Cá Nhân</span>
      <span class="card-head-sub">Không thể thay đổi: Tên đăng nhập, Mã KH</span>
    </div>
    <div class="card-body">

      <!-- Readonly: TenTaiKhoan + MaKH -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
        <div class="info-readonly">
          <span class="label">Tên đăng nhập</span>
          <span class="val">@<?= esc($kh['TenTaiKhoan']) ?></span>
          <span class="lock-icon">🔒</span>
        </div>
        <div class="info-readonly">
          <span class="label">Mã KH</span>
          <span class="val"><?= esc($kh['MaKH']) ?></span>
          <span class="lock-icon">🔒</span>
        </div>
      </div>

      <form method="POST" id="infoForm" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cap_nhat_tt">

        <div class="form-grid">

          <!-- Họ tên (full row) -->
          <div class="form-group full">
            <label class="form-label" for="ho_ten">Họ và Tên</label>
            <div class="input-wrap">
              <span class="input-icon">👤</span>
              <input type="text" id="ho_ten" name="ho_ten" class="form-input"
                     value="<?= $fHoTen ?>" placeholder="Nhập họ và tên đầy đủ" required>
            </div>
          </div>

          <!-- SĐT -->
          <div class="form-group">
            <label class="form-label" for="sdt">
              Số Điện Thoại
            </label>
            <div class="input-wrap">
              <span class="input-icon">📱</span>
              <input type="tel" id="sdt" name="sdt" class="form-input"
                     value="<?= $fSdt ?>" placeholder="VD: 0912345678">
            </div>
          </div>

          <!-- Email -->
          <div class="form-group">
            <label class="form-label" for="email">
              Email
              <span class="optional">Tùy chọn</span>
            </label>
            <div class="input-wrap">
              <span class="input-icon">📧</span>
              <input type="email" id="email" name="email" class="form-input"
                     value="<?= $fEmail ?>" placeholder="VD: example@email.com">
            </div>
          </div>

          <!-- Giới tính -->
          <div class="form-group">
            <label class="form-label" for="gioi_tinh">
              Giới Tính
              <span class="optional">Tùy chọn</span>
            </label>
            <select id="gioi_tinh" name="gioi_tinh" class="form-select">
              <option value="">— Không chỉ định —</option>
              <option value="Nam"  <?= $fGioiTinh==='Nam'  ?'selected':'' ?>>Nam</option>
              <option value="Nữ"   <?= $fGioiTinh==='Nữ'   ?'selected':'' ?>>Nữ</option>
              <option value="Khác" <?= $fGioiTinh==='Khác' ?'selected':'' ?>>Khác</option>
            </select>
          </div>

          <!-- Ngày sinh -->
          <div class="form-group">
            <label class="form-label" for="ngay_sinh">
              Ngày Sinh
              <span class="optional">Tùy chọn</span>
            </label>
            <div class="input-wrap">
              <span class="input-icon">🎂</span>
              <input type="date" id="ngay_sinh" name="ngay_sinh" class="form-input"
                     value="<?= esc($fNgaySinh) ?>"
                     max="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <!-- Địa chỉ (full row) -->
          <div class="form-group full">
            <label class="form-label" for="dia_chi">
              Địa Chỉ
              <span class="optional">Tùy chọn</span>
            </label>
            <div class="input-wrap">
              <span class="input-icon">📍</span>
              <input type="text" id="dia_chi" name="dia_chi" class="form-input"
                     value="<?= $fDiaChi ?>" placeholder="VD: 123 Lê Lợi, Huế">
            </div>
          </div>

          <!-- CCCD — Toggle (full row) -->
          <div class="form-group full">
            <label class="form-label">
              CCCD / CMND
              <span class="privacy">🔒 Riêng tư</span>
            </label>

            <!-- Checkbox toggle -->
            <label class="cccd-toggle" for="cung_cap_cccd_cb">
              <input type="checkbox" id="cung_cap_cccd_cb"
                     <?= $hasCccd ? 'checked' : '' ?>
                     onchange="toggleCccd(this)">
              <div class="cccd-toggle-text">
                <div class="cccd-toggle-title">Cung cấp số CCCD/CMND</div>
                <div class="cccd-toggle-desc">
                  Tùy chọn — Bỏ chọn nếu bạn muốn bảo mật thông tin.
                  CCCD chỉ dùng cho mục đích xác thực khi check-in.
                </div>
              </div>
            </label>

            <!-- Hidden input để gửi trạng thái checkbox -->
            <input type="hidden" name="cung_cap_cccd" id="cccdHidden"
                   value="<?= $hasCccd ? '1' : '0' ?>">

            <!-- CCCD input field (ẩn/hiện theo toggle) -->
            <div class="cccd-field <?= $hasCccd ? 'visible' : '' ?>" id="cccdField">
              <div class="input-wrap">
                <span class="input-icon">🪪</span>
                <input type="text" id="cccd" name="cccd" class="form-input"
                       value="<?= $fCccd ?>"
                       placeholder="9–12 chữ số"
                       inputmode="numeric" maxlength="12">
              </div>
              <div class="cccd-privacy-note">
                🛡️ Thông tin CCCD được mã hóa và bảo mật theo quy định.
                Chỉ nhân viên có thẩm quyền mới có thể xem khi cần thiết.
              </div>
            </div>
          </div>

        </div><!-- /form-grid -->

        <div class="form-actions">
          <button type="submit" class="btn-save">💾 Lưu Thông Tin</button>
          <a href="dashboard.php" class="btn-cancel">✕ Hủy</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════
       FORM 2 — Đổi mật khẩu
       ════════════════════════════════════════════════════════════════════ -->
  <div class="card">
    <div class="card-head">
      <span class="card-head-icon">🔐</span>
      <span class="card-head-title">Đổi Mật Khẩu</span>
    </div>
    <div class="card-body">
      <form method="POST" id="pwForm" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="doi_matkhau">

        <div class="form-grid single" style="max-width:440px">

          <!-- Mật khẩu hiện tại -->
          <div class="form-group">
            <label class="form-label" for="old_password">Mật Khẩu Hiện Tại</label>
            <div class="pw-wrap input-wrap">
              <span class="input-icon">🔒</span>
              <input type="password" id="old_password" name="old_password"
                     class="form-input" placeholder="••••••••"
                     autocomplete="current-password">
              <button type="button" class="toggle-pw" onclick="togglePw('old_password')">👁</button>
            </div>
          </div>

          <!-- Mật khẩu mới -->
          <div class="form-group">
            <label class="form-label" for="new_password">Mật Khẩu Mới</label>
            <div class="pw-wrap input-wrap">
              <span class="input-icon">🔑</span>
              <input type="password" id="new_password" name="new_password"
                     class="form-input" placeholder="Tối thiểu 6 ký tự"
                     autocomplete="new-password"
                     oninput="checkStrength(this.value)">
              <button type="button" class="toggle-pw" onclick="togglePw('new_password')">👁</button>
            </div>
            <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
            <div class="pw-strength-label" id="pwLabel"></div>
          </div>

          <!-- Xác nhận -->
          <div class="form-group">
            <label class="form-label" for="confirm_password">Xác Nhận Mật Khẩu Mới</label>
            <div class="pw-wrap input-wrap">
              <span class="input-icon">✅</span>
              <input type="password" id="confirm_password" name="confirm_password"
                     class="form-input" placeholder="Nhập lại mật khẩu mới"
                     autocomplete="new-password">
              <button type="button" class="toggle-pw" onclick="togglePw('confirm_password')">👁</button>
            </div>
          </div>

        </div>

        <div class="form-actions">
          <button type="submit" class="btn-save">🔐 Đổi Mật Khẩu</button>
          <a href="dashboard.php" class="btn-cancel">✕ Hủy</a>
        </div>
      </form>
    </div>
  </div>

</div><!-- /main -->

<script>
// ── CCCD Toggle ───────────────────────────────────────────────────────────────
function toggleCccd(cb) {
  const field  = document.getElementById('cccdField');
  const hidden = document.getElementById('cccdHidden');
  const input  = document.getElementById('cccd');
  if (cb.checked) {
    field.classList.add('visible');
    hidden.value = '1';
  } else {
    field.classList.remove('visible');
    hidden.value = '0';
    input.value  = '';   // xóa giá trị khi bỏ chọn
  }
}

// ── Password visibility toggle ────────────────────────────────────────────────
function togglePw(id) {
  const el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
}

// ── Password strength ─────────────────────────────────────────────────────────
function checkStrength(pw) {
  const bar   = document.getElementById('pwBar');
  const label = document.getElementById('pwLabel');
  let score = 0;
  if (pw.length >= 6)  score++;
  if (pw.length >= 10) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const levels = [
    { pct:'0%',   bg:'#e2e8f0', txt:'' },
    { pct:'20%',  bg:'#ef4444', txt:'Rất yếu' },
    { pct:'40%',  bg:'#f97316', txt:'Yếu' },
    { pct:'60%',  bg:'#f59e0b', txt:'Trung bình' },
    { pct:'80%',  bg:'#84cc16', txt:'Mạnh' },
    { pct:'100%', bg:'#22c55e', txt:'Rất mạnh 🔒' },
  ];
  const lv = pw.length === 0 ? levels[0] : levels[Math.min(score, 5)];
  bar.style.width      = lv.pct;
  bar.style.background = lv.bg;
  label.textContent    = lv.txt;
  label.style.color    = lv.bg;
}

// ── Client-side validation ────────────────────────────────────────────────────
document.getElementById('pwForm').addEventListener('submit', function(e) {
  const np = document.getElementById('new_password').value;
  const cp = document.getElementById('confirm_password').value;
  if (np.length > 0 && np.length < 6) {
    e.preventDefault();
    alert('Mật khẩu mới phải có ít nhất 6 ký tự.');
    return;
  }
  if (np !== cp) {
    e.preventDefault();
    alert('Xác nhận mật khẩu không khớp.');
  }
});

document.getElementById('infoForm').addEventListener('submit', function(e) {
  const hoTen = document.getElementById('ho_ten').value.trim();
  if (!hoTen) {
    e.preventDefault();
    alert('Vui lòng nhập họ và tên.');
    document.getElementById('ho_ten').focus();
  }
});
</script>

</body>
</html>
