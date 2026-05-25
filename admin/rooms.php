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

    // ---- Thêm phòng ----
    if ($action === 'add_room') {
        $maPhong   = strtoupper(trim($_POST['ma_phong']   ?? ''));
        $loai      = trim($_POST['loai_phong'] ?? '');
        $gia       = (float)($_POST['gia_phong']  ?? 0);
        $soNguoi   = max(1, (int)($_POST['so_nguoi']  ?? 1));
        $tang      = max(1, (int)($_POST['tang']       ?? 1));
        $tinhTrang = trim($_POST['tinh_trang']  ?? 'Trống');
        $moTa      = trim($_POST['mo_ta']       ?? '');

        $allowedLoai = ['Đơn','Đôi','Gia đình','VIP'];
        $allowedSt   = ['Trống','Đang dọn','Bảo trì'];

        if (!$maPhong || !$loai || $gia <= 0) {
            $msg = "Vui lòng điền đầy đủ Mã phòng, Loại phòng và Giá phòng.";
            $msgType = 'error'; $tab = 'add';
        } elseif (!in_array($loai, $allowedLoai)) {
            $msg = "Loại phòng không hợp lệ.";
            $msgType = 'error'; $tab = 'add';
        } else {
            $chk = $pdo->prepare("SELECT MaPhong FROM PHONG WHERE MaPhong = :id");
            $chk->execute([':id' => $maPhong]);
            if ($chk->fetch()) {
                $msg = "Mã phòng '$maPhong' đã tồn tại.";
                $msgType = 'error'; $tab = 'add';
            } else {
                $tinhTrang = in_array($tinhTrang, $allowedSt) ? $tinhTrang : 'Trống';
                $pdo->prepare("INSERT INTO PHONG (MaPhong,LoaiPhong,GiaPhong,SoNguoiToiDa,TinhTrang,MoTa,Tang) VALUES (:id,:l,:g,:sn,:tt,:mo,:tang)")
                    ->execute([':id'=>$maPhong,':l'=>$loai,':g'=>$gia,':sn'=>$soNguoi,':tt'=>$tinhTrang,':mo'=>$moTa,':tang'=>$tang]);
                $msg = "Đã thêm phòng $maPhong thành công."; $tab = 'list';
            }
        }
    }

    // ---- Sửa phòng ----
    elseif ($action === 'edit_room') {
        $maPhong   = trim($_POST['ma_phong']   ?? '');
        $loai      = trim($_POST['loai_phong'] ?? '');
        $gia       = (float)($_POST['gia_phong']  ?? 0);
        $soNguoi   = max(1, (int)($_POST['so_nguoi']  ?? 1));
        $tang      = max(1, (int)($_POST['tang']       ?? 1));
        $tinhTrang = trim($_POST['tinh_trang']  ?? '');
        $moTa      = trim($_POST['mo_ta']       ?? '');

        $allowedLoai = ['Đơn','Đôi','Gia đình','VIP'];
        $allowedSt   = ['Trống','Đang ở','Đang dọn','Bảo trì'];

        if (!$maPhong || !$loai || $gia <= 0) {
            $msg = "Vui lòng điền đầy đủ thông tin.";
            $msgType = 'error'; $tab = 'edit'; $editId = $maPhong;
        } else {
            $loai      = in_array($loai,      $allowedLoai) ? $loai      : 'Đơn';
            $tinhTrang = in_array($tinhTrang, $allowedSt)   ? $tinhTrang : 'Trống';
            $pdo->prepare("UPDATE PHONG SET LoaiPhong=:l,GiaPhong=:g,SoNguoiToiDa=:sn,TinhTrang=:tt,MoTa=:mo,Tang=:tang WHERE MaPhong=:id")
                ->execute([':l'=>$loai,':g'=>$gia,':sn'=>$soNguoi,':tt'=>$tinhTrang,':mo'=>$moTa,':tang'=>$tang,':id'=>$maPhong]);
            $msg = "Đã cập nhật phòng $maPhong."; $tab = 'list';
        }
    }

    // ---- Đổi trạng thái nhanh ----
    elseif ($action === 'change_status') {
        $maPhong   = trim($_POST['ma_phong']   ?? '');
        $newStatus = trim($_POST['new_status'] ?? '');
        $allowed   = ['Trống','Đang dọn','Bảo trì'];
        if ($maPhong && in_array($newStatus, $allowed)) {
            $pdo->prepare("UPDATE PHONG SET TinhTrang = :st WHERE MaPhong = :id")
                ->execute([':st' => $newStatus, ':id' => $maPhong]);
            $msg = "Phòng $maPhong → $newStatus."; $tab = 'list';
        } else {
            $msg = "Trạng thái không hợp lệ."; $msgType = 'error'; $tab = 'list';
        }
    }

    // ---- Xóa phòng ----
    elseif ($action === 'delete_room') {
        $maPhong = trim($_POST['ma_phong'] ?? '');
        if ($maPhong) {
            try {
                $active = $pdo->prepare("SELECT COUNT(*) FROM DAT_PHONG WHERE MaPhong=:id AND TrangThai NOT IN ('Đã hủy','Đã trả phòng')");
                $active->execute([':id' => $maPhong]);
                if ($active->fetchColumn() > 0) {
                    $msg = "Không thể xóa phòng $maPhong vì còn đặt phòng đang hoạt động.";
                    $msgType = 'error';
                } else {
                    $pdo->prepare("DELETE FROM DAT_PHONG WHERE MaPhong = :id")->execute([':id' => $maPhong]);
                    $pdo->prepare("DELETE FROM PHONG WHERE MaPhong = :id")->execute([':id' => $maPhong]);
                    $msg = "Đã xóa phòng $maPhong.";
                }
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage(); $msgType = 'error';
            }
        }
        $tab = 'list';
    }

    if ($msgType === 'success' && $msg) {
        header("Location: rooms.php?tab=$tab&msg=" . urlencode($msg));
        exit;
    }
}

