<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['kh_id'])) {
    header('Location: ../login.php'); exit;
}

$khId   = $_SESSION['kh_id'];
$khName = $_SESSION['kh_name'];
$tab    = $_GET['tab'] ?? 'profile';
$msg    = '';
$msgType = 'success';

// Pre-fill từ rooms.php (GET params khi click "Đặt Ngay")
$prefillType    = trim($_GET['room_type'] ?? '');
$prefillCheckin = trim($_GET['checkin']   ?? '');
$prefillCheckout= trim($_GET['checkout']  ?? '');

// ── Lấy thông tin khách hàng ────────────────────────────────────────────────
$kh = $pdo->prepare("SELECT * FROM KHACH_HANG WHERE MaKH = :id");
$kh->execute([':id' => $khId]);
$kh = $kh->fetch();

// ── Lấy danh sách dịch vụ ──────────────────────────────────────────────────
$services = $pdo->query("SELECT * FROM DICH_VU WHERE TrangThai='Khả dụng' ORDER BY GiaDV")->fetchAll();

// ── Khuyến mãi đang hoạt động ──────────────────────────────────────────────
$promos = $pdo->query("
    SELECT * FROM KHUYEN_MAI
    WHERE TrangThai='Đang áp dụng' AND NgayBatDau<=CURDATE() AND NgayKetThuc>=CURDATE()
    ORDER BY GiaTriKM DESC
")->fetchAll();

// ── Giá phòng theo loại ─────────────────────────────────────────────────────
$roomPrices = [
    'Đơn'      => ['min'=>500000, 'max'=>550000,  'beds'=>'1 giường đơn',  'icon'=>'🛏️',  'color'=>'#3b82f6'],
    'Đôi'      => ['min'=>800000, 'max'=>900000,  'beds'=>'2 giường / đôi','icon'=>'🛏️🛏️','color'=>'#8b5cf6'],
    'Gia đình' => ['min'=>1500000,'max'=>1500000, 'beds'=>'Phòng gia đình','icon'=>'👨‍👩‍👧', 'color'=>'#10b981'],
    'VIP'      => ['min'=>2500000,'max'=>2800000, 'beds'=>'Phòng cao cấp',  'icon'=>'👑',  'color'=>'#f59e0b'],
];

// ── Xử lý POST: Đặt phòng ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dat_phong') {
    $tab = 'booking';
    $ciDate  = $_POST['checkin_date']  ?? '';
    $ciTime  = $_POST['checkin_time']  ?? '14:00';
    $coDate  = $_POST['checkout_date'] ?? '';
    $coTime  = $_POST['checkout_time'] ?? '12:00';
    $loai    = $_POST['loai_phong']    ?? '';
    $soKhach = max(1, intval($_POST['so_khach'] ?? 1));
    $dvChon  = $_POST['services']      ?? [];
    $ghiChu  = trim($_POST['ghi_chu']  ?? '');

    $checkin  = $ciDate . ' ' . $ciTime . ':00';
    $checkout = $coDate . ' ' . $coTime . ':00';

    if (!$ciDate || !$coDate || !$loai) {
        $msg = 'Vui lòng chọn ngày check-in, check-out và loại phòng.';
        $msgType = 'error';
    } elseif (strtotime($checkin) >= strtotime($checkout)) {
        $msg = 'Thời gian check-out phải sau check-in.';
        $msgType = 'error';
    } else {
        // Tìm phòng còn trống
        $findRoom = $pdo->prepare("
            SELECT MaPhong, GiaPhong FROM PHONG
            WHERE LoaiPhong = :loai AND TinhTrang != 'Bảo trì'
              AND MaPhong NOT IN (
                SELECT MaPhong FROM DAT_PHONG
                WHERE TrangThai NOT IN ('Đã hủy','Đã trả phòng')
                  AND NgayCheckIn < :out AND NgayCheckOut > :in
              )
            LIMIT 1
        ");
        $findRoom->execute([':loai' => $loai, ':in' => $checkin, ':out' => $checkout]);
        $room = $findRoom->fetch();

        if (!$room) {
            $msg = "Không còn phòng {$loai} trống trong thời gian đã chọn. Vui lòng chọn loại phòng khác hoặc thay đổi ngày.";
            $msgType = 'error';
        } else {
            // Tính tiền
            $hours   = (strtotime($checkout) - strtotime($checkin)) / 3600;
            $days    = max(1, ceil($hours / 24));
            $tienPhong = $room['GiaPhong'] * $days;

            $tienDV = 0;
            $dvList = [];
            if ($dvChon) {
                $placeholders = implode(',', array_fill(0, count($dvChon), '?'));
                $svcQ = $pdo->prepare("SELECT MaDV, TenDV, GiaDV FROM DICH_VU WHERE MaDV IN ($placeholders)");
                $svcQ->execute($dvChon);
                $dvList = $svcQ->fetchAll();
                foreach ($dvList as $d) $tienDV += $d['GiaDV'];
            }

            // Áp dụng khuyến mãi
            $maKM    = trim($_POST['ma_km'] ?? '');
            $giamGia = 0; $kmNote = '';
            if ($maKM) {
                $kmQ = $pdo->prepare("
                    SELECT * FROM KHUYEN_MAI
                    WHERE MaKM=:km AND TrangThai='Đang áp dụng'
                      AND NgayBatDau<=CURDATE() AND NgayKetThuc>=CURDATE()
                ");
                $kmQ->execute([':km' => $maKM]);
                $kmRec = $kmQ->fetch();
                if ($kmRec) {
                    $giamGia = $kmRec['LoaiKM']==='PhanTram'
                        ? round(($tienPhong + $tienDV) * $kmRec['GiaTriKM'] / 100)
                        : min($kmRec['GiaTriKM'], $tienPhong + $tienDV);
                    $kmNote = " [GIAM:{$giamGia}][KM:{$kmRec['MaKM']} {$kmRec['TenKM']}]";
                }
            }
            $tongGia = max(0, $tienPhong + $tienDV - $giamGia);
            $tienCoc = round($tongGia * 0.3);

            // Sinh MaDP
            $lastDP = $pdo->query("SELECT MaDP FROM DAT_PHONG ORDER BY NgayDat DESC LIMIT 1")->fetchColumn();
            $dpNum  = $lastDP ? intval(preg_replace('/\D/', '', $lastDP)) + 1 : 1;
            $maDP   = 'DP' . str_pad($dpNum, 3, '0', STR_PAD_LEFT);

            // Insert DAT_PHONG
            $pdo->prepare("INSERT INTO DAT_PHONG (MaDP,MaKH,MaPhong,NgayCheckIn,NgayCheckOut,SoLuongKhach,TienCoc,TongGia,GhiChu) VALUES (:dp,:kh,:ph,:in,:out,:sk,:coc,:tong,:ghi)")
                ->execute([':dp'=>$maDP,':kh'=>$khId,':ph'=>$room['MaPhong'],':in'=>$checkin,':out'=>$checkout,':sk'=>$soKhach,':coc'=>$tienCoc,':tong'=>$tongGia,':ghi'=>$ghiChu.$kmNote]);

            // Insert DAT_DICH_VU
            foreach ($dvList as $d) {
                $pdo->prepare("INSERT INTO DAT_DICH_VU (MaDP,MaDV,SoLuong,ThanhTien) VALUES (?,?,1,?)")
                    ->execute([$maDP, $d['MaDV'], $d['GiaDV']]);
            }

            $msg = "Đặt phòng thành công! Mã đặt: <strong>{$maDP}</strong> — Phòng: <strong>{$room['MaPhong']}</strong> — Tiền cọc: <strong>" . number_format($tienCoc, 0, ',', '.') . "đ</strong>. Nhân viên sẽ liên hệ xác nhận sớm.";
            $msgType = 'success';
            $tab = 'history';
        }
    }
}

// ── Xử lý POST: Hủy đặt phòng ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'huy_phong') {
    $maDP = $_POST['ma_dp'] ?? '';
    $check = $pdo->prepare("SELECT MaDP FROM DAT_PHONG WHERE MaDP=:id AND MaKH=:kh AND TrangThai='Chờ xác nhận'");
    $check->execute([':id' => $maDP, ':kh' => $khId]);
    if ($check->fetch()) {
        $pdo->prepare("UPDATE DAT_PHONG SET TrangThai='Đã hủy' WHERE MaDP=:id")->execute([':id' => $maDP]);
        $msg = "Đã hủy đặt phòng <strong>{$maDP}</strong>.";
        $msgType = 'success';
    }
    $tab = 'history';
}

// ── Lấy lịch sử đặt phòng ──────────────────────────────────────────────────
$history = $pdo->prepare("
    SELECT dp.*, p.LoaiPhong, p.Tang,
           GROUP_CONCAT(dv.TenDV SEPARATOR ', ') AS DichVu
    FROM DAT_PHONG dp
    JOIN PHONG p ON dp.MaPhong = p.MaPhong
    LEFT JOIN DAT_DICH_VU ddv ON dp.MaDP = ddv.MaDP
    LEFT JOIN DICH_VU dv ON ddv.MaDV = dv.MaDV
    WHERE dp.MaKH = :kh
    GROUP BY dp.MaDP
    ORDER BY dp.NgayDat DESC
");
$history->execute([':kh' => $khId]);
$bookings = $history->fetchAll();

$statusColor = [
    'Chờ xác nhận' => '#f59e0b',
    'Đã nhận phòng'=> '#3b82f6',
    'Đã trả phòng' => '#10b981',
    'Đã hủy'       => '#6b7280',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tài Khoản — Easyhome</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f0f6ff;
  --border:#bfdbfe;--muted:#64748b;--text:#1e293b;
  --shadow:0 4px 24px rgba(29,78,216,.10);--shadow-lg:0 12px 40px rgba(29,78,216,.14);
  --radius:12px;--font:Calibri,'Calibri Light',Arial,sans-serif;
  --serif:'Playfair Display',Georgia,serif;
}
html,body{min-height:100%;font-family:var(--font);color:var(--text)}
body{background:var(--bg)}

/* ── TOP BAR ── */
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
.btn-logout{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:6px 14px;border-radius:7px;font-family:var(--font);
  font-size:.78rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-logout:hover{background:rgba(255,255,255,.25)}

/* ── MAIN ── */
.main{max-width:960px;margin:32px auto;padding:0 20px 60px}

/* ── ALERT ── */
.alert{padding:13px 16px;border-radius:10px;font-size:.88rem;line-height:1.55;margin-bottom:24px}
.alert-success{background:#f0fdf4;border:1.5px solid #86efac;border-left:4px solid #22c55e;color:#166534}
.alert-error{background:#fff0f0;border:1.5px solid #fca5a5;border-left:4px solid #ef4444;color:#dc2626;animation:shake .4s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-4px)}50%{transform:translateX(4px)}}

/* ── TABS ── */
.tabs{display:flex;gap:4px;background:#fff;padding:6px;border-radius:14px;
  border:1.5px solid var(--border);box-shadow:var(--shadow);margin-bottom:28px}
.tab-btn{flex:1;padding:11px;text-align:center;cursor:pointer;border:none;background:none;
  font-family:var(--font);font-size:.82rem;font-weight:700;letter-spacing:.5px;
  color:var(--muted);border-radius:10px;transition:all .25s;display:flex;
  align-items:center;justify-content:center;gap:7px}
.tab-btn:hover{background:var(--blue-pale);color:var(--blue)}
.tab-btn.active{background:linear-gradient(135deg,var(--blue-dark),var(--blue));color:#fff;
  box-shadow:0 4px 16px rgba(29,78,216,.32)}
.tab-icon{font-size:1rem}

.tab-content{display:none}
.tab-content.active{display:block;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* ── CARD ── */
.card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);
  box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.card-head{padding:18px 22px;border-bottom:1.5px solid var(--border);
  display:flex;align-items:center;gap:10px;background:var(--blue-pale)}
.card-head-icon{font-size:1.15rem}
.card-head-title{font-weight:700;font-size:.95rem;color:var(--blue-dark)}
.card-body{padding:22px}

/* ── PROFILE ── */
.profile-hero{display:flex;align-items:center;gap:20px;padding:24px;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));border-radius:var(--radius);
  margin-bottom:20px;color:#fff}
.profile-avatar{width:68px;height:68px;background:rgba(255,255,255,.2);border-radius:50%;
  display:flex;align-items:center;justify-content:center;font-size:2rem;
  border:3px solid rgba(255,255,255,.4);flex-shrink:0}
.profile-name{font-family:var(--serif);font-size:1.35rem;font-weight:600}
.profile-id{font-size:.75rem;color:rgba(255,255,255,.7);margin-top:3px;letter-spacing:1px}
.profile-points{margin-top:8px;background:rgba(255,255,255,.15);border-radius:20px;
  padding:5px 14px;font-size:.78rem;display:inline-flex;align-items:center;gap:6px}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.info-item{padding:14px 16px;background:var(--blue-pale);border-radius:10px;
  border:1px solid var(--border)}
.info-label{font-size:.65rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:var(--muted);margin-bottom:5px}
.info-value{font-size:.93rem;color:var(--text);font-weight:600}
.info-value.empty{color:#94a3b8;font-weight:400;font-style:italic}

/* ── BOOKING FORM ── */
.section-title{font-size:.72rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:var(--blue);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-title::after{content:'';flex:1;height:1px;background:var(--border)}

.date-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:8px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:.67rem;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);margin-bottom:7px}
.form-input,.form-select,.form-textarea{
  width:100%;padding:11px 14px;font-family:var(--font);font-size:.91rem;
  color:var(--text);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:9px;outline:none;transition:all .2s}
.form-input:focus,.form-select:focus,.form-textarea:focus{
  border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.11)}
.form-textarea{resize:vertical;min-height:70px}

.duration-badge{background:var(--blue-mid);color:var(--blue-dark);padding:9px 16px;
  border-radius:8px;font-size:.83rem;font-weight:600;text-align:center;margin-bottom:20px;
  border:1px solid var(--border)}

/* Room type cards */
.room-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.room-card{border:2px solid var(--border);border-radius:10px;padding:14px 10px;
  text-align:center;cursor:pointer;transition:all .25s;background:#fff;position:relative}
.room-card:hover{border-color:var(--blue-light);transform:translateY(-2px);
  box-shadow:0 4px 16px rgba(29,78,216,.15)}
.room-card.selected{border-color:var(--blue);background:var(--blue-pale);
  box-shadow:0 4px 20px rgba(29,78,216,.2)}
.room-card input[type=radio]{position:absolute;opacity:0;width:0;height:0}
.room-icon{font-size:1.4rem;margin-bottom:6px}
.room-type-name{font-weight:700;font-size:.82rem;color:var(--text);margin-bottom:3px}
.room-beds{font-size:.7rem;color:var(--muted);margin-bottom:6px}
.room-price{font-size:.78rem;font-weight:700;color:var(--blue)}

/* Service cards */
.service-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}
.svc-card{border:2px solid var(--border);border-radius:10px;padding:12px;
  cursor:pointer;transition:all .25s;background:#fff;display:flex;
  align-items:center;gap:10px;position:relative}
.svc-card:hover{border-color:var(--blue-light);background:var(--blue-pale)}
.svc-card.selected{border-color:var(--blue);background:var(--blue-pale)}
.svc-card input[type=checkbox]{position:absolute;opacity:0;width:0;height:0}
.svc-check{width:20px;height:20px;border:2px solid var(--border);border-radius:5px;
  flex-shrink:0;display:flex;align-items:center;justify-content:center;
  font-size:.75rem;transition:all .2s;background:#fff}
.svc-card.selected .svc-check{background:var(--blue);border-color:var(--blue);color:#fff}
.svc-info{flex:1;min-width:0}
.svc-name{font-size:.8rem;font-weight:600;color:var(--text);line-height:1.3}
.svc-price{font-size:.74rem;color:var(--blue);font-weight:700;margin-top:2px}

/* Price summary */
.price-summary{background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:10px;padding:18px 20px;color:#fff;margin-bottom:18px}
.price-row{display:flex;justify-content:space-between;align-items:center;
  font-size:.84rem;margin-bottom:8px}
.price-row:last-child{margin-bottom:0;padding-top:10px;border-top:1px solid rgba(255,255,255,.25);
  font-size:.95rem;font-weight:700}
.price-label{color:rgba(255,255,255,.8)}
.price-val{font-weight:700}
.price-coc{color:#fcd34d}

.btn-book{width:100%;padding:13px;font-family:var(--font);font-size:.88rem;font-weight:700;
  letter-spacing:2px;text-transform:uppercase;color:#fff;
  background:linear-gradient(135deg,var(--blue-dark),var(--blue));border:none;
  border-radius:10px;cursor:pointer;transition:all .3s;
  box-shadow:0 4px 18px rgba(29,78,216,.38)}
.btn-book:hover{transform:translateY(-1px);box-shadow:0 6px 24px rgba(29,78,216,.48)}

/* ── HISTORY ── */
.booking-card{border:1.5px solid var(--border);border-radius:10px;
  margin-bottom:14px;overflow:hidden;background:#fff}
.booking-header{padding:14px 18px;background:var(--blue-pale);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.booking-id{font-weight:700;font-size:.9rem;color:var(--blue-dark)}
.booking-date{font-size:.75rem;color:var(--muted)}
.status-badge{padding:4px 12px;border-radius:20px;font-size:.72rem;font-weight:700;color:#fff}
.booking-body{padding:16px 18px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.bk-item-label{font-size:.65rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.bk-item-val{font-size:.88rem;font-weight:600;color:var(--text)}
.booking-footer{padding:12px 18px;border-top:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between}
.bk-total{font-weight:700;color:var(--blue-dark);font-size:.92rem}
.btn-cancel{background:#fee2e2;border:1.5px solid #fca5a5;color:#dc2626;
  padding:6px 16px;border-radius:7px;font-family:var(--font);font-size:.76rem;
  font-weight:700;cursor:pointer;transition:all .2s}
.btn-cancel:hover{background:#fecaca}
.empty-state{text-align:center;padding:50px 20px;color:var(--muted)}
.empty-icon{font-size:2.5rem;margin-bottom:12px}

@media(max-width:640px){
  .room-cards{grid-template-columns:1fr 1fr}
  .service-cards{grid-template-columns:1fr 1fr}
  .date-grid{grid-template-columns:1fr}
  .info-grid{grid-template-columns:1fr}
  .booking-body{grid-template-columns:1fr 1fr}
}

/* ── KHUYẾN MÃI ── */
.promo-section-title{font-size:.66rem;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);margin-bottom:9px}
.promo-kh{display:flex;align-items:center;justify-content:space-between;
  padding:11px 15px;border:1.5px solid var(--border);border-radius:10px;
  background:#fff;cursor:pointer;transition:all .22s;margin-bottom:8px}
.promo-kh:hover{border-color:var(--blue-light);background:var(--blue-pale);transform:translateY(-1px)}
.promo-kh.applied{border-color:#22c55e;background:#f0fdf4}
.promo-kh.auto-eligible{border-left:3px solid var(--blue-light)}
.promo-kh-left{flex:1}
.promo-kh-code{font-size:.77rem;font-weight:700;color:var(--blue-dark);margin-bottom:2px}
.promo-kh-name{font-size:.8rem;color:var(--text)}
.promo-kh-cond{font-size:.71rem;color:var(--muted);margin-top:1px}
.promo-kh-badge{font-size:.73rem;font-weight:700;padding:3px 11px;border-radius:10px;
  background:var(--blue-mid);color:var(--blue-dark);white-space:nowrap;flex-shrink:0;margin-left:10px}
.promo-kh-badge.on{background:#dcfce7;color:#166534}
.promo-code-row{display:flex;gap:8px;margin-top:14px}
.btn-apply-km{padding:10px 16px;background:var(--blue);color:#fff;border:none;
  border-radius:8px;font-size:.77rem;font-weight:700;cursor:pointer;
  white-space:nowrap;font-family:var(--font);transition:all .2s}
.btn-apply-km:hover{background:var(--blue-dark)}
.price-row.discount-row{color:#fcd34d}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <a href="../index.php" class="topbar-brand">
    <img src="../assets/images/logo.jpg" alt="Easyhome">
    <span>Easyhome</span>
  </a>
  <div class="topbar-right">
    <span class="topbar-user">👤 <?= htmlspecialchars($khName) ?></span>
    <a href="?logout=1" class="btn-logout">Đăng xuất</a>
  </div>
</div>

<!-- LOGOUT handler inline -->
<?php if (isset($_GET['logout'])): session_destroy(); header('Location: ../login.php'); exit; endif; ?>

<div class="main">

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
  <?php endif; ?>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn <?= $tab==='profile'?'active':'' ?>" onclick="switchTab('profile')">
      <span class="tab-icon">👤</span> Thông Tin
    </button>
    <button class="tab-btn <?= $tab==='booking'?'active':'' ?>" onclick="switchTab('booking')">
      <span class="tab-icon">🛏️</span> Đặt Phòng
    </button>
    <button class="tab-btn <?= $tab==='history'?'active':'' ?>" onclick="switchTab('history')">
      <span class="tab-icon">📋</span> Lịch Sử
      <?php if (count($bookings)): ?><span style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 8px;font-size:.68rem;margin-left:3px;"><?= count($bookings) ?></span><?php endif; ?>
    </button>
  </div>

  <!-- ═══════════════════════ TAB: THÔNG TIN ════════════════════════ -->
  <div id="tab-profile" class="tab-content <?= $tab==='profile'?'active':'' ?>">

    <div class="profile-hero">
      <div class="profile-avatar">👤</div>
      <div>
        <div class="profile-name"><?= htmlspecialchars($kh['HoTen']) ?></div>
        <div class="profile-id">Mã KH: <?= $kh['MaKH'] ?> &nbsp;·&nbsp; @<?= htmlspecialchars($kh['TenTaiKhoan']) ?></div>
        <div class="profile-points">⭐ <?= number_format($kh['TichDiem']) ?> điểm tích lũy</div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <span class="card-head-icon">📋</span>
        <span class="card-head-title">Thông Tin Cá Nhân</span>
      </div>
      <div class="card-body">
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Họ và Tên</div>
            <div class="info-value"><?= htmlspecialchars($kh['HoTen']) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Tên Đăng Nhập</div>
            <div class="info-value">@<?= htmlspecialchars($kh['TenTaiKhoan']) ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Số Điện Thoại</div>
            <div class="info-value <?= $kh['SoDienThoai']?'':'empty' ?>">
              <?= $kh['SoDienThoai'] ?: 'Chưa cập nhật' ?>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">Email</div>
            <div class="info-value <?= $kh['Email']?'':'empty' ?>">
              <?= htmlspecialchars($kh['Email'] ?: 'Chưa cập nhật') ?>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">CCCD / CMND</div>
            <div class="info-value <?= $kh['CCCD']?'':'empty' ?>">
              <?= htmlspecialchars($kh['CCCD'] ?: 'Chưa cập nhật') ?>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">Ngày Tạo Tài Khoản</div>
            <div class="info-value"><?= date('d/m/Y', strtotime($kh['NgayTao'])) ?></div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- ═══════════════════════ TAB: ĐẶT PHÒNG ══════════════════════ -->
  <div id="tab-booking" class="tab-content <?= $tab==='booking'?'active':'' ?>">

    <form method="POST" id="bookingForm">
      <input type="hidden" name="action" value="dat_phong">
      <input type="hidden" name="ma_km" id="hidKMKh" value="">

      <!-- Thời gian -->
      <div class="card">
        <div class="card-head">
          <span class="card-head-icon">📅</span>
          <span class="card-head-title">Thời Gian Lưu Trú</span>
        </div>
        <div class="card-body">
          <div class="date-grid">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Ngày Check-in</label>
              <input type="date" name="checkin_date" id="ciDate" class="form-input"
                     value="<?= htmlspecialchars($_POST['checkin_date'] ?? $prefillCheckin ?: date('Y-m-d')) ?>"
                     min="<?= date('Y-m-d') ?>" onchange="calcDuration()">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Giờ Check-in</label>
              <input type="time" name="checkin_time" id="ciTime" class="form-input"
                     value="<?= htmlspecialchars($_POST['checkin_time'] ?? '14:00') ?>"
                     onchange="calcDuration()">
            </div>
          </div>
          <div style="text-align:center;color:var(--muted);font-size:1.1rem;padding:6px 0;">⬇</div>
          <div class="date-grid" style="margin-bottom:14px">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Ngày Check-out</label>
              <input type="date" name="checkout_date" id="coDate" class="form-input"
                     value="<?= htmlspecialchars($_POST['checkout_date'] ?? $prefillCheckout ?: date('Y-m-d', strtotime('+1 day'))) ?>"
                     min="<?= date('Y-m-d', strtotime('+1 day')) ?>" onchange="calcDuration()">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">Giờ Check-out</label>
              <input type="time" name="checkout_time" id="coTime" class="form-input"
                     value="<?= htmlspecialchars($_POST['checkout_time'] ?? '12:00') ?>"
                     onchange="calcDuration()">
            </div>
          </div>
          <div class="duration-badge" id="durationBadge">⏱ Đang tính thời gian...</div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Số Lượng Khách</label>
            <input type="number" name="so_khach" id="soKhach" class="form-input"
                   value="<?= intval($_POST['so_khach'] ?? 1) ?>" min="1" max="10"
                   style="width:120px">
          </div>
        </div>
      </div>

      <!-- Loại phòng -->
      <div class="card">
        <div class="card-head">
          <span class="card-head-icon">🛏️</span>
          <span class="card-head-title">Loại Phòng</span>
        </div>
        <div class="card-body">
          <div class="room-cards">
            <?php foreach ($roomPrices as $type => $info): ?>
            <?php $selected = ($_POST['loai_phong'] ?? $prefillType ?: 'Đôi') === $type; ?>
            <label class="room-card <?= $selected?'selected':'' ?>" onclick="selectRoom(this, '<?= $type ?>')">
              <input type="radio" name="loai_phong" value="<?= $type ?>" <?= $selected?'checked':'' ?>>
              <div class="room-icon"><?= $info['icon'] ?></div>
              <div class="room-type-name"><?= $type ?></div>
              <div class="room-beds"><?= $info['beds'] ?></div>
              <div class="room-price">
                <?php if ($info['min'] === $info['max']): ?>
                  <?= number_format($info['min']/1000) ?>k/đêm
                <?php else: ?>
                  <?= number_format($info['min']/1000) ?>–<?= number_format($info['max']/1000) ?>k/đêm
                <?php endif; ?>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Dịch vụ -->
      <div class="card">
        <div class="card-head">
          <span class="card-head-icon">✨</span>
          <span class="card-head-title">Dịch Vụ Thêm</span>
        </div>
        <div class="card-body">
          <?php
          $svcIcons = ['DV001'=>'💆','DV002'=>'👕','DV003'=>'🚗','DV004'=>'🍳','DV005'=>'🚲'];
          ?>
          <div class="service-cards">
            <?php foreach ($services as $svc): ?>
            <?php $chk = in_array($svc['MaDV'], $_POST['services'] ?? []); ?>
            <label class="svc-card <?= $chk?'selected':'' ?>" onclick="toggleSvc(this)">
              <input type="checkbox" name="services[]" value="<?= $svc['MaDV'] ?>"
                     data-price="<?= $svc['GiaDV'] ?>" <?= $chk?'checked':'' ?>>
              <div class="svc-check"><?= $chk?'✓':'' ?></div>
              <div class="svc-info">
                <div class="svc-name"><?= $svcIcons[$svc['MaDV']] ?? '⭐' ?> <?= htmlspecialchars($svc['TenDV']) ?></div>
                <div class="svc-price"><?= number_format($svc['GiaDV'],0,',','.') ?>đ</div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Khuyến mãi -->
      <div class="card">
        <div class="card-head">
          <span class="card-head-icon">🎁</span>
          <span class="card-head-title">Khuyến Mãi</span>
        </div>
        <div class="card-body">
          <!-- Promos auto-detect -->
          <div id="autoPromoSection">
            <div class="promo-section-title">Khuyến mãi có thể áp dụng</div>
            <div id="autoPromoList">
              <div style="font-size:.8rem;color:var(--muted);padding:6px 0">
                Chọn ngày để xem khuyến mãi tự động...
              </div>
            </div>
          </div>

          <!-- Manual code -->
          <div class="promo-section-title" style="margin-top:14px">Nhập mã khuyến mãi</div>
          <div class="promo-code-row">
            <input type="text" id="promoCodeKh" class="form-input" placeholder="VD: KM001, KM003..."
                   style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
            <button type="button" class="btn-apply-km"
                    onclick="applyPromoKh(document.getElementById('promoCodeKh').value)">
              Áp dụng
            </button>
          </div>
          <div id="promoMsgKh" style="font-size:.79rem;margin-top:8px;display:none"></div>
        </div>
      </div>

      <!-- Tổng tiền & Ghi chú -->
      <div class="card">
        <div class="card-head">
          <span class="card-head-icon">💰</span>
          <span class="card-head-title">Tóm Tắt Đặt Phòng</span>
        </div>
        <div class="card-body">
          <div class="price-summary">
            <div class="price-row">
              <span class="price-label">Tiền phòng</span>
              <span class="price-val" id="priceRoom">—</span>
            </div>
            <div class="price-row">
              <span class="price-label">Dịch vụ thêm</span>
              <span class="price-val" id="priceSvc">0đ</span>
            </div>
            <div class="price-row discount-row" id="priceDiscountRow" style="display:none">
              <span class="price-label">🎁 Giảm giá <span id="priceDiscName" style="font-size:.75rem;opacity:.85"></span></span>
              <span class="price-val" id="priceDiscount">0đ</span>
            </div>
            <div class="price-row">
              <span class="price-label">Tổng cộng</span>
              <span class="price-val" id="priceTotal">—</span>
            </div>
            <div class="price-row">
              <span class="price-label price-coc">⚡ Tiền cọc trước (30%)</span>
              <span class="price-val price-coc" id="priceCoc">—</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Ghi Chú</label>
            <textarea name="ghi_chu" class="form-textarea"
              placeholder="Yêu cầu đặc biệt, giờ đến dự kiến..."><?= htmlspecialchars($_POST['ghi_chu'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn-book">🛏️ Xác Nhận Đặt Phòng</button>
        </div>
      </div>

    </form>
  </div>

  <!-- ═══════════════════════ TAB: LỊCH SỬ ════════════════════════ -->
  <div id="tab-history" class="tab-content <?= $tab==='history'?'active':'' ?>">

    <?php if (!$bookings): ?>
    <div class="empty-state">
      <div class="empty-icon">🗓️</div>
      <div style="font-size:.95rem;font-weight:600;margin-bottom:8px;">Chưa có đặt phòng nào</div>
      <div style="font-size:.83rem;margin-bottom:18px;">Bấm <strong>Đặt Phòng</strong> để bắt đầu trải nghiệm Easyhome</div>
      <button class="btn-book" style="width:auto;padding:10px 28px;" onclick="switchTab('booking')">→ Đặt Phòng Ngay</button>
    </div>
    <?php else: ?>
    <?php foreach ($bookings as $bk): ?>
    <div class="booking-card">
      <div class="booking-header">
        <div>
          <div class="booking-id"># <?= $bk['MaDP'] ?> &nbsp;·&nbsp; Phòng <?= $bk['MaPhong'] ?> (<?= $bk['LoaiPhong'] ?> — Tầng <?= $bk['Tang'] ?>)</div>
          <div class="booking-date">Đặt lúc: <?= date('d/m/Y H:i', strtotime($bk['NgayDat'])) ?></div>
        </div>
        <span class="status-badge" style="background:<?= $statusColor[$bk['TrangThai']] ?? '#6b7280' ?>">
          <?= $bk['TrangThai'] ?>
        </span>
      </div>
      <div class="booking-body">
        <div>
          <div class="bk-item-label">Check-in</div>
          <div class="bk-item-val"><?= date('d/m/Y', strtotime($bk['NgayCheckIn'])) ?></div>
          <div style="font-size:.77rem;color:var(--muted);"><?= date('H:i', strtotime($bk['NgayCheckIn'])) ?></div>
        </div>
        <div>
          <div class="bk-item-label">Check-out</div>
          <div class="bk-item-val"><?= date('d/m/Y', strtotime($bk['NgayCheckOut'])) ?></div>
          <div style="font-size:.77rem;color:var(--muted);"><?= date('H:i', strtotime($bk['NgayCheckOut'])) ?></div>
        </div>
        <div>
          <div class="bk-item-label">Số Khách / Cọc</div>
          <div class="bk-item-val"><?= $bk['SoLuongKhach'] ?> người</div>
          <div style="font-size:.77rem;color:var(--muted);">Cọc: <?= number_format($bk['TienCoc'],0,',','.') ?>đ</div>
        </div>
      </div>
      <?php if ($bk['DichVu']): ?>
      <div style="padding:0 18px 12px;font-size:.78rem;color:var(--muted);">
        ✨ Dịch vụ: <?= htmlspecialchars($bk['DichVu']) ?>
      </div>
      <?php endif; ?>
      <?php if ($bk['GhiChu']): ?>
      <div style="padding:0 18px 12px;font-size:.78rem;color:var(--muted);">
        📝 <?= htmlspecialchars($bk['GhiChu']) ?>
      </div>
      <?php endif; ?>
      <div class="booking-footer">
        <div class="bk-total">Tổng: <?= number_format($bk['TongGia'],0,',','.') ?>đ</div>
        <?php if ($bk['TrangThai'] === 'Chờ xác nhận'): ?>
        <form method="POST" onsubmit="return confirm('Xác nhận hủy đặt phòng này?')">
          <input type="hidden" name="action" value="huy_phong">
          <input type="hidden" name="ma_dp" value="<?= $bk['MaDP'] ?>">
          <button type="submit" class="btn-cancel">✕ Hủy đặt phòng</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /main -->

<script>
// ── Chuyển tab ──────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelectorAll('.tab-btn')[['profile','booking','history'].indexOf(name)].classList.add('active');
}

// ── Khuyến mãi ───────────────────────────────────────────────────────────────
const availablePromos = <?= json_encode($promos) ?>;
let appliedKMKh = '';
let discountAmtKh = 0;

function getCurrentNightsKh() {
  const ci = document.getElementById('ciDate').value;
  const co = document.getElementById('coDate').value;
  const cit = document.getElementById('ciTime').value || '14:00';
  const cot = document.getElementById('coTime').value || '12:00';
  if (!ci || !co) return 1;
  const diff = new Date(co+'T'+cot) - new Date(ci+'T'+cit);
  return Math.max(1, Math.ceil(diff / 86400000));
}

function detectApplicablePromos() {
  const ci      = document.getElementById('ciDate').value;
  const nights  = getCurrentNightsKh();
  const today   = new Date();
  const ciDate  = ci ? new Date(ci + 'T14:00') : null;
  const daysAhead = ciDate ? Math.max(0, Math.ceil((ciDate - today) / 86400000)) : 0;

  const list = document.getElementById('autoPromoList');
  if (!list) return;

  // Filter eligible promos (only individual-booking promos, not group)
  const eligible = availablePromos.filter(pm => {
    if (pm.MaKM === 'KM001') return daysAhead >= 7 && daysAhead < 60;
    if (pm.MaKM === 'KM002') return daysAhead >= 60;
    if (pm.MaKM === 'KM003') return nights >= 3 && nights < 5;
    if (pm.MaKM === 'KM004') return nights >= 5;
    // Skip group promos (KM005, KM006) for single-room booking
    return false;
  });

  if (eligible.length === 0) {
    list.innerHTML = '<div style="font-size:.79rem;color:var(--muted);padding:4px 0">Không có khuyến mãi tự động. Nhập mã thủ công nếu có.</div>';
    return;
  }

  list.innerHTML = eligible.map(pm => {
    const isApplied = appliedKMKh === pm.MaKM;
    const badgeTxt  = pm.LoaiKM === 'PhanTram'
      ? '-' + pm.GiaTriKM + '%'
      : '-' + pm.GiaTriKM.toLocaleString('vi-VN') + 'đ';
    return `<div class="promo-kh auto-eligible ${isApplied?'applied':''}" onclick="applyPromoKh('${pm.MaKM}')">
      <div class="promo-kh-left">
        <div class="promo-kh-code">${pm.MaKM} — ${pm.TenKM}</div>
        <div class="promo-kh-cond">${pm.DieuKien || ''}</div>
      </div>
      <div class="promo-kh-badge ${isApplied?'on':''}">${badgeTxt}${isApplied?' ✓':''}</div>
    </div>`;
  }).join('');
}

function applyPromoKh(code) {
  code = (code || '').toUpperCase().trim();
  document.getElementById('promoCodeKh').value = code;
  const msg = document.getElementById('promoMsgKh');
  msg.style.display = 'block';

  if (!code) {
    appliedKMKh = ''; discountAmtKh = 0;
    document.getElementById('hidKMKh').value = '';
    msg.style.display = 'none';
    updatePrice(); detectApplicablePromos(); return;
  }

  const pm = availablePromos.find(p => p.MaKM === code);
  if (pm) {
    appliedKMKh = code;
    document.getElementById('hidKMKh').value = code;
    msg.style.color = '#166534';
    msg.textContent = '✓ Đã áp dụng: ' + pm.TenKM;
    updatePrice(); detectApplicablePromos();
  } else {
    appliedKMKh = ''; discountAmtKh = 0;
    document.getElementById('hidKMKh').value = '';
    msg.style.color = '#dc2626';
    msg.textContent = '✕ Mã không hợp lệ hoặc đã hết hạn';
    updatePrice(); detectApplicablePromos();
  }
}

// ── Chọn loại phòng ─────────────────────────────────────────────────────────
const roomPrices = <?= json_encode(array_map(fn($v)=>['min'=>$v['min'],'max'=>$v['max']], $roomPrices)) ?>;
let selectedRoomType = '<?= htmlspecialchars($_POST['loai_phong'] ?? $prefillType ?: 'Đôi') ?>';

function selectRoom(card, type) {
  document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  card.querySelector('input').checked = true;
  selectedRoomType = type;
  updatePrice();
}

// ── Toggle dịch vụ ──────────────────────────────────────────────────────────
function toggleSvc(card) {
  const cb = card.querySelector('input');
  const chk = card.querySelector('.svc-check');
  setTimeout(() => {
    if (cb.checked) { card.classList.add('selected'); chk.textContent = '✓'; }
    else { card.classList.remove('selected'); chk.textContent = ''; }
    updatePrice();
  }, 0);
}

// ── Tính thời gian lưu trú ──────────────────────────────────────────────────
function calcDuration() {
  const ciDate = document.getElementById('ciDate').value;
  const ciTime = document.getElementById('ciTime').value;
  const coDate = document.getElementById('coDate').value;
  const coTime = document.getElementById('coTime').value;
  const badge  = document.getElementById('durationBadge');

  if (!ciDate || !coDate) { badge.textContent = '⏱ Chọn ngày để tính thời gian'; return; }

  const inMs  = new Date(ciDate + 'T' + (ciTime||'14:00')).getTime();
  const outMs = new Date(coDate + 'T' + (coTime||'12:00')).getTime();
  const diff  = outMs - inMs;

  if (diff <= 0) {
    badge.textContent = '⚠ Check-out phải sau check-in';
    badge.style.background = '#fee2e2'; badge.style.color = '#dc2626';
    return;
  }
  badge.style.background = ''; badge.style.color = '';
  const hours = Math.round(diff / 3600000);
  const days  = Math.ceil(diff / 86400000);
  badge.textContent = hours < 24
    ? `⏱ ${hours} tiếng lưu trú`
    : `⏱ ${days} ngày lưu trú (${hours} tiếng)`;
  updatePrice();
  detectApplicablePromos();
}

// ── Cập nhật bảng giá ───────────────────────────────────────────────────────
function updatePrice() {
  const ciDate = document.getElementById('ciDate').value;
  const ciTime = document.getElementById('ciTime').value;
  const coDate = document.getElementById('coDate').value;
  const coTime = document.getElementById('coTime').value;

  let days = 1;
  if (ciDate && coDate) {
    const diff = new Date(coDate + 'T' + (coTime||'12:00')) - new Date(ciDate + 'T' + (ciTime||'14:00'));
    days = Math.max(1, Math.ceil(diff / 86400000));
  }

  const p = roomPrices[selectedRoomType];
  const roomMin = p ? p.min * days : 0;
  const roomMax = p ? p.max * days : 0;

  let svcTotal = 0;
  document.querySelectorAll('.svc-card input:checked').forEach(cb => {
    svcTotal += parseFloat(cb.dataset.price || 0);
  });

  const baseMin = roomMin + svcTotal;
  const baseMax = roomMax + svcTotal;

  // Tính giảm giá
  discountAmtKh = 0;
  let discName = '';
  if (appliedKMKh) {
    const pm = availablePromos.find(p => p.MaKM === appliedKMKh);
    if (pm) {
      discountAmtKh = pm.LoaiKM === 'PhanTram'
        ? Math.round(baseMin * pm.GiaTriKM / 100)
        : Math.min(pm.GiaTriKM, baseMin);
      discName = pm.TenKM;
    }
  }

  const totalMin = Math.max(0, baseMin - discountAmtKh);
  const totalMax = Math.max(0, baseMax - discountAmtKh);
  const fmt = n => n.toLocaleString('vi-VN') + 'đ';
  const fmtRange = (mn, mx) => mn === mx ? fmt(mn) : fmt(mn) + ' – ' + fmt(mx);

  // Hiển thị dòng giảm giá
  const discRow = document.getElementById('priceDiscountRow');
  if (discRow) {
    if (discountAmtKh > 0) {
      discRow.style.display = '';
      document.getElementById('priceDiscount').textContent = '-' + fmt(discountAmtKh);
      const nameEl = document.getElementById('priceDiscName');
      if (nameEl) nameEl.textContent = '(' + discName + ')';
    } else discRow.style.display = 'none';
  }

  document.getElementById('priceRoom').textContent = fmtRange(roomMin, roomMax);
  document.getElementById('priceSvc').textContent  = fmt(svcTotal);
  document.getElementById('priceTotal').textContent = fmtRange(totalMin, totalMax);
  document.getElementById('priceCoc').textContent   = fmtRange(Math.round(totalMin*.3), Math.round(totalMax*.3));
}

// Khởi tạo
calcDuration();
updatePrice();
detectApplicablePromos();
</script>
</body>
</html>
