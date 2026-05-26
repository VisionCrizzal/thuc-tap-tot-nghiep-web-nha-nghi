<?php
// admin/promotions.php — Quản lý Khuyến Mãi — Easyhome v20
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

$allowedLoai   = ['PhanTram', 'SoTien'];
$allowedStatus = ['Đang áp dụng', 'Tạm dừng'];
$today         = date('Y-m-d');

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    // ── Thêm khuyến mãi ──────────────────────────────────────────────────────
    if ($action === 'add_promo') {
        $maKM     = strtoupper(trim($_POST['ma_km']       ?? ''));
        $tenKM    = trim($_POST['ten_km']        ?? '');
        $loaiKM   = trim($_POST['loai_km']       ?? 'PhanTram');
        $giaTriKM = (float)($_POST['gia_tri_km'] ?? 0);
        $dieuKien = trim($_POST['dieu_kien']     ?? '');
        $batDau   = trim($_POST['ngay_bat_dau']  ?? '');
        $ketThuc  = trim($_POST['ngay_ket_thuc'] ?? '');
        $tt       = trim($_POST['trang_thai']    ?? 'Đang áp dụng');

        $loaiKM = in_array($loaiKM, $allowedLoai)   ? $loaiKM : 'PhanTram';
        $tt     = in_array($tt,     $allowedStatus)  ? $tt     : 'Đang áp dụng';

        if (!$maKM || !$tenKM || $giaTriKM <= 0 || !$batDau || !$ketThuc) {
            $msg = "Vui lòng điền đầy đủ: Mã KM, Tên, Giá trị (> 0), Ngày bắt đầu và kết thúc.";
            $msgType = 'error'; $tab = 'add';
        } elseif (strlen($maKM) > 10) {
            $msg = "Mã khuyến mãi tối đa 10 ký tự.";
            $msgType = 'error'; $tab = 'add';
        } elseif ($loaiKM === 'PhanTram' && ($giaTriKM <= 0 || $giaTriKM > 100)) {
            $msg = "Giảm theo phần trăm phải từ 1% đến 100%.";
            $msgType = 'error'; $tab = 'add';
        } elseif ($ketThuc < $batDau) {
            $msg = "Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.";
            $msgType = 'error'; $tab = 'add';
        } else {
            $chk = $pdo->prepare("SELECT MaKM FROM KHUYEN_MAI WHERE MaKM = :id");
            $chk->execute([':id' => $maKM]);
            if ($chk->fetch()) {
                $msg = "Mã khuyến mãi '$maKM' đã tồn tại, vui lòng dùng mã khác.";
                $msgType = 'error'; $tab = 'add';
            } else {
                $pdo->prepare("INSERT INTO KHUYEN_MAI
                               (MaKM,TenKM,LoaiKM,GiaTriKM,DieuKien,NgayBatDau,NgayKetThuc,TrangThai)
                               VALUES (:ma,:ten,:loai,:gt,:dk,:bd,:kt,:tt)")
                    ->execute([':ma'=>$maKM,':ten'=>$tenKM,':loai'=>$loaiKM,
                               ':gt'=>$giaTriKM,':dk'=>$dieuKien ?: null,
                               ':bd'=>$batDau,':kt'=>$ketThuc,':tt'=>$tt]);
                $msg = "Đã thêm khuyến mãi «{$tenKM}» ({$maKM}) thành công."; $tab = 'list';
            }
        }
    }

    // ── Sửa khuyến mãi ───────────────────────────────────────────────────────
    elseif ($action === 'edit_promo') {
        $maKM     = trim($_POST['ma_km']        ?? '');
        $tenKM    = trim($_POST['ten_km']        ?? '');
        $loaiKM   = trim($_POST['loai_km']       ?? 'PhanTram');
        $giaTriKM = (float)($_POST['gia_tri_km'] ?? 0);
        $dieuKien = trim($_POST['dieu_kien']     ?? '');
        $batDau   = trim($_POST['ngay_bat_dau']  ?? '');
        $ketThuc  = trim($_POST['ngay_ket_thuc'] ?? '');
        $tt       = trim($_POST['trang_thai']    ?? 'Đang áp dụng');

        $loaiKM = in_array($loaiKM, $allowedLoai)  ? $loaiKM : 'PhanTram';
        $tt     = in_array($tt,    $allowedStatus)  ? $tt     : 'Đang áp dụng';

        if (!$maKM || !$tenKM || $giaTriKM <= 0 || !$batDau || !$ketThuc) {
            $msg = "Vui lòng điền đầy đủ thông tin.";
            $msgType = 'error'; $tab = 'edit'; $editId = $maKM;
        } elseif ($loaiKM === 'PhanTram' && ($giaTriKM <= 0 || $giaTriKM > 100)) {
            $msg = "Giảm theo phần trăm phải từ 1% đến 100%.";
            $msgType = 'error'; $tab = 'edit'; $editId = $maKM;
        } elseif ($ketThuc < $batDau) {
            $msg = "Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.";
            $msgType = 'error'; $tab = 'edit'; $editId = $maKM;
        } else {
            $pdo->prepare("UPDATE KHUYEN_MAI
                           SET TenKM=:ten,LoaiKM=:loai,GiaTriKM=:gt,DieuKien=:dk,
                               NgayBatDau=:bd,NgayKetThuc=:kt,TrangThai=:tt
                           WHERE MaKM=:ma")
                ->execute([':ten'=>$tenKM,':loai'=>$loaiKM,':gt'=>$giaTriKM,
                           ':dk'=>$dieuKien ?: null,':bd'=>$batDau,':kt'=>$ketThuc,
                           ':tt'=>$tt,':ma'=>$maKM]);
            $msg = "Đã cập nhật khuyến mãi «{$tenKM}»."; $tab = 'list';
        }
    }

    // ── Đổi trạng thái nhanh ─────────────────────────────────────────────────
    elseif ($action === 'toggle_status') {
        $maKM      = trim($_POST['ma_km']      ?? '');
        $newStatus = trim($_POST['new_status'] ?? '');
        if ($maKM && in_array($newStatus, $allowedStatus)) {
            $pdo->prepare("UPDATE KHUYEN_MAI SET TrangThai = :st WHERE MaKM = :id")
                ->execute([':st' => $newStatus, ':id' => $maKM]);
            $msg = "Khuyến mãi $maKM → $newStatus."; $tab = 'list';
        } else {
            $msg = "Trạng thái không hợp lệ."; $msgType = 'error'; $tab = 'list';
        }
    }

    // ── Xóa khuyến mãi ───────────────────────────────────────────────────────
    elseif ($action === 'delete_promo') {
        $maKM = trim($_POST['ma_km'] ?? '');
        if ($maKM) {
            try {
                $pdo->prepare("DELETE FROM KHUYEN_MAI WHERE MaKM = :id")->execute([':id' => $maKM]);
                $msg = "Đã xóa khuyến mãi {$maKM}.";
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage(); $msgType = 'error';
            }
        }
        $tab = 'list';
    }

    // PRG redirect on success
    if ($msgType === 'success' && $msg) {
        $redir = "promotions.php?tab={$tab}" . ($editId ? "&id={$editId}" : "");
        header("Location: {$redir}&msg=" . urlencode($msg));
        exit;
    }
}

if (!$msg && isset($_GET['msg'])) {
    $msg = urldecode($_GET['msg']); $msgType = 'success';
}

// ── Queries ───────────────────────────────────────────────────────────────────
$promos = $pdo->query("SELECT * FROM KHUYEN_MAI ORDER BY NgayBatDau DESC, MaKM")->fetchAll();

// Enrich with effective status + days left
$stats = ['total'=>count($promos),'dangAp'=>0,'tamDung'=>0,'hetHan'=>0,'chuaBatDau'=>0,'phanTram'=>0,'soTien'=>0];
foreach ($promos as &$p) {
    if ($p['NgayKetThuc'] < $today) {
        $p['EffStatus'] = 'Hết hạn';         $stats['hetHan']++;
    } elseif ($p['NgayBatDau'] > $today) {
        $p['EffStatus'] = 'Chưa bắt đầu';    $stats['chuaBatDau']++;
    } elseif ($p['TrangThai'] === 'Tạm dừng') {
        $p['EffStatus'] = 'Tạm dừng';        $stats['tamDung']++;
    } else {
        $p['EffStatus'] = 'Đang áp dụng';    $stats['dangAp']++;
    }
    $p['DaysLeft'] = $p['NgayKetThuc'] >= $today
        ? (int)round((strtotime($p['NgayKetThuc']) - strtotime($today)) / 86400)
        : -1;
    if ($p['LoaiKM'] === 'PhanTram') $stats['phanTram']++; else $stats['soTien']++;
}
unset($p);

// Apply filter
$filterStatus  = $_GET['filter'] ?? 'all';
$filteredPromos = ($filterStatus !== 'all')
    ? array_values(array_filter($promos, fn($p) => $p['EffStatus'] === $filterStatus))
    : $promos;

// Auto-suggest mã KM tiếp theo
$maxNum   = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaKM,3) AS UNSIGNED)) FROM KHUYEN_MAI WHERE MaKM REGEXP '^KM[0-9]+'")->fetchColumn();
$nextMaKM = 'KM' . str_pad(((int)$maxNum + 1), 3, '0', STR_PAD_LEFT);

