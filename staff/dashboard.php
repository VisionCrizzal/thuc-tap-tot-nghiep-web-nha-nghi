<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['staff_id'])) {
    header('Location: ../login.php'); exit;
}

$staffId   = $_SESSION['staff_id'];
$staffRole = $_SESSION['staff_role'];
$staffMaNV = $_SESSION['staff_maNV'];
$tab       = $_GET['tab'] ?? 'bookings';
$msg       = ''; $msgType = 'success';

// Thông báo từ checkin.php / checkout.php redirect
if (isset($_GET['checkin_ok'])) { $msg = "✅ Check-in thành công! Hóa đơn đã được tạo."; }
if (isset($_GET['service_ok'])) { $msg = "✅ " . htmlspecialchars($_GET['service_ok']); $tab = 'services'; }
if (isset($_GET['service_err'])) { $msg = "⚠️ " . htmlspecialchars($_GET['service_err']); $msgType = 'error'; $tab = 'services'; }


// ── Thông tin nhân viên ─────────────────────────────────────────────────────
$nvRow = $pdo->prepare("
    SELECT nv.*, tk.VaiTro, tk.LanDangNhapCuoi
    FROM NHAN_VIEN nv
    JOIN TAI_KHOAN tk ON nv.MaNV = tk.MaNV
    WHERE tk.TenTK = :id
");
$nvRow->execute([':id' => $staffId]);
$nv = $nvRow->fetch();

// ── Xử lý logout ────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    header('Location: ../logout.php'); exit;
}

// ── Xử lý POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $maDP   = trim($_POST['ma_dp'] ?? '');
    $tab    = 'bookings';

    if ($maDP) {
        if ($action === 'xac_nhan') {
            // Chờ xác nhận → Đã nhận phòng + cập nhật phòng Đang ở
            $get = $pdo->prepare("SELECT MaPhong FROM DAT_PHONG WHERE MaDP=:id AND TrangThai='Chờ xác nhận'");
            $get->execute([':id' => $maDP]);
            $row = $get->fetch();
            if ($row) {
                $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã nhận phòng', MaNV_XuLy=:nv WHERE MaDP=:id")
                    ->execute([':nv' => $staffMaNV, ':id' => $maDP]);
                $pdo->prepare("UPDATE PHONG SET TinhTrang='Đang ở' WHERE MaPhong=:p")
                    ->execute([':p' => $row['MaPhong']]);
                $msg = "Đã xác nhận check-in cho đặt phòng <strong>{$maDP}</strong>.";
            } else {
                $msg = "Không thể xác nhận — đặt phòng không ở trạng thái 'Chờ xác nhận'.";
                $msgType = 'error';
            }

        } elseif ($action === 'checkout') {
            // Đã nhận phòng → Đã trả phòng + phòng Đang dọn
            $get = $pdo->prepare("SELECT MaPhong FROM DAT_PHONG WHERE MaDP=:id AND TrangThai='Đã nhận phòng'");
            $get->execute([':id' => $maDP]);
            $row = $get->fetch();
            if ($row) {
                $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã trả phòng', MaNV_XuLy=:nv WHERE MaDP=:id")
                    ->execute([':nv' => $staffMaNV, ':id' => $maDP]);
                $pdo->prepare("UPDATE PHONG SET TinhTrang='Đang dọn' WHERE MaPhong=:p")
                    ->execute([':p' => $row['MaPhong']]);
                $msg = "Đã xác nhận check-out cho đặt phòng <strong>{$maDP}</strong>. Phòng chuyển sang 'Đang dọn'.";
            } else {
                $msg = "Không thể check-out — đặt phòng không ở trạng thái 'Đang ở'.";
                $msgType = 'error';
            }

        } elseif ($action === 'huy') {
            $get = $pdo->prepare("SELECT MaPhong, TrangThai FROM DAT_PHONG WHERE MaDP=:id AND TrangThai='Chờ xác nhận'");
            $get->execute([':id' => $maDP]);
            $row = $get->fetch();
            if ($row) {
                $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã hủy', MaNV_XuLy=:nv WHERE MaDP=:id")
                    ->execute([':nv' => $staffMaNV, ':id' => $maDP]);
                $msg = "Đã hủy đặt phòng <strong>{$maDP}</strong>.";
            } else {
                $msg = "Không thể hủy — chỉ hủy được đặt phòng 'Chờ xác nhận'.";
                $msgType = 'error';
            }

        } elseif ($action === 'phong_sach') {
            // Đang dọn → Trống
            $pdo->prepare("UPDATE PHONG SET TinhTrang='Trống' WHERE MaPhong=:p")
                ->execute([':p' => $maDP]); // reuse field for MaPhong here
            $msg = "Phòng <strong>{$maDP}</strong> đã dọn xong, chuyển về 'Trống'.";

        } elseif ($action === 'add_service') {
            // ── Thêm dịch vụ phát sinh vào phòng đang ở ──────────────────────
            $maDV    = trim($_POST['ma_dv'] ?? '');
            $soLuong = max(1, min(99, (int)($_POST['so_luong'] ?? 1)));
            $check = $pdo->prepare("SELECT MaDP FROM DAT_PHONG WHERE MaDP=:dp AND TrangThai='Đã nhận phòng'");
            $check->execute([':dp' => $maDP]);
            if ($check->fetch() && $maDV !== '') {
                $dvQ = $pdo->prepare("SELECT * FROM DICH_VU WHERE MaDV=:dv AND TrangThai='Khả dụng'");
                $dvQ->execute([':dv' => $maDV]);
                $dv = $dvQ->fetch();
                if ($dv) {
                    $thanhTien = $dv['GiaDV'] * $soLuong;
                    $pdo->prepare("INSERT INTO DAT_DICH_VU (MaDP, MaDV, SoLuong, ThanhTien) VALUES (?,?,?,?)")
                        ->execute([$maDP, $maDV, $soLuong, $thanhTien]);
                    header('Location: dashboard.php?tab=services&service_ok=' .
                        urlencode("Đã thêm \"{$dv['TenDV']}\" × {$soLuong} vào đặt phòng {$maDP}."));
                    exit;
                }
            }
            header('Location: dashboard.php?tab=services&service_err=' .
                urlencode('Không tìm thấy dịch vụ hoặc phòng không hợp lệ.'));
            exit;

        } elseif ($action === 'del_service') {
            // ── Xóa dịch vụ phát sinh ─────────────────────────────────────────
            $maDDV = (int)($_POST['ma_ddv'] ?? 0);
            if ($maDDV > 0) {
                $check = $pdo->prepare("
                    SELECT ddv.MaDDV FROM DAT_DICH_VU ddv
                    JOIN DAT_PHONG dp ON ddv.MaDP = dp.MaDP
                    WHERE ddv.MaDDV = :id AND dp.TrangThai = 'Đã nhận phòng'
                ");
                $check->execute([':id' => $maDDV]);
                if ($check->fetch()) {
                    $pdo->prepare("DELETE FROM DAT_DICH_VU WHERE MaDDV=:id")->execute([':id' => $maDDV]);
                    header('Location: dashboard.php?tab=services&service_ok=' .
                        urlencode('Đã xóa dịch vụ khỏi đặt phòng.'));
                    exit;
                }
            }
            header('Location: dashboard.php?tab=services&service_err=' .
                urlencode('Không thể xóa dịch vụ này.'));
            exit;
        }
    }
}