if (!$msg && isset($_GET['msg'])) {
    $msg = urldecode($_GET['msg']); $msgType = 'success';
}

// ── Queries ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['filter'] ?? 'all';
$filterType   = $_GET['type']   ?? 'all';
$allowedSt    = ['Trống','Đang ở','Đang dọn','Bảo trì'];
$allowedTypes = ['Đơn','Đôi','Gia đình','VIP'];

$where = []; $params = [];
if ($filterStatus !== 'all' && in_array($filterStatus, $allowedSt)) {
    $where[] = "TinhTrang = :st"; $params[':st'] = $filterStatus;
}
if ($filterType !== 'all' && in_array($filterType, $allowedTypes)) {
    $where[] = "LoaiPhong = :lt"; $params[':lt'] = $filterType;
}
$sql = "SELECT * FROM PHONG" . ($where ? " WHERE ".implode(" AND ",$where) : "") . " ORDER BY Tang, MaPhong";
$roomStmt = $pdo->prepare($sql); $roomStmt->execute($params);
$rooms = $roomStmt->fetchAll();

$allRooms = $pdo->query("SELECT * FROM PHONG ORDER BY Tang, MaPhong")->fetchAll();

$stats = [
    'total'   => (int)$pdo->query("SELECT COUNT(*) FROM PHONG")->fetchColumn(),
    'trong'   => (int)$pdo->query("SELECT COUNT(*) FROM PHONG WHERE TinhTrang='Trống'")->fetchColumn(),
    'dangO'   => (int)$pdo->query("SELECT COUNT(*) FROM PHONG WHERE TinhTrang='Đang ở'")->fetchColumn(),
    'dangDon' => (int)$pdo->query("SELECT COUNT(*) FROM PHONG WHERE TinhTrang='Đang dọn'")->fetchColumn(),
    'baoTri'  => (int)$pdo->query("SELECT COUNT(*) FROM PHONG WHERE TinhTrang='Bảo trì'")->fetchColumn(),
];

$editRoom = null;
if ($tab === 'edit' && $editId) {
    $editStmt = $pdo->prepare("SELECT * FROM PHONG WHERE MaPhong = :id");
    $editStmt->execute([':id' => $editId]);
    $editRoom = $editStmt->fetch();
    if (!$editRoom) { $tab = 'list'; $editId = ''; }
}

