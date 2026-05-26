<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'];

// ── Đảm bảo cột TrangThai tồn tại trong KHACH_HANG ─────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM KHACH_HANG LIKE 'TrangThai'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE KHACH_HANG ADD COLUMN TrangThai NVARCHAR(20) NOT NULL DEFAULT 'Hoạt động' AFTER QuocTich");
    }
} catch (Exception $e) { /* bỏ qua */ }

$tab      = $_GET['tab']    ?? 'staff';
$editId   = $_GET['id']     ?? '';
$editKhId = $_GET['kh_id']  ?? '';
$editNvId = $_GET['nv_id']  ?? '';
$msg      = '';
$msgType  = 'success';

// ═══════════════════════════════════════════════════════════════════
//  POST HANDLERS
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    // ── Thêm tài khoản nhân viên mới ─────────────────────────────
    if ($action === 'add_account') {
        $tenTK   = trim($_POST['ten_tk']    ?? '');
        $matKhau = trim($_POST['mat_khau']  ?? '');
        $mk2     = trim($_POST['mat_khau2'] ?? '');
        $vaiTro  = trim($_POST['vai_tro']   ?? 'nhanvien');
        $maNV    = trim($_POST['ma_nv']     ?? '') ?: null;

        if (!$tenTK || !$matKhau) {
            $msg = "Vui lòng nhập đầy đủ Tên tài khoản và Mật khẩu.";
            $msgType = 'error'; $tab = 'add';
        } elseif (strlen($matKhau) < 6) {
            $msg = "Mật khẩu phải có ít nhất 6 ký tự.";
            $msgType = 'error'; $tab = 'add';
        } elseif ($matKhau !== $mk2) {
            $msg = "Xác nhận mật khẩu không khớp.";
            $msgType = 'error'; $tab = 'add';
        } elseif (!in_array($vaiTro, ['admin','nhanvien'])) {
            $msg = "Vai trò không hợp lệ.";
            $msgType = 'error'; $tab = 'add';
        } else {
            $chk = $pdo->prepare("SELECT TenTK FROM TAI_KHOAN WHERE TenTK = :id");
            $chk->execute([':id' => $tenTK]);
            if ($chk->fetch()) {
                $msg = "Tên tài khoản '$tenTK' đã tồn tại.";
                $msgType = 'error'; $tab = 'add';
            } else {
                $hash = password_hash($matKhau, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO TAI_KHOAN (TenTK,MatKhau,VaiTro,MaNV) VALUES (:tk,:mk,:vt,:nv)")
                    ->execute([':tk'=>$tenTK,':mk'=>$hash,':vt'=>$vaiTro,':nv'=>$maNV]);
                $msg = "Đã tạo tài khoản '$tenTK' thành công."; $tab = 'staff';
            }
        }
    }

    // ── Sửa tài khoản NV ─────────────────────────────────────────
    elseif ($action === 'edit_account') {
        $tenTK     = trim($_POST['ten_tk']     ?? '');
        $vaiTro    = trim($_POST['vai_tro']    ?? 'nhanvien');
        $trangThai = trim($_POST['trang_thai'] ?? 'Hoạt động');
        $maNV      = trim($_POST['ma_nv']      ?? '') ?: null;

        if (!$tenTK) {
            $msg = "Tên tài khoản không hợp lệ."; $msgType = 'error'; $tab = 'edit'; $editId = $tenTK;
        } elseif ($tenTK === $adminId && $trangThai === 'Khóa') {
            $msg = "⚠️ Không thể tự khóa tài khoản đang đăng nhập!"; $msgType = 'error'; $tab = 'edit'; $editId = $tenTK;
        } else {
            $vaiTro    = in_array($vaiTro, ['admin','nhanvien']) ? $vaiTro : 'nhanvien';
            $trangThai = in_array($trangThai, ['Hoạt động','Khóa']) ? $trangThai : 'Hoạt động';
            $pdo->prepare("UPDATE TAI_KHOAN SET VaiTro=:vt,TrangThai=:tt,MaNV=:nv WHERE TenTK=:tk")
                ->execute([':vt'=>$vaiTro,':tt'=>$trangThai,':nv'=>$maNV,':tk'=>$tenTK]);
            $msg = "Đã cập nhật tài khoản '$tenTK'."; $tab = 'staff';
        }
    }

    // ── Đặt lại mật khẩu ─────────────────────────────────────────
    elseif ($action === 'reset_password') {
        $tenTK   = trim($_POST['ten_tk']    ?? '');
        $matKhau = trim($_POST['mat_khau']  ?? '');
        $mk2     = trim($_POST['mat_khau2'] ?? '');

        if (!$tenTK || !$matKhau) {
            $msg = "Thiếu thông tin để đặt lại mật khẩu."; $msgType = 'error'; $tab = 'edit'; $editId = $tenTK;
        } elseif (strlen($matKhau) < 6) {
            $msg = "Mật khẩu mới phải có ít nhất 6 ký tự."; $msgType = 'error'; $tab = 'edit'; $editId = $tenTK;
        } elseif ($matKhau !== $mk2) {
            $msg = "Xác nhận mật khẩu không khớp."; $msgType = 'error'; $tab = 'edit'; $editId = $tenTK;
        } else {
            $hash = password_hash($matKhau, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE TAI_KHOAN SET MatKhau=:mk WHERE TenTK=:tk")
                ->execute([':mk'=>$hash,':tk'=>$tenTK]);
            $msg = "Đã đặt lại mật khẩu cho '$tenTK'."; $tab = 'staff';
        }
    }

    // ── Toggle trạng thái tài khoản NV ───────────────────────────
    elseif ($action === 'toggle_staff_status') {
        $tenTK = trim($_POST['ten_tk'] ?? '');
        if ($tenTK === $adminId) {
            $msg = "⚠️ Không thể tự khóa tài khoản đang đăng nhập!"; $msgType = 'error';
        } else {
            $curSt = $pdo->prepare("SELECT TrangThai FROM TAI_KHOAN WHERE TenTK = :tk");
            $curSt->execute([':tk' => $tenTK]);
            $cur = $curSt->fetchColumn();
            $newSt = ($cur === 'Hoạt động') ? 'Khóa' : 'Hoạt động';
            $pdo->prepare("UPDATE TAI_KHOAN SET TrangThai=:st WHERE TenTK=:tk")
                ->execute([':st'=>$newSt,':tk'=>$tenTK]);
            $msg = ($newSt === 'Khóa') ? "🔒 Đã khóa tài khoản '$tenTK'." : "🔓 Đã mở khóa tài khoản '$tenTK'.";
        }
        $tab = 'staff';
    }

    // ── Xóa tài khoản NV ─────────────────────────────────────────
    elseif ($action === 'delete_account') {
        $tenTK = trim($_POST['ten_tk'] ?? '');
        if ($tenTK === $adminId) {
            $msg = "⚠️ Không thể tự xóa tài khoản đang đăng nhập!"; $msgType = 'error';
        } else {
            $pdo->prepare("DELETE FROM TAI_KHOAN WHERE TenTK = :tk")->execute([':tk' => $tenTK]);
            $msg = "Đã xóa tài khoản '$tenTK'.";
        }
        $tab = 'staff';
    }

    // ── Toggle trạng thái tài khoản KH ───────────────────────────
    elseif ($action === 'toggle_kh_status') {
        $maKH = trim($_POST['ma_kh'] ?? '');
        $curQ = $pdo->prepare("SELECT TrangThai, HoTen FROM KHACH_HANG WHERE MaKH = :id");
        $curQ->execute([':id' => $maKH]);
        $row  = $curQ->fetch();
        $cur  = $row['TrangThai'] ?? 'Hoạt động';
        $newSt = ($cur === 'Hoạt động') ? 'Khóa' : 'Hoạt động';
        $pdo->prepare("UPDATE KHACH_HANG SET TrangThai=:st WHERE MaKH=:id")
            ->execute([':st'=>$newSt,':id'=>$maKH]);
        $msg = ($newSt === 'Khóa')
            ? "🔒 Đã khóa tài khoản KH: {$row['HoTen']}."
            : "🔓 Đã mở khóa tài khoản KH: {$row['HoTen']}.";
        $tab = 'customers';
    }

    // ── Sửa thông tin KH ─────────────────────────────────────────
    elseif ($action === 'edit_kh') {
        $maKH   = trim($_POST['ma_kh']   ?? '');
        $hoTen  = trim($_POST['ho_ten']  ?? '');
        $sdt    = trim($_POST['sdt']     ?? '');
        $email  = trim($_POST['email']   ?? '');
        $cccd   = trim($_POST['cccd']    ?? '');
        $diaChi = trim($_POST['dia_chi'] ?? '');
        if (!$maKH || !$hoTen) {
            $msg = "Vui lòng nhập Họ tên khách hàng.";
            $msgType = 'error'; $tab = 'edit_kh'; $editKhId = $maKH;
        } else {
            $pdo->prepare("UPDATE KHACH_HANG SET HoTen=?,SoDienThoai=?,Email=?,CCCD=?,DiaChi=? WHERE MaKH=?")
                ->execute([$hoTen, $sdt ?: null, $email ?: null, encryptCCCD($cccd ?: null), $diaChi ?: null, $maKH]);
            $msg = "Đã cập nhật thông tin khách hàng '$hoTen'.";
            $tab = 'customers';
        }
    }

    // ── Sửa thông tin NV ─────────────────────────────────────────
    elseif ($action === 'edit_nv') {
        $maNV       = trim($_POST['ma_nv']         ?? '');
        $hoTen      = trim($_POST['ho_ten']        ?? '');
        $sdt        = trim($_POST['sdt']           ?? '');
        $email      = trim($_POST['email']         ?? '');
        $chucVu     = trim($_POST['chuc_vu']       ?? 'Lễ tân');
        $ngayVaoLam = trim($_POST['ngay_vao_lam']  ?? '');
        $trangThaiNV = trim($_POST['trang_thai_nv'] ?? 'Đang làm việc');
        if (!$maNV || !$hoTen) {
            $msg = "Vui lòng nhập Họ tên nhân viên.";
            $msgType = 'error'; $tab = 'edit_nv'; $editNvId = $maNV;
        } else {
            $chucVu      = in_array($chucVu, ['Lễ tân','Quản lý']) ? $chucVu : 'Lễ tân';
            $trangThaiNV = in_array($trangThaiNV, ['Đang làm việc','Ngừng hoạt động']) ? $trangThaiNV : 'Đang làm việc';
            $pdo->prepare("UPDATE NHAN_VIEN SET HoTen=?,SoDienThoai=?,Email=?,ChucVu=?,NgayVaoLam=?,TrangThai=? WHERE MaNV=?")
                ->execute([$hoTen, $sdt ?: null, $email ?: null, $chucVu, $ngayVaoLam ?: null, $trangThaiNV, $maNV]);
            $msg = "Đã cập nhật thông tin nhân viên '$hoTen'.";
            $tab = 'staff'; $editNvId = '';
        }
    }

    // PRG redirect
    header("Location: accounts.php?tab=$tab"
        . ($editId   ? "&id=".urlencode($editId)       : "")
        . ($editKhId ? "&kh_id=".urlencode($editKhId)  : "")
        . ($editNvId ? "&nv_id=".urlencode($editNvId)  : "")
        . "&msg=" . urlencode($msg)
        . "&mtype=$msgType");
    exit;
}

// Lấy message từ redirect
if (!$msg && isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['mtype'] ?? 'success';
}

// ═══════════════════════════════════════════════════════════════════
//  DATA QUERIES
// ═══════════════════════════════════════════════════════════════════

$filterRole = $_GET['role']   ?? 'all';
$filterSt   = $_GET['status'] ?? 'all';

// Danh sách tài khoản NV
$sql = "SELECT tk.*, nv.HoTen AS TenNV, nv.ChucVu, nv.SoDienThoai AS DienThoaiNV
        FROM TAI_KHOAN tk
        LEFT JOIN NHAN_VIEN nv ON tk.MaNV = nv.MaNV
        WHERE 1=1";
$params = [];
if ($filterRole !== 'all') { $sql .= " AND tk.VaiTro = :vt";    $params[':vt'] = $filterRole; }
if ($filterSt !== 'all')   { $sql .= " AND tk.TrangThai = :st"; $params[':st'] = $filterSt;   }
$sql .= " ORDER BY tk.NgayTaoTK DESC";
$stmtAccounts = $pdo->prepare($sql);
$stmtAccounts->execute($params);
$staffAccounts = $stmtAccounts->fetchAll();

// Danh sách tài khoản KH
$khAccounts = $pdo->query("
    SELECT MaKH, HoTen, TenTaiKhoan, Email, SoDienThoai, TichDiem, NgayTao,
           COALESCE(TrangThai,'Hoạt động') AS TrangThai, CCCD, QuocTich
    FROM KHACH_HANG
    WHERE TenTaiKhoan IS NOT NULL AND TenTaiKhoan != ''
    ORDER BY NgayTao DESC
")->fetchAll();

// Edit data
$editData = null;
if ($tab === 'edit' && $editId) {
    $stmtE = $pdo->prepare("
        SELECT tk.*, nv.HoTen AS TenNV
        FROM TAI_KHOAN tk
        LEFT JOIN NHAN_VIEN nv ON tk.MaNV = nv.MaNV
        WHERE tk.TenTK = :id
    ");
    $stmtE->execute([':id' => $editId]);
    $editData = $stmtE->fetch();
    if (!$editData) { $tab = 'staff'; $editId = ''; }
}

// Edit KH data
$editKhData = null;
if ($tab === 'edit_kh' && $editKhId) {
    $stmtKhE = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE MaKH = :id");
    $stmtKhE->execute([':id' => $editKhId]);
    $editKhData = $stmtKhE->fetch();
    if (!$editKhData) { $tab = 'customers'; $editKhId = ''; }
}

// Edit NV data
$editNvData = null;
if ($tab === 'edit_nv' && $editNvId) {
    $stmtNvE = $pdo->prepare("SELECT * FROM NHAN_VIEN WHERE MaNV = :id");
    $stmtNvE->execute([':id' => $editNvId]);
    $editNvData = $stmtNvE->fetch();
    if (!$editNvData) { $tab = 'staff'; $editNvId = ''; }
}

// Nhân viên đang làm để gán tài khoản
$allNV = $pdo->query("
    SELECT nv.MaNV, nv.HoTen, nv.ChucVu,
           (SELECT TenTK FROM TAI_KHOAN WHERE MaNV = nv.MaNV LIMIT 1) AS TenTKHienCo
    FROM NHAN_VIEN nv
    WHERE nv.TrangThai = 'Đang làm việc'
    ORDER BY nv.HoTen
")->fetchAll();

// Stats
$stats = [
    'total'     => (int)$pdo->query("SELECT COUNT(*) FROM TAI_KHOAN")->fetchColumn(),
    'admin'     => (int)$pdo->query("SELECT COUNT(*) FROM TAI_KHOAN WHERE VaiTro='admin'")->fetchColumn(),
    'nhanvien'  => (int)$pdo->query("SELECT COUNT(*) FROM TAI_KHOAN WHERE VaiTro='nhanvien'")->fetchColumn(),
    'locked'    => (int)$pdo->query("SELECT COUNT(*) FROM TAI_KHOAN WHERE TrangThai='Khóa'")->fetchColumn(),
    'khCount'   => (int)$pdo->query("SELECT COUNT(*) FROM KHACH_HANG WHERE TenTaiKhoan IS NOT NULL AND TenTaiKhoan != ''")->fetchColumn(),
    'khLocked'  => (int)$pdo->query("SELECT COUNT(*) FROM KHACH_HANG WHERE TrangThai='Khóa'")->fetchColumn(),
];

// ═══════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════
function esc(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function statusBadge(string $s): string {
    if ($s === 'Hoạt động') return "<span class='badge badge-active'>✓ Hoạt động</span>";
    return "<span class='badge badge-locked'>🔒 Khóa</span>";
}
function roleBadge(string $r): string {
    if ($r === 'admin')
        return "<span class='badge badge-admin'>👑 Quản Lý</span>";
    return "<span class='badge badge-staff'>👤 Lễ Tân</span>";
}
function fmtDate(?string $d): string {
    if (!$d) return '<span style="color:#94a3b8">—</span>';
    $ts = strtotime($d);
    return ($ts !== false) ? date('d/m/Y H:i', $ts) : esc($d);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quản Lý Tài Khoản — Easyhome</title>
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

/* ── ALERT ── */
.alert{padding:12px 18px;border-radius:8px;font-size:.88rem;font-weight:600;
  margin-bottom:20px;display:flex;align-items:center;gap:8px}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#fff;border-radius:var(--radius);padding:16px 18px;
  border:1.5px solid var(--border);box-shadow:var(--shadow);transition:.2s}
.stat-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px)}
.stat-icon{font-size:1.6rem;margin-bottom:6px}
.stat-val{font-size:1.6rem;font-weight:800;color:var(--blue-dark);line-height:1}
.stat-label{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:3px}

/* ── TABS ── */
.page-tabs{display:flex;gap:4px;background:#fff;border-radius:var(--radius);
  padding:6px;box-shadow:var(--shadow);margin-bottom:20px;border:1.5px solid var(--border)}
.ptab{padding:8px 18px;border-radius:7px;border:none;background:transparent;cursor:pointer;
  font-family:var(--font);font-size:.85rem;font-weight:700;color:var(--muted);
  text-decoration:none;transition:.2s;display:flex;align-items:center;gap:6px}
.ptab:hover{background:var(--blue-pale);color:var(--blue)}
.ptab.active{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(29,78,216,.3)}
.ptab-edit{color:var(--blue-dark)}

/* ── FILTER BAR ── */
.filter-row{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.ftab{padding:5px 14px;border-radius:20px;border:1.5px solid var(--border);
  background:#fff;color:var(--muted);font-size:.78rem;font-weight:700;cursor:pointer;
  text-decoration:none;transition:.2s;white-space:nowrap}
.ftab:hover{border-color:var(--blue-light);color:var(--blue)}
.ftab.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.filter-sep{width:1px;height:24px;background:var(--border);margin:0 4px}

/* ── TABLE ── */
.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);box-shadow:var(--shadow);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-title{font-family:var(--serif);font-size:1rem;color:var(--blue-dark)}
.tbl{width:100%;border-collapse:collapse}
.tbl th{background:var(--blue-pale);color:var(--blue-dark);font-size:.72rem;text-transform:uppercase;
  letter-spacing:.8px;padding:10px 14px;text-align:left;white-space:nowrap}
.tbl td{padding:11px 14px;border-bottom:1px solid #f1f5f9;font-size:.85rem;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:var(--blue-pale)}
.tbl-actions{display:flex;gap:6px;align-items:center}
.btn-edit{padding:4px 10px;border-radius:6px;border:1.5px solid #3b82f6;background:#eff6ff;
  color:#1d4ed8;font-size:.75rem;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;transition:.2s}
.btn-edit:hover{background:#1d4ed8;color:#fff}
.btn-toggle{padding:4px 10px;border-radius:6px;border:1.5px solid;font-size:.75rem;
  font-weight:700;cursor:pointer;white-space:nowrap;transition:.2s;background:transparent}
.btn-toggle-lock{border-color:#f59e0b;color:#b45309}
.btn-toggle-lock:hover{background:#f59e0b;color:#fff}
.btn-toggle-unlock{border-color:#22c55e;color:#166534}
.btn-toggle-unlock:hover{background:#22c55e;color:#fff}
.btn-danger{padding:4px 10px;border-radius:6px;border:1.5px solid #ef4444;background:#fff0f0;
  color:#ef4444;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:.2s}
.btn-danger:hover{background:#ef4444;color:#fff}
.btn-primary-sm{padding:6px 16px;border-radius:7px;background:var(--blue);color:#fff;border:none;
  font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;gap:5px;transition:.2s}
.btn-primary-sm:hover{background:var(--blue-dark)}

/* ── BADGES ── */
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:700;white-space:nowrap}
.badge-active{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-locked{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-admin{background:#fef3c7;color:#d97706;border:1px solid #fcd34d}
.badge-staff{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}

/* ── AVATAR ── */
.avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--blue-light));
  display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem;font-weight:700;flex-shrink:0}
.user-cell{display:flex;align-items:center;gap:10px}
.user-name{font-weight:700;color:var(--text)}
.user-sub{font-size:.72rem;color:var(--muted)}

/* ── FORMS ── */
.form-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);padding:28px 32px;max-width:640px}
.form-section-title{font-family:var(--serif);font-size:1rem;color:var(--blue-dark);
  margin-bottom:20px;padding-bottom:10px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group.full{grid-column:1/-1}
label{font-size:.78rem;font-weight:700;color:var(--blue-dark);text-transform:uppercase;letter-spacing:.5px}
.form-input,.form-select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;
  font-family:var(--font);font-size:.9rem;color:var(--text);transition:.2s;background:#fff}
.form-input:focus,.form-select:focus{outline:none;border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.form-input:read-only{background:#f8fafc;color:var(--muted);cursor:not-allowed}
.form-hint{font-size:.72rem;color:var(--muted);margin-top:2px}
.form-actions{display:flex;gap:10px;margin-top:24px;align-items:center}
.btn-submit{padding:10px 24px;border-radius:8px;background:var(--blue);color:#fff;border:none;
  font-family:var(--font);font-size:.9rem;font-weight:700;cursor:pointer;transition:.2s}
.btn-submit:hover{background:var(--blue-dark)}
.btn-secondary{padding:10px 20px;border-radius:8px;background:transparent;color:var(--muted);
  border:1.5px solid var(--border);font-family:var(--font);font-size:.9rem;font-weight:700;
  cursor:pointer;text-decoration:none;transition:.2s;display:inline-block}
.btn-secondary:hover{border-color:var(--blue-light);color:var(--blue)}
.password-wrap{position:relative}
.password-wrap .form-input{padding-right:40px}
.toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:1rem;color:var(--muted);padding:0}
.divider{border:none;border-top:1.5px dashed var(--border);margin:24px 0}
.btn-danger-lg{padding:10px 20px;border-radius:8px;background:#fff0f0;color:#ef4444;
  border:1.5px solid #fecaca;font-family:var(--font);font-size:.88rem;font-weight:700;cursor:pointer;transition:.2s}
.btn-danger-lg:hover{background:#ef4444;color:#fff}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:48px 20px;color:var(--muted)}
.empty-icon{font-size:2.5rem;margin-bottom:12px}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .stats-grid{grid-template-columns:repeat(3,1fr)}
  .form-grid{grid-template-columns:1fr}
}
@media(max-width:600px){
  .content{padding:14px 10px}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<!-- ════ SIDEBAR ════ -->
<aside class="sidebar">
  <div class="sb-brand">
    <img src="../assets/images/logo.jpg" alt="Logo" class="sb-logo">
    <div>
      <div class="sb-site-name">Easyhome</div>
      <div class="sb-site-sub">Hotel Admin</div>
    </div>
  </div>
  <div class="sb-admin">
    <div class="sb-admin-label">Quản Trị Viên</div>
    <div class="sb-admin-card">
      <div class="sb-admin-name"><?= esc($adminName) ?></div>
      <div class="sb-admin-row">🔑 <?= esc($adminId) ?></div>
      <div class="sb-admin-badge">ADMIN</div>
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
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"       class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                  class="sb-nav-item active"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
  </nav>
  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-title">🔑 Quản Lý Tài Khoản</div>
    <div class="topbar-meta">
      <span>📅 <?= date('d/m/Y') ?></span>
      <span>👤 <?= esc($adminName) ?></span>
    </div>
  </div>

  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>">
      <?= $msgType === 'error' ? '⚠️' : '✅' ?> <?= esc($msg) ?>
    </div>
    <?php endif; ?>

    <!-- ── STAT CARDS ── -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon">🔑</div>
        <div class="stat-val"><?= $stats['total'] ?></div>
        <div class="stat-label">Tài Khoản NV</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">👑</div>
        <div class="stat-val"><?= $stats['admin'] ?></div>
        <div class="stat-label">Quản Lý</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">👤</div>
        <div class="stat-val"><?= $stats['nhanvien'] ?></div>
        <div class="stat-label">Lễ Tân</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">🔒</div>
        <div class="stat-val" style="color:<?= $stats['locked'] > 0 ? '#ef4444' : 'var(--blue-dark)' ?>"><?= $stats['locked'] ?></div>
        <div class="stat-label">Đang Khóa (NV)</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">🧑‍💼</div>
        <div class="stat-val"><?= $stats['khCount'] ?></div>
        <div class="stat-label">Tài Khoản KH</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">🚫</div>
        <div class="stat-val" style="color:<?= $stats['khLocked'] > 0 ? '#ef4444' : 'var(--blue-dark)' ?>"><?= $stats['khLocked'] ?></div>
        <div class="stat-label">KH Bị Khóa</div>
      </div>
    </div>

    <!-- ── TAB NAV ── -->
    <div class="page-tabs">
      <a href="accounts.php?tab=staff"     class="ptab <?= $tab==='staff'     ? 'active' : '' ?>">👥 Tài Khoản Nhân Viên</a>
      <a href="accounts.php?tab=customers" class="ptab <?= $tab==='customers' ? 'active' : '' ?>">🧑‍💼 Tài Khoản Khách Hàng</a>
      <a href="accounts.php?tab=add"       class="ptab <?= $tab==='add'       ? 'active' : '' ?>">➕ Thêm Tài Khoản</a>
      <?php if ($editData): ?>
      <a href="accounts.php?tab=edit&id=<?= esc($editId) ?>" class="ptab ptab-edit <?= $tab==='edit' ? 'active' : '' ?>">✏️ Sửa TK: <?= esc($editId) ?></a>
      <?php endif; ?>
      <?php if ($editKhData): ?>
      <a href="accounts.php?tab=edit_kh&kh_id=<?= esc($editKhId) ?>" class="ptab ptab-edit <?= $tab==='edit_kh' ? 'active' : '' ?>">✏️ KH: <?= esc(mb_substr($editKhData['HoTen'],0,14)) ?></a>
      <?php endif; ?>
      <?php if ($editNvData): ?>
      <a href="accounts.php?tab=edit_nv&nv_id=<?= esc($editNvId) ?>" class="ptab ptab-edit <?= $tab==='edit_nv' ? 'active' : '' ?>">✏️ NV: <?= esc(mb_substr($editNvData['HoTen'],0,14)) ?></a>
      <?php endif; ?>
    </div>

    <?php /* ════════════════════════════════════════════════════════
           TAB: STAFF ACCOUNTS
           ════════════════════════════════════════════════════════ */ ?>
    <?php if ($tab === 'staff'): ?>

    <!-- Filter bar -->
    <div class="filter-row">
      <span style="font-size:.78rem;font-weight:700;color:var(--muted)">Vai trò:</span>
      <?php
      $roles = ['all'=>'Tất cả','admin'=>'👑 Quản Lý','nhanvien'=>'👤 Lễ Tân'];
      foreach ($roles as $k=>$v):
        $url = "accounts.php?tab=staff&role=$k&status=".urlencode($filterSt);
      ?>
        <a href="<?= $url ?>" class="ftab <?= $filterRole===$k?'active':'' ?>"><?= $v ?></a>
      <?php endforeach; ?>
      <div class="filter-sep"></div>
      <span style="font-size:.78rem;font-weight:700;color:var(--muted)">Trạng thái:</span>
      <?php
      $statuses = ['all'=>'Tất cả','Hoạt động'=>'✓ Hoạt động','Khóa'=>'🔒 Khóa'];
      foreach ($statuses as $k=>$v):
        $url = "accounts.php?tab=staff&role=".urlencode($filterRole)."&status=".urlencode($k);
      ?>
        <a href="<?= $url ?>" class="ftab <?= $filterSt===$k?'active':'' ?>"><?= $v ?></a>
      <?php endforeach; ?>
      <div style="flex:1"></div>
      <a href="accounts.php?tab=add" class="btn-primary-sm">➕ Thêm Tài Khoản</a>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title">Tài Khoản Nhân Viên / Quản Lý</span>
        <span style="font-size:.78rem;color:var(--muted)"><?= count($staffAccounts) ?> tài khoản</span>
      </div>
      <?php if ($staffAccounts): ?>
      <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Tài Khoản</th>
            <th>Nhân Viên</th>
            <th>Vai Trò</th>
            <th>Trạng Thái</th>
            <th>Ngày Tạo</th>
            <th>Đăng Nhập Cuối</th>
            <th style="text-align:center">Thao Tác</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($staffAccounts as $i => $acc): ?>
          <?php
            $initials = mb_strtoupper(mb_substr($acc['TenNV'] ?? $acc['TenTK'], 0, 1));
            $isSelf   = ($acc['TenTK'] === $adminId);
          ?>
          <tr class="acc-row" style="opacity:0;transform:translateX(-10px)">
            <td>
              <div class="user-cell">
                <div class="avatar"><?= esc($initials) ?></div>
                <div>
                  <div class="user-name">
                    <?= esc($acc['TenTK']) ?>
                    <?php if ($isSelf): ?><span style="font-size:.68rem;color:#3b82f6;font-weight:700;margin-left:4px">(Bạn)</span><?php endif; ?>
                  </div>
                  <?php if ($acc['TenNV']): ?><div class="user-sub"><?= esc($acc['TenNV']) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php if ($acc['ChucVu']): ?>
                <span style="font-size:.82rem;color:var(--muted)"><?= esc($acc['ChucVu']) ?></span>
              <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
            </td>
            <td><?= roleBadge($acc['VaiTro']) ?></td>
            <td><?= statusBadge($acc['TrangThai']) ?></td>
            <td style="font-size:.8rem;color:var(--muted)"><?= fmtDate($acc['NgayTaoTK']) ?></td>
            <td style="font-size:.8rem;color:var(--muted)"><?= fmtDate($acc['LanDangNhapCuoi']) ?></td>
            <td>
              <div class="tbl-actions" style="justify-content:center">
                <a href="accounts.php?tab=edit&id=<?= esc($acc['TenTK']) ?>" class="btn-edit">✏️ Sửa TK</a>
                <?php if (!empty($acc['MaNV'])): ?>
                  <a href="accounts.php?tab=edit_nv&nv_id=<?= esc($acc['MaNV']) ?>" class="btn-edit" style="border-color:#16a34a;color:#15803d;background:#f0fdf4">👤 Sửa NV</a>
                <?php endif; ?>
                <?php if (!$isSelf): ?>
                  <?php if ($acc['TrangThai'] === 'Hoạt động'): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Khóa tài khoản <?= esc($acc['TenTK']) ?>?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle_staff_status">
                      <input type="hidden" name="ten_tk" value="<?= esc($acc['TenTK']) ?>">
                      <button type="submit" class="btn-toggle btn-toggle-lock">🔒 Khóa</button>
                    </form>
                  <?php else: ?>
                    <form method="POST" style="display:inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle_staff_status">
                      <input type="hidden" name="ten_tk" value="<?= esc($acc['TenTK']) ?>">
                      <button type="submit" class="btn-toggle btn-toggle-unlock">🔓 Mở</button>
                    </form>
                  <?php endif; ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Xóa vĩnh viễn tài khoản «<?= esc($acc['TenTK']) ?>»?\n\nHành động này không thể hoàn tác!')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_account">
                    <input type="hidden" name="ten_tk" value="<?= esc($acc['TenTK']) ?>">
                    <button type="submit" class="btn-danger">🗑️</button>
                  </form>
                <?php else: ?>
                  <span style="font-size:.72rem;color:#94a3b8;font-style:italic">Đang dùng</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <div class="empty">
        <div class="empty-icon">🔍</div>
        <p>Không tìm thấy tài khoản nào phù hợp với bộ lọc.</p>
        <a href="accounts.php?tab=add" style="display:inline-block;margin-top:12px;padding:7px 18px;background:var(--blue);color:#fff;border-radius:7px;text-decoration:none;font-size:.85rem;font-weight:700">➕ Thêm Tài Khoản</a>
      </div>
      <?php endif; ?>
    </div>

    <?php /* ════════════════════════════════════════════════════════
           TAB: CUSTOMER ACCOUNTS
           ════════════════════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'customers'): ?>

    <div class="card">
      <div class="card-header">
        <span class="card-title">Tài Khoản Khách Hàng Đã Đăng Ký</span>
        <span style="font-size:.78rem;color:var(--muted)"><?= count($khAccounts) ?> tài khoản</span>
      </div>
      <?php if ($khAccounts): ?>
      <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Khách Hàng</th>
            <th>Tài Khoản</th>
            <th>Liên Hệ</th>
            <th>Điểm Tích Lũy</th>
            <th>Ngày Đăng Ký</th>
            <th>Trạng Thái</th>
            <th style="text-align:center">Thao Tác</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($khAccounts as $i => $kh): ?>
          <?php $initials = mb_strtoupper(mb_substr($kh['HoTen'], 0, 1)); ?>
          <tr class="kh-row" style="opacity:0;transform:translateX(-10px)">
            <td>
              <div class="user-cell">
                <div class="avatar" style="background:linear-gradient(135deg,#16a34a,#22c55e)"><?= esc($initials) ?></div>
                <div>
                  <div class="user-name"><?= esc($kh['HoTen']) ?></div>
                  <div class="user-sub"><?= esc($kh['MaKH']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <span style="font-weight:700;color:var(--blue)">@<?= esc($kh['TenTaiKhoan']) ?></span>
              <?php if ($kh['Email']): ?><div class="user-sub"><?= esc($kh['Email']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:.82rem">
              <?= $kh['SoDienThoai'] ? '📞 '.esc($kh['SoDienThoai']) : '<span style="color:#94a3b8">—</span>' ?>
              <?php if ($kh['QuocTich'] && $kh['QuocTich'] !== 'Việt Nam'): ?>
                <div class="user-sub">🌍 <?= esc($kh['QuocTich']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span style="font-weight:700;color:var(--blue-dark)"><?= number_format($kh['TichDiem']) ?></span>
              <span style="font-size:.72rem;color:var(--muted)"> điểm</span>
            </td>
            <td style="font-size:.8rem;color:var(--muted)"><?= fmtDate($kh['NgayTao']) ?></td>
            <td><?= statusBadge($kh['TrangThai'] ?? 'Hoạt động') ?></td>
            <td>
              <div class="tbl-actions" style="justify-content:center">
                <a href="accounts.php?tab=edit_kh&kh_id=<?= esc($kh['MaKH']) ?>" class="btn-edit">✏️ Sửa</a>
                <?php $khSt = $kh['TrangThai'] ?? 'Hoạt động'; ?>
                <?php if ($khSt === 'Hoạt động'): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Khóa tài khoản của «<?= esc($kh['HoTen']) ?>»?\n\nKhách này sẽ không đăng nhập được cho đến khi mở khóa.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_kh_status">
                    <input type="hidden" name="ma_kh" value="<?= esc($kh['MaKH']) ?>">
                    <button type="submit" class="btn-toggle btn-toggle-lock">🔒 Khóa</button>
                  </form>
                <?php else: ?>
                  <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_kh_status">
                    <input type="hidden" name="ma_kh" value="<?= esc($kh['MaKH']) ?>">
                    <button type="submit" class="btn-toggle btn-toggle-unlock">🔓 Mở Khóa</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <div class="empty">
        <div class="empty-icon">🧑‍💼</div>
        <p>Chưa có khách hàng nào đăng ký tài khoản.</p>
      </div>
      <?php endif; ?>
    </div>

    <?php /* ════════════════════════════════════════════════════════
           TAB: ADD ACCOUNT
           ════════════════════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'add'): ?>

    <div class="form-card">
      <div class="form-section-title">➕ Tạo Tài Khoản Nhân Viên Mới</div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_account">
        <div class="form-grid">
          <div class="form-group">
            <label for="ten_tk">Tên Tài Khoản *</label>
            <input type="text" id="ten_tk" name="ten_tk" class="form-input"
              placeholder="VD: letan03" maxlength="50" required
              oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_\.]/g,'')">
            <span class="form-hint">Chỉ dùng chữ thường, số, dấu _ hoặc .</span>
          </div>
          <div class="form-group">
            <label for="vai_tro">Vai Trò *</label>
            <select name="vai_tro" id="vai_tro" class="form-select">
              <option value="nhanvien">👤 Lễ Tân</option>
              <option value="admin">👑 Quản Lý (Admin)</option>
            </select>
          </div>
          <div class="form-group">
            <label for="mat_khau">Mật Khẩu *</label>
            <div class="password-wrap">
              <input type="password" id="mat_khau" name="mat_khau" class="form-input"
                placeholder="Ít nhất 6 ký tự" minlength="6" required>
              <button type="button" class="toggle-pw" onclick="togglePw('mat_khau')">👁️</button>
            </div>
          </div>
          <div class="form-group">
            <label for="mat_khau2">Xác Nhận Mật Khẩu *</label>
            <div class="password-wrap">
              <input type="password" id="mat_khau2" name="mat_khau2" class="form-input"
                placeholder="Nhập lại mật khẩu" minlength="6" required>
              <button type="button" class="toggle-pw" onclick="togglePw('mat_khau2')">👁️</button>
            </div>
          </div>
          <div class="form-group full">
            <label for="ma_nv">Liên Kết Nhân Viên</label>
            <select name="ma_nv" id="ma_nv" class="form-select">
              <option value="">— Chưa liên kết —</option>
              <?php foreach ($allNV as $nv): ?>
                <option value="<?= esc($nv['MaNV']) ?>"
                  <?= ($nv['TenTKHienCo'] ? 'style="color:#94a3b8"' : '') ?>>
                  <?= esc($nv['HoTen']) ?> (<?= esc($nv['MaNV']) ?> — <?= esc($nv['ChucVu']) ?>)
                  <?= $nv['TenTKHienCo'] ? ' [đã có TK: '.$nv['TenTKHienCo'].']' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-hint">Tùy chọn — gán tài khoản với hồ sơ nhân viên tương ứng</span>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-submit">✅ Tạo Tài Khoản</button>
          <a href="accounts.php?tab=staff" class="btn-secondary">← Hủy bỏ</a>
        </div>
      </form>
    </div>

    <?php /* ════════════════════════════════════════════════════════
           TAB: EDIT ACCOUNT
           ════════════════════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'edit' && $editData): ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

      <!-- Form sửa thông tin -->
      <div class="form-card">
        <div class="form-section-title">✏️ Sửa Tài Khoản: <?= esc($editData['TenTK']) ?></div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="edit_account">
          <input type="hidden" name="ten_tk" value="<?= esc($editData['TenTK']) ?>">
          <div class="form-group" style="margin-bottom:16px">
            <label>Tên Tài Khoản</label>
            <input type="text" class="form-input" value="<?= esc($editData['TenTK']) ?>" readonly>
            <span class="form-hint">Tên tài khoản không thể thay đổi</span>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label for="vai_tro_e">Vai Trò</label>
              <select name="vai_tro" id="vai_tro_e" class="form-select"
                <?= ($editData['TenTK'] === $adminId) ? 'disabled' : '' ?>>
                <option value="nhanvien" <?= $editData['VaiTro']==='nhanvien'?'selected':'' ?>>👤 Lễ Tân</option>
                <option value="admin"    <?= $editData['VaiTro']==='admin'   ?'selected':'' ?>>👑 Quản Lý</option>
              </select>
              <?php if ($editData['TenTK'] === $adminId): ?>
                <input type="hidden" name="vai_tro" value="<?= esc($editData['VaiTro']) ?>">
                <span class="form-hint" style="color:#f59e0b">⚠️ Không thể hạ quyền tài khoản đang dùng</span>
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label for="trang_thai_e">Trạng Thái</label>
              <select name="trang_thai" id="trang_thai_e" class="form-select"
                <?= ($editData['TenTK'] === $adminId) ? 'disabled' : '' ?>>
                <option value="Hoạt động" <?= $editData['TrangThai']==='Hoạt động'?'selected':'' ?>>✓ Hoạt động</option>
                <option value="Khóa"      <?= $editData['TrangThai']==='Khóa'     ?'selected':'' ?>>🔒 Khóa</option>
              </select>
              <?php if ($editData['TenTK'] === $adminId): ?>
                <input type="hidden" name="trang_thai" value="Hoạt động">
                <span class="form-hint" style="color:#f59e0b">⚠️ Không thể khóa tài khoản đang dùng</span>
              <?php endif; ?>
            </div>
            <div class="form-group full">
              <label for="ma_nv_e">Liên Kết Nhân Viên</label>
              <select name="ma_nv" id="ma_nv_e" class="form-select">
                <option value="">— Chưa liên kết —</option>
                <?php foreach ($allNV as $nv): ?>
                  <option value="<?= esc($nv['MaNV']) ?>" <?= $editData['MaNV']===$nv['MaNV']?'selected':'' ?>>
                    <?= esc($nv['HoTen']) ?> (<?= esc($nv['MaNV']) ?> — <?= esc($nv['ChucVu']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn-submit">💾 Lưu Thay Đổi</button>
            <a href="accounts.php?tab=staff" class="btn-secondary">← Hủy</a>
          </div>
        </form>

        <hr class="divider">

        <!-- Đặt lại mật khẩu -->
        <div class="form-section-title" style="margin-top:0">🔐 Đặt Lại Mật Khẩu</div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="ten_tk" value="<?= esc($editData['TenTK']) ?>">
          <div class="form-grid">
            <div class="form-group">
              <label>Mật Khẩu Mới</label>
              <div class="password-wrap">
                <input type="password" name="mat_khau" class="form-input"
                  placeholder="Ít nhất 6 ký tự" minlength="6" required>
                <button type="button" class="toggle-pw" onclick="togglePw(this.previousElementSibling)">👁️</button>
              </div>
            </div>
            <div class="form-group">
              <label>Xác Nhận Mật Khẩu</label>
              <div class="password-wrap">
                <input type="password" name="mat_khau2" class="form-input"
                  placeholder="Nhập lại" minlength="6" required>
                <button type="button" class="toggle-pw" onclick="togglePw(this.previousElementSibling)">👁️</button>
              </div>
            </div>
          </div>
          <div class="form-actions" style="margin-top:14px">
            <button type="submit" class="btn-submit" style="background:#f59e0b" onclick="return confirm('Đặt lại mật khẩu cho «<?= esc($editData['TenTK']) ?>»?')">🔑 Đặt Lại Mật Khẩu</button>
          </div>
        </form>
      </div>

      <!-- Thông tin & Xóa -->
      <div style="display:flex;flex-direction:column;gap:16px">
        <!-- Info card -->
        <div class="form-card" style="max-width:100%">
          <div class="form-section-title">ℹ️ Thông Tin Tài Khoản</div>
          <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <tr><td style="padding:8px 0;color:var(--muted);width:45%">Tên tài khoản</td>
                <td style="font-weight:700"><?= esc($editData['TenTK']) ?></td></tr>
            <tr><td style="padding:8px 0;color:var(--muted)">Nhân viên</td>
                <td><?= $editData['TenNV'] ? esc($editData['TenNV']) : '<span style="color:#94a3b8">Chưa gán</span>' ?></td></tr>
            <tr><td style="padding:8px 0;color:var(--muted)">Vai trò</td>
                <td><?= roleBadge($editData['VaiTro']) ?></td></tr>
            <tr><td style="padding:8px 0;color:var(--muted)">Trạng thái</td>
                <td><?= statusBadge($editData['TrangThai']) ?></td></tr>
            <tr><td style="padding:8px 0;color:var(--muted)">Ngày tạo</td>
                <td style="font-size:.8rem"><?= fmtDate($editData['NgayTaoTK']) ?></td></tr>
            <tr><td style="padding:8px 0;color:var(--muted)">Đăng nhập cuối</td>
                <td style="font-size:.8rem"><?= fmtDate($editData['LanDangNhapCuoi']) ?></td></tr>
          </table>
        </div>

        <!-- Vùng nguy hiểm -->
        <?php if ($editData['TenTK'] !== $adminId): ?>
        <div class="form-card" style="max-width:100%;border-color:#fecaca;background:#fff8f8">
          <div class="form-section-title" style="color:#dc2626">⚠️ Vùng Nguy Hiểm</div>
          <p style="font-size:.82rem;color:#991b1b;margin-bottom:14px">
            Xóa tài khoản sẽ <strong>không thể hoàn tác</strong>. Lịch sử đặt phòng của nhân viên này vẫn được giữ lại.
          </p>
          <form method="POST" onsubmit="return confirm('🗑️ Xóa vĩnh viễn tài khoản «<?= esc($editData['TenTK']) ?>»?\n\nHành động này KHÔNG thể hoàn tác!')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_account">
            <input type="hidden" name="ten_tk" value="<?= esc($editData['TenTK']) ?>">
            <button type="submit" class="btn-danger-lg">🗑️ Xóa Tài Khoản</button>
          </form>
        </div>
        <?php else: ?>
        <div class="form-card" style="max-width:100%;border-color:#bfdbfe;background:#eff6ff">
          <div style="font-size:.82rem;color:#1d4ed8;text-align:center;padding:8px">
            🛡️ Đây là tài khoản đang đăng nhập — không thể xóa.
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* ════════════════════════════════════════════════════════
           TAB: EDIT CUSTOMER INFO
           ════════════════════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'edit_kh' && $editKhData): ?>

    <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start">

      <!-- Form sửa thông tin KH -->
      <div class="form-card" style="max-width:100%">
        <div class="form-section-title">✏️ Sửa Thông Tin Khách Hàng</div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="edit_kh">
          <input type="hidden" name="ma_kh" value="<?= esc($editKhData['MaKH']) ?>">
          <div class="form-grid">
            <div class="form-group full">
              <label for="ho_ten_kh">Họ Và Tên *</label>
              <input type="text" id="ho_ten_kh" name="ho_ten" class="form-input"
                value="<?= esc($editKhData['HoTen']) ?>" placeholder="Nguyễn Văn A" required maxlength="100">
            </div>
            <div class="form-group">
              <label for="sdt_kh">Số Điện Thoại</label>
              <input type="tel" id="sdt_kh" name="sdt" class="form-input"
                value="<?= esc($editKhData['SoDienThoai'] ?? '') ?>" placeholder="0900 000 000" maxlength="20">
            </div>
            <div class="form-group">
              <label for="email_kh">Email</label>
              <input type="email" id="email_kh" name="email" class="form-input"
                value="<?= esc($editKhData['Email'] ?? '') ?>" placeholder="email@example.com" maxlength="100">
            </div>
            <div class="form-group full">
              <label for="cccd_kh">CCCD / CMND</label>
              <input type="text" id="cccd_kh" name="cccd" class="form-input"
                value="<?= esc(decryptCCCD($editKhData['CCCD'] ?? '') ?? '') ?>" placeholder="Để trống nếu không có" maxlength="20">
              <span class="form-hint">⚠️ Số CCCD/CMND là thông tin nhạy cảm — chỉ cập nhật khi cần thiết. Để trống = xóa CCCD.</span>
            </div>
            <div class="form-group full">
              <label for="dia_chi_kh">Địa Chỉ</label>
              <input type="text" id="dia_chi_kh" name="dia_chi" class="form-input"
                value="<?= esc($editKhData['DiaChi'] ?? '') ?>" placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố" maxlength="255">
            </div>
          </div>
          <div class="form-actions" style="margin-top:20px">
            <button type="submit" class="btn-submit">💾 Lưu Thông Tin</button>
            <a href="accounts.php?tab=customers" class="btn-secondary">← Quay Lại</a>
          </div>
        </form>
      </div>

      <!-- Info card bên phải -->
      <div class="form-card" style="max-width:100%">
        <div class="form-section-title">ℹ️ Thông Tin Tài Khoản</div>
        <table style="width:100%;border-collapse:collapse;font-size:.84rem">
          <tr><td style="padding:9px 0;color:var(--muted);width:45%">Mã KH</td>
              <td style="font-weight:700"><?= esc($editKhData['MaKH']) ?></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Tài khoản</td>
              <td><span style="font-weight:700;color:var(--blue)">@<?= esc($editKhData['TenTaiKhoan']) ?></span></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Trạng thái</td>
              <td><?= statusBadge($editKhData['TrangThai'] ?? 'Hoạt động') ?></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Điểm tích lũy</td>
              <td><strong style="color:var(--blue-dark)"><?= number_format((int)$editKhData['TichDiem']) ?></strong> <span style="font-size:.72rem;color:var(--muted)">điểm</span></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Ngày đăng ký</td>
              <td style="font-size:.8rem"><?= fmtDate($editKhData['NgayTao']) ?></td></tr>
        </table>
        <div style="margin-top:16px;background:#fff8f0;border:1px solid #fed7aa;border-radius:8px;padding:12px;font-size:.78rem;color:#92400e">
          <strong>⚠️ Lưu ý:</strong> Tên tài khoản (@<?= esc($editKhData['TenTaiKhoan']) ?>) và mật khẩu không thể chỉnh sửa từ đây. Khách hàng có thể tự thay đổi tại trang hồ sơ của họ.
        </div>
      </div>
    </div>

    <?php /* ════════════════════════════════════════════════════════
           TAB: EDIT STAFF INFO (NV)
           ════════════════════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'edit_nv' && $editNvData): ?>

    <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">

      <!-- Form sửa thông tin NV -->
      <div class="form-card" style="max-width:100%">
        <div class="form-section-title">✏️ Sửa Thông Tin Nhân Viên</div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="edit_nv">
          <input type="hidden" name="ma_nv" value="<?= esc($editNvData['MaNV']) ?>">
          <div class="form-grid">
            <div class="form-group full">
              <label for="ho_ten_nv">Họ Và Tên *</label>
              <input type="text" id="ho_ten_nv" name="ho_ten" class="form-input"
                value="<?= esc($editNvData['HoTen']) ?>" placeholder="Nguyễn Văn A" required maxlength="100">
            </div>
            <div class="form-group">
              <label for="sdt_nv">Số Điện Thoại</label>
              <input type="tel" id="sdt_nv" name="sdt" class="form-input"
                value="<?= esc($editNvData['SoDienThoai'] ?? '') ?>" placeholder="0900 000 000" maxlength="20">
            </div>
            <div class="form-group">
              <label for="email_nv">Email</label>
              <input type="email" id="email_nv" name="email" class="form-input"
                value="<?= esc($editNvData['Email'] ?? '') ?>" placeholder="email@example.com" maxlength="100">
            </div>
            <div class="form-group">
              <label for="chuc_vu_nv">Chức Vụ</label>
              <select name="chuc_vu" id="chuc_vu_nv" class="form-select">
                <option value="Lễ tân"  <?= ($editNvData['ChucVu'] === 'Lễ tân')  ? 'selected' : '' ?>>👤 Lễ Tân</option>
                <option value="Quản lý" <?= ($editNvData['ChucVu'] === 'Quản lý') ? 'selected' : '' ?>>👑 Quản Lý</option>
              </select>
            </div>
            <div class="form-group">
              <label for="ngay_vao_lam_nv">Ngày Vào Làm</label>
              <input type="date" id="ngay_vao_lam_nv" name="ngay_vao_lam" class="form-input"
                value="<?= esc($editNvData['NgayVaoLam'] ?? '') ?>">
            </div>
            <div class="form-group full">
              <label for="trang_thai_nv_sel">Trạng Thái Làm Việc</label>
              <select name="trang_thai_nv" id="trang_thai_nv_sel" class="form-select">
                <option value="Đang làm việc"   <?= ($editNvData['TrangThai'] === 'Đang làm việc')   ? 'selected' : '' ?>>✅ Đang Làm Việc</option>
                <option value="Ngừng hoạt động" <?= ($editNvData['TrangThai'] === 'Ngừng hoạt động') ? 'selected' : '' ?>>⛔ Ngừng Hoạt Động</option>
              </select>
              <span class="form-hint">⚠️ "Ngừng hoạt động" sẽ ẩn nhân viên này khỏi danh sách phân công dọn phòng và gán đặt phòng mới.</span>
            </div>
          </div>
          <div class="form-actions" style="margin-top:20px">
            <button type="submit" class="btn-submit">💾 Lưu Thông Tin</button>
            <a href="accounts.php?tab=staff" class="btn-secondary">← Quay Lại</a>
          </div>
        </form>
      </div>

      <!-- Info card bên phải -->
      <div class="form-card" style="max-width:100%">
        <div class="form-section-title">ℹ️ Thông Tin Nhân Viên</div>
        <?php
          $nvTkQ = $pdo->prepare("SELECT TenTK, VaiTro FROM TAI_KHOAN WHERE MaNV = :id LIMIT 1");
          $nvTkQ->execute([':id' => $editNvData['MaNV']]);
          $nvTkRow = $nvTkQ->fetch();
        ?>
        <table style="width:100%;border-collapse:collapse;font-size:.84rem">
          <tr><td style="padding:9px 0;color:var(--muted);width:42%">Mã NV</td>
              <td style="font-weight:700"><?= esc($editNvData['MaNV']) ?></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Tài khoản</td>
              <td><?= $nvTkRow
                    ? '<span style="font-weight:700;color:var(--blue)">@'.esc($nvTkRow['TenTK']).'</span> '.roleBadge($nvTkRow['VaiTro'])
                    : '<span style="color:#94a3b8;font-style:italic">Chưa có tài khoản</span>' ?></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Chức vụ</td>
              <td><?= esc($editNvData['ChucVu'] ?? '—') ?></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Trạng thái</td>
              <td><?php
                $nvSt = $editNvData['TrangThai'] ?? 'Đang làm việc';
                echo $nvSt === 'Đang làm việc'
                  ? "<span class='badge badge-active'>✓ Đang Làm Việc</span>"
                  : "<span class='badge badge-locked'>⛔ Ngừng HĐ</span>";
              ?></td></tr>
          <tr><td style="padding:9px 0;color:var(--muted)">Ngày vào làm</td>
              <td style="font-size:.8rem"><?= $editNvData['NgayVaoLam']
                ? date('d/m/Y', strtotime($editNvData['NgayVaoLam']))
                : '<span style="color:#94a3b8">—</span>' ?></td></tr>
        </table>
        <?php if (!$nvTkRow): ?>
        <div style="margin-top:16px">
          <a href="accounts.php?tab=add" class="btn-primary-sm" style="width:100%;justify-content:center">➕ Tạo Tài Khoản Cho NV Này</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
// Animate rows on load
function animateRows(selector) {
  const rows = document.querySelectorAll(selector);
  rows.forEach((r, i) => {
    setTimeout(() => {
      r.style.transition = 'opacity .35s ease, transform .35s ease';
      r.style.opacity = '1';
      r.style.transform = 'translateX(0)';
    }, i * 50);
  });
}
document.addEventListener('DOMContentLoaded', () => {
  animateRows('.acc-row');
  animateRows('.kh-row');
});

// Toggle password visibility
function togglePw(el) {
  // el is either an id string or the input element directly
  const inp = (typeof el === 'string') ? document.getElementById(el) : el;
  inp.type = (inp.type === 'password') ? 'text' : 'password';
}

// Validate password confirmation on add form
const addForm = document.querySelector('form input[name="action"][value="add_account"]');
if (addForm) {
  addForm.closest('form').addEventListener('submit', function(e) {
    const pw  = this.querySelector('[name="mat_khau"]').value;
    const pw2 = this.querySelector('[name="mat_khau2"]').value;
    if (pw !== pw2) {
      e.preventDefault();
      alert('⚠️ Xác nhận mật khẩu không khớp!');
    }
  });
}

// Validate password reset form
document.querySelectorAll('form input[name="action"][value="reset_password"]').forEach(inp => {
  inp.closest('form').addEventListener('submit', function(e) {
    const pw  = this.querySelector('[name="mat_khau"]').value;
    const pw2 = this.querySelector('[name="mat_khau2"]').value;
    if (pw !== pw2) {
      e.preventDefault();
      alert('⚠️ Xác nhận mật khẩu không khớp!');
    }
  });
});
</script>
</body>
</html>