// ── Thống kê ─────────────────────────────────────────────────────────────────
$statsQ = $pdo->query("SELECT TrangThai, COUNT(*) as cnt FROM DAT_PHONG GROUP BY TrangThai");
$statsRaw = $statsQ->fetchAll(PDO::FETCH_KEY_PAIR);
$stats = [
    'cho'   => $statsRaw['Chờ xác nhận']  ?? 0,
    'dang'  => $statsRaw['Đã nhận phòng'] ?? 0,
    'tra'   => $statsRaw['Đã trả phòng']  ?? 0,
    'huy'   => $statsRaw['Đã hủy']        ?? 0,
];
$stats['total'] = array_sum($stats);

$todayQ = $pdo->prepare("SELECT COUNT(*) FROM DAT_PHONG WHERE DATE(NgayDat)=CURDATE()");
$todayQ->execute();
$stats['today'] = $todayQ->fetchColumn();

// ── Lấy danh sách đặt phòng — tìm kiếm + phân trang ─────────────────────────
$filterStatus  = $_GET['status'] ?? 'all';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 20;
$allowedStatus = ['Chờ xác nhận','Đã nhận phòng','Đã trả phòng','Đã hủy'];

$bkWhere  = [];
$bkParams = [];
if ($filterStatus !== 'all' && in_array($filterStatus, $allowedStatus)) {
    $bkWhere[]       = "dp.TrangThai = :st";
    $bkParams[':st'] = $filterStatus;
}
if ($search !== '') {
    $bkWhere[]       = "(kh.HoTen LIKE :q1 OR kh.SoDienThoai LIKE :q2 OR dp.MaDP LIKE :q3 OR dp.MaPhong LIKE :q4)";
    $s = "%$search%";
    $bkParams[':q1'] = $bkParams[':q2'] = $bkParams[':q3'] = $bkParams[':q4'] = $s;
}
$bkWhereSQL = $bkWhere ? "WHERE " . implode(" AND ", $bkWhere) : "";