// Edit record
$editPromo = null;
if ($tab === 'edit' && $editId) {
    $ep = $pdo->prepare("SELECT * FROM KHUYEN_MAI WHERE MaKM = :id");
    $ep->execute([':id' => $editId]);
    $editPromo = $ep->fetch();
    if (!$editPromo) { $tab = 'list'; $editId = ''; }
}

$adminProfile = $pdo->prepare("SELECT nv.MaNV FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV WHERE tk.TenTK=:tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($n)     { return number_format((float)$n, 0, ',', '.'); }
function esc($s)     { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }

function effStatusBadge(string $s): string {
    return match($s) {
        'Đang áp dụng'  => "<span class='badge-active'>✅ Đang áp dụng</span>",
        'Tạm dừng'      => "<span class='badge-pause'>⏸️ Tạm dừng</span>",
        'Hết hạn'       => "<span class='badge-expired'>⌛ Hết hạn</span>",
        'Chưa bắt đầu'  => "<span class='badge-upcoming'>🕐 Chưa bắt đầu</span>",
        default         => "<span class='badge-pause'>" . esc($s) . "</span>",
    };
}

function loaiBadge(string $loai, float $gt): string {
    if ($loai === 'PhanTram')
        return "<span class='badge-pct'>−{$gt}%</span>";
    return "<span class='badge-vnd'>−" . number_format($gt,0,',','.') . "đ</span>";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quản Lý Khuyến Mãi — Easyhome</title>
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
.stat-sub{font-size:.68rem;color:var(--muted)}

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
.filter-btns{display:flex;gap:4px;flex-wrap:wrap}
.flt-btn{padding:5px 12px;border-radius:20px;border:1.5px solid var(--border);background:#fff;
  font-family:var(--font);font-size:.75rem;font-weight:700;color:var(--muted);cursor:pointer;
  text-decoration:none;transition:all .2s;white-space:nowrap}
.flt-btn.active,.flt-btn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
table{width:100%;border-collapse:collapse}
thead th{background:var(--blue-dark);color:rgba(255,255,255,.85);padding:10px 14px;
  font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;font-weight:700;text-align:left}
thead th:first-child{width:42px;text-align:center}
tbody tr{border-bottom:1px solid var(--blue-pale);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--blue-pale)}
td{padding:11px 14px;font-size:.85rem;vertical-align:middle}
td:first-child{text-align:center;color:var(--muted);font-size:.78rem;width:42px}

/* ── PROMO NAME CELL ── */
.promo-name{font-weight:700;color:var(--text);line-height:1.3}
.promo-code{font-size:.72rem;font-family:monospace;color:var(--muted);background:var(--blue-pale);
  padding:1px 6px;border-radius:4px;border:1px solid var(--border);display:inline-block;margin-top:2px}
.promo-cond{font-size:.75rem;color:var(--muted);margin-top:3px;line-height:1.4}

/* ── DATE RANGE ── */
.date-range{font-size:.8rem;color:var(--text);white-space:nowrap}
.days-left{display:inline-block;margin-top:3px;font-size:.7rem;padding:1px 7px;border-radius:10px;font-weight:700}
.days-ok{background:#ecfdf5;color:#059669;border:1px solid #10b98120}
.days-warn{background:#fffbeb;color:#d97706;border:1px solid #f59e0b30}
.days-expired{background:#fff0f0;color:#b91c1c;border:1px solid #ef444420}
.days-soon{background:#f5f3ff;color:#7c3aed;border:1px solid #7c3aed20}

/* ── BADGES ── */
.badge-active{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#ecfdf5;color:#065f46;border:1px solid #10b98135}
.badge-pause{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#fffbeb;color:#92400e;border:1px solid #f59e0b35}
.badge-expired{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#fff0f0;color:#b91c1c;border:1px solid #ef444435}
.badge-upcoming{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#f5f3ff;color:#5b21b6;border:1px solid #8b5cf635}
.badge-pct{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.8rem;font-weight:700;
  background:var(--blue-mid);color:var(--blue-dark);border:1px solid var(--border);letter-spacing:.3px}
.badge-vnd{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:700;
  background:#fefce8;color:#713f12;border:1px solid #fef08a}

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
  padding:28px 32px;box-shadow:var(--shadow);max-width:720px}
.form-card h3{font-family:var(--serif);font-size:1.1rem;color:var(--blue-dark);margin-bottom:22px;
  padding-bottom:12px;border-bottom:2px solid var(--blue-mid)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-group.span3{grid-column:1/3}
.form-label{font-size:.76rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.form-label .req{color:#ef4444}
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

/* ── VALUE PREVIEW ── */
.val-preview{margin-top:8px;padding:8px 14px;background:var(--blue-pale);border-radius:8px;
  border:1.5px solid var(--border);font-size:.88rem;color:var(--blue-dark);font-weight:700;
  display:none}

/* ── LOAI SELECTOR ── */
.loai-selector{display:flex;gap:8px;margin-top:4px}
.loai-opt{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:5px;padding:12px 8px;border:2px solid var(--border);border-radius:10px;cursor:pointer;
  transition:all .2s;background:#fff;text-align:center}
.loai-opt:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.loai-opt.sel{border-color:var(--blue);background:var(--blue-pale)}
.loai-opt input[type=radio]{display:none}
.loai-opt .loai-icon{font-size:1.6rem}
.loai-opt .loai-label{font-size:.75rem;font-weight:700;color:var(--muted)}
.loai-opt.sel .loai-label{color:var(--blue-dark)}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-state .empty-icon{font-size:3.5rem;margin-bottom:14px;opacity:.5;display:block}
.empty-state p{font-size:.9rem}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .stats-grid{grid-template-columns:repeat(3,1fr)}
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .form-grid{grid-template-columns:1fr}.form-group.full,.form-group.span3{grid-column:auto}
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
    <a href="calendar.php"                  class="sb-nav-item"><span class="sb-nav-icon">📅</span><span>Lịch Đặt Phòng</span></a>
    <a href="dashboard.php?tab=new-booking" class="sb-nav-item"><span class="sb-nav-icon">➕</span><span>Đặt Phòng Mới</span></a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php"                     class="sb-nav-item"><span class="sb-nav-icon">🛏️</span><span>Quản Lý Phòng</span></a>
    <a href="housekeeping.php"              class="sb-nav-item"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                  class="sb-nav-item"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                class="sb-nav-item active"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"       class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                  class="sb-nav-item"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-title">🎁 Quản Lý Khuyến Mãi</div>
    <div class="topbar-meta">
      <span>📅 <?= date('d/m/Y') ?></span>
      <span>🎁 <?= $stats['total'] ?> chương trình</span>
      <span>✅ <?= $stats['dangAp'] ?> đang áp dụng</span>
    </div>
  </div>

  <div class="content">

    <!-- Alert -->
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>" id="alertBox">
      <?= $msgType === 'success' ? '✅' : '⚠️' ?>
      <?= esc($msg) ?>
    </div>
    <?php endif; ?>

    <!-- ── STATS ──────────────────────────────────────────────────────────── -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">🎁</div>
        <div class="stat-value"><?= $stats['total'] ?></div>
        <div class="stat-label">Tổng Chương Trình</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">✅</div>
        <div class="stat-value" style="color:#059669"><?= $stats['dangAp'] ?></div>
        <div class="stat-label">Đang Áp Dụng</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb">⏸️</div>
        <div class="stat-value" style="color:#d97706"><?= $stats['tamDung'] ?></div>
        <div class="stat-label">Tạm Dừng</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff0f0">⌛</div>
        <div class="stat-value" style="color:#dc2626"><?= $stats['hetHan'] ?></div>
        <div class="stat-label">Hết Hạn</div>
        <div class="stat-sub"><?= $stats['chuaBatDau'] ?> chưa bắt đầu</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4">📊</div>
        <div class="stat-value"><?= $stats['phanTram'] ?></div>
        <div class="stat-label">Giảm Theo %</div>
        <div class="stat-sub"><?= $stats['soTien'] ?> giảm số tiền</div>
      </div>
    </div>

    <!-- ── PAGE TABS ──────────────────────────────────────────────────────── -->
    <div class="page-tabs">
      <a href="promotions.php?tab=list" class="ptab <?= $tab==='list' && !in_array($tab,['add','edit']) ? 'active':'' ?>">📋 Danh Sách</a>
      <a href="promotions.php?tab=add"  class="ptab <?= $tab==='add'  ? 'active':'' ?>">➕ Thêm Mới</a>
      <?php if ($tab === 'edit' && $editPromo): ?>
      <a href="promotions.php?tab=edit&id=<?= esc($editId) ?>" class="ptab active">✏️ Sửa: <?= esc($editId) ?></a>
      <?php endif; ?>
    </div>

    <!-- ════════════════════════ TAB: LIST ════════════════════════════════ -->
    <?php if ($tab !== 'add' && $tab !== 'edit'): ?>

    <div class="tbl-wrap">
      <div class="tbl-top">
        <div class="tbl-top-left">
          <span class="section-title">🎁 Chương Trình Khuyến Mãi</span>
          <div class="filter-btns">
            <a href="promotions.php?filter=all"           class="flt-btn <?= $filterStatus==='all'          ?'active':'' ?>">Tất Cả (<?= $stats['total'] ?>)</a>
            <a href="promotions.php?filter=Đang+áp+dụng" class="flt-btn <?= $filterStatus==='Đang áp dụng' ?'active':'' ?>">✅ Đang áp dụng (<?= $stats['dangAp'] ?>)</a>
            <a href="promotions.php?filter=Tạm+dừng"     class="flt-btn <?= $filterStatus==='Tạm dừng'     ?'active':'' ?>">⏸️ Tạm dừng (<?= $stats['tamDung'] ?>)</a>
            <a href="promotions.php?filter=Hết+hạn"      class="flt-btn <?= $filterStatus==='Hết hạn'      ?'active':'' ?>">⌛ Hết hạn (<?= $stats['hetHan'] ?>)</a>
            <a href="promotions.php?filter=Chưa+bắt+đầu" class="flt-btn <?= $filterStatus==='Chưa bắt đầu' ?'active':'' ?>">🕐 Chưa bắt đầu (<?= $stats['chuaBatDau'] ?>)</a>
          </div>
        </div>
        <a href="promotions.php?tab=add" class="btn-primary" style="text-decoration:none;padding:8px 18px;font-size:.82rem">➕ Thêm Khuyến Mãi</a>
      </div>

      <?php if (empty($filteredPromos)): ?>
        <div class="empty-state">
          <span class="empty-icon">🎁</span>
          <p>Không có chương trình khuyến mãi nào<?= $filterStatus !== 'all' ? ' khớp bộ lọc này' : '' ?>.</p>
          <br>
          <a href="promotions.php?tab=add" class="btn-primary" style="text-decoration:none;padding:9px 20px;font-size:.85rem">➕ Tạo chương trình đầu tiên</a>
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Mã</th>
            <th>Chương Trình / Điều Kiện</th>
            <th>Loại Giảm</th>
            <th>Thời Hạn</th>
            <th>Trạng Thái</th>
            <th style="width:200px">Thao Tác</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filteredPromos as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <span class="promo-code"><?= esc($p['MaKM']) ?></span>
            </td>
            <td>
              <div class="promo-name"><?= esc($p['TenKM']) ?></div>
              <?php if ($p['DieuKien']): ?>
              <div class="promo-cond">📌 <?= esc($p['DieuKien']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= loaiBadge($p['LoaiKM'], $p['GiaTriKM']) ?></td>
            <td>
              <div class="date-range">
                📅 <?= fmtDate($p['NgayBatDau']) ?><br>
                🏁 <?= fmtDate($p['NgayKetThuc']) ?>
              </div>
              <?php if ($p['DaysLeft'] >= 0): ?>
                <?php
                  $dc = $p['DaysLeft'] > 30 ? 'days-ok' : ($p['DaysLeft'] > 7 ? 'days-warn' : 'days-soon');
                  if ($p['EffStatus'] === 'Chưa bắt đầu') $dc = 'days-upcoming';
                ?>
                <span class="days-left <?= $dc ?>">
                  <?= $p['EffStatus'] === 'Chưa bắt đầu' ? 'Bắt đầu sau '.$p['DaysLeft'].' ngày' : 'Còn '.$p['DaysLeft'].' ngày' ?>
                </span>
              <?php else: ?>
                <span class="days-left days-expired">Đã hết hạn</span>
              <?php endif; ?>
            </td>
            <td><?= effStatusBadge($p['EffStatus']) ?></td>
            <td>
              <div class="actions">
                <a href="promotions.php?tab=edit&id=<?= esc($p['MaKM']) ?>" class="btn-edit">✏️ Sửa</a>
                <?php if ($p['TrangThai'] === 'Đang áp dụng'): ?>
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"     value="toggle_status">
                  <input type="hidden" name="ma_km"      value="<?= esc($p['MaKM']) ?>">
                  <input type="hidden" name="new_status" value="Tạm dừng">
                  <button type="submit" class="btn-off">⏸️ Tạm dừng</button>
                </form>
                <?php else: ?>
                <form method="POST" style="display:inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"     value="toggle_status">
                  <input type="hidden" name="ma_km"      value="<?= esc($p['MaKM']) ?>">
                  <input type="hidden" name="new_status" value="Đang áp dụng">
                  <button type="submit" class="btn-on">▶️ Kích Hoạt</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Xóa khuyến mãi «<?= esc(addslashes($p['TenKM'])) ?>»?\nThao tác này không thể hoàn tác.')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_promo">
                  <input type="hidden" name="ma_km"  value="<?= esc($p['MaKM']) ?>">
                  <button type="submit" class="btn-del">🗑️ Xóa</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- ════════════════════════ TAB: ADD ═════════════════════════════════ -->
    <?php elseif ($tab === 'add'): ?>

    <div class="form-card">
      <h3>🎁 Thêm Chương Trình Khuyến Mãi Mới</h3>
      <form method="POST" id="addForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_promo">
        <div class="form-grid">

          <!-- Mã KM -->
          <div class="form-group">
            <label class="form-label">Mã Khuyến Mãi <span class="req">*</span></label>
            <input type="text" name="ma_km" class="form-ctrl" maxlength="10"
                   value="<?= esc($_POST['ma_km'] ?? $nextMaKM) ?>"
                   placeholder="VD: KM007" required
                   oninput="this.value=this.value.toUpperCase()">
            <div class="form-hint">Tối đa 10 ký tự, tự động đề xuất: <strong><?= esc($nextMaKM) ?></strong></div>
          </div>

          <!-- Tên KM -->
          <div class="form-group">
            <label class="form-label">Tên Chương Trình <span class="req">*</span></label>
            <input type="text" name="ten_km" class="form-ctrl" maxlength="100"
                   value="<?= esc($_POST['ten_km'] ?? '') ?>"
                   placeholder="VD: Đặt sớm 20%" required>
          </div>

          <!-- Loại giảm -->
          <div class="form-group full">
            <label class="form-label">Loại Giảm Giá <span class="req">*</span></label>
            <div class="loai-selector">
              <label class="loai-opt <?= ($_POST['loai_km'] ?? 'PhanTram') === 'PhanTram' ? 'sel' : '' ?>"
                     id="optPct">
                <input type="radio" name="loai_km" value="PhanTram"
                       <?= ($_POST['loai_km'] ?? 'PhanTram') === 'PhanTram' ? 'checked' : '' ?>
                       onchange="switchLoai('PhanTram')">
                <span class="loai-icon">%</span>
                <span class="loai-label">Giảm Theo Phần Trăm</span>
                <small style="font-size:.68rem;color:var(--muted)">VD: giảm 10%</small>
              </label>
              <label class="loai-opt <?= ($_POST['loai_km'] ?? '') === 'SoTien' ? 'sel' : '' ?>"
                     id="optVnd">
                <input type="radio" name="loai_km" value="SoTien"
                       <?= ($_POST['loai_km'] ?? '') === 'SoTien' ? 'checked' : '' ?>
                       onchange="switchLoai('SoTien')">
                <span class="loai-icon">₫</span>
                <span class="loai-label">Giảm Số Tiền Cố Định</span>
                <small style="font-size:.68rem;color:var(--muted)">VD: giảm 200.000đ</small>
              </label>
            </div>
          </div>

          <!-- Giá trị -->
          <div class="form-group">
            <label class="form-label">Giá Trị Giảm <span class="req">*</span></label>
            <input type="number" name="gia_tri_km" id="giaTriInput" class="form-ctrl"
                   min="1" step="any"
                   value="<?= esc($_POST['gia_tri_km'] ?? '') ?>"
                   placeholder="VD: 10" required
                   oninput="updatePreview()">
            <div class="form-hint" id="giaTriHint">Nhập phần trăm giảm (1–100)</div>
            <div class="val-preview" id="valPreview"></div>
          </div>

          <!-- Trạng thái -->
          <div class="form-group">
            <label class="form-label">Trạng Thái Ban Đầu</label>
            <select name="trang_thai" class="form-ctrl">
              <option value="Đang áp dụng" <?= ($_POST['trang_thai'] ?? '') === 'Đang áp dụng' ? 'selected' : '' ?>>✅ Đang áp dụng</option>
              <option value="Tạm dừng"     <?= ($_POST['trang_thai'] ?? '') === 'Tạm dừng'     ? 'selected' : '' ?>>⏸️ Tạm dừng (kích hoạt sau)</option>
            </select>
          </div>

          <!-- Ngày bắt đầu -->
          <div class="form-group">
            <label class="form-label">Ngày Bắt Đầu <span class="req">*</span></label>
            <input type="date" name="ngay_bat_dau" class="form-ctrl"
                   value="<?= esc($_POST['ngay_bat_dau'] ?? $today) ?>"
                   onchange="validateDates()" required>
          </div>

          <!-- Ngày kết thúc -->
          <div class="form-group">
            <label class="form-label">Ngày Kết Thúc <span class="req">*</span></label>
            <input type="date" name="ngay_ket_thuc" class="form-ctrl"
                   value="<?= esc($_POST['ngay_ket_thuc'] ?? '') ?>"
                   onchange="validateDates()" required>
            <div class="form-hint" id="dateHint"></div>
          </div>

          <!-- Điều kiện -->
          <div class="form-group full">
            <label class="form-label">Điều Kiện Áp Dụng</label>
            <textarea name="dieu_kien" class="form-ctrl" rows="3"
                      placeholder="VD: Đặt trước ít nhất 1 tuần, áp dụng cho phòng Đôi và VIP..."><?= esc($_POST['dieu_kien'] ?? '') ?></textarea>
            <div class="form-hint">Mô tả điều kiện để khuyến mãi được áp dụng (không bắt buộc)</div>
          </div>

        </div>
        <div class="form-actions">
          <button type="submit" class="btn-primary">💾 Lưu Khuyến Mãi</button>
          <a href="promotions.php?tab=list" class="btn-secondary">← Quay Lại</a>
        </div>
      </form>
    </div>

    <!-- ════════════════════════ TAB: EDIT ════════════════════════════════ -->
    <?php elseif ($tab === 'edit' && $editPromo): ?>

    <div class="form-card">
      <h3>✏️ Sửa Khuyến Mãi: <?= esc($editPromo['MaKM']) ?></h3>
      <form method="POST" id="editForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_promo">
        <input type="hidden" name="ma_km"  value="<?= esc($editPromo['MaKM']) ?>">
        <div class="form-grid">

          <!-- Mã KM (readonly) -->
          <div class="form-group">
            <label class="form-label">Mã Khuyến Mãi</label>
            <input type="text" class="form-ctrl" value="<?= esc($editPromo['MaKM']) ?>" readonly>
            <div class="form-hint">Mã không thể thay đổi sau khi tạo.</div>
          </div>

          <!-- Tên KM -->
          <div class="form-group">
            <label class="form-label">Tên Chương Trình <span class="req">*</span></label>
            <input type="text" name="ten_km" class="form-ctrl" maxlength="100"
                   value="<?= esc($editPromo['TenKM']) ?>" required>
          </div>

          <!-- Loại giảm -->
          <div class="form-group full">
            <label class="form-label">Loại Giảm Giá <span class="req">*</span></label>
            <div class="loai-selector">
              <label class="loai-opt <?= $editPromo['LoaiKM'] === 'PhanTram' ? 'sel' : '' ?>" id="optPct">
                <input type="radio" name="loai_km" value="PhanTram"
                       <?= $editPromo['LoaiKM'] === 'PhanTram' ? 'checked' : '' ?>
                       onchange="switchLoai('PhanTram')">
                <span class="loai-icon">%</span>
                <span class="loai-label">Giảm Theo Phần Trăm</span>
              </label>
              <label class="loai-opt <?= $editPromo['LoaiKM'] === 'SoTien' ? 'sel' : '' ?>" id="optVnd">
                <input type="radio" name="loai_km" value="SoTien"
                       <?= $editPromo['LoaiKM'] === 'SoTien' ? 'checked' : '' ?>
                       onchange="switchLoai('SoTien')">
                <span class="loai-icon">₫</span>
                <span class="loai-label">Giảm Số Tiền Cố Định</span>
              </label>
            </div>
          </div>

          <!-- Giá trị -->
          <div class="form-group">
            <label class="form-label">Giá Trị Giảm <span class="req">*</span></label>
            <input type="number" name="gia_tri_km" id="giaTriInput" class="form-ctrl"
                   min="1" step="any"
                   value="<?= esc($editPromo['GiaTriKM']) ?>" required
                   oninput="updatePreview()">
            <div class="form-hint" id="giaTriHint">
              <?= $editPromo['LoaiKM'] === 'PhanTram' ? 'Nhập phần trăm giảm (1–100)' : 'Nhập số tiền giảm (VNĐ)' ?>
            </div>
            <div class="val-preview" id="valPreview" style="display:block">
              <?= $editPromo['LoaiKM'] === 'PhanTram'
                  ? "Giảm {$editPromo['GiaTriKM']}% trên giá phòng"
                  : "Giảm " . number_format($editPromo['GiaTriKM'],0,',','.') . "đ cố định" ?>
            </div>
          </div>

          <!-- Trạng thái -->
          <div class="form-group">
            <label class="form-label">Trạng Thái</label>
            <select name="trang_thai" class="form-ctrl">
              <option value="Đang áp dụng" <?= $editPromo['TrangThai']==='Đang áp dụng'?'selected':'' ?>>✅ Đang áp dụng</option>
              <option value="Tạm dừng"     <?= $editPromo['TrangThai']==='Tạm dừng'    ?'selected':'' ?>>⏸️ Tạm dừng</option>
            </select>
          </div>

          <!-- Ngày bắt đầu -->
          <div class="form-group">
            <label class="form-label">Ngày Bắt Đầu <span class="req">*</span></label>
            <input type="date" name="ngay_bat_dau" class="form-ctrl"
                   value="<?= esc($editPromo['NgayBatDau']) ?>"
                   onchange="validateDates()" required>
          </div>

          <!-- Ngày kết thúc -->
          <div class="form-group">
            <label class="form-label">Ngày Kết Thúc <span class="req">*</span></label>
            <input type="date" name="ngay_ket_thuc" class="form-ctrl"
                   value="<?= esc($editPromo['NgayKetThuc']) ?>"
                   onchange="validateDates()" required>
            <div class="form-hint" id="dateHint"></div>
          </div>

          <!-- Điều kiện -->
          <div class="form-group full">
            <label class="form-label">Điều Kiện Áp Dụng</label>
            <textarea name="dieu_kien" class="form-ctrl" rows="3"><?= esc($editPromo['DieuKien'] ?? '') ?></textarea>
          </div>

        </div>
        <div class="form-actions">
          <button type="submit" class="btn-primary">💾 Cập Nhật</button>
          <a href="promotions.php?tab=list" class="btn-secondary">← Hủy</a>
        </div>
      </form>
    </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
// ── Alert auto-hide ───────────────────────────────────────────────────────────
const alertBox = document.getElementById('alertBox');
if (alertBox) {
    setTimeout(() => {
        alertBox.style.transition = 'opacity .5s';
        alertBox.style.opacity = '0';
        setTimeout(() => alertBox.remove(), 500);
    }, 4000);
}

// ── Loai selector toggle ──────────────────────────────────────────────────────
function switchLoai(loai) {
    const optPct = document.getElementById('optPct');
    const optVnd = document.getElementById('optVnd');
    if (optPct) optPct.classList.toggle('sel', loai === 'PhanTram');
    if (optVnd) optVnd.classList.toggle('sel', loai === 'SoTien');

    const hint  = document.getElementById('giaTriHint');
    const input = document.getElementById('giaTriInput');
    if (loai === 'PhanTram') {
        if (hint) hint.textContent = 'Nhập phần trăm giảm (1–100)';
        if (input) { input.placeholder = 'VD: 10'; input.max = '100'; }
    } else {
        if (hint) hint.textContent = 'Nhập số tiền giảm (VNĐ)';
        if (input) { input.placeholder = 'VD: 200000'; input.removeAttribute('max'); }
    }
    updatePreview();
}

// ── Value preview ─────────────────────────────────────────────────────────────
function updatePreview() {
    const preview = document.getElementById('valPreview');
    const input   = document.getElementById('giaTriInput');
    const radio   = document.querySelector('input[name="loai_km"]:checked');
    if (!preview || !input || !radio) return;

    const val  = parseFloat(input.value) || 0;
    const loai = radio.value;

    if (val <= 0) { preview.style.display = 'none'; return; }
    preview.style.display = 'block';

    if (loai === 'PhanTram') {
        preview.textContent = `Giảm ${val}% trên giá phòng`;
    } else {
        preview.textContent = `Giảm ${val.toLocaleString('vi-VN')}đ cố định`;
    }
}

// ── Date validation ───────────────────────────────────────────────────────────
function validateDates() {
    const hint   = document.getElementById('dateHint');
    const inputs = document.querySelectorAll('input[name="ngay_bat_dau"], input[name="ngay_ket_thuc"]');
    if (inputs.length < 2 || !hint) return;

    const bd = inputs[0].value;
    const kt = inputs[1].value;
    if (!bd || !kt) return;

    const diff = Math.round((new Date(kt) - new Date(bd)) / 86400000);
    if (diff < 0) {
        hint.textContent = '⚠️ Ngày kết thúc phải sau ngày bắt đầu!';
        hint.style.color = '#dc2626';
    } else if (diff === 0) {
        hint.textContent = '⚠️ Thời hạn 1 ngày (bắt đầu và kết thúc cùng ngày).';
        hint.style.color = '#d97706';
    } else {
        hint.textContent = `✅ Thời hạn: ${diff} ngày`;
        hint.style.color = '#059669';
    }
}

// Init preview on page load
updatePreview();
validateDates();
</script>
</body>
</html>
