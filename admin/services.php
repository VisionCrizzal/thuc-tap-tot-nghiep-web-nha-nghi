<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'];

$tab     = $_GET['tab'] ?? 'list';
$editId  = $_GET['id']  ?? '';
$msg     = '';
$msgType = 'success';

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Thêm dịch vụ ─────────────────────────────────────────────────────────
    if ($action === 'add_service') {
        $maDV      = strtoupper(trim($_POST['ma_dv']     ?? ''));
        $tenDV     = trim($_POST['ten_dv']    ?? '');
        $giaDV     = (float)($_POST['gia_dv'] ?? 0);
        $moTa      = trim($_POST['mo_ta']     ?? '');
        $hinhAnh   = trim($_POST['hinh_anh'] ?? '');
        $trangThai = trim($_POST['trang_thai'] ?? 'Khả dụng');
        $allowedTT = ['Khả dụng', 'Ngừng'];

        if (!$maDV || !$tenDV || $giaDV <= 0) {
            $msg = "Vui lòng điền đầy đủ Mã dịch vụ, Tên và Giá (> 0).";
            $msgType = 'error'; $tab = 'add';
        } elseif (strlen($maDV) > 10) {
            $msg = "Mã dịch vụ tối đa 10 ký tự.";
            $msgType = 'error'; $tab = 'add';
        } else {
            $chk = $pdo->prepare("SELECT MaDV FROM DICH_VU WHERE MaDV = :id");
            $chk->execute([':id' => $maDV]);
            if ($chk->fetch()) {
                $msg = "Mã dịch vụ '$maDV' đã tồn tại.";
                $msgType = 'error'; $tab = 'add';
            } else {
                $trangThai = in_array($trangThai, $allowedTT) ? $trangThai : 'Khả dụng';
                $pdo->prepare("INSERT INTO DICH_VU (MaDV,TenDV,GiaDV,MoTa,TrangThai,HinhAnh) VALUES (:id,:t,:g,:m,:tt,:h)")
                    ->execute([':id'=>$maDV,':t'=>$tenDV,':g'=>$giaDV,':m'=>$moTa,':tt'=>$trangThai,':h'=>$hinhAnh ?: null]);
                $msg = "Đã thêm dịch vụ «{$tenDV}» ($maDV) thành công."; $tab = 'list';
            }
        }
    }

    // ── Sửa dịch vụ ──────────────────────────────────────────────────────────
    elseif ($action === 'edit_service') {
        $maDV      = trim($_POST['ma_dv']     ?? '');
        $tenDV     = trim($_POST['ten_dv']    ?? '');
        $giaDV     = (float)($_POST['gia_dv'] ?? 0);
        $moTa      = trim($_POST['mo_ta']     ?? '');
        $hinhAnh   = trim($_POST['hinh_anh'] ?? '');
        $trangThai = trim($_POST['trang_thai'] ?? 'Khả dụng');
        $allowedTT = ['Khả dụng', 'Ngừng'];

        if (!$maDV || !$tenDV || $giaDV <= 0) {
            $msg = "Vui lòng điền đầy đủ thông tin.";
            $msgType = 'error'; $tab = 'edit'; $editId = $maDV;
        } else {
            $trangThai = in_array($trangThai, $allowedTT) ? $trangThai : 'Khả dụng';
            $pdo->prepare("UPDATE DICH_VU SET TenDV=:t,GiaDV=:g,MoTa=:m,TrangThai=:tt,HinhAnh=:h WHERE MaDV=:id")
                ->execute([':t'=>$tenDV,':g'=>$giaDV,':m'=>$moTa,':tt'=>$trangThai,':h'=>$hinhAnh ?: null,':id'=>$maDV]);
            $msg = "Đã cập nhật dịch vụ «{$tenDV}»."; $tab = 'list';
        }
    }

    // ── Đổi trạng thái nhanh ─────────────────────────────────────────────────
    elseif ($action === 'toggle_status') {
        $maDV      = trim($_POST['ma_dv']      ?? '');
        $newStatus = trim($_POST['new_status'] ?? '');
        $allowed   = ['Khả dụng', 'Ngừng'];
        if ($maDV && in_array($newStatus, $allowed)) {
            $pdo->prepare("UPDATE DICH_VU SET TrangThai = :st WHERE MaDV = :id")
                ->execute([':st' => $newStatus, ':id' => $maDV]);
            $msg = "Dịch vụ $maDV → $newStatus."; $tab = 'list';
        } else {
            $msg = "Trạng thái không hợp lệ."; $msgType = 'error'; $tab = 'list';
        }
    }

    // ── Xóa dịch vụ ──────────────────────────────────────────────────────────
    elseif ($action === 'delete_service') {
        $maDV = trim($_POST['ma_dv'] ?? '');
        if ($maDV) {
            try {
                $cntQ = $pdo->prepare("SELECT COUNT(*) FROM DAT_DICH_VU WHERE MaDV = :id");
                $cntQ->execute([':id' => $maDV]);
                $cnt = (int)$cntQ->fetchColumn();
                if ($cnt > 0) {
                    // Soft delete — có lịch sử đặt, không được xóa cứng
                    $pdo->prepare("UPDATE DICH_VU SET TrangThai = 'Ngừng' WHERE MaDV = :id")
                        ->execute([':id' => $maDV]);
                    $msg = "Dịch vụ $maDV đang có $cnt lượt sử dụng — đã chuyển sang «Ngừng» thay vì xóa để giữ lịch sử.";
                    $msgType = 'error';
                } else {
                    $pdo->prepare("DELETE FROM DICH_VU WHERE MaDV = :id")->execute([':id' => $maDV]);
                    $msg = "Đã xóa dịch vụ $maDV.";
                }
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage(); $msgType = 'error';
            }
        }
        $tab = 'list';
    }

    if ($msgType === 'success' && $msg) {
        header("Location: services.php?tab={$tab}&msg=" . urlencode($msg));
        exit;
    }
}

