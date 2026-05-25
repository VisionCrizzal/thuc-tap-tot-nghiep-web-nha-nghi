<?php
// staff/profile.php — Nhân viên xem/đổi mật khẩu, cập nhật SĐT & email
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id'])) {
    header('Location: ../login.php'); exit;
}

$staffId   = $_SESSION['staff_id'];
$staffRole = $_SESSION['staff_role'];
$staffMaNV = $_SESSION['staff_maNV'];
$msg       = ''; $msgType = 'success';

// ── Lấy thông tin NV ────────────────────────────────────────────────────────
function fetchNV(PDO $pdo, string $staffId): array|false {
    $q = $pdo->prepare("
        SELECT nv.*, tk.TenTK, tk.VaiTro, tk.LanDangNhapCuoi, tk.TrangThai AS TrangThaiTK
        FROM NHAN_VIEN nv
        JOIN TAI_KHOAN tk ON nv.MaNV = tk.MaNV
        WHERE tk.TenTK = :id
    ");
    $q->execute([':id' => $staffId]);
    return $q->fetch();
}
$nv = fetchNV($pdo, $staffId);

// ── Đếm đặt phòng đã xử lý ──────────────────────────────────────────────────
$handledCnt = $pdo->prepare("SELECT COUNT(*) FROM DAT_PHONG WHERE MaNV_XuLy=?");
$handledCnt->execute([$staffMaNV]);
$handled = $handledCnt->fetchColumn();

$checkinCnt = $pdo->prepare("SELECT COUNT(*) FROM DAT_PHONG WHERE MaNV_XuLy=? AND TrangThai='Đã nhận phòng'");
$checkinCnt->execute([$staffMaNV]);
$checking = $checkinCnt->fetchColumn();

// ── Xử lý POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Đổi mật khẩu ────────────────────────────────────────────────────────
    if ($action === 'doi_matkhau') {
        $oldPw = $_POST['old_password'] ?? '';
        $newPw = $_POST['new_password'] ?? '';
        $cfmPw = $_POST['confirm_password'] ?? '';

        // Lấy hash hiện tại
        $tkQ = $pdo->prepare("SELECT MatKhau FROM TAI_KHOAN WHERE TenTK = ?");
        $tkQ->execute([$staffId]);
        $tk = $tkQ->fetch();

        if (!$tk) {
            $msg = 'Không tìm thấy tài khoản.'; $msgType = 'error';
        } elseif (!password_verify($oldPw, $tk['MatKhau'])) {
            $msg = '❌ Mật khẩu hiện tại không đúng.'; $msgType = 'error';
        } elseif (strlen($newPw) < 6) {
            $msg = '❌ Mật khẩu mới phải có ít nhất 6 ký tự.'; $msgType = 'error';
        } elseif ($newPw !== $cfmPw) {
            $msg = '❌ Xác nhận mật khẩu không khớp.'; $msgType = 'error';
        } elseif ($newPw === $oldPw) {
            $msg = '❌ Mật khẩu mới phải khác mật khẩu hiện tại.'; $msgType = 'error';
        } else {
            $pdo->prepare("UPDATE TAI_KHOAN SET MatKhau = ? WHERE TenTK = ?")
                ->execute([password_hash($newPw, PASSWORD_DEFAULT), $staffId]);
            $msg = '✅ Đổi mật khẩu thành công!';
        }
    }

    // ── Cập nhật thông tin liên hệ ──────────────────────────────────────────
    if ($action === 'cap_nhat_tt') {
        $sdt   = trim($_POST['so_dien_thoai'] ?? '');
        $email = trim($_POST['email'] ?? '');

        // Validate SĐT
        if ($sdt && !preg_match('/^[0-9]{9,11}$/', $sdt)) {
            $msg = '❌ Số điện thoại không hợp lệ (9–11 chữ số).'; $msgType = 'error';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = '❌ Địa chỉ email không hợp lệ.'; $msgType = 'error';
        } else {
            $pdo->prepare("UPDATE NHAN_VIEN SET SoDienThoai = ?, Email = ? WHERE MaNV = ?")
                ->execute([$sdt ?: null, $email ?: null, $staffMaNV]);
            $msg = '✅ Cập nhật thông tin thành công!';
            // Reload
            $nv = fetchNV($pdo, $staffId);
        }
    }
}

