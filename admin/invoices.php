<?php
// admin/invoices.php — Quản Lý Hóa Đơn — Easyhome v24
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'] ?? $adminId;
$msg = ''; $msgType = 'success';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $maHD   = trim($_POST['ma_hd'] ?? '');

    if ($action === 'mark_paid' && $maHD) {
        $pdo->prepare("UPDATE HOA_DON SET TrangThai='Đã thanh toán' WHERE MaHD=:id")
            ->execute([':id' => $maHD]);
        $msg = "✅ Hóa đơn <strong>$maHD</strong> đã được đánh dấu thanh toán.";
    }
    elseif ($action === 'mark_unpaid' && $maHD) {
        $pdo->prepare("UPDATE HOA_DON SET TrangThai='Chưa thanh toán' WHERE MaHD=:id")
            ->execute([':id' => $maHD]);
        $msg = "↩️ Hóa đơn <strong>$maHD</strong> đã chuyển về chưa thanh toán.";
    }
    elseif ($action === 'update_pttt' && $maHD) {
        $pttt = trim($_POST['phuong_thuc'] ?? '');
        $allowed = ['Tiền mặt','Chuyển khoản','Thẻ ngân hàng','QR Code','Apple Pay','Google Pay','Samsung Pay'];
        if (in_array($pttt, $allowed)) {
            $pdo->prepare("UPDATE HOA_DON SET PhuongThucTT=:pt WHERE MaHD=:id")
                ->execute([':pt' => $pttt, ':id' => $maHD]);
            $msg = "✅ Đã cập nhật phương thức thanh toán hóa đơn $maHD.";
        }
    }
    // PRG — redirect để tránh re-submit
    $qs = http_build_query(array_filter([
        'msg'    => $msg,
        'mtype'  => $msgType,
        'filter' => $_POST['_filter'] ?? 'all',
        'q'      => $_POST['_q']      ?? '',
        'month'  => $_POST['_month']  ?? '',
        'year'   => $_POST['_year']   ?? '',
    ]));
    header("Location: invoices.php?$qs"); exit;
}

// Khôi phục msg từ redirect
if (isset($_GET['msg'])) {
    $msg     = htmlspecialchars($_GET['msg']);
    $msgType = $_GET['mtype'] ?? 'success';
}

// ── GET params ────────────────────────────────────────────────────────────────
$filterStatus = $_GET['filter'] ?? 'all';
$search       = trim($_GET['q']     ?? '');
$filterMonth  = trim($_GET['month'] ?? '');
$filterYear   = trim($_GET['year']  ?? date('Y'));
$filterNV     = trim($_GET['nv']    ?? '');