if (!$msg && isset($_GET['msg'])) {
    $msg = urldecode($_GET['msg']); $msgType = 'success';
}

// ── Queries ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['filter'] ?? 'all';
$allowedFlt   = ['Khả dụng', 'Ngừng'];

$where = []; $params = [];
if ($filterStatus !== 'all' && in_array($filterStatus, $allowedFlt)) {
    $where[] = "dv.TrangThai = :st"; $params[':st'] = $filterStatus;
}
$whereSQL = $where ? " WHERE " . implode(" AND ", $where) : "";
$sql = "SELECT dv.*, COUNT(ddv.MaDDV) AS LuotDung,
               COALESCE(SUM(ddv.ThanhTien), 0) AS DoanhThuDV
        FROM DICH_VU dv
        LEFT JOIN DAT_DICH_VU ddv ON dv.MaDV = ddv.MaDV
        $whereSQL
        GROUP BY dv.MaDV
        ORDER BY dv.MaDV";
$svcStmt = $pdo->prepare($sql); $svcStmt->execute($params);
$services = $svcStmt->fetchAll();

$stats = [
    'total'    => (int)$pdo->query("SELECT COUNT(*) FROM DICH_VU")->fetchColumn(),
    'khaDung'  => (int)$pdo->query("SELECT COUNT(*) FROM DICH_VU WHERE TrangThai='Khả dụng'")->fetchColumn(),
    'ngung'    => (int)$pdo->query("SELECT COUNT(*) FROM DICH_VU WHERE TrangThai='Ngừng'")->fetchColumn(),
    'luotDung' => (int)$pdo->query("SELECT COUNT(*) FROM DAT_DICH_VU")->fetchColumn(),
    'doanhThu' => (float)$pdo->query("SELECT COALESCE(SUM(ThanhTien),0) FROM DAT_DICH_VU")->fetchColumn(),
];

// Auto-suggest mã DV tiếp theo
$maxNum   = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaDV,3) AS UNSIGNED)) FROM DICH_VU WHERE MaDV REGEXP '^DV[0-9]+'") ->fetchColumn();
$nextMaDV = 'DV' . str_pad(((int)$maxNum + 1), 3, '0', STR_PAD_LEFT);

$editSvc = null;
if ($tab === 'edit' && $editId) {
    $es = $pdo->prepare("SELECT * FROM DICH_VU WHERE MaDV = :id");
    $es->execute([':id' => $editId]);
    $editSvc = $es->fetch();
    if (!$editSvc) { $tab = 'list'; $editId = ''; }
}