$roleName   = $nv ? ($nv['VaiTro'] === 'admin' ? 'Quản Lý / Admin' : 'Nhân Viên Lễ Tân') : '—';
$roleColor  = $nv ? ($nv['VaiTro'] === 'admin' ? '#f59e0b' : '#3b82f6') : '#6b7280';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Hồ Sơ — <?= htmlspecialchars($nv['HoTen'] ?? $staffId) ?> — Easyhome</title>
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
.staff-info-top{text-align:right}
.staff-name-top{font-size:.83rem;color:#fff;font-weight:700}
.staff-role-top{font-size:.68rem;color:rgba(255,255,255,.65);letter-spacing:1px;text-transform:uppercase}
.btn-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:6px 14px;border-radius:7px;font-size:.78rem;font-weight:600;
  text-decoration:none;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.28)}
.btn-logout{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
  color:rgba(255,255,255,.8);padding:6px 14px;border-radius:7px;font-size:.76rem;
  text-decoration:none;transition:all .2s}
.btn-logout:hover{background:rgba(255,255,255,.2);color:#fff}

/* ── PAGE ── */
.page-wrap{max-width:760px;margin:28px auto;padding:0 20px 60px}

/* ── ALERT ── */
.alert{padding:13px 16px;border-radius:10px;font-size:.88rem;line-height:1.55;margin-bottom:24px;animation:slideIn .3s ease}
.alert-success{background:#f0fdf4;border:1.5px solid #86efac;border-left:4px solid #22c55e;color:#166534}
.alert-error{background:#fff0f0;border:1.5px solid #fca5a5;border-left:4px solid #ef4444;color:#dc2626}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

/* ── HERO ── */
.profile-hero{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:var(--radius);padding:26px 28px;display:flex;align-items:center;
  gap:20px;margin-bottom:22px;color:#fff}
.hero-avatar{width:72px;height:72px;background:rgba(255,255,255,.2);border-radius:50%;
  border:3px solid rgba(255,255,255,.4);display:flex;align-items:center;
  justify-content:center;font-size:2.2rem;flex-shrink:0}
.hero-name{font-family:var(--serif);font-size:1.35rem;font-weight:600}
.hero-id{font-size:.76rem;color:rgba(255,255,255,.7);margin-top:3px;letter-spacing:1px}
.hero-role{margin-top:8px;display:inline-flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.15);border-radius:20px;padding:5px 14px;font-size:.78rem}
.hero-stats{margin-left:auto;display:flex;gap:18px;flex-shrink:0}
.h-stat{text-align:center}
.h-stat-val{font-size:1.35rem;font-weight:700}
.h-stat-lbl{font-size:.68rem;color:rgba(255,255,255,.7);margin-top:2px}

/* ── CARD ── */
.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.card-head{padding:16px 22px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;gap:10px;background:var(--blue-pale)}
.ch-icon{font-size:1.05rem;flex-shrink:0}
.ch-title{font-weight:700;font-size:.92rem;color:var(--blue-dark);flex:1}
.ch-badge{font-size:.72rem;color:var(--muted);background:var(--blue-mid);
  padding:3px 10px;border-radius:10px}
.card-body{padding:22px}

/* ── INFO GRID ── */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px}
.info-item{padding:12px 15px;background:var(--blue-pale);border-radius:9px;border:1px solid var(--border)}
.info-label{font-size:.63rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.info-val{font-size:.9rem;font-weight:600;color:var(--text)}
.info-val.accent{color:var(--blue-dark)}
.info-val.muted{color:var(--muted);font-weight:400;font-style:italic}

/* ── FORM ── */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.form-group{margin-bottom:16px}
.form-group:last-of-type{margin-bottom:0}
.form-label{display:block;font-size:.65rem;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.form-input{width:100%;padding:11px 14px;font-family:var(--font);font-size:.91rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:9px;outline:none;transition:all .2s}
.form-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.11)}
.form-input:read-only{cursor:default;opacity:.65}
.input-wrap{position:relative}
.input-wrap .form-input{padding-right:44px}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:.95rem;color:var(--muted);
  padding:4px;border-radius:6px;transition:color .2s}
.toggle-pw:hover{color:var(--blue)}

.pw-strength{height:3px;border-radius:3px;margin-top:6px;background:var(--border);overflow:hidden}
.pw-strength-bar{height:100%;border-radius:3px;transition:width .3s,background .3s}

.form-hint{font-size:.74rem;color:var(--muted);margin-top:5px;line-height:1.4}

.btn-submit{display:block;width:100%;padding:13px;font-family:var(--font);font-size:.88rem;
  font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#fff;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));border:none;
  border-radius:10px;cursor:pointer;transition:all .3s;
  box-shadow:0 4px 16px rgba(29,78,216,.35);margin-top:6px}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(29,78,216,.45)}