// ── Stats tổng quát (không phụ thuộc filter) ─────────────────────────────────
$statsQ = $pdo->query("
    SELECT
        COUNT(*)                                          AS total,
        SUM(TrangThai='Đã thanh toán')                   AS paid,
        SUM(TrangThai='Chưa thanh toán')                  AS unpaid,
        SUM(CASE WHEN TrangThai='Đã thanh toán' THEN TongTien ELSE 0 END) AS revenue_paid,
        SUM(CASE WHEN TrangThai='Chưa thanh toán' THEN TongTien ELSE 0 END) AS revenue_unpaid
    FROM HOA_DON
")->fetch();

// ── Lấy danh sách hóa đơn với JOINs ─────────────────────────────────────────
$conditions = ['1=1'];
$params     = [];

if ($filterStatus === 'paid')   { $conditions[] = "hd.TrangThai = 'Đã thanh toán'";   }
if ($filterStatus === 'unpaid') { $conditions[] = "hd.TrangThai = 'Chưa thanh toán'"; }

if ($filterMonth) {
    $conditions[] = "MONTH(hd.NgayLap) = :month";
    $params[':month'] = (int)$filterMonth;
}
if ($filterYear) {
    $conditions[] = "YEAR(hd.NgayLap) = :year";
    $params[':year'] = (int)$filterYear;
}
if ($filterNV) {
    $conditions[] = "hd.MaNV = :nv";
    $params[':nv'] = $filterNV;
}
if ($search) {
    $conditions[] = "(hd.MaHD LIKE :q OR hd.MaDP LIKE :q OR kh.HoTen LIKE :q OR kh.TenTaiKhoan LIKE :q)";
    $params[':q'] = "%$search%";
}

$where = implode(' AND ', $conditions);
$stmt = $pdo->prepare("
    SELECT hd.*,
           kh.HoTen       AS TenKH,
           kh.SoDienThoai AS SdtKH,
           kh.Email        AS EmailKH,
           nv.HoTen       AS TenNV,
           dp.MaPhong,
           dp.NgayCheckIn,
           dp.NgayCheckOut,
           dp.SoLuongKhach
    FROM HOA_DON hd
    JOIN DAT_PHONG  dp ON hd.MaDP  = dp.MaDP
    JOIN KHACH_HANG kh ON dp.MaKH  = kh.MaKH
    LEFT JOIN NHAN_VIEN nv ON hd.MaNV = nv.MaNV
    WHERE $where
    ORDER BY hd.NgayLap DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// ── Danh sách NV để filter ────────────────────────────────────────────────────
$nvList = $pdo->query("
    SELECT DISTINCT nv.MaNV, nv.HoTen
    FROM NHAN_VIEN nv
    JOIN HOA_DON hd ON hd.MaNV = nv.MaNV
    ORDER BY nv.HoTen
")->fetchAll();

// ── Danh sách dịch vụ theo từng hóa đơn (cho modal detail) ───────────────────
$svcMap = [];
$svcRows = $pdo->query("
    SELECT ddv.MaDP, dv.TenDV, ddv.SoLuong, ddv.ThanhTien
    FROM DAT_DICH_VU ddv
    JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    ORDER BY ddv.NgayDat
")->fetchAll();
foreach ($svcRows as $s) {
    $svcMap[$s['MaDP']][] = $s;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($n)     { return number_format((float)$n, 0, ',', '.'); }
function esc($s)     { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDt($d)   { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function fmtDtFull($d) { return $d ? date('d/m/Y H:i', strtotime($d)) : '—'; }

function trangThaiBadge(string $tt): string {
    return match($tt) {
        'Đã thanh toán'  => "<span class='badge-paid'>✅ Đã thanh toán</span>",
        'Chưa thanh toán'=> "<span class='badge-unpaid'>⏳ Chưa thanh toán</span>",
        default          => "<span class='badge-unpaid'>" . esc($tt) . "</span>",
    };
}

$adminProfile = $pdo->prepare("SELECT nv.MaNV FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV WHERE tk.TenTK=:tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

// Tổng stats cho filter hiện tại
$totalFiltered = count($invoices);
$sumFiltered   = array_sum(array_column($invoices, 'TongTien'));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Quản Lý Hóa Đơn — Easyhome</title>
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
.stat-sub{font-size:.72rem;color:var(--muted)}

/* ── FILTER BAR ── */
.filter-bar{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);padding:14px 18px;margin-bottom:18px;
  display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.filter-tabs{display:flex;gap:4px;flex-wrap:wrap}
.ftab{padding:6px 14px;border-radius:20px;border:1.5px solid var(--border);background:#fff;
  font-family:var(--font);font-size:.78rem;font-weight:700;color:var(--muted);cursor:pointer;
  text-decoration:none;transition:all .2s;white-space:nowrap}
.ftab.active,.ftab:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.filter-sep{width:1px;height:28px;background:var(--border);flex-shrink:0}
.search-wrap{position:relative;flex:1;min-width:180px}
.search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.9rem;pointer-events:none}
.search-input{width:100%;padding:7px 10px 7px 32px;border-radius:8px;border:1.5px solid var(--border);
  font-family:var(--font);font-size:.82rem;outline:none;background:var(--blue-pale);transition:all .2s}
.search-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.08)}
.filter-select{padding:7px 10px;border-radius:8px;border:1.5px solid var(--border);
  font-family:var(--font);font-size:.82rem;outline:none;background:var(--blue-pale);
  color:var(--text);cursor:pointer;transition:border-color .2s}
.filter-select:focus{border-color:var(--blue)}
.btn-filter{padding:7px 16px;border-radius:8px;border:none;background:var(--blue);color:#fff;
  font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;transition:background .2s}
.btn-filter:hover{background:var(--blue-dark)}
.btn-reset{padding:7px 12px;border-radius:8px;border:1.5px solid var(--border);background:#fff;
  font-family:var(--font);font-size:.82rem;color:var(--muted);cursor:pointer;text-decoration:none;transition:all .2s}
.btn-reset:hover{border-color:var(--blue);color:var(--blue)}

/* ── TABLE ── */
.tbl-wrap{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow)}
.tbl-top{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;
  border-bottom:1px solid var(--border);gap:10px;flex-wrap:wrap}
.section-title{font-family:var(--serif);font-size:1rem;color:var(--blue-dark)}
.tbl-summary{font-size:.78rem;color:var(--muted);display:flex;align-items:center;gap:12px}
.tbl-summary strong{color:var(--blue-dark)}
table{width:100%;border-collapse:collapse;min-width:900px}
.tbl-scroll{overflow-x:auto}
thead th{background:var(--blue-dark);color:rgba(255,255,255,.85);padding:10px 14px;
  font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;font-weight:700;text-align:left;white-space:nowrap}
thead th.center{text-align:center}
tbody tr{border-bottom:1px solid var(--blue-pale);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--blue-pale)}
td{padding:10px 14px;font-size:.84rem;vertical-align:middle}
td.center{text-align:center}
td.num{text-align:right;font-variant-numeric:tabular-nums}

/* ── INVOICE CELLS ── */
.inv-id{font-family:monospace;font-weight:700;color:var(--blue-dark);font-size:.88rem}
.inv-dp{font-size:.72rem;color:var(--muted);margin-top:2px}
.kh-name{font-weight:600;color:var(--text);line-height:1.3}
.kh-sub{font-size:.72rem;color:var(--muted);margin-top:2px}
.room-chip{display:inline-block;background:var(--blue-mid);color:var(--blue-dark);
  padding:2px 8px;border-radius:5px;font-size:.75rem;font-weight:700}
.date-cell{font-size:.82rem;white-space:nowrap}
.date-sub{font-size:.7rem;color:var(--muted);margin-top:2px;white-space:nowrap}
.amount{font-weight:700;color:var(--text)}
.amount-muted{color:var(--muted);font-size:.78rem}
.pttt-chip{display:inline-block;padding:2px 8px;border-radius:5px;font-size:.72rem;font-weight:700;
  background:#f1f5f9;color:var(--muted);border:1px solid var(--border)}

/* ── BADGES ── */
.badge-paid{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#ecfdf5;color:#065f46;border:1px solid #10b98135;white-space:nowrap}
.badge-unpaid{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
  background:#fff7ed;color:#92400e;border:1px solid #f59e0b35;white-space:nowrap}

/* ── ACTION BUTTONS ── */
.actions{display:flex;gap:5px;align-items:center;flex-wrap:nowrap}
.btn-view{padding:5px 10px;border-radius:7px;font-size:.72rem;font-weight:700;
  background:var(--blue-pale);color:var(--blue);border:1.5px solid var(--blue-mid);
  cursor:pointer;font-family:var(--font);transition:all .2s;white-space:nowrap}
.btn-view:hover{background:var(--blue);color:#fff}
.btn-pay{padding:5px 10px;border-radius:7px;font-size:.72rem;font-weight:700;
  background:#ecfdf5;color:#065f46;border:1.5px solid #10b98135;
  cursor:pointer;font-family:var(--font);transition:all .2s;white-space:nowrap}
.btn-pay:hover{background:#10b981;color:#fff;border-color:#10b981}
.btn-unpay{padding:5px 10px;border-radius:7px;font-size:.72rem;font-weight:700;
  background:#fff7ed;color:#92400e;border:1.5px solid #f59e0b35;
  cursor:pointer;font-family:var(--font);transition:all .2s;white-space:nowrap}
.btn-unpay:hover{background:#f59e0b;color:#fff;border-color:#f59e0b}
.btn-print{padding:5px 10px;border-radius:7px;font-size:.72rem;font-weight:700;
  background:#f5f3ff;color:#5b21b6;border:1.5px solid #8b5cf635;
  cursor:pointer;font-family:var(--font);transition:all .2s;white-space:nowrap}
.btn-print:hover{background:#7c3aed;color:#fff;border-color:#7c3aed}

/* ── EMPTY STATE ── */
.empty-state{padding:56px 20px;text-align:center;color:var(--muted)}
.empty-icon{font-size:3rem;margin-bottom:12px}
.empty-text{font-size:.95rem}

/* ── MODAL ── */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);
  z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:16px;box-shadow:0 24px 80px rgba(29,78,216,.22);
  width:100%;max-width:640px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
  animation:modalIn .3s cubic-bezier(.34,1.56,.64,1)}
@keyframes modalIn{from{transform:scale(.94) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.modal-header{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:20px 24px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.modal-title{font-family:var(--serif);font-size:1.1rem;color:#fff}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;
  border-radius:8px;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;
  transition:background .2s;flex-shrink:0}
.modal-close:hover{background:rgba(255,255,255,.28)}
.modal-body{padding:22px 24px;overflow-y:auto;flex:1}
.modal-section{margin-bottom:18px}
.modal-section-title{font-size:.68rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:var(--muted);margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px}
.modal-row{display:flex;flex-direction:column;gap:2px}
.modal-label{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px}
.modal-value{font-size:.88rem;font-weight:600;color:var(--text)}
.modal-amount-table{width:100%;border-collapse:collapse}
.modal-amount-table tr{border-bottom:1px solid var(--blue-pale)}
.modal-amount-table tr:last-child{border-bottom:none}
.modal-amount-table td{padding:7px 0;font-size:.85rem}
.modal-amount-table td:last-child{text-align:right;font-weight:700}
.modal-total-row td{font-size:.95rem;font-weight:700;color:var(--blue-dark);padding-top:10px;
  border-top:2px solid var(--border)!important}
.svc-table{width:100%;border-collapse:collapse}
.svc-table thead th{background:var(--blue-pale);color:var(--blue-dark);padding:6px 10px;
  font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;font-weight:700;text-align:left}
.svc-table tbody td{padding:6px 10px;font-size:.82rem;border-bottom:1px solid var(--blue-pale)}
.svc-table tbody tr:last-child td{border-bottom:none}
.modal-footer{padding:16px 24px;border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-shrink:0;background:var(--blue-pale)}
.modal-btn{padding:9px 20px;border-radius:8px;font-family:var(--font);font-size:.82rem;
  font-weight:700;cursor:pointer;border:none;transition:all .2s}
.modal-btn-close{background:#fff;color:var(--muted);border:1.5px solid var(--border)}
.modal-btn-close:hover{border-color:var(--blue);color:var(--blue)}
.modal-btn-print{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(29,78,216,.25)}
.modal-btn-print:hover{background:var(--blue-dark)}

/* ── PRINT ── */
@media print{
  .sidebar,.topbar,.filter-bar,.tbl-top,.actions,.modal-footer,.modal-close{display:none!important}
  body{display:block;background:#fff}
  .modal-bg{position:static;display:block!important;padding:0;background:none}
  .modal{box-shadow:none;border-radius:0;max-height:none;max-width:100%;border:none}
  .modal-header{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .stat-card:last-child{grid-column:span 2}
  .content{padding:16px}
  .topbar{padding:12px 16px}
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
      <?php if ($adminProfile && $adminProfile['MaNV']): ?>
        <div class="sb-admin-row">👤 <?= esc($adminProfile['MaNV']) ?></div>
      <?php endif; ?>
      <div class="sb-admin-badge">👑 Quản Lý</div>
    </div>
  </div>

  <nav class="sb-nav">
    <a href="dashboard.php?tab=overview"    class="sb-nav-item"><span class="sb-nav-icon">📊</span><span>Tổng Quan</span></a>
    <a href="dashboard.php?tab=bookings"    class="sb-nav-item"><span class="sb-nav-icon">📋</span><span>Quản Lý Đặt Phòng</span></a>
    <a href="calendar.php"                  class="sb-nav-item"><span class="sb-nav-icon">📅</span><span>Lịch Đặt Phòng</span></a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php"                     class="sb-nav-item"><span class="sb-nav-icon">🛏️</span><span>Quản Lý Phòng</span></a>
    <a href="housekeeping.php"              class="sb-nav-item"><span class="sb-nav-icon">🧹</span><span>Dọn Phòng</span></a>
    <a href="services.php"                  class="sb-nav-item"><span class="sb-nav-icon">🛎️</span><span>Dịch Vụ</span></a>
    <a href="promotions.php"                class="sb-nav-item"><span class="sb-nav-icon">🎁</span><span>Khuyến Mãi</span></a>
    <a href="invoices.php"                  class="sb-nav-item active"><span class="sb-nav-icon">🧾</span><span>Hóa Đơn</span></a>
    <div class="sb-nav-divider"></div>
    <a href="dashboard.php?tab=staff"       class="sb-nav-item"><span class="sb-nav-icon">👥</span><span>Nhân Viên</span></a>
    <a href="accounts.php"                  class="sb-nav-item"><span class="sb-nav-icon">🔑</span><span>Tài Khoản</span></a>
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>
</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-title">🧾 Quản Lý Hóa Đơn</div>
    <div class="topbar-meta">
      <span>📅 <?= date('d/m/Y') ?></span>
      <span>🧾 <?= (int)$statsQ['total'] ?> hóa đơn</span>
      <span>✅ <?= (int)$statsQ['paid'] ?> đã thanh toán</span>
      <span>💰 <?= fmt($statsQ['revenue_paid']) ?>đ đã thu</span>
    </div>
  </div>

  <div class="content">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>">
      <?= $msg ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">🧾</div>
        <div class="stat-value"><?= (int)$statsQ['total'] ?></div>
        <div class="stat-label">Tổng Hóa Đơn</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">✅</div>
        <div class="stat-value" style="color:#059669"><?= (int)$statsQ['paid'] ?></div>
        <div class="stat-label">Đã Thanh Toán</div>
        <div class="stat-sub"><?= $statsQ['total'] > 0 ? round($statsQ['paid']/$statsQ['total']*100) : 0 ?>% tổng số</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff7ed">⏳</div>
        <div class="stat-value" style="color:#d97706"><?= (int)$statsQ['unpaid'] ?></div>
        <div class="stat-label">Chưa Thanh Toán</div>
        <div class="stat-sub">Cần xử lý</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5">💰</div>
        <div class="stat-value" style="font-size:1.2rem;color:#059669"><?= fmt($statsQ['revenue_paid']) ?>đ</div>
        <div class="stat-label">Đã Thu</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff7ed">📬</div>
        <div class="stat-value" style="font-size:1.2rem;color:#d97706"><?= fmt($statsQ['revenue_unpaid']) ?>đ</div>
        <div class="stat-label">Chưa Thu</div>
      </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="invoices.php">
      <div class="filter-bar">

        <!-- Tabs trạng thái -->
        <div class="filter-tabs">
          <?php
          $tabDefs = [
            'all'    => ['Tất cả',            (int)$statsQ['total']],
            'unpaid' => ['⏳ Chưa TT',         (int)$statsQ['unpaid']],
            'paid'   => ['✅ Đã TT',            (int)$statsQ['paid']],
          ];
          foreach ($tabDefs as $k => [$label, $count]): ?>
            <a href="invoices.php?filter=<?= $k ?>&q=<?= urlencode($search) ?>&month=<?= esc($filterMonth) ?>&year=<?= esc($filterYear) ?>&nv=<?= esc($filterNV) ?>"
               class="ftab <?= $filterStatus === $k ? 'active' : '' ?>">
              <?= $label ?> (<?= $count ?>)
            </a>
          <?php endforeach; ?>
        </div>

        <div class="filter-sep"></div>

        <!-- Search -->
        <div class="search-wrap">
          <span class="search-icon">🔍</span>
          <input type="text" name="q" class="search-input" placeholder="Mã HĐ, mã đặt phòng, tên KH…"
                 value="<?= esc($search) ?>">
        </div>

        <!-- Tháng -->
        <select name="month" class="filter-select">
          <option value="">Tất cả tháng</option>
          <?php for ($m=1;$m<=12;$m++): ?>
            <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>>Tháng <?= $m ?></option>
          <?php endfor; ?>
        </select>

        <!-- Năm -->
        <select name="year" class="filter-select">
          <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
            <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>

        <!-- Nhân viên lập -->
        <select name="nv" class="filter-select">
          <option value="">Tất cả NV</option>
          <?php foreach ($nvList as $nv): ?>
            <option value="<?= esc($nv['MaNV']) ?>" <?= $filterNV === $nv['MaNV'] ? 'selected' : '' ?>>
              <?= esc($nv['HoTen']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <input type="hidden" name="filter" value="<?= esc($filterStatus) ?>">
        <button type="submit" class="btn-filter">🔍 Lọc</button>
        <a href="invoices.php" class="btn-reset">✕ Xóa lọc</a>
      </div>
    </form>

    <!-- Table -->
    <div class="tbl-wrap">
      <div class="tbl-top">
        <div class="section-title">📋 Danh Sách Hóa Đơn</div>
        <div class="tbl-summary">
          <span>Hiển thị <strong><?= $totalFiltered ?></strong> hóa đơn</span>
          <span>Tổng: <strong><?= fmt($sumFiltered) ?>đ</strong></span>
        </div>
      </div>

      <div class="tbl-scroll">
        <table>
          <thead>
            <tr>
              <th class="center">#</th>
              <th>Hóa Đơn / Đặt Phòng</th>
              <th>Khách Hàng</th>
              <th class="center">Phòng</th>
              <th class="center">Ngày Lập</th>
              <th class="num">Tiền Phòng</th>
              <th class="num">Tiền DV</th>
              <th class="num">Phụ Phí</th>
              <th class="num">Tổng Tiền</th>
              <th class="center">Thanh Toán</th>
              <th class="center">Trạng Thái</th>
              <th class="center">Thao Tác</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($invoices)): ?>
            <tr>
              <td colspan="12">
                <div class="empty-state">
                  <div class="empty-icon">🔍</div>
                  <div class="empty-text">Không tìm thấy hóa đơn nào phù hợp với bộ lọc.</div>
                </div>
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($invoices as $i => $hd):
              $svcs = $svcMap[$hd['MaDP']] ?? [];
              $svcJson = htmlspecialchars(json_encode($svcs, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
              $hdJson  = htmlspecialchars(json_encode([
                'MaHD'       => $hd['MaHD'],
                'MaDP'       => $hd['MaDP'],
                'MaPhong'    => $hd['MaPhong'],
                'TenKH'      => $hd['TenKH'],
                'SdtKH'      => $hd['SdtKH'],
                'EmailKH'    => $hd['EmailKH'],
                'TenNV'      => $hd['TenNV'],
                'NgayCheckIn'  => $hd['NgayCheckIn'],
                'NgayCheckOut' => $hd['NgayCheckOut'],
                'SoLuongKhach' => $hd['SoLuongKhach'],
                'TienPhong'    => $hd['TienPhong'],
                'TienDichVu'   => $hd['TienDichVu'],
                'PhuPhi'       => $hd['PhuPhi'],
                'TienCocDaThu' => $hd['TienCocDaThu'],
                'TongTien'     => $hd['TongTien'],
                'PhuongThucTT' => $hd['PhuongThucTT'],
                'NgayLap'      => $hd['NgayLap'],
                'TrangThai'    => $hd['TrangThai'],
                'GhiChu'       => $hd['GhiChu'],
              ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            ?>
            <tr>
              <td class="center" style="color:var(--muted);font-size:.78rem"><?= $i+1 ?></td>
              <td>
                <div class="inv-id"><?= esc($hd['MaHD']) ?></div>
                <div class="inv-dp">📋 <?= esc($hd['MaDP']) ?></div>
              </td>
              <td>
                <div class="kh-name"><?= esc($hd['TenKH']) ?></div>
                <div class="kh-sub">📞 <?= esc($hd['SdtKH']) ?></div>
              </td>
              <td class="center">
                <span class="room-chip">🛏 <?= esc($hd['MaPhong']) ?></span>
              </td>
              <td class="center">
                <div class="date-cell"><?= fmtDt($hd['NgayLap']) ?></div>
                <div class="date-sub"><?= date('H:i', strtotime($hd['NgayLap'])) ?></div>
              </td>
              <td class="num"><span class="amount"><?= fmt($hd['TienPhong']) ?>đ</span></td>
              <td class="num">
                <?php if ($hd['TienDichVu'] > 0): ?>
                  <span class="amount"><?= fmt($hd['TienDichVu']) ?>đ</span>
                <?php else: ?>
                  <span class="amount-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="num">
                <?php if ($hd['PhuPhi'] > 0): ?>
                  <span class="amount" style="color:#d97706"><?= fmt($hd['PhuPhi']) ?>đ</span>
                <?php else: ?>
                  <span class="amount-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="num"><span class="amount" style="color:var(--blue-dark)"><?= fmt($hd['TongTien']) ?>đ</span></td>
              <td class="center">
                <?php if ($hd['PhuongThucTT']): ?>
                  <span class="pttt-chip"><?= esc($hd['PhuongThucTT']) ?></span>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.78rem">—</span>
                <?php endif; ?>
              </td>
              <td class="center"><?= trangThaiBadge($hd['TrangThai']) ?></td>
              <td class="center">
                <div class="actions">
                  <button class="btn-view"
                          onclick="openModal(<?= $hdJson ?>, <?= $svcJson ?>)"
                          title="Xem chi tiết">👁 Chi tiết</button>

                  <?php if ($hd['TrangThai'] === 'Chưa thanh toán'): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Xác nhận đã thu tiền hóa đơn <?= esc($hd['MaHD']) ?>?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="mark_paid">
                    <input type="hidden" name="ma_hd"    value="<?= esc($hd['MaHD']) ?>">
                    <input type="hidden" name="_filter"  value="<?= esc($filterStatus) ?>">
                    <input type="hidden" name="_q"       value="<?= esc($search) ?>">
                    <input type="hidden" name="_month"   value="<?= esc($filterMonth) ?>">
                    <input type="hidden" name="_year"    value="<?= esc($filterYear) ?>">
                    <button type="submit" class="btn-pay" title="Đánh dấu đã thanh toán">✅ Thu tiền</button>
                  </form>
                  <?php else: ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Chuyển hóa đơn <?= esc($hd['MaHD']) ?> về chưa thanh toán?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="mark_unpaid">
                    <input type="hidden" name="ma_hd"    value="<?= esc($hd['MaHD']) ?>">
                    <input type="hidden" name="_filter"  value="<?= esc($filterStatus) ?>">
                    <input type="hidden" name="_q"       value="<?= esc($search) ?>">
                    <input type="hidden" name="_month"   value="<?= esc($filterMonth) ?>">
                    <input type="hidden" name="_year"    value="<?= esc($filterYear) ?>">
                    <button type="submit" class="btn-unpay" title="Đánh dấu chưa thanh toán">↩️ Hoàn lại</button>
                  </form>
                  <?php endif; ?>

                  <button class="btn-print"
                          onclick="openModal(<?= $hdJson ?>, <?= $svcJson ?>, true)"
                          title="In hóa đơn">🖨 In</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ════ MODAL CHI TIẾT HÓA ĐƠN ════ -->
<div class="modal-bg" id="modalBg" onclick="closeModalBg(event)">
  <div class="modal" id="modalBox">
    <div class="modal-header">
      <div class="modal-title" id="mTitle">🧾 Chi Tiết Hóa Đơn</div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="mBody"><!-- filled by JS --></div>
    <div class="modal-footer">
      <button class="modal-btn modal-btn-close" onclick="closeModal()">Đóng</button>
      <button class="modal-btn modal-btn-print" onclick="window.print()">🖨 In Hóa Đơn</button>
    </div>
  </div>
</div>

<script>
function fmt(n) {
  return Number(n).toLocaleString('vi-VN');
}
function esc(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

function openModal(hd, svcs, printNow = false) {
  const nights = hd.NgayCheckIn && hd.NgayCheckOut
    ? Math.max(1, Math.round((new Date(hd.NgayCheckOut) - new Date(hd.NgayCheckIn)) / 86400000))
    : '?';
  const fmtDate = s => s ? new Date(s).toLocaleDateString('vi-VN') : '—';

  let svcRows = '';
  if (svcs && svcs.length) {
    svcs.forEach(s => {
      svcRows += `<tr>
        <td>${esc(s.TenDV)}</td>
        <td style="text-align:center">${s.SoLuong}</td>
        <td style="text-align:right">${fmt(s.ThanhTien)}đ</td>
      </tr>`;
    });
  } else {
    svcRows = `<tr><td colspan="3" style="color:var(--muted);font-style:italic;text-align:center">Không có dịch vụ</td></tr>`;
  }

  const ttBadge = hd.TrangThai === 'Đã thanh toán'
    ? `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700;background:#ecfdf5;color:#065f46;border:1px solid #10b98135">✅ Đã thanh toán</span>`
    : `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700;background:#fff7ed;color:#92400e;border:1px solid #f59e0b35">⏳ Chưa thanh toán</span>`;

  document.getElementById('mTitle').textContent = '🧾 Hóa Đơn ' + hd.MaHD;
  document.getElementById('mBody').innerHTML = `
    <!-- Info chung -->
    <div class="modal-section">
      <div class="modal-section-title">Thông Tin Chung</div>
      <div class="modal-grid">
        <div class="modal-row"><span class="modal-label">Mã hóa đơn</span><span class="modal-value" style="font-family:monospace">${esc(hd.MaHD)}</span></div>
        <div class="modal-row"><span class="modal-label">Mã đặt phòng</span><span class="modal-value" style="font-family:monospace">${esc(hd.MaDP)}</span></div>
        <div class="modal-row"><span class="modal-label">Phòng</span><span class="modal-value">🛏 ${esc(hd.MaPhong)}</span></div>
        <div class="modal-row"><span class="modal-label">Số đêm</span><span class="modal-value">${nights} đêm</span></div>
        <div class="modal-row"><span class="modal-label">Check-in</span><span class="modal-value">${fmtDate(hd.NgayCheckIn)}</span></div>
        <div class="modal-row"><span class="modal-label">Check-out</span><span class="modal-value">${fmtDate(hd.NgayCheckOut)}</span></div>
        <div class="modal-row"><span class="modal-label">Số khách</span><span class="modal-value">👥 ${hd.SoLuongKhach} người</span></div>
        <div class="modal-row"><span class="modal-label">Ngày lập</span><span class="modal-value">${esc(new Date(hd.NgayLap).toLocaleString('vi-VN'))}</span></div>
      </div>
    </div>

    <!-- Khách hàng & NV -->
    <div class="modal-section">
      <div class="modal-section-title">Khách Hàng & Nhân Viên</div>
      <div class="modal-grid">
        <div class="modal-row"><span class="modal-label">Khách hàng</span><span class="modal-value">${esc(hd.TenKH)}</span></div>
        <div class="modal-row"><span class="modal-label">SĐT khách</span><span class="modal-value">${esc(hd.SdtKH)}</span></div>
        <div class="modal-row" style="grid-column:span 2"><span class="modal-label">Nhân viên lập HĐ</span><span class="modal-value">${esc(hd.TenNV || '—')}</span></div>
      </div>
    </div>

    <!-- Dịch vụ đi kèm -->
    <div class="modal-section">
      <div class="modal-section-title">Dịch Vụ Đi Kèm</div>
      <table class="svc-table">
        <thead><tr><th>Tên dịch vụ</th><th style="text-align:center">SL</th><th style="text-align:right">Thành tiền</th></tr></thead>
        <tbody>${svcRows}</tbody>
      </table>
    </div>

    <!-- Bảng tiền -->
    <div class="modal-section">
      <div class="modal-section-title">Thanh Toán</div>
      <table class="modal-amount-table">
        <tr><td>Tiền phòng (${nights} đêm)</td><td>${fmt(hd.TienPhong)}đ</td></tr>
        <tr><td>Tiền dịch vụ</td><td>${fmt(hd.TienDichVu)}đ</td></tr>
        ${Number(hd.PhuPhi) > 0 ? `<tr><td style="color:#d97706">Phụ phí</td><td style="color:#d97706">${fmt(hd.PhuPhi)}đ</td></tr>` : ''}
        ${Number(hd.TienCocDaThu) > 0 ? `<tr><td style="color:#059669">Tiền cọc đã thu (−)</td><td style="color:#059669">−${fmt(hd.TienCocDaThu)}đ</td></tr>` : ''}
        <tr class="modal-total-row"><td>💰 TỔNG THANH TOÁN</td><td>${fmt(hd.TongTien)}đ</td></tr>
      </table>
      <div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div>${ttBadge}</div>
        ${hd.PhuongThucTT ? `<span style="font-size:.82rem;color:var(--muted)">💳 ${esc(hd.PhuongThucTT)}</span>` : ''}
      </div>
      ${hd.GhiChu ? `<div style="margin-top:10px;font-size:.82rem;color:var(--muted);background:var(--blue-pale);padding:8px 12px;border-radius:7px;border-left:3px solid var(--border)">📝 ${esc(hd.GhiChu)}</div>` : ''}
    </div>
  `;

  document.getElementById('modalBg').classList.add('open');
  if (printNow) {
    setTimeout(() => window.print(), 350);
  }
}

function closeModal() {
  document.getElementById('modalBg').classList.remove('open');
}
function closeModalBg(e) {
  if (e.target === document.getElementById('modalBg')) closeModal();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>