$adminProfile = $pdo->prepare("SELECT nv.SoDienThoai, nv.MaNV FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV WHERE tk.TenTK=:tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($n)  { return number_format((float)$n, 0, ',', '.'); }
function esc($s)  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getServiceIcon(string $tenDV, ?string $hinhAnh): string {
    if ($hinhAnh) {
        // URL ảnh → <img>
        if (str_starts_with($hinhAnh, 'http') || str_starts_with($hinhAnh, '/')) {
            return '<img src="' . esc($hinhAnh) . '" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:8px;display:block">';
        }
        // Emoji / text ngắn
        return '<span style="font-size:1.5rem;line-height:1">' . esc($hinhAnh) . '</span>';
    }
    // Auto icon theo tên
    $t = mb_strtolower($tenDV);
    if (str_contains($t,'spa') || str_contains($t,'massage'))   return '<span style="font-size:1.5rem">💆</span>';
    if (str_contains($t,'giặt') || str_contains($t,'ủi'))       return '<span style="font-size:1.5rem">👕</span>';
    if (str_contains($t,'sân bay') || str_contains($t,'đưa đón')) return '<span style="font-size:1.5rem">🚗</span>';
    if (str_contains($t,'ăn') || str_contains($t,'sáng') || str_contains($t,'bữa')) return '<span style="font-size:1.5rem">🍳</span>';
    if (str_contains($t,'xe đạp') || str_contains($t,'bike'))   return '<span style="font-size:1.5rem">🚲</span>';
    if (str_contains($t,'bơi') || str_contains($t,'hồ bơi'))    return '<span style="font-size:1.5rem">🏊</span>';
    if (str_contains($t,'bar') || str_contains($t,'rượu') || str_contains($t,'đồ uống')) return '<span style="font-size:1.5rem">🍸</span>';
    if (str_contains($t,'gym') || str_contains($t,'thể dục'))   return '<span style="font-size:1.5rem">💪</span>';
    if (str_contains($t,'phòng họp') || str_contains($t,'hội nghị')) return '<span style="font-size:1.5rem">🏛️</span>';
    if (str_contains($t,'tour') || str_contains($t,'tham quan')) return '<span style="font-size:1.5rem">🗺️</span>';
    return '<span style="font-size:1.5rem">🛎️</span>';
}

function statusBadge(string $s): string {
    if ($s === 'Khả dụng')
        return "<span class='badge-ok'>✅ Khả dụng</span>";
    return "<span class='badge-off'>⛔ Ngừng</span>";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quản Lý Dịch Vụ — Easyhome</title>
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
.topbar-meta{font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:14px}
.content{padding:24px 28px;flex:1}

/* ── ALERT ── */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:.85rem;display:flex;align-items:center;gap:10px;border-left:4px solid}
.alert-success{background:#ecfdf5;color:#065f46;border-color:#10b981}
.alert-error{background:#fff7ed;color:#92400e;border-color:#f59e0b;animation:shake .35s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}

/* ── STATS ── */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#fff;border-radius:var(--radius);padding:16px 18px;border:1.5px solid var(--border);
  box-shadow:var(--shadow);display:flex;flex-direction:column;gap:6px;transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.stat-value{font-size:1.7rem;font-weight:700;color:var(--blue-dark);line-height:1}
.stat-label{font-size:.68rem;color:var(--muted);letter-spacing:.5px;text-transform:uppercase}

/* ── PAGE TABS ── */
.page-tabs{display:flex;gap:4px;margin-bottom:18px;background:#fff;padding:6px;border-radius:10px;
  border:1.5px solid var(--border);width:fit-content;box-shadow:var(--shadow)}
.ptab{padding:7px 16px;border-radius:7px;font-size:.8rem;font-weight:700;text-decoration:none;
  color:var(--muted);transition:all .2s;letter-spacing:.3px}
.ptab.active{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(29,78,216,.25)}
.ptab:hover:not(.active){background:var(--blue-pale);color:var(--blue)}

/* ── TABLE ── */
.tbl-wrap{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow)}
.tbl-top{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;
  border-bottom:1px solid var(--border);gap:10px;flex-wrap:wrap}
.tbl-top-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.section-title{font-family:var(--serif);font-size:1rem;color:var(--blue-dark)}
.filter-btns{display:flex;gap:4px}
.flt-btn{padding:5px 12px;border-radius:20px;border:1.5px solid var(--border);background:#fff;
  font-family:var(--font);font-size:.75rem;font-weight:700;color:var(--muted);cursor:pointer;
  text-decoration:none;transition:all .2s;white-space:nowrap}
.flt-btn.active,.flt-btn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
table{width:100%;border-collapse:collapse}
thead th{background:var(--blue-dark);color:rgba(255,255,255,.85);padding:10px 14px;
  font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;font-weight:700;text-align:left}
thead th:first-child{border-radius:0;width:46px;text-align:center}
tbody tr{border-bottom:1px solid var(--blue-pale);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--blue-pale)}
td{padding:11px 14px;font-size:.85rem;vertical-align:middle}
td:first-child{text-align:center;color:var(--muted);font-size:.78rem;width:46px}

/* ── ICON CELL ── */
.svc-icon-cell{width:52px;text-align:center}
.svc-icon-wrap{width:42px;height:42px;border-radius:10px;background:var(--blue-pale);
  display:flex;align-items:center;justify-content:center;border:1px solid var(--border);overflow:hidden}

/* ── BADGES ── */
.badge-ok{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#ecfdf5;color:#065f46;border:1px solid #10b98135}
.badge-off{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#fff0f0;color:#b91c1c;border:1px solid #ef444435}

/* ── ACTION BUTTONS ── */
.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.btn-edit{padding:5px 12px;border-radius:7px;font-size:.75rem;font-weight:700;text-decoration:none;
  background:var(--blue-pale);color:var(--blue);border:1.5px solid var(--blue-mid);
  transition:all .2s;cursor:pointer;font-family:var(--font)}
.btn-edit:hover{background:var(--blue);color:#fff}
.btn-on{padding:5px 12px;border-radius:7px;font-size:.75rem;font-weight:700;
  background:#ecfdf5;color:#059669;border:1.5px solid #10b98130;
  cursor:pointer;font-family:var(--font);transition:all .2s}
.btn-on:hover{background:#059669;color:#fff}
.btn-off{padding:5px 12px;border-radius:7px;font-size:.75rem;font-weight:700;
  background:#fffbeb;color:#d97706;border:1.5px solid #f59e0b30;
  cursor:pointer;font-family:var(--font);transition:all .2s}
.btn-off:hover{background:#d97706;color:#fff}
.btn-del{padding:5px 12px;border-radius:7px;font-size:.75rem;font-weight:700;
  background:#fff0f0;color:#dc2626;border:1.5px solid #ef444430;
  cursor:pointer;font-family:var(--font);transition:all .2s}
.btn-del:hover{background:#dc2626;color:#fff}

/* ── FORM ── */
.form-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  padding:28px 32px;box-shadow:var(--shadow);max-width:680px}
.form-card h3{font-family:var(--serif);font-size:1.1rem;color:var(--blue-dark);margin-bottom:22px;
  padding-bottom:12px;border-bottom:2px solid var(--blue-mid)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:.76rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.form-ctrl{padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;
  font-family:var(--font);font-size:.9rem;color:var(--text);background:#fff;
  transition:border-color .2s,box-shadow .2s;width:100%}
.form-ctrl:focus{outline:none;border-color:var(--blue-light);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.form-ctrl[readonly]{background:var(--blue-pale);color:var(--muted)}
.form-hint{font-size:.72rem;color:var(--muted);margin-top:2px}
.form-actions{display:flex;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid var(--border)}
.btn-primary{padding:10px 24px;background:var(--blue);color:#fff;border:none;border-radius:8px;
  font-family:var(--font);font-size:.88rem;font-weight:700;cursor:pointer;transition:all .2s}
.btn-primary:hover{background:var(--blue-dark);transform:translateY(-1px);box-shadow:0 4px 12px rgba(29,78,216,.3)}
.btn-secondary{padding:10px 20px;background:var(--blue-pale);color:var(--blue);border:1.5px solid var(--blue-mid);
  border-radius:8px;font-family:var(--font);font-size:.88rem;font-weight:700;text-decoration:none;
  cursor:pointer;transition:all .2s;display:inline-flex;align-items:center}
.btn-secondary:hover{background:var(--blue-mid)}

/* ── IMAGE PREVIEW ── */
.img-preview{margin-top:8px;display:none}
.img-preview img{width:64px;height:64px;object-fit:cover;border-radius:10px;border:1.5px solid var(--border)}
.img-preview .emoji-big{font-size:2.5rem;line-height:1;display:block}

/* ── EMOJI PICKER ── */
.emoji-grid{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.emoji-chip{width:36px;height:36px;border-radius:8px;border:1.5px solid var(--border);background:#fff;
  font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:all .15s}
.emoji-chip:hover,.emoji-chip.sel{background:var(--blue-pale);border-color:var(--blue-light)}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-state .empty-icon{font-size:3.5rem;margin-bottom:14px;opacity:.5}
.empty-state p{font-size:.9rem}

/* ── USAGE PILL ── */
.usage-pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:var(--blue-mid);color:var(--blue-dark)}

/* ── PRICE TEXT ── */
.price-text{font-weight:700;color:var(--blue-dark)}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .stats-grid{grid-template-columns:repeat(3,1fr)}
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .form-grid{grid-template-columns:1fr}
}
@media(max-width:600px){
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .content{padding:16px}
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
      <?php if ($adminProfile): ?>
        <div class="sb-admin-row">👤 <?= esc($adminProfile['MaNV'] ?? $adminId) ?></div>
      <?php endif; ?>
      <div class="sb-admin-badge">👑 Quản Lý</div>
    </div>
  </div>

  <nav class="sb-nav">
    <a href="dashboard.php?tab=overview"    class="sb-nav-item"><span class="sb-nav-icon">📊</span><span>Tổng Quan</span></a>
    <a href="dashboard.php?tab=bookings"    class="sb-nav-item"><span class="sb-nav-icon">📋</span><span>Quản Lý Đặt Phòng</span></a>
    <a href="dashboard.php?tab=new-booking" class="sb-nav-item"><span class="sb-nav-icon">➕</span><span>Đặt Phòng Mới</span></a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php"                     class="sb-nav-item"><span class="sb-nav-icon">🛏️</span><span>Quản Lý Phòng</span></a>
    <a href="housekeeping.php"              class="sb-nav-item"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                  class="sb-nav-item active"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                class="sb-nav-item"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"       class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                  class="sb-nav-item"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
    <a href="dashboard.php?tab=report"      class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <div class="topbar">
    <div class="topbar-title">🛎️ Quản Lý Dịch Vụ</div>
    <div class="topbar-meta">
      <span>Tổng: <strong><?= $stats['total'] ?></strong> dịch vụ</span>
      <span>Khả dụng: <strong style="color:#059669"><?= $stats['khaDung'] ?></strong></span>
      <span>Lượt dùng: <strong style="color:#1d4ed8"><?= fmt($stats['luotDung']) ?></strong></span>
    </div>
  </div>

  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>">
      <?= $msgType === 'success' ? '✅' : '⚠️' ?> <?= esc($msg) ?>
    </div>
    <?php endif; ?>

    <!-- Inner tabs -->
    <div class="page-tabs">
      <a href="services.php?tab=list"
         class="ptab <?= $tab==='list'?'active':'' ?>">📋 Danh Sách</a>
      <a href="services.php?tab=add"
         class="ptab <?= $tab==='add'?'active':'' ?>">➕ Thêm Dịch Vụ</a>
      <?php if ($tab === 'edit' && $editSvc): ?>
      <a href="services.php?tab=edit&id=<?= esc($editId) ?>"
         class="ptab active">✏️ Sửa: <?= esc($editId) ?></a>
      <?php endif; ?>
    </div>

    <!-- ══ STAT CARDS ══ -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">🛎️</div>
        <div class="stat-value"><?= $stats['total'] ?></div>
        <div class="stat-label">Tổng Dịch Vụ</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">✅</div>
        <div class="stat-value" style="color:#059669"><?= $stats['khaDung'] ?></div>
        <div class="stat-label">Khả Dụng</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff0f0">⛔</div>
        <div class="stat-value" style="color:#dc2626"><?= $stats['ngung'] ?></div>
        <div class="stat-label">Ngừng Hoạt Động</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">📊</div>
        <div class="stat-value" style="color:#1d4ed8"><?= fmt($stats['luotDung']) ?></div>
        <div class="stat-label">Tổng Lượt Dùng</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4">💰</div>
        <div class="stat-value" style="color:#059669;font-size:1.2rem"><?= fmt($stats['doanhThu']) ?>đ</div>
        <div class="stat-label">Doanh Thu Dịch Vụ</div>
      </div>
    </div>

    <?php /* ══════════════════ LIST TAB ══════════════════ */ ?>
    <?php if ($tab === 'list'): ?>

    <div class="tbl-wrap">
      <div class="tbl-top">
        <div class="tbl-top-left">
          <span class="section-title">Danh Sách Dịch Vụ</span>
          <div class="filter-btns">
            <a href="services.php?tab=list&filter=all"
               class="flt-btn <?= $filterStatus==='all'?'active':'' ?>">Tất cả (<?= $stats['total'] ?>)</a>
            <a href="services.php?tab=list&filter=Khả+dụng"
               class="flt-btn <?= $filterStatus==='Khả dụng'?'active':'' ?>">✅ Khả dụng (<?= $stats['khaDung'] ?>)</a>
            <a href="services.php?tab=list&filter=Ngừng"
               class="flt-btn <?= $filterStatus==='Ngừng'?'active':'' ?>">⛔ Ngừng (<?= $stats['ngung'] ?>)</a>
          </div>
        </div>
        <a href="services.php?tab=add" class="btn-primary" style="font-size:.8rem;padding:7px 16px;text-decoration:none">➕ Thêm Dịch Vụ</a>
      </div>

      <?php if ($services): ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th style="width:52px">Ảnh</th>
            <th>Mã DV</th>
            <th>Tên Dịch Vụ</th>
            <th>Giá / Lần</th>
            <th>Mô Tả</th>
            <th>Trạng Thái</th>
            <th style="text-align:center">Lượt Dùng</th>
            <th>Thao Tác</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($services as $i => $s): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td class="svc-icon-cell">
              <div class="svc-icon-wrap">
                <?= getServiceIcon($s['TenDV'], $s['HinhAnh']) ?>
              </div>
            </td>
            <td><strong style="font-size:.8rem;color:var(--muted);letter-spacing:.5px"><?= esc($s['MaDV']) ?></strong></td>
            <td><strong><?= esc($s['TenDV']) ?></strong></td>
            <td class="price-text"><?= fmt($s['GiaDV']) ?>đ</td>
            <td style="color:var(--muted);font-size:.82rem;max-width:200px">
              <?= $s['MoTa'] ? esc(mb_strimwidth($s['MoTa'], 0, 60, '…')) : '<span style="opacity:.4">—</span>' ?>
            </td>
            <td><?= statusBadge($s['TrangThai']) ?></td>
            <td style="text-align:center">
              <?php if ((int)$s['LuotDung'] > 0): ?>
                <span class="usage-pill"><?= $s['LuotDung'] ?> lần</span>
              <?php else: ?>
                <span style="color:var(--muted);font-size:.78rem">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <!-- Sửa -->
                <a href="services.php?tab=edit&id=<?= esc($s['MaDV']) ?>" class="btn-edit">✏️ Sửa</a>

                <!-- Toggle trạng thái -->
                <?php if ($s['TrangThai'] === 'Khả dụng'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Tạm ngừng dịch vụ «<?= esc($s['TenDV']) ?>»?')">
                  <input type="hidden" name="action"     value="toggle_status">
                  <input type="hidden" name="ma_dv"      value="<?= esc($s['MaDV']) ?>">
                  <input type="hidden" name="new_status" value="Ngừng">
                  <button type="submit" class="btn-off">⛔ Ngừng</button>
                </form>
                <?php else: ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action"     value="toggle_status">
                  <input type="hidden" name="ma_dv"      value="<?= esc($s['MaDV']) ?>">
                  <input type="hidden" name="new_status" value="Khả dụng">
                  <button type="submit" class="btn-on">✅ Kích hoạt</button>
                </form>
                <?php endif; ?>

                <!-- Xóa -->
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Xóa dịch vụ «<?= esc($s['TenDV']) ?>»?\n<?= (int)$s['LuotDung'] > 0 ? "Đã có " . $s['LuotDung'] . " lượt sử dụng — sẽ chuyển sang Ngừng." : "Sẽ bị xóa vĩnh viễn." ?>')">
                  <input type="hidden" name="action" value="delete_service">
                  <input type="hidden" name="ma_dv"  value="<?= esc($s['MaDV']) ?>">
                  <button type="submit" class="btn-del">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">🛎️</div>
        <p><?= $filterStatus !== 'all' ? "Không có dịch vụ nào với trạng thái <strong>$filterStatus</strong>." : "Chưa có dịch vụ nào." ?></p>
        <a href="services.php?tab=add" style="display:inline-block;margin-top:14px;padding:8px 20px;background:var(--blue);color:#fff;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:700">➕ Thêm Dịch Vụ Đầu Tiên</a>
      </div>
      <?php endif; ?>
    </div><!-- /.tbl-wrap -->

    <?php /* ══════════════════ ADD TAB ══════════════════ */ ?>
    <?php elseif ($tab === 'add'): ?>

    <div class="form-card">
      <h3>➕ Thêm Dịch Vụ Mới</h3>
      <form method="post" id="addForm">
        <input type="hidden" name="action" value="add_service">
        <div class="form-grid">

          <div class="form-group">
            <label class="form-label">Mã dịch vụ *</label>
            <input type="text" name="ma_dv" class="form-ctrl"
                   value="<?= esc($_POST['ma_dv'] ?? $nextMaDV) ?>"
                   placeholder="VD: DV006" maxlength="10"
                   style="text-transform:uppercase" required>
            <span class="form-hint">Gợi ý tiếp theo: <strong><?= esc($nextMaDV) ?></strong></span>
          </div>

          <div class="form-group">
            <label class="form-label">Trạng thái</label>
            <select name="trang_thai" class="form-ctrl">
              <option value="Khả dụng" <?= ($_POST['trang_thai'] ?? 'Khả dụng') === 'Khả dụng' ? 'selected' : '' ?>>✅ Khả dụng</option>
              <option value="Ngừng"    <?= ($_POST['trang_thai'] ?? '') === 'Ngừng' ? 'selected' : '' ?>>⛔ Ngừng hoạt động</option>
            </select>
          </div>

          <div class="form-group full">
            <label class="form-label">Tên dịch vụ *</label>
            <input type="text" name="ten_dv" class="form-ctrl"
                   value="<?= esc($_POST['ten_dv'] ?? '') ?>"
                   placeholder="VD: Spa & Massage toàn thân" maxlength="100" required>
          </div>

          <div class="form-group">
            <label class="form-label">Giá (đồng/lần) *</label>
            <input type="number" name="gia_dv" class="form-ctrl"
                   value="<?= esc($_POST['gia_dv'] ?? '') ?>"
                   placeholder="350000" min="0" step="1000" required>
          </div>

          <div class="form-group">
            <label class="form-label">Hình ảnh / Icon</label>
            <input type="text" name="hinh_anh" id="addImgInput" class="form-ctrl"
                   value="<?= esc($_POST['hinh_anh'] ?? '') ?>"
                   placeholder="URL ảnh hoặc emoji: 💆"
                   oninput="previewImg('addImgInput','addImgPreview')">
            <span class="form-hint">Nhập URL (https://...) hoặc 1 emoji</span>
            <div class="img-preview" id="addImgPreview"></div>
          </div>

          <div class="form-group full">
            <label class="form-label">Mô tả</label>
            <textarea name="mo_ta" class="form-ctrl" rows="3"
                      placeholder="Mô tả ngắn về dịch vụ, điều kiện sử dụng…" maxlength="255"><?= esc($_POST['mo_ta'] ?? '') ?></textarea>
          </div>

          <!-- Emoji shortcuts -->
          <div class="form-group full">
            <label class="form-label">Chọn nhanh icon phổ biến</label>
            <div class="emoji-grid" id="emojiGrid">
              <?php foreach(['💆','👕','🚗','🍳','🚲','🏊','🍸','💪','🛁','🎮','🏋️','🏌️','🗺️','🌿','🧺','📦'] as $em): ?>
              <button type="button" class="emoji-chip" onclick="pickEmoji('addImgInput','addImgPreview','<?= $em ?>')" title="<?= $em ?>"><?= $em ?></button>
              <?php endforeach; ?>
            </div>
          </div>

        </div><!-- /.form-grid -->
        <div class="form-actions">
          <button type="submit" class="btn-primary">➕ Thêm Dịch Vụ</button>
          <a href="services.php?tab=list" class="btn-secondary">← Hủy bỏ</a>
        </div>
      </form>
    </div>

    <?php /* ══════════════════ EDIT TAB ══════════════════ */ ?>
    <?php elseif ($tab === 'edit' && $editSvc): ?>

    <div class="form-card">
      <h3>✏️ Chỉnh Sửa Dịch Vụ</h3>
      <form method="post" id="editForm">
        <input type="hidden" name="action" value="edit_service">
        <input type="hidden" name="ma_dv"  value="<?= esc($editSvc['MaDV']) ?>">
        <div class="form-grid">

          <div class="form-group">
            <label class="form-label">Mã dịch vụ</label>
            <input type="text" class="form-ctrl" value="<?= esc($editSvc['MaDV']) ?>" readonly>
            <span class="form-hint">Không thể thay đổi mã dịch vụ</span>
          </div>

          <div class="form-group">
            <label class="form-label">Trạng thái</label>
            <select name="trang_thai" class="form-ctrl">
              <option value="Khả dụng" <?= $editSvc['TrangThai'] === 'Khả dụng' ? 'selected' : '' ?>>✅ Khả dụng</option>
              <option value="Ngừng"    <?= $editSvc['TrangThai'] === 'Ngừng'    ? 'selected' : '' ?>>⛔ Ngừng hoạt động</option>
            </select>
          </div>

          <div class="form-group full">
            <label class="form-label">Tên dịch vụ *</label>
            <input type="text" name="ten_dv" class="form-ctrl"
                   value="<?= esc($editSvc['TenDV']) ?>"
                   maxlength="100" required>
          </div>

          <div class="form-group">
            <label class="form-label">Giá (đồng/lần) *</label>
            <input type="number" name="gia_dv" class="form-ctrl"
                   value="<?= esc($editSvc['GiaDV']) ?>"
                   min="0" step="1000" required>
          </div>

          <div class="form-group">
            <label class="form-label">Hình ảnh / Icon</label>
            <input type="text" name="hinh_anh" id="editImgInput" class="form-ctrl"
                   value="<?= esc($editSvc['HinhAnh'] ?? '') ?>"
                   placeholder="URL ảnh hoặc emoji: 💆"
                   oninput="previewImg('editImgInput','editImgPreview')">
            <span class="form-hint">Nhập URL (https://...) hoặc 1 emoji</span>
            <div class="img-preview" id="editImgPreview"></div>
          </div>

          <div class="form-group full">
            <label class="form-label">Mô tả</label>
            <textarea name="mo_ta" class="form-ctrl" rows="3" maxlength="255"><?= esc($editSvc['MoTa'] ?? '') ?></textarea>
          </div>

          <!-- Emoji shortcuts -->
          <div class="form-group full">
            <label class="form-label">Chọn nhanh icon phổ biến</label>
            <div class="emoji-grid">
              <?php foreach(['💆','👕','🚗','🍳','🚲','🏊','🍸','💪','🛁','🎮','🏋️','🏌️','🗺️','🌿','🧺','📦'] as $em): ?>
              <button type="button" class="emoji-chip" onclick="pickEmoji('editImgInput','editImgPreview','<?= $em ?>')" title="<?= $em ?>"><?= $em ?></button>
              <?php endforeach; ?>
            </div>
          </div>

        </div><!-- /.form-grid -->

        <!-- Thống kê nhanh -->
        <?php
          $usageQ = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(ThanhTien),0) AS tong FROM DAT_DICH_VU WHERE MaDV = :id");
          $usageQ->execute([':id' => $editSvc['MaDV']]);
          $usage = $usageQ->fetch();
        ?>
        <?php if ((int)$usage['cnt'] > 0): ?>
        <div style="background:var(--blue-pale);border:1.5px solid var(--border);border-radius:8px;padding:12px 16px;margin-top:4px;font-size:.83rem;color:var(--blue-dark)">
          📊 Dịch vụ này đã được sử dụng <strong><?= $usage['cnt'] ?> lần</strong>,
          doanh thu: <strong><?= fmt($usage['tong']) ?>đ</strong>.
          <?php if ((int)$usage['cnt'] > 0): ?>
          <span style="color:#d97706">⚠️ Không thể xóa — chỉ có thể Ngừng hoạt động.</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="form-actions">
          <button type="submit" class="btn-primary">💾 Lưu Thay Đổi</button>
          <a href="services.php?tab=list" class="btn-secondary">← Hủy bỏ</a>
          <?php if ((int)$usage['cnt'] === 0): ?>
          <form method="post" style="display:inline;margin-left:auto"
                onsubmit="return confirm('Xóa vĩnh viễn dịch vụ «<?= esc($editSvc['TenDV']) ?>»?')">
            <input type="hidden" name="action" value="delete_service">
            <input type="hidden" name="ma_dv"  value="<?= esc($editSvc['MaDV']) ?>">
            <button type="submit" class="btn-del" style="padding:10px 18px">🗑️ Xóa Dịch Vụ</button>
          </form>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php endif; ?>

  </div><!-- /.content -->
</div><!-- /.main -->

<script>
// ── Image preview ───────────────────────────────────────────────────────────
function previewImg(inputId, previewId) {
  const val     = document.getElementById(inputId).value.trim();
  const preview = document.getElementById(previewId);
  if (!val) { preview.style.display = 'none'; preview.innerHTML = ''; return; }

  preview.style.display = 'block';
  if (val.startsWith('http') || val.startsWith('/')) {
    preview.innerHTML = `<img src="${val}" alt="Preview"
      onerror="this.parentElement.innerHTML='<span style=color:#dc2626;font-size:.8rem>❌ Không tải được ảnh</span>'">`;
  } else {
    // Emoji hoặc text ngắn
    preview.innerHTML = `<span class="emoji-big">${val}</span>`;
  }
}

// ── Emoji picker ─────────────────────────────────────────────────────────────
function pickEmoji(inputId, previewId, emoji) {
  const input = document.getElementById(inputId);
  input.value = emoji;
  // Highlight selected chip
  document.querySelectorAll('.emoji-chip').forEach(c => {
    c.classList.toggle('sel', c.textContent === emoji);
  });
  previewImg(inputId, previewId);
}

// ── Init previews on page load ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('addImgInput'))  previewImg('addImgInput',  'addImgPreview');
  if (document.getElementById('editImgInput')) previewImg('editImgInput', 'editImgPreview');

  // Highlight current emoji in edit form
  const editInput = document.getElementById('editImgInput');
  if (editInput && editInput.value) {
    document.querySelectorAll('.emoji-chip').forEach(c => {
      if (c.textContent === editInput.value.trim()) c.classList.add('sel');
    });
  }
});

// ── MaDV auto-uppercase ────────────────────────────────────────────────────────
const maInput = document.querySelector('input[name="ma_dv"]:not([readonly])');
if (maInput) maInput.addEventListener('input', () => {
  const pos = maInput.selectionStart;
  maInput.value = maInput.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
  maInput.setSelectionRange(pos, pos);
});

// ── Table row highlight on hover (already handled by CSS) ──────────────────────
// Animate rows in
document.querySelectorAll('tbody tr').forEach((row, i) => {
  row.style.cssText += `opacity:0;transform:translateX(-8px);
    transition:opacity .3s ease ${i*.04}s,transform .3s ease ${i*.04}s`;
  setTimeout(() => {
    row.style.opacity = '1';
    row.style.transform = 'translateX(0)';
  }, 60 + i * 40);
});
</script>
</body>
</html>