.btn-submit:active{transform:translateY(0)}
.btn-submit.green{background:linear-gradient(135deg,#15803d,#16a34a);
  box-shadow:0 4px 16px rgba(22,163,74,.35)}
.btn-submit.green:hover{box-shadow:0 6px 22px rgba(22,163,74,.45)}

/* ── ACCOUNT INFO ── */
.acc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.acc-item{padding:14px;background:var(--blue-pale);border-radius:10px;border:1px solid var(--border);text-align:center}
.acc-icon{font-size:1.4rem;margin-bottom:6px}
.acc-label{font-size:.64rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.acc-val{font-size:.85rem;font-weight:600}

/* ── DIVIDER ── */
.section-divider{display:flex;align-items:center;gap:12px;margin:22px 0 18px}
.section-divider-line{flex:1;height:1px;background:var(--border)}
.section-divider-text{font-size:.65rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--muted)}

@media(max-width:600px){
  .info-grid{grid-template-columns:1fr}
  .form-row{grid-template-columns:1fr}
  .hero-stats{display:none}
  .acc-grid{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <a href="../index.php" class="brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome">
    <span class="brand-name">Easyhome</span>
  </a>
  <div class="topbar-right">
    <div class="staff-info-top">
      <div class="staff-name-top"><?= htmlspecialchars($nv['HoTen'] ?? $staffId) ?></div>
      <div class="staff-role-top"><?= $roleName ?></div>
    </div>
    <a href="dashboard.php" class="btn-back">← Dashboard</a>
    <a href="../logout.php" class="btn-logout">🚪 Đăng Xuất</a>
  </div>
</div>

<div class="page-wrap">

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
  <?php endif; ?>

  <!-- ── HERO ── -->
  <?php if ($nv): ?>
  <div class="profile-hero">
    <div class="hero-avatar">👨‍💼</div>
    <div>
      <div class="hero-name"><?= htmlspecialchars($nv['HoTen']) ?></div>
      <div class="hero-id">@<?= htmlspecialchars($staffId) ?> &nbsp;·&nbsp; <?= htmlspecialchars($nv['MaNV']) ?></div>
      <div class="hero-role">
        <span style="background:<?= $roleColor ?>;width:8px;height:8px;border-radius:50%;display:inline-block"></span>
        <?= $roleName ?>
      </div>
    </div>
    <div class="hero-stats">
      <div class="h-stat">
        <div class="h-stat-val"><?= $handled ?></div>
        <div class="h-stat-lbl">Đặt phòng<br>đã xử lý</div>
      </div>
      <div class="h-stat">
        <div class="h-stat-val"><?= $checking ?></div>
        <div class="h-stat-lbl">Đang nhận<br>phòng</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── THÔNG TIN CÁ NHÂN ── -->
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">📋</span>
      <span class="ch-title">Thông Tin Cá Nhân</span>
      <span class="ch-badge">Chỉ Admin mới được đổi tên / chức vụ</span>
    </div>
    <div class="card-body">
      <?php if ($nv): ?>
      <!-- Read-only info -->
      <div class="info-grid" style="margin-bottom:0">
        <div class="info-item">
          <div class="info-label">Họ và Tên</div>
          <div class="info-val accent"><?= htmlspecialchars($nv['HoTen']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Chức Vụ</div>
          <div class="info-val"><?= htmlspecialchars($nv['ChucVu']) ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Ngày Vào Làm</div>
          <div class="info-val"><?= $nv['NgayVaoLam'] ? date('d/m/Y', strtotime($nv['NgayVaoLam'])) : '—' ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Trạng Thái</div>
          <div class="info-val" style="color:#16a34a">● <?= htmlspecialchars($nv['TrangThai']) ?></div>
        </div>
      </div>

      <!-- Divider -->
      <div class="section-divider">
        <div class="section-divider-line"></div>
        <div class="section-divider-text">Thông tin liên hệ (có thể cập nhật)</div>
        <div class="section-divider-line"></div>
      </div>

      <!-- Editable contact form -->
      <form method="POST" id="contactForm">
        <input type="hidden" name="action" value="cap_nhat_tt">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Số Điện Thoại</label>
            <input type="tel" name="so_dien_thoai" class="form-input"
                   value="<?= htmlspecialchars($nv['SoDienThoai'] ?? '') ?>"
                   placeholder="Nhập số điện thoại..."
                   maxlength="11">
            <div class="form-hint">9–11 chữ số, không cần dấu gạch</div>
          </div>
          <div class="form-group">
            <label class="form-label">Email Cá Nhân</label>
            <input type="email" name="email" class="form-input"
                   value="<?= htmlspecialchars($nv['Email'] ?? '') ?>"
                   placeholder="example@email.com">
          </div>
        </div>
        <button type="submit" class="btn-submit green">💾 Lưu Thông Tin Liên Hệ</button>
      </form>
      <?php else: ?>
      <div style="text-align:center;padding:30px;color:var(--muted)">
        Không tìm thấy thông tin nhân viên.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── ĐỔI MẬT KHẨU ── -->
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">🔐</span>
      <span class="ch-title">Bảo Mật — Đổi Mật Khẩu</span>
    </div>
    <div class="card-body">
      <form method="POST" id="pwForm" onsubmit="return validatePwForm()">
        <input type="hidden" name="action" value="doi_matkhau">

        <div class="form-group">
          <label class="form-label">Mật Khẩu Hiện Tại</label>
          <div class="input-wrap">
            <input type="password" id="oldPw" name="old_password" class="form-input"
                   placeholder="Nhập mật khẩu đang dùng..." autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="togglePw('oldPw',this)" title="Hiện/ẩn">👁</button>
          </div>
        </div>

        <div class="section-divider">
          <div class="section-divider-line"></div>
          <div class="section-divider-text">Mật khẩu mới</div>
          <div class="section-divider-line"></div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Mật Khẩu Mới</label>
            <div class="input-wrap">
              <input type="password" id="newPw" name="new_password" class="form-input"
                     placeholder="Ít nhất 6 ký tự..." autocomplete="new-password"
                     oninput="checkStrength(this.value)">
              <button type="button" class="toggle-pw" onclick="togglePw('newPw',this)" title="Hiện/ẩn">👁</button>
            </div>
            <div class="pw-strength">
              <div class="pw-strength-bar" id="pwBar" style="width:0;background:#ef4444"></div>
            </div>
            <div class="form-hint" id="pwHint">Nhập mật khẩu để kiểm tra độ mạnh</div>
          </div>
          <div class="form-group">
            <label class="form-label">Xác Nhận Mật Khẩu Mới</label>
            <div class="input-wrap">
              <input type="password" id="cfmPw" name="confirm_password" class="form-input"
                     placeholder="Nhập lại mật khẩu mới..." autocomplete="new-password"
                     oninput="checkMatch()">
              <button type="button" class="toggle-pw" onclick="togglePw('cfmPw',this)" title="Hiện/ẩn">👁</button>
            </div>
            <div class="form-hint" id="matchHint"></div>
          </div>
        </div>

        <button type="submit" class="btn-submit">🔑 Đổi Mật Khẩu</button>
      </form>
    </div>
  </div>

  <!-- ── THÔNG TIN TÀI KHOẢN ── -->
  <?php if ($nv): ?>
  <div class="card">
    <div class="card-head">
      <span class="ch-icon">🔒</span>
      <span class="ch-title">Thông Tin Tài Khoản</span>
      <span class="ch-badge">Chỉ đọc</span>
    </div>
    <div class="card-body">
      <div class="acc-grid">
        <div class="acc-item">
          <div class="acc-icon">👤</div>
          <div class="acc-label">Tên Đăng Nhập</div>
          <div class="acc-val"><?= htmlspecialchars($staffId) ?></div>
        </div>
        <div class="acc-item">
          <div class="acc-icon"><?= $nv['VaiTro'] === 'admin' ? '👑' : '🏷️' ?></div>
          <div class="acc-label">Vai Trò</div>
          <div class="acc-val" style="color:<?= $roleColor ?>"><?= $roleName ?></div>
        </div>
        <div class="acc-item">
          <div class="acc-icon">🕐</div>
          <div class="acc-label">Đăng Nhập Cuối</div>
          <div class="acc-val" style="font-size:.78rem">
            <?= $nv['LanDangNhapCuoi'] ? date('d/m/Y H:i', strtotime($nv['LanDangNhapCuoi'])) : '—' ?>
          </div>
        </div>
        <div class="acc-item">
          <div class="acc-icon">✅</div>
          <div class="acc-label">Trạng Thái TK</div>
          <div class="acc-val" style="color:#16a34a">● <?= htmlspecialchars($nv['TrangThaiTK']) ?></div>
        </div>
        <div class="acc-item">
          <div class="acc-icon">📋</div>
          <div class="acc-label">Đã Xử Lý</div>
          <div class="acc-val" style="color:var(--blue)"><?= $handled ?> ĐP</div>
        </div>
        <div class="acc-item">
          <div class="acc-icon">🏨</div>
          <div class="acc-label">Đang Nhận Phòng</div>
          <div class="acc-val" style="color:var(--blue-dark)"><?= $checking ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /page-wrap -->

<script>
function togglePw(id, btn) {
  const el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
  btn.textContent = el.type === 'password' ? '👁' : '🙈';
}

function checkStrength(pw) {
  const bar  = document.getElementById('pwBar');
  const hint = document.getElementById('pwHint');
  if (!pw) { bar.style.width='0'; hint.textContent='Nhập mật khẩu để kiểm tra độ mạnh'; hint.style.color=''; return; }

  let score = 0;
  if (pw.length >= 6)  score++;
  if (pw.length >= 10) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const pct   = [0,25,50,75,90,100][score];
  const color = ['#ef4444','#f97316','#eab308','#3b82f6','#22c55e'][score-1] || '#ef4444';
  const label = ['','Rất yếu','Yếu','Trung bình','Mạnh','Rất mạnh'][score] || 'Rất yếu';

  bar.style.width   = pct + '%';
  bar.style.background = color;
  hint.textContent  = 'Độ mạnh: ' + label;
  hint.style.color  = color;
}

function checkMatch() {
  const nw  = document.getElementById('newPw').value;
  const cfm = document.getElementById('cfmPw').value;
  const el  = document.getElementById('matchHint');
  if (!cfm) { el.textContent=''; return; }
  if (nw === cfm) {
    el.textContent = '✓ Mật khẩu khớp';
    el.style.color = '#16a34a';
  } else {
    el.textContent = '✕ Mật khẩu chưa khớp';
    el.style.color = '#dc2626';
  }
}

function validatePwForm() {
  const oldPw = document.getElementById('oldPw').value;
  const newPw = document.getElementById('newPw').value;
  const cfmPw = document.getElementById('cfmPw').value;

  if (!oldPw) { alert('Vui lòng nhập mật khẩu hiện tại.'); return false; }
  if (newPw.length < 6) { alert('Mật khẩu mới phải có ít nhất 6 ký tự.'); return false; }
  if (newPw !== cfmPw) { alert('Xác nhận mật khẩu không khớp.'); return false; }
  return true;
}
</script>
</body>
</html>