// Đếm tổng
$cntQ = $pdo->prepare("SELECT COUNT(DISTINCT dp.MaDP)
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    $bkWhereSQL");
$cntQ->execute($bkParams);
$bkTotal = (int)$cntQ->fetchColumn();
$bkPages = max(1, (int)ceil($bkTotal / $perPage));
$page    = min($page, $bkPages);
$bkOff   = ($page - 1) * $perPage;

// Lấy dữ liệu
$bkQ = $pdo->prepare("
    SELECT dp.*, kh.HoTen AS TenKH, kh.SoDienThoai AS SdtKH,
           p.LoaiPhong, p.Tang, p.GiaPhong,
           nv2.HoTen AS TenNV,
           GROUP_CONCAT(dv.TenDV SEPARATOR ', ') AS DichVu
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    LEFT JOIN NHAN_VIEN nv2 ON dp.MaNV_XuLy = nv2.MaNV
    LEFT JOIN DAT_DICH_VU ddv ON dp.MaDP = ddv.MaDP
    LEFT JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    $bkWhereSQL
    GROUP BY dp.MaDP
    ORDER BY FIELD(dp.TrangThai,'Chờ xác nhận','Đã nhận phòng','Đã trả phòng','Đã hủy'), dp.NgayCheckIn
    LIMIT :lim OFFSET :off");
foreach ($bkParams as $k => $v) $bkQ->bindValue($k, $v);
$bkQ->bindValue(':lim', $perPage, PDO::PARAM_INT);
$bkQ->bindValue(':off', $bkOff,  PDO::PARAM_INT);
$bkQ->execute();
$bookings = $bkQ->fetchAll();

// URL builder cho pagination — giữ nguyên filter + search
$bkUrl = fn(array $ov = []) => '?' . http_build_query(array_merge(
    ['tab' => 'bookings', 'status' => $filterStatus, 'search' => $search],
    $ov
));

// ── Dịch vụ phát sinh — phòng đang có khách ──────────────────────────────────
$activeQ = $pdo->query("
    SELECT dp.MaDP, dp.MaPhong, dp.NgayCheckIn, dp.NgayCheckOut, dp.TienCoc, dp.TongGia,
           kh.HoTen AS TenKH, kh.SoDienThoai AS SdtKH,
           p.LoaiPhong, p.Tang, p.GiaPhong
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH = kh.MaKH
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    WHERE dp.TrangThai = 'Đã nhận phòng'
    ORDER BY dp.NgayCheckIn
");
$activeBookings = $activeQ->fetchAll();

$activeDVMap = [];
if ($activeBookings) {
    $activeMaDP = array_column($activeBookings, 'MaDP');
    $ph = implode(',', array_fill(0, count($activeMaDP), '?'));
    $dvListQ = $pdo->prepare("
        SELECT ddv.MaDDV, ddv.MaDP, ddv.MaDV, ddv.SoLuong, ddv.ThanhTien, dv.TenDV
        FROM DAT_DICH_VU ddv
        JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
        WHERE ddv.MaDP IN ($ph)
        ORDER BY ddv.NgayDat
    ");
    $dvListQ->execute($activeMaDP);
    foreach ($dvListQ->fetchAll() as $row) {
        $activeDVMap[$row['MaDP']][] = $row;
    }
}

$allSvcsAvail = getAllServices($pdo);  // dùng cho dropdown thêm DV

// ── Danh sách phòng ──────────────────────────────────────────────────────────
$rooms = getAllRooms($pdo);

$statusColor = [
    'Chờ xác nhận' => '#f59e0b',
    'Đã nhận phòng'=> '#3b82f6',
    'Đã trả phòng' => '#10b981',
    'Đã hủy'       => '#6b7280',
];
$roomColor = [
    'Trống'    => ['bg'=>'#eff6ff','border'=>'#3b82f6','text'=>'#1d4ed8'],
    'Đang ở'   => ['bg'=>'#fff1f2','border'=>'#ef4444','text'=>'#dc2626'],
    'Đang dọn' => ['bg'=>'#fffbeb','border'=>'#f59e0b','text'=>'#d97706'],
    'Bảo trì'  => ['bg'=>'#f9fafb','border'=>'#6b7280','text'=>'#6b7280'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Nhân Viên — Easyhome</title>
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

/* TOP BAR */
.topbar{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  padding:0 5%;display:flex;align-items:center;justify-content:space-between;
  height:60px;box-shadow:0 2px 16px rgba(29,78,216,.3);position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.brand img{width:34px;height:34px;border-radius:8px;object-fit:cover;border:2px solid rgba(255,255,255,.4)}
.brand-name{font-family:var(--serif);font-size:1.1rem;color:#fff;letter-spacing:.5px}
.brand-badge{background:rgba(255,255,255,.2);color:#fff;font-size:.62rem;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;padding:3px 8px;border-radius:4px;margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:10px}
.staff-info{text-align:right}
.staff-name{font-size:.83rem;color:#fff;font-weight:700}
.staff-role{font-size:.68rem;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:1px}
.btn-logout{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:6px 14px;border-radius:7px;font-size:.76rem;font-weight:600;
  cursor:pointer;text-decoration:none;transition:all .2s}
.btn-logout:hover{background:rgba(255,255,255,.28)}

/* MAIN */
.main{max-width:1100px;margin:0 auto;padding:28px 20px 60px}

/* ALERT */
.alert{padding:12px 16px;border-radius:10px;font-size:.87rem;line-height:1.5;margin-bottom:22px}
.alert-success{background:#f0fdf4;border:1.5px solid #86efac;border-left:4px solid #22c55e;color:#166534}
.alert-error{background:#fff0f0;border:1.5px solid #fca5a5;border-left:4px solid #ef4444;color:#dc2626}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:28px}
.stat-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  padding:18px 16px;text-align:center;box-shadow:var(--shadow);transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-num{font-size:2rem;font-weight:700;line-height:1;margin-bottom:5px}
.stat-label{font-size:.69rem;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}

/* TABS */
.tabs{display:flex;gap:4px;background:#fff;padding:5px;border-radius:13px;
  border:1.5px solid var(--border);box-shadow:var(--shadow);margin-bottom:24px;width:fit-content}
.tab-btn{padding:9px 22px;text-align:center;cursor:pointer;border:none;background:none;
  font-size:.8rem;font-weight:700;letter-spacing:.4px;color:var(--muted);
  border-radius:9px;transition:all .22s;display:flex;align-items:center;gap:6px}
.tab-btn:hover{background:var(--blue-pale);color:var(--blue)}
.tab-btn.active{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  color:#fff;box-shadow:0 3px 14px rgba(29,78,216,.3)}
.tab-content{display:none}
.tab-content.active{display:block;animation:fadeIn .28s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}

/* CARD */
.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.card-head{padding:16px 20px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;background:var(--blue-pale)}
.card-head-left{display:flex;align-items:center;gap:9px}
.card-head-icon{font-size:1.1rem}
.card-head-title{font-weight:700;font-size:.92rem;color:var(--blue-dark)}
.card-body{padding:20px}

/* PROFILE */
.nv-hero{display:flex;align-items:center;gap:20px;padding:22px;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:var(--radius);margin-bottom:18px;color:#fff}
.nv-avatar{width:64px;height:64px;background:rgba(255,255,255,.2);border-radius:50%;
  display:flex;align-items:center;justify-content:center;font-size:1.9rem;
  border:3px solid rgba(255,255,255,.4);flex-shrink:0}
.nv-name{font-family:var(--serif);font-size:1.25rem;font-weight:600}
.nv-sub{font-size:.74rem;color:rgba(255,255,255,.7);margin-top:3px;letter-spacing:.5px}
.nv-tag{margin-top:8px;background:rgba(255,255,255,.15);border-radius:20px;
  padding:4px 14px;font-size:.76rem;display:inline-flex;align-items:center;gap:5px}
.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:13px}
.info-item{padding:13px 15px;background:var(--blue-pale);border-radius:10px;border:1px solid var(--border)}
.info-label{font-size:.63rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.info-val{font-size:.9rem;font-weight:600}

/* FILTER */
.filter-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;align-items:center}
.filter-label{font-size:.72rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.flt-btn{padding:7px 16px;border-radius:20px;border:1.5px solid var(--border);background:#fff;
  font-size:.77rem;font-weight:700;color:var(--muted);cursor:pointer;text-decoration:none;
  transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.flt-btn:hover{border-color:var(--blue-light);color:var(--blue);background:var(--blue-pale)}
.flt-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}
.flt-count{background:rgba(255,255,255,.25);border-radius:10px;padding:1px 7px;font-size:.68rem}
.flt-btn:not(.active) .flt-count{background:var(--blue-mid);color:var(--blue-dark)}

/* BOOKING TABLE */
.bk-table{width:100%;border-collapse:collapse;font-size:.83rem}
.bk-table th{background:var(--blue-pale);padding:11px 13px;text-align:left;
  font-size:.66rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--muted);border-bottom:1.5px solid var(--border)}
.bk-table td{padding:12px 13px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.bk-table tr:last-child td{border-bottom:none}
.bk-table tr:hover td{background:#f8fbff}

.status-badge{padding:3px 10px;border-radius:20px;font-size:.69rem;font-weight:700;
  color:#fff;white-space:nowrap;display:inline-block}
.guest-name{font-weight:700;font-size:.88rem;margin-bottom:2px}
.guest-sdt{font-size:.74rem;color:var(--muted)}
.room-tag{font-weight:700;color:var(--blue-dark)}
.room-floor{font-size:.73rem;color:var(--muted);margin-top:1px}
.time-main{font-weight:600}
.time-sub{font-size:.73rem;color:var(--muted);margin-top:1px}
.svc-text{font-size:.73rem;color:var(--muted);max-width:140px;line-height:1.4}

.actions{display:flex;flex-direction:column;gap:5px;min-width:120px}
.btn-action{padding:6px 12px;border-radius:7px;border:none;font-size:.74rem;font-weight:700;
  cursor:pointer;transition:all .2s;text-align:center;white-space:nowrap}
.btn-confirm{background:#dcfce7;color:#166534;border:1.5px solid #86efac}
.btn-confirm:hover{background:#bbf7d0}
.btn-checkout{background:#dbeafe;color:#1d4ed8;border:1.5px solid #93c5fd}
.btn-checkout:hover{background:#bfdbfe}
.btn-cancel{background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5}
.btn-cancel:hover{background:#fecaca}

.empty-row td{text-align:center;padding:40px;color:var(--muted);font-size:.88rem}

/* ROOM DIAGRAM */
.floor-label{font-size:.7rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:var(--muted);margin:20px 0 10px;display:flex;align-items:center;gap:8px}
.floor-label::after{content:'';flex:1;height:1px;background:var(--border)}
.room-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:4px}
.room-cell{border-radius:10px;padding:14px 12px;border:2px solid;text-align:center;
  transition:transform .2s;cursor:default}
.room-cell:hover{transform:translateY(-2px)}
.room-num{font-size:1.1rem;font-weight:700;margin-bottom:4px}
.room-type{font-size:.7rem;font-weight:600;letter-spacing:.5px;margin-bottom:5px;opacity:.8}
.room-status{font-size:.68rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;
  padding:3px 9px;border-radius:10px;display:inline-block;background:rgba(255,255,255,.5)}
.room-price{font-size:.72rem;margin-top:5px;opacity:.75;font-weight:600}
.legend{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.legend-item{display:flex;align-items:center;gap:6px;font-size:.76rem;font-weight:600;color:var(--muted)}
.legend-dot{width:12px;height:12px;border-radius:3px}
.clean-form{display:inline}

/* Dọn phòng button in room grid */
.btn-clean{background:none;border:1.5px solid;border-radius:6px;padding:3px 10px;
  font-size:.68rem;font-weight:700;cursor:pointer;margin-top:6px;transition:all .2s}

/* ── DỊCH VỤ PHÁT SINH ── */
.svc-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px}
.svc-room-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);overflow:hidden;transition:box-shadow .2s}
.svc-room-card:hover{box-shadow:var(--shadow-lg)}
.svc-room-head{padding:13px 16px;background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  display:flex;align-items:center;gap:10px;color:#fff}
.svc-room-num{font-size:1.05rem;font-weight:700;font-family:var(--serif)}
.svc-room-info{flex:1;min-width:0}
.svc-room-guest{font-size:.82rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.svc-room-sub{font-size:.69rem;color:rgba(255,255,255,.7);margin-top:1px}
.svc-room-badge{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);
  color:#fff;font-size:.65rem;font-weight:700;padding:3px 9px;border-radius:20px;flex-shrink:0}
.svc-room-body{padding:14px 16px}
.svc-list-title{font-size:.66rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--muted);margin-bottom:8px}
.svc-list{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;min-height:28px}
.svc-item{display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--blue-pale);
  border-radius:7px;border:1px solid var(--border)}
.svc-item-name{flex:1;font-size:.8rem;font-weight:600;color:var(--text)}
.svc-item-qty{font-size:.73rem;color:var(--muted);white-space:nowrap}
.svc-item-price{font-size:.8rem;font-weight:700;color:var(--blue-dark);white-space:nowrap;margin-left:4px}
.btn-del-svc{background:#fee2e2;border:1px solid #fca5a5;color:#dc2626;
  padding:3px 8px;border-radius:5px;font-size:.68rem;font-weight:700;cursor:pointer;
  flex-shrink:0;transition:.2s;line-height:1}
.btn-del-svc:hover{background:#fecaca}
.svc-empty{font-size:.77rem;color:var(--muted);font-style:italic;padding:4px 2px}
.svc-total{display:flex;justify-content:space-between;align-items:center;
  background:var(--blue-mid);border-radius:7px;padding:8px 10px;margin-bottom:13px;
  font-size:.8rem;font-weight:700;color:var(--blue-dark)}
.svc-add-form{display:flex;gap:7px;align-items:center;flex-wrap:wrap;
  background:#f8faff;border:1.5px dashed var(--border);border-radius:8px;padding:10px 12px}
.svc-add-select{flex:1;min-width:130px;padding:7px 10px;border:1.5px solid var(--border);
  border-radius:7px;font-size:.8rem;font-family:var(--font);color:var(--text);
  background:#fff;outline:none;cursor:pointer;transition:.2s}
.svc-add-select:focus{border-color:var(--blue)}
.svc-add-qty{width:60px;padding:7px 8px;border:1.5px solid var(--border);border-radius:7px;
  font-size:.8rem;font-family:var(--font);text-align:center;outline:none;transition:.2s}
.svc-add-qty:focus{border-color:var(--blue)}
.btn-add-svc{padding:7px 14px;background:var(--blue);color:#fff;border:none;
  border-radius:7px;font-size:.78rem;font-weight:700;cursor:pointer;transition:.2s;
  white-space:nowrap;font-family:var(--font)}
.btn-add-svc:hover{background:var(--blue-dark);transform:translateY(-1px)}
.svc-empty-state{text-align:center;padding:48px 20px;color:var(--muted)}
.svc-empty-state .es-icon{font-size:2.8rem;margin-bottom:12px}
.svc-empty-state p{font-size:.88rem;line-height:1.6}

@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(3,1fr)}
  .info-grid{grid-template-columns:1fr 1fr}
  .bk-table{display:block;overflow-x:auto}
  .svc-cards{grid-template-columns:1fr}
}

/* ── SEARCH + PAGINATION ── */
.search-row{display:flex;gap:8px;margin-bottom:14px;align-items:center}
.search-wrap{flex:1;position:relative;display:flex;align-items:center}
.search-icon{position:absolute;left:11px;font-size:.88rem;pointer-events:none;color:var(--muted)}
.search-input{width:100%;padding:9px 40px 9px 34px;border:1.5px solid var(--border);
  border-radius:9px;font-size:.85rem;font-family:var(--font);background:var(--blue-pale);
  outline:none;transition:all .2s}
.search-input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.1)}
.search-clear{position:absolute;right:9px;font-size:.75rem;color:var(--muted);text-decoration:none;
  padding:2px 7px;border-radius:5px;background:var(--blue-mid);transition:all .2s;font-weight:700}
.search-clear:hover{background:var(--blue-light);color:#fff}
.btn-search{padding:9px 18px;background:var(--blue);color:#fff;border:none;border-radius:9px;
  font-size:.82rem;font-weight:700;cursor:pointer;font-family:var(--font);
  transition:all .2s;white-space:nowrap}
.btn-search:hover{background:var(--blue-dark)}

.pager{display:flex;align-items:center;justify-content:space-between;
  padding:12px 18px;border-top:1.5px solid var(--border);flex-wrap:wrap;gap:8px;background:#fafcff}
.pager-info{font-size:.75rem;color:var(--muted);font-weight:600}
.pager-btns{display:flex;gap:4px}
.pager-btn{padding:5px 10px;border-radius:6px;border:1.5px solid var(--border);
  background:#fff;font-size:.79rem;font-weight:700;color:var(--muted);
  text-decoration:none;transition:all .2s;min-width:32px;text-align:center;line-height:1.4}
.pager-btn:hover{border-color:var(--blue-light);color:var(--blue);background:var(--blue-pale)}
.pager-btn.active{background:var(--blue);border-color:var(--blue);color:#fff;pointer-events:none}
.pager-btn.disabled{opacity:.38;pointer-events:none;cursor:default}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <a href="../index.php" class="brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome">
    <span class="brand-name">Easyhome</span>
    <span class="brand-badge">Nhân Viên</span>
  </a>
  <div class="topbar-right">
    <div class="staff-info">
      <div class="staff-name"><?= htmlspecialchars($nv['HoTen'] ?? $staffId) ?></div>
      <div class="staff-role"><?= $nv['ChucVu'] ?? 'Lễ tân' ?> · <?= $nv['MaNV'] ?? '' ?></div>
    </div>
    <a href="../logout.php" class="btn-logout">Đăng xuất</a>
  </div>
</div>

<div class="main">

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-num" style="color:#f59e0b"><?= $stats['cho'] ?></div>
      <div class="stat-label">Chờ Xác Nhận</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#3b82f6"><?= $stats['dang'] ?></div>
      <div class="stat-label">Đang Ở</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#10b981"><?= $stats['tra'] ?></div>
      <div class="stat-label">Đã Trả Phòng</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#6b7280"><?= $stats['huy'] ?></div>
      <div class="stat-label">Đã Hủy</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:var(--blue)"><?= $stats['today'] ?></div>
      <div class="stat-label">Đặt Hôm Nay</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn <?= $tab==='bookings'?'active':'' ?>" onclick="switchTab('bookings')">
      📋 Quản Lý Đặt Phòng
    </button>
    <button class="tab-btn <?= $tab==='rooms'?'active':'' ?>" onclick="switchTab('rooms')">
      🏨 Sơ Đồ Phòng
    </button>
    <button class="tab-btn <?= $tab==='services'?'active':'' ?>" onclick="switchTab('services')">
      🛎️ Dịch Vụ Phát Sinh
      <?php if (count($activeBookings) > 0): ?>
      <span style="background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;
        padding:1px 6px;border-radius:10px;margin-left:2px"><?= count($activeBookings) ?></span>
      <?php endif; ?>
    </button>
    <button class="tab-btn <?= $tab==='profile'?'active':'' ?>" onclick="switchTab('profile')">
      👤 Thông Tin Nhân Viên
    </button>
  </div>

  <!-- ══════════════ TAB: QUẢN LÝ ĐẶT PHÒNG ══════════════ -->
  <div id="tab-bookings" class="tab-content <?= $tab==='bookings'?'active':'' ?>">

    <!-- Search -->
    <form method="GET" class="search-row">
      <input type="hidden" name="tab" value="bookings">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <div class="search-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               class="search-input" placeholder="Tìm tên khách hàng, SĐT, mã ĐP, mã phòng...">
        <?php if ($search !== ''): ?>
        <a href="<?= htmlspecialchars($bkUrl(['search' => '', 'page' => 1])) ?>" class="search-clear" title="Xoá tìm kiếm">✕</a>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn-search">🔍 Tìm</button>
    </form>

    <!-- Filter -->
    <div class="filter-bar">
      <span class="filter-label">Lọc:</span>
      <?php
      $filters = [
        'all'          => ['Tất Cả',       $stats['total']],
        'Chờ xác nhận' => ['Chờ Xác Nhận', $stats['cho']],
        'Đã nhận phòng'=> ['Đang Ở',       $stats['dang']],
        'Đã trả phòng' => ['Đã Trả Phòng', $stats['tra']],
        'Đã hủy'       => ['Đã Hủy',       $stats['huy']],
      ];
      foreach ($filters as $val => [$label, $cnt]):
        $active = ($filterStatus === $val) ? 'active' : '';
      ?>
      <a href="<?= htmlspecialchars($bkUrl(['status' => $val, 'page' => 1])) ?>" class="flt-btn <?= $active ?>">
        <?= $label ?> <span class="flt-count"><?= $cnt ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom:0">
      <div class="card-head">
        <div class="card-head-left">
          <span class="card-head-icon">📋</span>
          <span class="card-head-title">
            Danh Sách Đặt Phòng
            <?= $filterStatus !== 'all' ? "— <em style='font-weight:400;color:var(--muted)'>" . htmlspecialchars($filterStatus) . "</em>" : '' ?>
            <?= $search !== '' ? "— <em style='font-weight:400;color:var(--blue)'>\"" . htmlspecialchars($search) . "\"</em>" : '' ?>
          </span>
        </div>
        <span style="font-size:.75rem;color:var(--muted)"><?= $bkTotal ?> bản ghi</span>
      </div>
      <div style="overflow-x:auto">
        <table class="bk-table">
          <thead>
            <tr>
              <th>Mã ĐP</th>
              <th>Khách Hàng</th>
              <th>Phòng</th>
              <th>Check-in</th>
              <th>Check-out</th>
              <th>Dịch Vụ</th>
              <th>Tổng Tiền</th>
              <th>Trạng Thái</th>
              <th>Thao Tác</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$bookings): ?>
            <tr class="empty-row"><td colspan="9">📭 Không có đặt phòng nào</td></tr>
          <?php endif; ?>
          <?php foreach ($bookings as $bk): ?>
          <tr>
            <td>
              <strong style="color:var(--blue-dark)"><?= $bk['MaDP'] ?></strong><br>
              <span style="font-size:.71rem;color:var(--muted);"><?= date('d/m H:i', strtotime($bk['NgayDat'])) ?></span>
            </td>
            <td>
              <div class="guest-name"><?= htmlspecialchars($bk['TenKH']) ?></div>
              <div class="guest-sdt"><?= htmlspecialchars($bk['SdtKH'] ?? '') ?></div>
              <div style="font-size:.71rem;color:var(--muted)"><?= $bk['SoLuongKhach'] ?> khách</div>
            </td>
            <td>
              <div class="room-tag"><?= $bk['MaPhong'] ?></div>
              <div class="room-floor"><?= $bk['LoaiPhong'] ?> · Tầng <?= $bk['Tang'] ?></div>
            </td>
            <td>
              <div class="time-main"><?= date('d/m/Y', strtotime($bk['NgayCheckIn'])) ?></div>
              <div class="time-sub"><?= date('H:i', strtotime($bk['NgayCheckIn'])) ?></div>
            </td>
            <td>
              <div class="time-main"><?= date('d/m/Y', strtotime($bk['NgayCheckOut'])) ?></div>
              <div class="time-sub"><?= date('H:i', strtotime($bk['NgayCheckOut'])) ?></div>
            </td>
            <td>
              <div class="svc-text"><?= $bk['DichVu'] ? htmlspecialchars($bk['DichVu']) : '<span style="color:#94a3b8">—</span>' ?></div>
            </td>
            <td>
              <strong style="color:var(--blue-dark)"><?= number_format($bk['TongGia'],0,',','.') ?>đ</strong><br>
              <span style="font-size:.71rem;color:var(--muted)">Cọc: <?= number_format($bk['TienCoc'],0,',','.') ?>đ</span>
            </td>
            <td>
              <span class="status-badge" style="background:<?= $statusColor[$bk['TrangThai']] ?? '#6b7280' ?>">
                <?= $bk['TrangThai'] ?>
              </span>
              <?php if ($bk['TenNV']): ?>
              <div style="font-size:.7rem;color:var(--muted);margin-top:3px">NV: <?= htmlspecialchars($bk['TenNV']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <?php if ($bk['TrangThai'] === 'Chờ xác nhận'): ?>
                <a href="checkin.php?dp=<?= urlencode($bk['MaDP']) ?>"
                   class="btn-action btn-confirm" style="text-decoration:none;display:block;text-align:center">
                  ✓ Check-in
                </a>
                <form method="POST" onsubmit="return confirm('Hủy đặt phòng <?= $bk['MaDP'] ?>?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="huy">
                  <input type="hidden" name="ma_dp" value="<?= $bk['MaDP'] ?>">
                  <button type="submit" class="btn-action btn-cancel">✕ Hủy</button>
                </form>
                <?php elseif ($bk['TrangThai'] === 'Đã nhận phòng'): ?>
                <a href="checkout.php?dp=<?= urlencode($bk['MaDP']) ?>"
                   class="btn-action btn-checkout" style="text-decoration:none;display:block;text-align:center">
                  ⬆ Check-out
                </a>
                <?php elseif ($bk['TrangThai'] === 'Đã trả phòng'): ?>
                <a href="checkout.php?dp=<?= urlencode($bk['MaDP']) ?>&done=1"
                   class="btn-action" style="text-decoration:none;display:block;text-align:center;
                   background:#f0fdf4;border:1.5px solid #86efac;color:#166534">
                  🧾 Hóa đơn
                </a>
                <?php else: ?>
                <span style="font-size:.73rem;color:#94a3b8">—</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <div class="pager">
        <span class="pager-info">
          <?php if ($bkTotal === 0): ?>
            Không có kết quả<?= $search ? " cho \"" . htmlspecialchars($search) . "\"" : '' ?>
          <?php else: ?>
            Hiển thị <?= $bkOff + 1 ?>–<?= min($bkOff + $perPage, $bkTotal) ?> / <?= $bkTotal ?> đặt phòng
          <?php endif; ?>
        </span>
        <?php if ($bkPages > 1): ?>
        <div class="pager-btns">
          <a href="<?= htmlspecialchars($bkUrl(['page' => $page - 1])) ?>"
             class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
          <?php
          $pStart = max(1, $page - 2);
          $pEnd   = min($bkPages, $page + 2);
          if ($pStart > 1) echo '<span class="pager-btn disabled">…</span>';
          for ($p = $pStart; $p <= $pEnd; $p++):
          ?>
          <a href="<?= htmlspecialchars($bkUrl(['page' => $p])) ?>"
             class="pager-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor;
          if ($pEnd < $bkPages) echo '<span class="pager-btn disabled">…</span>'; ?>
          <a href="<?= htmlspecialchars($bkUrl(['page' => $page + 1])) ?>"
             class="pager-btn <?= $page >= $bkPages ? 'disabled' : '' ?>">›</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ══════════════ TAB: SƠ ĐỒ PHÒNG ══════════════ -->
  <div id="tab-rooms" class="tab-content <?= $tab==='rooms'?'active':'' ?>">

    <div class="legend">
      <?php foreach ($roomColor as $st => $c): ?>
      <div class="legend-item">
        <div class="legend-dot" style="background:<?= $c['border'] ?>"></div>
        <?= $st ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php
    $floors = [];
    foreach ($rooms as $r) $floors[$r['Tang']][] = $r;
    ksort($floors);
    foreach ($floors as $floor => $floorRooms):
    ?>
    <div class="floor-label">Tầng <?= $floor ?></div>
    <div class="room-grid">
      <?php foreach ($floorRooms as $r):
        $rc = $roomColor[$r['TinhTrang']] ?? $roomColor['Bảo trì'];
      ?>
      <div class="room-cell" style="background:<?= $rc['bg'] ?>;border-color:<?= $rc['border'] ?>;color:<?= $rc['text'] ?>">
        <div class="room-num"><?= $r['MaPhong'] ?></div>
        <div class="room-type"><?= $r['LoaiPhong'] ?></div>
        <div class="room-status"><?= $r['TinhTrang'] ?></div>
        <div class="room-price"><?= number_format($r['GiaPhong']/1000) ?>k/đêm</div>
        <?php if ($r['TinhTrang'] === 'Đang dọn'): ?>
        <form method="POST" class="clean-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="phong_sach">
          <input type="hidden" name="ma_dp" value="<?= $r['MaPhong'] ?>">
          <button type="submit" class="btn-clean" style="border-color:<?= $rc['border'] ?>;color:<?= $rc['text'] ?>"
                  onclick="return confirm('Xác nhận phòng <?= $r['MaPhong'] ?> đã dọn xong?')">
            ✓ Dọn xong
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

  </div>

  <!-- ══════════════ TAB: DỊCH VỤ PHÁT SINH ══════════════ -->
  <div id="tab-services" class="tab-content <?= $tab==='services'?'active':'' ?>">

    <div class="card" style="margin-bottom:20px">
      <div class="card-head">
        <div class="card-head-left">
          <span class="card-head-icon">🛎️</span>
          <span class="card-head-title">Dịch Vụ Phát Sinh — Phòng Đang Có Khách</span>
        </div>
        <span style="font-size:.75rem;color:var(--muted)">
          <?= count($activeBookings) ?> phòng đang ở
        </span>
      </div>
      <div class="card-body">

        <?php if (!$activeBookings): ?>
        <div class="svc-empty-state">
          <div class="es-icon">🏨</div>
          <p><strong>Hiện không có phòng nào đang có khách</strong><br>
             Các dịch vụ phát sinh chỉ có thể thêm khi khách đã check-in.</p>
        </div>
        <?php else: ?>

        <p style="font-size:.79rem;color:var(--muted);margin-bottom:16px;line-height:1.6">
          💡 Chọn dịch vụ và số lượng rồi bấm <strong>+ Thêm</strong> để ghi vào đơn đặt phòng.
          Dịch vụ sẽ được tính vào hóa đơn khi khách check-out.
        </p>

        <div class="svc-cards">
        <?php foreach ($activeBookings as $ab):
          $ciDate  = date('d/m H:i', strtotime($ab['NgayCheckIn']));
          $coDate  = date('d/m H:i', strtotime($ab['NgayCheckOut']));
          $nights  = max(1,(int)ceil((strtotime($ab['NgayCheckOut'])-strtotime($ab['NgayCheckIn']))/86400));
          $dvItems = $activeDVMap[$ab['MaDP']] ?? [];
          $dvTotal = array_sum(array_column($dvItems, 'ThanhTien'));
        ?>
        <div class="svc-room-card">
          <!-- HEADER -->
          <div class="svc-room-head">
            <div style="font-size:1.4rem">🛏️</div>
            <div class="svc-room-info">
              <div class="svc-room-guest"><?= htmlspecialchars($ab['TenKH']) ?></div>
              <div class="svc-room-sub">
                <?= htmlspecialchars($ab['SdtKH'] ?? '') ?> &bull;
                CI: <?= $ciDate ?> → CO: <?= $coDate ?> (<?= $nights ?> đêm)
              </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
              <span class="svc-room-num"><?= $ab['MaPhong'] ?></span>
              <span class="svc-room-badge"><?= $ab['LoaiPhong'] ?> · T<?= $ab['Tang'] ?></span>
            </div>
          </div>

          <!-- BODY -->
          <div class="svc-room-body">

            <!-- Danh sách DV đã thêm -->
            <div class="svc-list-title">Dịch vụ đã thêm</div>
            <div class="svc-list">
              <?php if (!$dvItems): ?>
              <div class="svc-empty">Chưa có dịch vụ phát sinh nào</div>
              <?php else: ?>
              <?php foreach ($dvItems as $di): ?>
              <div class="svc-item">
                <div class="svc-item-name">
                  <?= htmlspecialchars($di['TenDV']) ?>
                </div>
                <div class="svc-item-qty">× <?= $di['SoLuong'] ?></div>
                <div class="svc-item-price"><?= number_format($di['ThanhTien'],0,',','.') ?>đ</div>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Xóa dịch vụ này khỏi đặt phòng <?= $ab['MaDP'] ?>?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action"  value="del_service">
                  <input type="hidden" name="ma_dp"   value="<?= $ab['MaDP'] ?>">
                  <input type="hidden" name="ma_ddv"  value="<?= $di['MaDDV'] ?>">
                  <button type="submit" class="btn-del-svc" title="Xóa dịch vụ này">✕</button>
                </form>
              </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <!-- Tổng DV (chỉ hiện nếu có) -->
            <?php if ($dvItems): ?>
            <div class="svc-total">
              <span>🧾 Tổng dịch vụ phát sinh:</span>
              <span><?= number_format($dvTotal,0,',','.') ?>đ</span>
            </div>
            <?php endif; ?>

            <!-- Form thêm DV mới -->
            <div class="svc-list-title" style="margin-top:4px">Thêm dịch vụ</div>
            <form method="POST" class="svc-add-form">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="add_service">
              <input type="hidden" name="ma_dp"  value="<?= $ab['MaDP'] ?>">
              <select name="ma_dv" required class="svc-add-select">
                <option value="" disabled selected>— Chọn dịch vụ —</option>
                <?php foreach ($allSvcsAvail as $sv): ?>
                <option value="<?= htmlspecialchars($sv['MaDV']) ?>">
                  <?= htmlspecialchars($sv['TenDV']) ?>
                  (<?= number_format($sv['GiaDV'],0,',','.')  ?>đ)
                </option>
                <?php endforeach; ?>
              </select>
              <input type="number" name="so_luong" value="1" min="1" max="99"
                     class="svc-add-qty" title="Số lượng">
              <button type="submit" class="btn-add-svc">+ Thêm</button>
            </form>

          </div><!-- /body -->
        </div><!-- /svc-room-card -->
        <?php endforeach; ?>
        </div><!-- /svc-cards -->

        <?php endif; ?>
      </div><!-- /card-body -->
    </div><!-- /card -->

  </div><!-- /tab-services -->

  <!-- ══════════════ TAB: THÔNG TIN NHÂN VIÊN ══════════════ -->
  <div id="tab-profile" class="tab-content <?= $tab==='profile'?'active':'' ?>">

    <?php if ($nv): ?>
    <div class="nv-hero">
      <div class="nv-avatar">👨‍💼</div>
      <div>
        <div class="nv-name"><?= htmlspecialchars($nv['HoTen']) ?></div>
        <div class="nv-sub">Tài khoản: <?= htmlspecialchars($staffId) ?> · <?= $nv['VaiTro'] === 'admin' ? 'Quản lý' : 'Nhân viên' ?></div>
        <div class="nv-tag">🏷️ <?= htmlspecialchars($nv['ChucVu']) ?></div>
      </div>
      <a href="profile.php" style="margin-left:auto;flex-shrink:0;background:rgba(255,255,255,.2);
         border:1.5px solid rgba(255,255,255,.35);color:#fff;padding:8px 16px;border-radius:9px;
         font-size:.79rem;font-weight:700;text-decoration:none;transition:all .2s;
         display:inline-flex;align-items:center;gap:6px"
         onmouseover="this.style.background='rgba(255,255,255,.32)'"
         onmouseout="this.style.background='rgba(255,255,255,.2)'">
        ✏️ Chỉnh sửa hồ sơ
      </a>
    </div>
    <div class="card">
      <div class="card-head">
        <div class="card-head-left">
          <span class="card-head-icon">📋</span>
          <span class="card-head-title">Thông Tin Chi Tiết</span>
        </div>
      </div>
      <div class="card-body">
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Mã Nhân Viên</div>
            <div class="info-val"><?= $nv['MaNV'] ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Họ và Tên</div>
            <div class="info-val"><?= htmlspecialchars($nv['HoTen']) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Chức Vụ</div>
            <div class="info-val"><?= htmlspecialchars($nv['ChucVu']) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Số Điện Thoại</div>
            <div class="info-val"><?= htmlspecialchars($nv['SoDienThoai'] ?? '—') ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Email</div>
            <div class="info-val"><?= htmlspecialchars($nv['Email'] ?? '—') ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Ngày Vào Làm</div>
            <div class="info-val"><?= $nv['NgayVaoLam'] ? date('d/m/Y', strtotime($nv['NgayVaoLam'])) : '—' ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Trạng Thái</div>
            <div class="info-val" style="color:#16a34a">● <?= htmlspecialchars($nv['TrangThai']) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Đăng Nhập Cuối</div>
            <div class="info-val"><?= $nv['LanDangNhapCuoi'] ? date('d/m/Y H:i', strtotime($nv['LanDangNhapCuoi'])) : '—' ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Đã Xử Lý</div>
            <div class="info-val" style="color:var(--blue)">
              <?php
              $handled = $pdo->prepare("SELECT COUNT(*) FROM DAT_PHONG WHERE MaNV_XuLy=:nv");
              $handled->execute([':nv' => $nv['MaNV']]);
              echo $handled->fetchColumn() . ' đặt phòng';
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:40px;color:var(--muted)">
      Không tìm thấy thông tin nhân viên. Tài khoản: <?= htmlspecialchars($staffId) ?>
    </div>
    <?php endif; ?>

  </div>

</div><!-- /main -->

<script>
function switchTab(name) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  const idx = ['bookings','rooms','services','profile'].indexOf(name);
  if (idx >= 0) document.querySelectorAll('.tab-btn')[idx].classList.add('active');
}
</script>
</body>
</html>
