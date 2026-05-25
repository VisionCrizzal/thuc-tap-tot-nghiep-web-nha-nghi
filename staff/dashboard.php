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

// ── Lấy danh sách đặt phòng ──────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$sql = "
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
";
if ($filterStatus !== 'all') {
    $sql .= " WHERE dp.TrangThai = :st";
}
$sql .= " GROUP BY dp.MaDP ORDER BY FIELD(dp.TrangThai,'Chờ xác nhận','Đã nhận phòng','Đã trả phòng','Đã hủy'), dp.NgayCheckIn";

$bkQ = $pdo->prepare($sql);
if ($filterStatus !== 'all') $bkQ->execute([':st' => $filterStatus]);
else $bkQ->execute();
$bookings = $bkQ->fetchAll();

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

@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(3,1fr)}
  .info-grid{grid-template-columns:1fr 1fr}
  .bk-table{display:block;overflow-x:auto}
}
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
    <button class="tab-btn <?= $tab==='profile'?'active':'' ?>" onclick="switchTab('profile')">
      👤 Thông Tin Nhân Viên
    </button>
  </div>

  <!-- ══════════════ TAB: QUẢN LÝ ĐẶT PHÒNG ══════════════ -->
  <div id="tab-bookings" class="tab-content <?= $tab==='bookings'?'active':'' ?>">

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
      <a href="?tab=bookings&status=<?= urlencode($val) ?>" class="flt-btn <?= $active ?>">
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
            <?= $filterStatus !== 'all' ? "— <em style='font-weight:400;color:var(--muted)'>{$filterStatus}</em>" : '' ?>
          </span>
        </div>
        <span style="font-size:.75rem;color:var(--muted)"><?= count($bookings) ?> bản ghi</span>
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
  const idx = ['bookings','rooms','profile'].indexOf(name);
  if (idx >= 0) document.querySelectorAll('.tab-btn')[idx].classList.add('active');
}
</script>
</body>
</html>