$adminProfile = $pdo->prepare("SELECT nv.SoDienThoai, nv.MaNV FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV WHERE tk.TenTK=:tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

// Group all rooms by floor for diagram
$byFloor = [];
foreach ($allRooms as $r) { $byFloor[$r['Tang']][] = $r; }
ksort($byFloor);

// Helper functions
function fmt($n)  { return number_format((float)$n, 0, ',', '.'); }
function esc($s)  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tinhTrangBadge($s) {
    $map = [
        'Trống'    => ['#2563eb','#eff6ff'],
        'Đang ở'   => ['#dc2626','#fff0f0'],
        'Đang dọn' => ['#d97706','#fffbeb'],
        'Bảo trì'  => ['#4b5563','#f1f5f9'],
    ];
    $c = $map[$s] ?? ['#94a3b8','#f8fafc'];
    $icons = ['Trống'=>'🟢','Đang ở'=>'🔴','Đang dọn'=>'🟡','Bảo trì'=>'🔧'];
    $ic = $icons[$s] ?? '?';
    return "<span style='display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:{$c[1]};color:{$c[0]};border:1px solid {$c[0]}35;letter-spacing:.3px'>$ic " . esc($s) . "</span>";
}
function loaiBadge($l) {
    $map = ['Đơn'=>['#2563eb','#eff6ff'],'Đôi'=>['#7c3aed','#f5f3ff'],'Gia đình'=>['#059669','#ecfdf5'],'VIP'=>['#d97706','#fffbeb']];
    $c = $map[$l] ?? ['#94a3b8','#f8fafc'];
    return "<span style='display:inline-block;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;background:{$c[1]};color:{$c[0]};border:1px solid {$c[0]}30'>" . esc($l) . "</span>";
}
function diagramColor($tt) {
    return match($tt) {
        'Trống'    => ['#1d4ed8','#dbeafe','#bfdbfe'],
        'Đang ở'   => ['#dc2626','#fee2e2','#fca5a5'],
        'Đang dọn' => ['#d97706','#fef3c7','#fde68a'],
        'Bảo trì'  => ['#4b5563','#f1f5f9','#e2e8f0'],
        default    => ['#6b7280','#f8fafc','#e2e8f0'],
    };
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quản Lý Phòng — Easyhome</title>
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
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:.85rem;display:flex;align-items:center;gap:10px;border-left:4px solid}
.alert-success{background:#ecfdf5;color:#065f46;border-color:#10b981}
.alert-error{background:#fff0f0;color:#b91c1c;border-color:#ef4444;animation:shake .35s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}

/* ── STATS ── */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:#fff;border-radius:var(--radius);padding:16px 18px;border:1.5px solid var(--border);
  box-shadow:var(--shadow);display:flex;flex-direction:column;gap:6px;transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.stat-value{font-size:1.7rem;font-weight:700;color:var(--blue-dark);line-height:1}
.stat-label{font-size:.68rem;color:var(--muted);letter-spacing:.5px;text-transform:uppercase}

/* ── SECTION HEADER ── */
.section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.section-title{font-family:var(--serif);font-size:1.05rem;color:var(--blue-dark)}

/* ── TABLE ── */
.tbl-wrap{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow)}
.tbl-top{padding:12px 16px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.filter-group{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.filter-label{font-size:.68rem;font-weight:700;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-right:4px}
.ftab{padding:5px 11px;border-radius:20px;font-size:.72rem;font-weight:700;letter-spacing:.5px;
  cursor:pointer;border:1.5px solid var(--border);background:var(--blue-pale);color:var(--muted);
  text-decoration:none;transition:all .2s}
.ftab:hover,.ftab.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.ftab.t-don.active{background:#1d4ed8;border-color:#1d4ed8}
.ftab.t-doi.active{background:#7c3aed;border-color:#7c3aed}
.ftab.t-gd.active{background:#059669;border-color:#059669}
.ftab.t-vip.active{background:#d97706;border-color:#d97706}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead tr{background:var(--blue-pale)}
th{padding:10px 12px;text-align:left;font-size:.67rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);border-bottom:1.5px solid var(--border)}
td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafbff}
.text-right{text-align:right}.text-center{text-align:center}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:6px;
  font-family:var(--font);font-size:.72rem;font-weight:700;letter-spacing:.4px;
  cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-edit{background:#dbeafe;color:#1d4ed8}
.btn-edit:hover{background:#1d4ed8;color:#fff}
.btn-del{background:#fee2e2;color:#b91c1c}
.btn-del:hover{background:#ef4444;color:#fff}
.btn-primary{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(29,78,216,.28)}
.btn-primary:hover{background:var(--blue-dark)}
.btn-secondary{background:var(--blue-pale);color:var(--blue);border:1.5px solid var(--border)}
.btn-secondary:hover{background:var(--blue-mid)}
.btn-lg{padding:11px 22px;font-size:.85rem}

/* ── STATUS CHANGE INLINE ── */
.st-form{display:inline-flex;align-items:center;gap:4px}
.st-select{padding:3px 6px;font-size:.7rem;border:1.5px solid var(--border);border-radius:5px;
  font-family:var(--font);background:var(--blue-pale);color:var(--text);cursor:pointer;outline:none}
.st-select:focus{border-color:var(--blue)}
.st-btn{padding:3px 9px;font-size:.7rem;font-weight:700;background:var(--blue);color:#fff;
  border:none;border-radius:5px;cursor:pointer;transition:background .2s}
.st-btn:hover{background:var(--blue-dark)}

/* ── FORM ── */
.form-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);padding:24px;box-shadow:var(--shadow);max-width:680px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
label.lbl{font-size:.67rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted)}
.req{color:#ef4444;margin-left:2px}
.form-ctrl{padding:10px 13px;border:1.5px solid var(--border);border-radius:8px;
  font-family:var(--font);font-size:.88rem;color:var(--text);background:var(--blue-pale);
  outline:none;transition:all .25s}
.form-ctrl:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.12)}
.form-ctrl::placeholder{color:#94a3b8}
.form-ctrl:disabled{opacity:.6;cursor:not-allowed}
select.form-ctrl{cursor:pointer}
textarea.form-ctrl{resize:vertical;min-height:70px}
.form-footer{margin-top:20px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center}
.form-note{font-size:.75rem;color:var(--muted)}

/* ── DIAGRAM ── */
.diagram-section{margin-top:28px}
.floor-block{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  padding:18px 20px;margin-bottom:16px;box-shadow:var(--shadow)}
.floor-title{font-size:.78rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--blue-dark);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.floor-rooms{display:flex;flex-wrap:wrap;gap:10px}
.room-card{width:110px;border-radius:9px;padding:10px 12px;border:2px solid;cursor:default;transition:transform .15s}
.room-card:hover{transform:translateY(-2px)}
.rc-id{font-size:.82rem;font-weight:700;margin-bottom:4px}
.rc-type{font-size:.65rem;font-weight:600;opacity:.75;letter-spacing:.5px;text-transform:uppercase}
.rc-price{font-size:.7rem;margin-top:5px;opacity:.8}
.rc-status{font-size:.65rem;font-weight:700;margin-top:3px;opacity:.9}

/* ── LEGEND ── */
.legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.legend-item{display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--muted)}
.legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}

/* ── TABS (inner) ── */
.page-tabs{display:flex;gap:2px;margin-bottom:20px;background:var(--blue-pale);padding:4px;border-radius:10px;width:fit-content;border:1.5px solid var(--border)}
.ptab{padding:8px 18px;border-radius:7px;font-size:.78rem;font-weight:700;letter-spacing:.5px;
  cursor:pointer;text-decoration:none;color:var(--muted);transition:all .2s}
.ptab.active{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(29,78,216,.3)}
.ptab:hover:not(.active){color:var(--blue)}

/* ── EMPTY ── */
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-icon{font-size:2.5rem;margin-bottom:10px}
.empty-text{font-size:.88rem}

@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .content{padding:16px}.form-grid{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
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
      <div class="sb-site-sub">Quản Trị</div>
    </div>
  </div>

  <div class="sb-admin">
    <div class="sb-admin-label">Đang đăng nhập</div>
    <div class="sb-admin-card">
      <div class="sb-admin-name"><?= esc($adminName) ?></div>
      <?php if ($adminProfile): ?>
        <div class="sb-admin-row">👤 Mã: <?= esc($adminProfile['MaNV'] ?? $adminId) ?></div>
        <?php if ($adminProfile['SoDienThoai']): ?>
          <div class="sb-admin-row">📞 <?= esc($adminProfile['SoDienThoai']) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="sb-admin-badge">👑 Quản Lý</div>
    </div>
  </div>

  <nav class="sb-nav">
    <a href="dashboard.php?tab=overview"     class="sb-nav-item"><span class="sb-nav-icon">📊</span><span>Tổng Quan</span></a>
    <a href="dashboard.php?tab=bookings"     class="sb-nav-item"><span class="sb-nav-icon">📋</span><span>Quản Lý Đặt Phòng</span></a>
    <a href="calendar.php"                   class="sb-nav-item"><span class="sb-nav-icon">📅</span><span>Lịch Đặt Phòng</span></a>
    <a href="dashboard.php?tab=new-booking"  class="sb-nav-item"><span class="sb-nav-icon">➕</span><span>Đặt Phòng Mới</span></a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php"                      class="sb-nav-item active"><span class="sb-nav-icon">🛏️</span><span>Quản Lý Phòng</span></a>
    <a href="housekeeping.php"               class="sb-nav-item"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                   class="sb-nav-item"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                 class="sb-nav-item"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"        class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                   class="sb-nav-item"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
    <a href="dashboard.php?tab=report"       class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <div class="topbar">
    <div class="topbar-title">🛏️ Quản Lý Phòng</div>
    <div class="topbar-meta">
      <span>Tổng: <strong><?= $stats['total'] ?></strong> phòng</span>
      <span>Trống: <strong style="color:#1d4ed8"><?= $stats['trong'] ?></strong></span>
      <span>Đang ở: <strong style="color:#dc2626"><?= $stats['dangO'] ?></strong></span>
    </div>
  </div>

  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>">
      <?= $msgType === 'success' ? '✅' : '⚠' ?> <?= esc($msg) ?>
    </div>
    <?php endif; ?>

    <!-- Inner tabs -->
    <div class="page-tabs">
      <a href="rooms.php?tab=list"
         class="ptab <?= $tab==='list'?'active':'' ?>">📋 Danh Sách Phòng</a>
      <a href="rooms.php?tab=diagram"
         class="ptab <?= $tab==='diagram'?'active':'' ?>">🗺️ Sơ Đồ Phòng</a>
      <a href="rooms.php?tab=add"
         class="ptab <?= $tab==='add'?'active':'' ?>">➕ Thêm Phòng</a>
      <?php if ($tab === 'edit' && $editRoom): ?>
      <a href="rooms.php?tab=edit&id=<?= esc($editId) ?>"
         class="ptab active">✏️ Sửa: <?= esc($editId) ?></a>
      <?php endif; ?>
    </div>

    <!-- ══ STAT CARDS ══ -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">🏨</div>
        <div class="stat-value"><?= $stats['total'] ?></div>
        <div class="stat-label">Tổng Phòng</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">🟢</div>
        <div class="stat-value" style="color:#1d4ed8"><?= $stats['trong'] ?></div>
        <div class="stat-label">Trống</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff0f0">🔴</div>
        <div class="stat-value" style="color:#dc2626"><?= $stats['dangO'] ?></div>
        <div class="stat-label">Đang Ở</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb">🟡</div>
        <div class="stat-value" style="color:#d97706"><?= $stats['dangDon'] ?></div>
        <div class="stat-label">Đang Dọn</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f1f5f9">🔧</div>
        <div class="stat-value" style="color:#4b5563"><?= $stats['baoTri'] ?></div>
        <div class="stat-label">Bảo Trì</div>
      </div>
    </div>

    <?php /* ══════════════════════════════════════════
           LIST TAB
           ══════════════════════════════════════════ */ ?>
    <?php if ($tab === 'list'): ?>

    <div class="tbl-wrap">
      <div class="tbl-top">
        <!-- Status filter -->
        <div class="filter-group">
          <span class="filter-label">Trạng thái:</span>
          <?php
          $stFilters = ['all'=>'Tất Cả','Trống'=>'🟢 Trống','Đang ở'=>'🔴 Đang Ở','Đang dọn'=>'🟡 Đang Dọn','Bảo trì'=>'🔧 Bảo Trì'];
          foreach ($stFilters as $k => $v):
            $url = "rooms.php?tab=list&filter=".urlencode($k)."&type=".urlencode($filterType);
          ?>
          <a href="<?= $url ?>" class="ftab <?= $filterStatus===$k?'active':'' ?>"><?= $v ?></a>
          <?php endforeach; ?>
        </div>
        <!-- Type filter -->
        <div class="filter-group">
          <span class="filter-label">Loại:</span>
          <?php
          $typeFilters = ['all'=>'Tất Cả','Đơn'=>'Đơn','Đôi'=>'Đôi','Gia đình'=>'Gia Đình','VIP'=>'VIP'];
          $typeClass   = ['all'=>'','Đơn'=>'t-don','Đôi'=>'t-doi','Gia đình'=>'t-gd','VIP'=>'t-vip'];
          foreach ($typeFilters as $k => $v):
            $url = "rooms.php?tab=list&filter=".urlencode($filterStatus)."&type=".urlencode($k);
          ?>
          <a href="<?= $url ?>" class="ftab <?= $typeClass[$k] ?> <?= $filterType===$k?'active':'' ?>"><?= $v ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (empty($rooms)): ?>
      <div class="empty-state">
        <div class="empty-icon">🛏️</div>
        <div class="empty-text">Không tìm thấy phòng nào phù hợp.</div>
      </div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Mã Phòng</th>
            <th>Loại</th>
            <th>Tầng</th>
            <th class="text-right">Giá/Đêm</th>
            <th class="text-center">Sức Chứa</th>
            <th>Trạng Thái</th>
            <th>Mô Tả</th>
            <th class="text-center">Thao Tác</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rooms as $r): ?>
          <tr>
            <td><strong><?= esc($r['MaPhong']) ?></strong></td>
            <td><?= loaiBadge($r['LoaiPhong']) ?></td>
            <td class="text-center">Tầng <?= (int)$r['Tang'] ?></td>
            <td class="text-right" style="font-weight:600;color:var(--blue-dark)"><?= fmt($r['GiaPhong']) ?>đ</td>
            <td class="text-center"><?= (int)$r['SoNguoiToiDa'] ?> người</td>
            <td><?= tinhTrangBadge($r['TinhTrang']) ?></td>
            <td style="max-width:180px;font-size:.78rem;color:var(--muted)"><?= esc($r['MoTa'] ?: '—') ?></td>
            <td class="text-center">
              <div style="display:flex;align-items:center;gap:6px;justify-content:center;flex-wrap:wrap">

                <!-- Edit button -->
                <a href="rooms.php?tab=edit&id=<?= esc($r['MaPhong']) ?>" class="btn btn-edit">✏️ Sửa</a>

                <!-- Quick status change — không cho set "Đang ở" thủ công -->
                <?php if ($r['TinhTrang'] !== 'Đang ở'): ?>
                <form method="POST" class="st-form">
                  <input type="hidden" name="action" value="change_status">
                  <input type="hidden" name="ma_phong" value="<?= esc($r['MaPhong']) ?>">
                  <select name="new_status" class="st-select">
                    <?php foreach (['Trống','Đang dọn','Bảo trì'] as $opt): ?>
                      <option value="<?= $opt ?>" <?= $r['TinhTrang']===$opt?'selected':'' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="st-btn">↺</button>
                </form>
                <?php endif; ?>

                <!-- Delete -->
                <form method="POST" onsubmit="return confirm('Xóa phòng <?= esc($r['MaPhong']) ?>?\nHành động này không thể hoàn tác.')">
                  <input type="hidden" name="action" value="delete_room">
                  <input type="hidden" name="ma_phong" value="<?= esc($r['MaPhong']) ?>">
                  <button type="submit" class="btn btn-del">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <?php /* ══════════════════════════════════════════
           DIAGRAM TAB
           ══════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'diagram'): ?>

    <div class="legend">
      <?php
      $legendItems = ['Trống'=>'#1d4ed8','Đang ở'=>'#dc2626','Đang dọn'=>'#d97706','Bảo trì'=>'#4b5563'];
      foreach ($legendItems as $lb => $lc): ?>
      <div class="legend-item">
        <div class="legend-dot" style="background:<?= $lc ?>"></div>
        <?= esc($lb) ?>
      </div>
      <?php endforeach; ?>
      <span style="font-size:.72rem;color:var(--muted);margin-left:8px">(Click nút "Sửa" để thay đổi phòng)</span>
    </div>

    <?php if (empty($byFloor)): ?>
    <div class="empty-state"><div class="empty-icon">🏨</div><div class="empty-text">Chưa có phòng nào.</div></div>
    <?php else: ?>
    <?php foreach ($byFloor as $floor => $floorRooms): ?>
    <div class="floor-block">
      <div class="floor-title">
        <span>🏢</span> Tầng <?= (int)$floor ?>
        <span style="font-size:.68rem;font-weight:400;color:var(--muted);margin-left:4px">(<?= count($floorRooms) ?> phòng)</span>
      </div>
      <div class="floor-rooms">
        <?php foreach ($floorRooms as $r):
          [$tc, $bg, $br] = diagramColor($r['TinhTrang']); ?>
        <div class="room-card" style="background:<?= $bg ?>;border-color:<?= $br ?>;color:<?= $tc ?>">
          <div class="rc-id"><?= esc($r['MaPhong']) ?></div>
          <div class="rc-type"><?= esc($r['LoaiPhong']) ?></div>
          <div class="rc-price"><?= fmt($r['GiaPhong']) ?>đ</div>
          <div class="rc-status" style="opacity:.8;font-size:.62rem"><?= esc($r['TinhTrang']) ?></div>
          <?php if ($r['TinhTrang'] !== 'Đang ở'): ?>
          <form method="POST" style="margin-top:7px">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="ma_phong" value="<?= esc($r['MaPhong']) ?>">
            <select name="new_status" class="st-select" style="width:100%;font-size:.65rem;background:rgba(255,255,255,.7)">
              <?php foreach (['Trống','Đang dọn','Bảo trì'] as $opt): ?>
              <option value="<?= $opt ?>" <?= $r['TinhTrang']===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="st-btn" style="width:100%;margin-top:4px;font-size:.65rem;padding:4px">↺ Đổi</button>
          </form>
          <?php else: ?>
          <div style="margin-top:7px;font-size:.65rem;opacity:.7;font-style:italic">Đang có khách</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php /* ══════════════════════════════════════════
           ADD ROOM TAB
           ══════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'add'): ?>

    <div class="section-hdr">
      <div><div class="section-title">Thêm Phòng Mới</div></div>
    </div>

    <div class="form-card">
      <form method="POST">
        <input type="hidden" name="action" value="add_room">
        <div class="form-grid">

          <div class="form-group">
            <label class="lbl">Mã Phòng <span class="req">*</span></label>
            <input type="text" name="ma_phong" class="form-ctrl" placeholder="VD: P401"
                   value="<?= esc($_POST['ma_phong'] ?? '') ?>"
                   style="text-transform:uppercase" required>
          </div>

          <div class="form-group">
            <label class="lbl">Loại Phòng <span class="req">*</span></label>
            <select name="loai_phong" class="form-ctrl" required>
              <?php foreach (['Đơn','Đôi','Gia đình','VIP'] as $lt): ?>
              <option value="<?= $lt ?>" <?= (($_POST['loai_phong']??'')===$lt)?'selected':'' ?>><?= $lt ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="lbl">Giá / Đêm (VNĐ) <span class="req">*</span></label>
            <input type="number" name="gia_phong" class="form-ctrl" placeholder="VD: 800000"
                   value="<?= esc($_POST['gia_phong'] ?? '') ?>" min="1" required>
          </div>

          <div class="form-group">
            <label class="lbl">Số Người Tối Đa <span class="req">*</span></label>
            <input type="number" name="so_nguoi" class="form-ctrl"
                   value="<?= esc($_POST['so_nguoi'] ?? '2') ?>" min="1" max="10" required>
          </div>

          <div class="form-group">
            <label class="lbl">Tầng <span class="req">*</span></label>
            <input type="number" name="tang" class="form-ctrl"
                   value="<?= esc($_POST['tang'] ?? '1') ?>" min="1" max="20" required>
          </div>

          <div class="form-group">
            <label class="lbl">Trạng Thái Ban Đầu</label>
            <select name="tinh_trang" class="form-ctrl">
              <?php foreach (['Trống','Đang dọn','Bảo trì'] as $st): ?>
              <option value="<?= $st ?>" <?= (($_POST['tinh_trang']??'Trống')===$st)?'selected':'' ?>><?= $st ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group full">
            <label class="lbl">Mô Tả</label>
            <textarea name="mo_ta" class="form-ctrl" placeholder="Mô tả ngắn về phòng..."><?= esc($_POST['mo_ta'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="form-footer">
          <button type="submit" class="btn btn-primary btn-lg">✓ Thêm Phòng</button>
          <a href="rooms.php?tab=list" class="btn btn-secondary btn-lg">✕ Hủy</a>
          <span class="form-note">(*) Bắt buộc</span>
        </div>
      </form>
    </div>

    <?php /* ══════════════════════════════════════════
           EDIT TAB
           ══════════════════════════════════════════ */ ?>
    <?php elseif ($tab === 'edit' && $editRoom): ?>

    <div class="section-hdr">
      <div>
        <div class="section-title">Sửa Phòng: <?= esc($editRoom['MaPhong']) ?></div>
      </div>
    </div>

    <div class="form-card">
      <form method="POST">
        <input type="hidden" name="action" value="edit_room">
        <input type="hidden" name="ma_phong" value="<?= esc($editRoom['MaPhong']) ?>">
        <div class="form-grid">

          <div class="form-group">
            <label class="lbl">Mã Phòng</label>
            <input type="text" class="form-ctrl" value="<?= esc($editRoom['MaPhong']) ?>" disabled>
          </div>

          <div class="form-group">
            <label class="lbl">Loại Phòng <span class="req">*</span></label>
            <select name="loai_phong" class="form-ctrl" required>
              <?php foreach (['Đơn','Đôi','Gia đình','VIP'] as $lt): ?>
              <option value="<?= $lt ?>" <?= $editRoom['LoaiPhong']===$lt?'selected':'' ?>><?= $lt ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="lbl">Giá / Đêm (VNĐ) <span class="req">*</span></label>
            <input type="number" name="gia_phong" class="form-ctrl"
                   value="<?= esc($editRoom['GiaPhong']) ?>" min="1" required>
          </div>

          <div class="form-group">
            <label class="lbl">Số Người Tối Đa <span class="req">*</span></label>
            <input type="number" name="so_nguoi" class="form-ctrl"
                   value="<?= esc($editRoom['SoNguoiToiDa']) ?>" min="1" max="10" required>
          </div>

          <div class="form-group">
            <label class="lbl">Tầng <span class="req">*</span></label>
            <input type="number" name="tang" class="form-ctrl"
                   value="<?= esc($editRoom['Tang']) ?>" min="1" max="20" required>
          </div>

          <div class="form-group">
            <label class="lbl">Trạng Thái</label>
            <select name="tinh_trang" class="form-ctrl">
              <?php foreach (['Trống','Đang ở','Đang dọn','Bảo trì'] as $st): ?>
              <option value="<?= $st ?>" <?= $editRoom['TinhTrang']===$st?'selected':'' ?>><?= $st ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group full">
            <label class="lbl">Mô Tả</label>
            <textarea name="mo_ta" class="form-ctrl"><?= esc($editRoom['MoTa'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="form-footer">
          <button type="submit" class="btn btn-primary btn-lg">💾 Lưu Thay Đổi</button>
          <a href="rooms.php?tab=list" class="btn btn-secondary btn-lg">✕ Hủy</a>
        </div>
      </form>
    </div>

    <?php endif; ?>

  </div><!-- .content -->
</div><!-- .main -->

</body>
</html>
