<?php
// admin/dashboard.php — Trang quản trị dành cho Admin
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// ── Kiểm tra quyền admin ────────────────────────────────────────────────
if (!isset($_SESSION['staff_id']) || $_SESSION['staff_role'] !== 'admin') {
    header('Location: ../login.php?role=quanly');
    exit;
}

$adminId   = $_SESSION['staff_id'];
$adminName = $_SESSION['staff_name'];
$adminMaNV = $_SESSION['staff_maNV'];

$tab     = $_GET['tab'] ?? 'overview';
$msg     = '';
$msgType = 'success';

// ── Xử lý POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Xác nhận đặt phòng (Chờ xác nhận → Đã nhận phòng) ----
    if ($action === 'confirm_booking') {
        $maDP = $_POST['ma_dp'] ?? '';
        if ($maDP) {
            try {
                $stmt = $pdo->prepare("SELECT MaPhong FROM DAT_PHONG WHERE MaDP = :id AND TrangThai = 'Chờ xác nhận'");
                $stmt->execute([':id' => $maDP]);
                $bk = $stmt->fetch();
                if ($bk) {
                    $pdo->prepare("UPDATE DAT_PHONG SET TrangThai = 'Đã nhận phòng', MaNV_XuLy = :nv WHERE MaDP = :id")
                        ->execute([':nv' => $adminMaNV, ':id' => $maDP]);
                    $pdo->prepare("UPDATE PHONG SET TinhTrang = 'Đang ở' WHERE MaPhong = :id")
                        ->execute([':id' => $bk['MaPhong']]);
                    $msg = "Đã xác nhận check-in cho đặt phòng $maDP.";
                } else {
                    $msg = "Không tìm thấy đặt phòng hoặc trạng thái không phù hợp.";
                    $msgType = 'error';
                }
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage();
                $msgType = 'error';
            }
        }
        $tab = 'bookings';
    }

    // ---- Hủy đặt phòng ----
    elseif ($action === 'cancel_booking') {
        $maDP = $_POST['ma_dp'] ?? '';
        if ($maDP) {
            try {
                $stmt = $pdo->prepare("SELECT MaPhong, TrangThai FROM DAT_PHONG WHERE MaDP = :id");
                $stmt->execute([':id' => $maDP]);
                $bk = $stmt->fetch();
                if ($bk && $bk['TrangThai'] !== 'Đã hủy' && $bk['TrangThai'] !== 'Đã trả phòng') {
                    $pdo->prepare("UPDATE DAT_PHONG SET TrangThai = 'Đã hủy' WHERE MaDP = :id")
                        ->execute([':id' => $maDP]);
                    if ($bk['TrangThai'] === 'Đã nhận phòng') {
                        $pdo->prepare("UPDATE PHONG SET TinhTrang = 'Đang dọn' WHERE MaPhong = :id")
                            ->execute([':id' => $bk['MaPhong']]);
                    }
                    $msg = "Đã hủy đặt phòng $maDP thành công.";
                } else {
                    $msg = "Không thể hủy đặt phòng này.";
                    $msgType = 'error';
                }
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage();
                $msgType = 'error';
            }
        }
        $tab = 'bookings';
    }

    // ---- Đặt phòng mới ----
    elseif ($action === 'new_booking') {
        $maKH    = trim($_POST['ma_kh']    ?? '');
        $maPhong = trim($_POST['ma_phong'] ?? '');
        $checkIn  = trim($_POST['check_in']  ?? '');
        $checkOut = trim($_POST['check_out'] ?? '');
        $soKhach  = max(1, (int)($_POST['so_khach'] ?? 1));
        $tienCoc  = max(0, (float)($_POST['tien_coc'] ?? 0));
        $ghiChu   = trim($_POST['ghi_chu'] ?? '');

        if (!$maKH || !$maPhong || !$checkIn || !$checkOut) {
            $msg = "Vui lòng điền đầy đủ thông tin bắt buộc.";
            $msgType = 'error';
            $tab = 'new-booking';
        } elseif ($checkIn >= $checkOut) {
            $msg = "Ngày check-out phải sau ngày check-in.";
            $msgType = 'error';
            $tab = 'new-booking';
        } else {
            try {
                $phongStmt = $pdo->prepare("SELECT GiaPhong FROM PHONG WHERE MaPhong = :id");
                $phongStmt->execute([':id' => $maPhong]);
                $phong  = $phongStmt->fetch();
                $soNgay = (strtotime($checkOut) - strtotime($checkIn)) / 86400;
                $tongGia = $phong ? $phong['GiaPhong'] * max(1, $soNgay) : 0;

                $maxIdRes = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaDP,3) AS UNSIGNED)) FROM DAT_PHONG")->fetchColumn();
                $newMaDP  = 'DP' . str_pad(($maxIdRes ?? 0) + 1, 3, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO DAT_PHONG
                    (MaDP,MaKH,MaPhong,NgayCheckIn,NgayCheckOut,SoLuongKhach,TienCoc,TongGia,GhiChu,TrangThai,MaNV_XuLy)
                    VALUES (:id,:kh,:phong,:cin,:cout,:sk,:coc,:tg,:gc,'Chờ xác nhận',:nv)")
                    ->execute([
                        ':id'=>$newMaDP,':kh'=>$maKH,':phong'=>$maPhong,
                        ':cin'=>$checkIn,':cout'=>$checkOut,':sk'=>$soKhach,
                        ':coc'=>$tienCoc,':tg'=>$tongGia,':gc'=>$ghiChu,':nv'=>$adminMaNV
                    ]);
                $msg = "Đã tạo đặt phòng $newMaDP — Tổng: " . number_format($tongGia, 0, ',', '.') . " VNĐ";
                $tab = 'bookings';
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage();
                $msgType = 'error';
                $tab = 'new-booking';
            }
        }
    }

    // ---- Tạo tài khoản nhân viên ----
    elseif ($action === 'create_staff') {
        $hoTen   = trim($_POST['ho_ten']   ?? '');
        $sdt     = trim($_POST['so_dt']    ?? '');
        $email   = trim($_POST['email']    ?? '');
        $chucVu  = trim($_POST['chuc_vu']  ?? 'Lễ tân');
        $tenTK   = trim($_POST['ten_tk']   ?? '');
        $matKhau = trim($_POST['mat_khau'] ?? '');
        $vaiTro  = in_array($_POST['vai_tro'] ?? '', ['admin','nhanvien']) ? $_POST['vai_tro'] : 'nhanvien';

        if (!$hoTen || !$sdt || !$tenTK || !$matKhau) {
            $msg = "Vui lòng điền đầy đủ thông tin bắt buộc (*).";
            $msgType = 'error';
        } elseif (strlen($matKhau) < 6) {
            $msg = "Mật khẩu phải ít nhất 6 ký tự.";
            $msgType = 'error';
        } else {
            try {
                $chk = $pdo->prepare("SELECT TenTK FROM TAI_KHOAN WHERE TenTK = :tk");
                $chk->execute([':tk' => $tenTK]);
                if ($chk->fetch()) {
                    $msg = "Tên tài khoản '$tenTK' đã tồn tại.";
                    $msgType = 'error';
                } else {
                    $maxNV   = $pdo->query("SELECT MAX(CAST(SUBSTRING(MaNV,3) AS UNSIGNED)) FROM NHAN_VIEN")->fetchColumn();
                    $newMaNV = 'NV' . str_pad(($maxNV ?? 0) + 1, 3, '0', STR_PAD_LEFT);

                    $pdo->prepare("INSERT INTO NHAN_VIEN (MaNV,HoTen,SoDienThoai,Email,ChucVu,NgayVaoLam) VALUES (:id,:ht,:sdt,:em,:cv,CURDATE())")
                        ->execute([':id'=>$newMaNV,':ht'=>$hoTen,':sdt'=>$sdt,':em'=>$email,':cv'=>$chucVu]);

                    $hashPw = password_hash($matKhau, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO TAI_KHOAN (TenTK,MatKhau,VaiTro,MaNV) VALUES (:tk,:pw,:vt,:nv)")
                        ->execute([':tk'=>$tenTK,':pw'=>$hashPw,':vt'=>$vaiTro,':nv'=>$newMaNV]);

                    $msg = "Đã tạo tài khoản '$tenTK' cho nhân viên $hoTen (Mã: $newMaNV).";
                }
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage();
                $msgType = 'error';
            }
        }
        $tab = 'staff';
    }

    // ---- Vô hiệu hóa tài khoản nhân viên ----
    elseif ($action === 'delete_staff') {
        $tenTK = trim($_POST['ten_tk'] ?? '');
        if ($tenTK === $adminId) {
            $msg = "Không thể vô hiệu hóa tài khoản đang đăng nhập.";
            $msgType = 'error';
        } elseif ($tenTK) {
            try {
                $pdo->prepare("UPDATE TAI_KHOAN SET TrangThai = 'Ngừng hoạt động' WHERE TenTK = :tk")
                    ->execute([':tk' => $tenTK]);
                $pdo->prepare("UPDATE NHAN_VIEN nv INNER JOIN TAI_KHOAN tk ON nv.MaNV=tk.MaNV SET nv.TrangThai='Đã nghỉ việc' WHERE tk.TenTK=:tk")
                    ->execute([':tk' => $tenTK]);
                $msg = "Đã vô hiệu hóa tài khoản '$tenTK'.";
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage();
                $msgType = 'error';
            }
        }
        $tab = 'staff';
    }

    // ---- Khôi phục tài khoản ----
    elseif ($action === 'restore_staff') {
        $tenTK = trim($_POST['ten_tk'] ?? '');
        if ($tenTK) {
            try {
                $pdo->prepare("UPDATE TAI_KHOAN SET TrangThai = 'Hoạt động' WHERE TenTK = :tk")
                    ->execute([':tk' => $tenTK]);
                $pdo->prepare("UPDATE NHAN_VIEN nv INNER JOIN TAI_KHOAN tk ON nv.MaNV=tk.MaNV SET nv.TrangThai='Đang làm việc' WHERE tk.TenTK=:tk")
                    ->execute([':tk' => $tenTK]);
                $msg = "Đã khôi phục tài khoản '$tenTK'.";
            } catch (PDOException $e) {
                $msg = "Lỗi CSDL: " . $e->getMessage();
                $msgType = 'error';
            }
        }
        $tab = 'staff';
    }

    // Redirect để tránh re-submit form
    if ($msgType === 'success' && $msg) {
        header("Location: dashboard.php?tab=$tab&msg=" . urlencode($msg));
        exit;
    }
}

// Lấy message từ redirect
if (!$msg && isset($_GET['msg'])) {
    $msg = urldecode($_GET['msg']);
    $msgType = 'success';
}

// ── Lấy dữ liệu hiển thị ────────────────────────────────────────────────

// Thông tin quản lý
$adminProfile = $pdo->prepare("
    SELECT nv.*, tk.TenTK, tk.LanDangNhapCuoi, tk.NgayTaoTK
    FROM TAI_KHOAN tk LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV
    WHERE tk.TenTK = :tk");
$adminProfile->execute([':tk' => $adminId]);
$adminProfile = $adminProfile->fetch();

// Thống kê tổng quan
$bkStatusCnt = $pdo->query("SELECT TrangThai, COUNT(*) cnt FROM DAT_PHONG GROUP BY TrangThai")->fetchAll(PDO::FETCH_KEY_PAIR);
$stats = [
    'total'         => array_sum($bkStatusCnt),
    'pending'       => $bkStatusCnt['Chờ xác nhận']  ?? 0,
    'bk_dang'       => $bkStatusCnt['Đã nhận phòng'] ?? 0,
    'bk_tra'        => $bkStatusCnt['Đã trả phòng']  ?? 0,
    'bk_huy'        => $bkStatusCnt['Đã hủy']        ?? 0,
    'occupied'      => (int)$pdo->query("SELECT COUNT(*) FROM PHONG WHERE TinhTrang='Đang ở'")->fetchColumn(),
    'total_room'    => (int)$pdo->query("SELECT COUNT(*) FROM PHONG")->fetchColumn(),
    'revenue_month' => (float)$pdo->query("SELECT COALESCE(SUM(TongGia),0) FROM DAT_PHONG WHERE TrangThai NOT IN ('Đã hủy') AND MONTH(NgayDat)=MONTH(NOW()) AND YEAR(NgayDat)=YEAR(NOW())")->fetchColumn(),
    'checkin_today' => (int)$pdo->query("SELECT COUNT(*) FROM DAT_PHONG WHERE DATE(NgayCheckIn)=CURDATE() AND TrangThai NOT IN ('Đã hủy')")->fetchColumn(),
];

// ── Pending bookings cho overview tab (luôn lấy, không bị ảnh hưởng bởi pagination) ──
$pendingBookings = $pdo->query("
    SELECT dp.*, kh.HoTen AS TenKH, kh.SoDienThoai,
           p.LoaiPhong, p.GiaPhong, p.Tang
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH=kh.MaKH
    JOIN PHONG p ON dp.MaPhong=p.MaPhong
    WHERE dp.TrangThai='Chờ xác nhận'
    ORDER BY dp.NgayDat ASC LIMIT 5")->fetchAll();

// ── Danh sách đặt phòng — tìm kiếm + phân trang (cho bookings tab) ────────────
$filterStatus  = $_GET['filter'] ?? 'all';
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

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH=kh.MaKH
    JOIN PHONG p ON dp.MaPhong=p.MaPhong
    $bkWhereSQL");
$cntStmt->execute($bkParams);
$bkTotal = (int)$cntStmt->fetchColumn();
$bkPages = max(1, (int)ceil($bkTotal / $perPage));
$page    = min($page, $bkPages);
$bkOff   = ($page - 1) * $perPage;

$bkStmt = $pdo->prepare("
    SELECT dp.*, kh.HoTen AS TenKH, kh.SoDienThoai,
           p.LoaiPhong, p.GiaPhong, p.Tang
    FROM DAT_PHONG dp
    JOIN KHACH_HANG kh ON dp.MaKH=kh.MaKH
    JOIN PHONG p ON dp.MaPhong=p.MaPhong
    $bkWhereSQL
    ORDER BY dp.NgayDat DESC
    LIMIT :lim OFFSET :off");
foreach ($bkParams as $k => $v) $bkStmt->bindValue($k, $v);
$bkStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$bkStmt->bindValue(':off', $bkOff,  PDO::PARAM_INT);
$bkStmt->execute();
$bookings = $bkStmt->fetchAll();

// URL builder cho pagination — giữ nguyên filter + search
$bkUrl = fn(array $ov = []) => '?' . http_build_query(array_merge(
    ['tab' => 'bookings', 'filter' => $filterStatus, 'search' => $search],
    $ov
));

// Phòng trống + khách hàng cho form đặt phòng
$availRooms = $pdo->query("SELECT * FROM PHONG WHERE TinhTrang='Trống' ORDER BY Tang, MaPhong")->fetchAll();
$customers  = $pdo->query("SELECT MaKH, HoTen, SoDienThoai FROM KHACH_HANG ORDER BY HoTen")->fetchAll();

// Danh sách nhân viên / tài khoản
$staffList = $pdo->query("
    SELECT tk.TenTK, tk.VaiTro, tk.TrangThai, tk.NgayTaoTK, tk.LanDangNhapCuoi,
           nv.MaNV, nv.HoTen, nv.SoDienThoai, nv.Email, nv.ChucVu, nv.NgayVaoLam
    FROM TAI_KHOAN tk
    LEFT JOIN NHAN_VIEN nv ON tk.MaNV=nv.MaNV
    ORDER BY tk.NgayTaoTK DESC")->fetchAll();

// Báo cáo doanh thu theo tháng (6 tháng gần nhất)
$revMonth = $pdo->query("
    SELECT DATE_FORMAT(NgayDat,'%m/%Y') AS Thang,
           MONTH(NgayDat) AS M, YEAR(NgayDat) AS Y,
           COUNT(*) AS TongDon,
           COUNT(CASE WHEN TrangThai='Đã hủy' THEN 1 END) AS SoHuy,
           COUNT(CASE WHEN TrangThai NOT IN ('Đã hủy') THEN 1 END) AS SoHieuLuc,
           COALESCE(SUM(CASE WHEN TrangThai NOT IN ('Đã hủy') THEN TongGia ELSE 0 END),0) AS DoanhThu
    FROM DAT_PHONG
    WHERE NgayDat >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY Y, M, Thang
    ORDER BY Y DESC, M DESC")->fetchAll();

$revType = $pdo->query("
    SELECT p.LoaiPhong,
           COUNT(dp.MaDP) AS TongDon,
           COALESCE(SUM(CASE WHEN dp.TrangThai NOT IN ('Đã hủy') THEN dp.TongGia ELSE 0 END),0) AS DoanhThu
    FROM PHONG p
    LEFT JOIN DAT_PHONG dp ON p.MaPhong=dp.MaPhong
    GROUP BY p.LoaiPhong
    ORDER BY DoanhThu DESC")->fetchAll();

$maxRevType = max(array_column($revType, 'DoanhThu') ?: [1]);

// Helpers
function fmt(mixed $n): string  { return number_format((float)$n, 0, ',', '.'); }
function esc(mixed $s): string  { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function statusBadge(mixed $s): string {
    $map = [
        'Chờ xác nhận' => ['#f59e0b','#fffbeb','⏳'],
        'Đã nhận phòng'=> ['#3b82f6','#eff6ff','🏨'],
        'Đã trả phòng' => ['#10b981','#ecfdf5','✅'],
        'Đã hủy'       => ['#6b7280','#f1f5f9','✗'],
    ];
    $c = $map[$s] ?? ['#94a3b8','#f8fafc','?'];
    return "<span style='display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
            background:{$c[1]};color:{$c[0]};border:1px solid {$c[0]}40;letter-spacing:.5px'>{$c[2]} " . esc($s) . "</span>";
}
$navItems = [
    'overview'    => ['icon'=>'📊','label'=>'Tổng Quan'],
    'bookings'    => ['icon'=>'📋','label'=>'Quản Lý Đặt Phòng'],
    'new-booking' => ['icon'=>'➕','label'=>'Đặt Phòng Mới'],
    'staff'       => ['icon'=>'👥','label'=>'Nhân Viên'],
    'report'      => ['icon'=>'📈','label'=>'Báo Cáo Doanh Thu'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản Trị — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:       #1d4ed8;
  --blue-light: #3b82f6;
  --blue-dark:  #1e3a8a;
  --blue-pale:  #eff6ff;
  --blue-mid:   #dbeafe;
  --bg:         #f0f6ff;
  --text:       #1e293b;
  --muted:      #64748b;
  --border:     #bfdbfe;
  --shadow:     0 4px 24px rgba(29,78,216,.10);
  --shadow-lg:  0 12px 48px rgba(29,78,216,.15);
  --radius:     10px;
  --font:       Calibri,'Calibri Light',Arial,sans-serif;
  --serif:      'Playfair Display',Georgia,serif;
  --sidebar-w:  260px;
}
html,body{height:100%;font-family:var(--font);font-size:15px;color:var(--text)}
body{display:flex;background:var(--bg);overflow-x:hidden}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar-w);min-height:100vh;flex-shrink:0;
  background:linear-gradient(175deg,var(--blue-dark) 0%,#1a3070 60%,#16275e 100%);
  display:flex;flex-direction:column;
  box-shadow:4px 0 24px rgba(29,78,216,.25);
  position:sticky;top:0;height:100vh;overflow-y:auto;
}
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
.sb-nav-item{
  display:flex;align-items:center;gap:10px;
  padding:12px 20px;cursor:pointer;text-decoration:none;
  font-size:.82rem;color:rgba(255,255,255,.65);font-weight:600;letter-spacing:.3px;
  transition:all .2s;border-left:3px solid transparent;
}
.sb-nav-item:hover{background:rgba(255,255,255,.07);color:#fff;border-left-color:rgba(255,255,255,.3)}
.sb-nav-item.active{background:rgba(255,255,255,.12);color:#fff;border-left-color:#60a5fa}
.sb-nav-icon{font-size:1rem;width:22px;text-align:center;flex-shrink:0}
.sb-badge{margin-left:auto;background:#ef4444;color:#fff;border-radius:20px;
  font-size:.62rem;font-weight:700;padding:2px 7px;min-width:20px;text-align:center}

.sb-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.1)}
.btn-logout{
  width:100%;padding:10px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);
  color:#fca5a5;border-radius:8px;cursor:pointer;font-family:var(--font);
  font-size:.78rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;
  transition:all .2s;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;
}
.btn-logout:hover{background:rgba(239,68,68,.28);color:#fff}

/* ── MAIN CONTENT ── */
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{
  background:#fff;border-bottom:1.5px solid var(--border);
  padding:14px 28px;display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 2px 8px rgba(29,78,216,.06);position:sticky;top:0;z-index:10;
}
.topbar-title{font-family:var(--serif);font-size:1.2rem;color:var(--blue-dark);display:flex;align-items:center;gap:8px}
.topbar-meta{font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:14px}

.content{padding:24px 28px;flex:1}

/* ── ALERT ── */
.alert{
  padding:12px 16px;border-radius:8px;margin-bottom:20px;
  font-size:.85rem;display:flex;align-items:center;gap:10px;border-left:4px solid;
}
.alert-success{background:#ecfdf5;color:#065f46;border-color:#10b981}
.alert-error  {background:#fff0f0;color:#b91c1c;border-color:#ef4444;animation:shake .35s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px}
.stat-card{
  background:#fff;border-radius:var(--radius);padding:18px 20px;
  border:1.5px solid var(--border);box-shadow:var(--shadow);
  display:flex;flex-direction:column;gap:8px;transition:transform .2s,box-shadow .2s;
}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;
  justify-content:center;font-size:1.3rem;flex-shrink:0}
.stat-value{font-size:1.8rem;font-weight:700;color:var(--blue-dark);line-height:1}
.stat-label{font-size:.72rem;color:var(--muted);letter-spacing:.5px;text-transform:uppercase}

/* ── SECTION HEADER ── */
.section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.section-title{font-family:var(--serif);font-size:1.1rem;color:var(--blue-dark)}
.section-sub{font-size:.75rem;color:var(--muted);margin-top:2px}

/* ── TABLE ── */
.tbl-wrap{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow)}
.tbl-top{padding:14px 18px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.tbl-top-title{font-size:.85rem;font-weight:700;color:var(--blue-dark)}
.filter-tabs{display:flex;gap:4px;flex-wrap:wrap}
.ftab{padding:5px 12px;border-radius:20px;font-size:.72rem;font-weight:700;letter-spacing:.5px;
  cursor:pointer;border:1.5px solid var(--border);background:var(--blue-pale);color:var(--muted);
  text-decoration:none;transition:all .2s}
.ftab:hover,.ftab.active{background:var(--blue);color:#fff;border-color:var(--blue)}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead tr{background:var(--blue-pale)}
th{padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:1px;
  text-transform:uppercase;color:var(--muted);border-bottom:1.5px solid var(--border)}
td{padding:10px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--blue-pale)}
.text-right{text-align:right}
.text-center{text-align:center}

/* ── ACTION BUTTONS ── */
.btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:6px 12px;border-radius:6px;font-family:var(--font);
  font-size:.73rem;font-weight:700;letter-spacing:.5px;cursor:pointer;
  border:none;transition:all .2s;text-decoration:none;
}
.btn-confirm{background:#dbeafe;color:#1d4ed8}
.btn-confirm:hover{background:#1d4ed8;color:#fff}
.btn-cancel{background:#fee2e2;color:#b91c1c}
.btn-cancel:hover{background:#ef4444;color:#fff}
.btn-primary{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(29,78,216,.3)}
.btn-primary:hover{background:var(--blue-dark)}
.btn-danger{background:#fee2e2;color:#b91c1c}
.btn-danger:hover{background:#ef4444;color:#fff}
.btn-restore{background:#d1fae5;color:#065f46}
.btn-restore:hover{background:#10b981;color:#fff}
.btn-sm{padding:4px 9px;font-size:.7rem}

/* ── FORM ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
label.lbl{font-size:.68rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted)}
.req{color:#ef4444;margin-left:2px}
.form-ctrl{
  padding:10px 13px;border:1.5px solid var(--border);border-radius:8px;
  font-family:var(--font);font-size:.88rem;color:var(--text);background:var(--blue-pale);
  outline:none;transition:all .25s;
}
.form-ctrl:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.12)}
.form-ctrl::placeholder{color:#94a3b8}
select.form-ctrl{cursor:pointer}
.form-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);padding:24px;box-shadow:var(--shadow)}
.form-footer{margin-top:20px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center}

/* ── ADMIN PROFILE CARD ── */
.profile-card{background:#fff;border-radius:var(--radius);border:1.5px solid var(--border);padding:24px;box-shadow:var(--shadow);margin-bottom:24px}
.profile-header{display:flex;align-items:center;gap:16px;margin-bottom:20px}
.profile-avatar{width:68px;height:68px;background:linear-gradient(135deg,var(--blue-dark),var(--blue));
  border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;flex-shrink:0}
.profile-name{font-family:var(--serif);font-size:1.3rem;color:var(--blue-dark);margin-bottom:4px}
.profile-role-badge{display:inline-block;padding:3px 10px;background:var(--blue-mid);color:var(--blue);
  border-radius:20px;font-size:.68rem;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.profile-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.pinfo-item{background:var(--blue-pale);border-radius:8px;padding:10px 14px}
.pinfo-label{font-size:.62rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
.pinfo-value{font-size:.88rem;color:var(--text);font-weight:600}

/* ── REPORT ── */
.report-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.bar-label{font-size:.75rem;color:var(--text);width:90px;flex-shrink:0;text-align:right}
.bar-track{flex:1;height:22px;background:var(--blue-pale);border-radius:6px;overflow:hidden}
.bar-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--blue-light),var(--blue));
  display:flex;align-items:center;padding:0 8px;font-size:.7rem;color:#fff;font-weight:700;
  transition:width .6s ease;min-width:2px}
.bar-amt{font-size:.72rem;color:var(--muted);width:110px;flex-shrink:0;text-align:right}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--muted)}
.empty-icon{font-size:3rem;margin-bottom:12px}
.empty-text{font-size:.9rem}

/* ── QUICK STATS ROW ── */
.quick-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px}
.quick-card{background:#fff;border-radius:8px;border:1.5px solid var(--border);padding:14px 16px;text-align:center}
.quick-num{font-size:1.4rem;font-weight:700;color:var(--blue-dark)}
.quick-lbl{font-size:.68rem;color:var(--muted);letter-spacing:.5px;text-transform:uppercase;margin-top:3px}

@media(max-width:900px){
  .sidebar{width:64px}.sb-site-name,.sb-site-sub,.sb-admin,.sb-nav-item span,.sb-footer span{display:none}
  .sb-nav-item{justify-content:center;padding:14px}.sb-nav-icon{width:auto}
  .content{padding:16px}.form-grid{grid-template-columns:1fr}.report-grid{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
}

/* ── SEARCH + PAGINATION ── */
.search-row{display:flex;gap:8px;align-items:center}
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
    <?php foreach ($navItems as $key => $item): ?>
    <a href="?tab=<?= $key ?>" class="sb-nav-item <?= $tab===$key?'active':'' ?>">
      <span class="sb-nav-icon"><?= $item['icon'] ?></span>
      <span><?= $item['label'] ?></span>
      <?php if ($key==='bookings' && $stats['pending']>0): ?>
        <span class="sb-badge"><?= $stats['pending'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <a href="calendar.php" class="sb-nav-item">
      <span class="sb-nav-icon">📅</span>
      <span>Lịch Đặt Phòng</span>
    </a>
    <div class="sb-nav-divider"></div>
    <a href="rooms.php" class="sb-nav-item">
      <span class="sb-nav-icon">🛏️</span>
      <span>Quản Lý Phòng</span>
    </a>
    <a href="housekeeping.php" class="sb-nav-item">
      <span class="sb-nav-icon">🧹</span>
      <span>Dọn Phòng</span>
    </a>
    <a href="services.php" class="sb-nav-item">
      <span class="sb-nav-icon">🛎️</span>
      <span>Dịch Vụ</span>
    </a>
    <a href="promotions.php" class="sb-nav-item">
      <span class="sb-nav-icon">🎁</span>
      <span>Khuyến Mãi</span>
    </a>
    <a href="invoices.php" class="sb-nav-item">
    <a href="reports.php"                   class="sb-nav-item"><span class="sb-nav-icon">📈</span><span>Báo Cáo</span></a>
      <span class="sb-nav-icon">🧾</span>
      <span>Hóa Đơn</span>
    </a>
    <div class="sb-nav-divider"></div>
    <a href="accounts.php" class="sb-nav-item">
      <span class="sb-nav-icon">🔑</span>
      <span>Tài Khoản</span>
    </a>
  </nav>

  <div class="sb-footer">
    <a href="../logout.php" class="btn-logout">🚪 <span>Đăng Xuất</span></a>
  </div>

</aside>

<!-- ════ MAIN ════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div>
      <div class="topbar-title">
        <?= $navItems[$tab]['icon'] ?? '📊' ?> <?= esc($navItems[$tab]['label'] ?? 'Tổng Quan') ?>
      </div>
    </div>
    <div class="topbar-meta">
      <span>📅 <?= date('d/m/Y H:i') ?></span>
      <span>👑 <?= esc($adminName) ?></span>
    </div>
  </div>

  <div class="content">

    <!-- Alert message -->
    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>">
      <?= $msgType === 'error' ? '⚠' : '✅' ?> <?= esc($msg) ?>
    </div>
    <?php endif; ?>

    <?php /* ════════════ TAB: TỔNG QUAN ════════════ */ if ($tab === 'overview'): ?>

      <!-- Admin Profile -->
      <div class="profile-card">
        <div class="profile-header">
          <div class="profile-avatar">👑</div>
          <div>
            <div class="profile-name"><?= esc($adminName) ?></div>
            <div class="profile-role-badge">Quản Lý Hệ Thống</div>
          </div>
        </div>
        <div class="profile-info-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Mã Nhân Viên</div>
            <div class="pinfo-value"><?= esc($adminProfile['MaNV'] ?? '—') ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Tên Tài Khoản</div>
            <div class="pinfo-value"><?= esc($adminId) ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Điện Thoại</div>
            <div class="pinfo-value"><?= esc($adminProfile['SoDienThoai'] ?? '—') ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Email</div>
            <div class="pinfo-value"><?= esc($adminProfile['Email'] ?? '—') ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Chức Vụ</div>
            <div class="pinfo-value"><?= esc($adminProfile['ChucVu'] ?? 'Quản lý') ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Ngày Vào Làm</div>
            <div class="pinfo-value"><?= $adminProfile['NgayVaoLam'] ? date('d/m/Y', strtotime($adminProfile['NgayVaoLam'])) : '—' ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Đăng Nhập Gần Nhất</div>
            <div class="pinfo-value"><?= $adminProfile['LanDangNhapCuoi'] ? date('d/m/Y H:i', strtotime($adminProfile['LanDangNhapCuoi'])) : '—' ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Ngày Tạo TK</div>
            <div class="pinfo-value"><?= $adminProfile['NgayTaoTK'] ? date('d/m/Y', strtotime($adminProfile['NgayTaoTK'])) : '—' ?></div>
          </div>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#eff6ff">📋</div>
          <div class="stat-value"><?= $stats['total'] ?></div>
          <div class="stat-label">Tổng Đặt Phòng</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fffbeb">⏳</div>
          <div class="stat-value" style="color:#d97706"><?= $stats['pending'] ?></div>
          <div class="stat-label">Chờ Xác Nhận</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fef2f2">🏨</div>
          <div class="stat-value" style="color:#dc2626"><?= $stats['occupied'] ?> / <?= $stats['total_room'] ?></div>
          <div class="stat-label">Phòng Đang Ở</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#f0fdf4">📅</div>
          <div class="stat-value" style="color:#16a34a"><?= $stats['checkin_today'] ?></div>
          <div class="stat-label">Check-in Hôm Nay</div>
        </div>
        <div class="stat-card" style="grid-column:span 2">
          <div class="stat-icon" style="background:#eff6ff">💰</div>
          <div class="stat-value"><?= fmt($stats['revenue_month']) ?>đ</div>
          <div class="stat-label">Doanh Thu Tháng Này (VNĐ)</div>
        </div>
      </div>

      <!-- Pending bookings list -->
      <?php
        $pendingList = $pendingBookings;
      ?>
      <?php if (count($pendingList) > 0): ?>
      <div class="tbl-wrap">
        <div class="tbl-top">
          <div>
            <div class="tbl-top-title">⏳ Đặt Phòng Chờ Xác Nhận</div>
          </div>
          <a href="?tab=bookings" class="btn btn-primary btn-sm">Xem tất cả →</a>
        </div>
        <table>
          <thead><tr>
            <th>Mã ĐP</th><th>Khách Hàng</th><th>Phòng</th>
            <th>Check-in</th><th>Check-out</th><th>Tổng Giá</th><th>Thao Tác</th>
          </tr></thead>
          <tbody>
          <?php foreach (array_slice($pendingList, 0, 5) as $bk): ?>
          <tr>
            <td><strong><?= esc($bk['MaDP']) ?></strong></td>
            <td>
              <div><?= esc($bk['TenKH']) ?></div>
              <div style="font-size:.72rem;color:var(--muted)"><?= esc($bk['SoDienThoai']) ?></div>
            </td>
            <td><?= esc($bk['MaPhong']) ?> — <?= esc($bk['LoaiPhong']) ?></td>
            <td><?= date('d/m/Y', strtotime($bk['NgayCheckIn'])) ?></td>
            <td><?= date('d/m/Y', strtotime($bk['NgayCheckOut'])) ?></td>
            <td class="text-right"><strong><?= fmt($bk['TongGia']) ?>đ</strong></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="confirm_booking">
                <input type="hidden" name="ma_dp" value="<?= esc($bk['MaDP']) ?>">
                <button type="submit" class="btn btn-confirm btn-sm">✓ Xác Nhận</button>
              </form>
              <form method="POST" style="display:inline;margin-left:4px" onsubmit="return confirm('Hủy đặt phòng <?= esc($bk['MaDP']) ?>?')">
                <input type="hidden" name="action" value="cancel_booking">
                <input type="hidden" name="ma_dp" value="<?= esc($bk['MaDP']) ?>">
                <button type="submit" class="btn btn-cancel btn-sm">✕ Hủy</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <div class="empty-state"><div class="empty-icon">🎉</div><div class="empty-text">Không có đặt phòng nào chờ xác nhận.</div></div>
      </div>
      <?php endif; ?>

    <?php /* ════════════ TAB: QUẢN LÝ ĐẶT PHÒNG ════════════ */ elseif ($tab === 'bookings'): ?>

      <div class="quick-row">
        <?php
          $countMap = [
              'Chờ xác nhận' => $stats['pending'],
              'Đã nhận phòng'=> $stats['bk_dang'],
              'Đã trả phòng' => $stats['bk_tra'],
              'Đã hủy'       => $stats['bk_huy'],
          ];
          $colors = ['Chờ xác nhận'=>'#d97706','Đã nhận phòng'=>'#1d4ed8','Đã trả phòng'=>'#16a34a','Đã hủy'=>'#6b7280'];
        ?>
        <?php foreach ($countMap as $st => $cnt): ?>
        <div class="quick-card">
          <div class="quick-num" style="color:<?= $colors[$st] ?>"><?= $cnt ?></div>
          <div class="quick-lbl"><?= esc($st) ?></div>
        </div>
        <?php endforeach; ?>
        <div class="quick-card">
          <div class="quick-num"><?= $stats['total'] ?></div>
          <div class="quick-lbl">Tổng cộng</div>
        </div>
        <div class="quick-card">
          <div class="quick-num" style="color:#16a34a"><?= fmt($stats['revenue_month']) ?>đ</div>
          <div class="quick-lbl">Doanh thu tháng</div>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-row" style="margin-bottom:12px">
        <input type="hidden" name="tab" value="bookings">
        <input type="hidden" name="filter" value="<?= esc($filterStatus) ?>">
        <div class="search-wrap">
          <span class="search-icon">🔍</span>
          <input type="text" name="search" value="<?= esc($search) ?>"
                 class="search-input" placeholder="Tìm tên khách hàng, SĐT, mã ĐP, mã phòng...">
          <?php if ($search !== ''): ?>
          <a href="<?= esc($bkUrl(['search' => '', 'page' => 1])) ?>" class="search-clear" title="Xoá tìm kiếm">✕</a>
          <?php endif; ?>
        </div>
        <button type="submit" class="btn-search">🔍 Tìm</button>
      </form>

      <div class="tbl-wrap">
        <div class="tbl-top">
          <div>
            <div class="tbl-top-title">
              📋 Danh Sách Đặt Phòng
              <?= $search !== '' ? "<span style='font-size:.8rem;font-weight:400;color:var(--blue)'>— \"" . esc($search) . "\"</span>" : '' ?>
            </div>
            <div style="font-size:.72rem;color:var(--muted);margin-top:2px"><?= $bkTotal ?> kết quả</div>
          </div>
          <div class="filter-tabs">
            <a href="<?= esc($bkUrl(['filter' => 'all', 'page' => 1])) ?>" class="ftab <?= $filterStatus==='all'?'active':'' ?>">Tất cả (<?= $stats['total'] ?>)</a>
            <a href="<?= esc($bkUrl(['filter' => 'Chờ xác nhận', 'page' => 1])) ?>" class="ftab <?= $filterStatus==='Chờ xác nhận'?'active':'' ?>">⏳ Chờ XN (<?= $stats['pending'] ?>)</a>
            <a href="<?= esc($bkUrl(['filter' => 'Đã nhận phòng', 'page' => 1])) ?>" class="ftab <?= $filterStatus==='Đã nhận phòng'?'active':'' ?>">🏨 Đang ở (<?= $stats['bk_dang'] ?>)</a>
            <a href="<?= esc($bkUrl(['filter' => 'Đã trả phòng', 'page' => 1])) ?>" class="ftab <?= $filterStatus==='Đã trả phòng'?'active':'' ?>">✅ Đã trả (<?= $stats['bk_tra'] ?>)</a>
            <a href="<?= esc($bkUrl(['filter' => 'Đã hủy', 'page' => 1])) ?>" class="ftab <?= $filterStatus==='Đã hủy'?'active':'' ?>">✗ Đã hủy (<?= $stats['bk_huy'] ?>)</a>
          </div>
        </div>
        <?php if (empty($bookings)): ?>
          <div class="empty-state"><div class="empty-icon">📭</div>
            <div class="empty-text"><?= $search ? "Không tìm thấy đặt phòng nào khớp với \"" . esc($search) . "\"." : 'Không có đặt phòng nào.' ?></div>
          </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
          <thead><tr>
            <th>Mã ĐP</th><th>Khách Hàng</th><th>Phòng</th>
            <th>Check-in</th><th>Check-out</th><th>Tổng Giá</th>
            <th>Tiền Cọc</th><th>Trạng Thái</th><th>Thao Tác</th>
          </tr></thead>
          <tbody>
          <?php foreach ($bookings as $bk): ?>
          <tr>
            <td><strong><?= esc($bk['MaDP']) ?></strong><br><span style="font-size:.68rem;color:var(--muted)"><?= date('d/m/Y', strtotime($bk['NgayDat'])) ?></span></td>
            <td>
              <div style="font-weight:600"><?= esc($bk['TenKH']) ?></div>
              <div style="font-size:.72rem;color:var(--muted)"><?= esc($bk['SoDienThoai']) ?></div>
            </td>
            <td>
              <div><?= esc($bk['MaPhong']) ?></div>
              <div style="font-size:.72rem;color:var(--muted)"><?= esc($bk['LoaiPhong']) ?> · T<?= esc($bk['Tang']) ?></div>
            </td>
            <td><?= date('d/m/Y', strtotime($bk['NgayCheckIn'])) ?></td>
            <td><?= date('d/m/Y', strtotime($bk['NgayCheckOut'])) ?></td>
            <td class="text-right"><strong><?= fmt($bk['TongGia']) ?>đ</strong></td>
            <td class="text-right"><?= fmt($bk['TienCoc']) ?>đ</td>
            <td><?= statusBadge($bk['TrangThai']) ?></td>
            <td style="white-space:nowrap">
              <?php if ($bk['TrangThai'] === 'Chờ xác nhận'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="confirm_booking">
                <input type="hidden" name="ma_dp" value="<?= esc($bk['MaDP']) ?>">
                <button type="submit" class="btn btn-confirm btn-sm">✓ XN</button>
              </form>
              <?php endif; ?>
              <?php if (in_array($bk['TrangThai'], ['Chờ xác nhận','Đã nhận phòng'])): ?>
              <form method="POST" style="display:inline;margin-left:3px"
                    onsubmit="return confirm('Xác nhận hủy đặt phòng <?= esc($bk['MaDP']) ?>?')">
                <input type="hidden" name="action" value="cancel_booking">
                <input type="hidden" name="ma_dp" value="<?= esc($bk['MaDP']) ?>">
                <button type="submit" class="btn btn-cancel btn-sm">✕ Hủy</button>
              </form>
              <?php endif; ?>
              <?php if ($bk['TrangThai'] === 'Đã hủy' || $bk['TrangThai'] === 'Đã trả phòng'): ?>
                <span style="font-size:.72rem;color:#94a3b8">—</span>
              <?php endif; ?>
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
              Không có kết quả<?= $search ? " cho \"" . esc($search) . "\"" : '' ?>
            <?php else: ?>
              Hiển thị <?= $bkOff + 1 ?>–<?= min($bkOff + $perPage, $bkTotal) ?> / <?= $bkTotal ?> đặt phòng
            <?php endif; ?>
          </span>
          <?php if ($bkPages > 1): ?>
          <div class="pager-btns">
            <a href="<?= esc($bkUrl(['page' => $page - 1])) ?>"
               class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>
            <?php
            $pStart = max(1, $page - 2);
            $pEnd   = min($bkPages, $page + 2);
            if ($pStart > 1) echo '<span class="pager-btn disabled">…</span>';
            for ($p = $pStart; $p <= $pEnd; $p++):
            ?>
            <a href="<?= esc($bkUrl(['page' => $p])) ?>"
               class="pager-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($pEnd < $bkPages) echo '<span class="pager-btn disabled">…</span>'; ?>
            <a href="<?= esc($bkUrl(['page' => $page + 1])) ?>"
               class="pager-btn <?= $page >= $bkPages ? 'disabled' : '' ?>">›</a>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

    <?php /* ════════════ TAB: ĐẶT PHÒNG MỚI ════════════ */ elseif ($tab === 'new-booking'): ?>

      <div class="form-card">
        <div class="section-hdr" style="margin-bottom:20px">
          <div>
            <div class="section-title">➕ Tạo Đặt Phòng Mới</div>
            <div class="section-sub">Admin thêm đặt phòng trực tiếp vào hệ thống</div>
          </div>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="new_booking">
          <div class="form-grid">
            <div class="form-group">
              <label class="lbl">Khách Hàng <span class="req">*</span></label>
              <?php if (empty($customers)): ?>
                <div style="padding:10px;background:#fffbeb;border-radius:8px;font-size:.82rem;color:#92400e">
                  ⚠ Chưa có khách hàng trong hệ thống.
                </div>
                <input type="hidden" name="ma_kh" value="">
              <?php else: ?>
              <select name="ma_kh" class="form-ctrl" required>
                <option value="">— Chọn khách hàng —</option>
                <?php foreach ($customers as $kh): ?>
                <option value="<?= esc($kh['MaKH']) ?>">
                  <?= esc($kh['HoTen']) ?> (<?= esc($kh['SoDienThoai']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label class="lbl">Phòng <span class="req">*</span></label>
              <?php if (empty($availRooms)): ?>
                <div style="padding:10px;background:#fffbeb;border-radius:8px;font-size:.82rem;color:#92400e">
                  ⚠ Không có phòng trống.
                </div>
                <input type="hidden" name="ma_phong" value="">
              <?php else: ?>
              <select name="ma_phong" class="form-ctrl" required>
                <option value="">— Chọn phòng —</option>
                <?php foreach ($availRooms as $r): ?>
                <option value="<?= esc($r['MaPhong']) ?>">
                  <?= esc($r['MaPhong']) ?> — <?= esc($r['LoaiPhong']) ?> (<?= fmt($r['GiaPhong']) ?>đ/đêm · Tầng <?= $r['Tang'] ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label class="lbl">Ngày Check-in <span class="req">*</span></label>
              <input type="date" name="check_in" class="form-ctrl" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
              <label class="lbl">Ngày Check-out <span class="req">*</span></label>
              <input type="date" name="check_out" class="form-ctrl" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
            </div>
            <div class="form-group">
              <label class="lbl">Số Lượng Khách</label>
              <input type="number" name="so_khach" class="form-ctrl" value="1" min="1" max="10">
            </div>
            <div class="form-group">
              <label class="lbl">Tiền Cọc (VNĐ)</label>
              <input type="number" name="tien_coc" class="form-ctrl" value="0" min="0" step="50000">
            </div>
            <div class="form-group full">
              <label class="lbl">Ghi Chú</label>
              <textarea name="ghi_chu" class="form-ctrl" rows="3" placeholder="Yêu cầu đặc biệt, ghi chú thêm..."></textarea>
            </div>
          </div>
          <div class="form-footer">
            <button type="submit" class="btn btn-primary">➕ Tạo Đặt Phòng</button>
            <a href="?tab=bookings" class="btn" style="background:var(--blue-pale);color:var(--muted)">← Quay lại</a>
          </div>
        </form>
      </div>

    <?php /* ════════════ TAB: NHÂN VIÊN ════════════ */ elseif ($tab === 'staff'): ?>

      <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">

        <!-- Danh sách nhân viên -->
        <div class="tbl-wrap">
          <div class="tbl-top">
            <div class="tbl-top-title">👥 Danh Sách Tài Khoản Nhân Viên</div>
          </div>
          <?php if (empty($staffList)): ?>
            <div class="empty-state"><div class="empty-icon">👤</div><div class="empty-text">Chưa có nhân viên nào.</div></div>
          <?php else: ?>
          <div style="overflow-x:auto">
          <table>
            <thead><tr>
              <th>Mã NV</th><th>Họ Tên</th><th>Tài Khoản</th>
              <th>Vai Trò</th><th>Trạng Thái</th><th>Đăng Nhập Cuối</th><th>Thao Tác</th>
            </tr></thead>
            <tbody>
            <?php foreach ($staffList as $s): ?>
            <tr>
              <td><strong><?= esc($s['MaNV'] ?? '—') ?></strong></td>
              <td>
                <div style="font-weight:600"><?= esc($s['HoTen'] ?? $s['TenTK']) ?></div>
                <div style="font-size:.7rem;color:var(--muted)"><?= esc($s['Email'] ?? '') ?></div>
              </td>
              <td>
                <div><?= esc($s['TenTK']) ?></div>
                <div style="font-size:.7rem;color:var(--muted)"><?= esc($s['ChucVu'] ?? '') ?></div>
              </td>
              <td>
                <?php if ($s['VaiTro']==='admin'): ?>
                  <span style="color:#1d4ed8;font-weight:700;font-size:.75rem">👑 Admin</span>
                <?php else: ?>
                  <span style="color:#64748b;font-size:.75rem">🏨 Nhân viên</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($s['TrangThai']==='Hoạt động'): ?>
                  <span style="color:#16a34a;font-weight:700;font-size:.75rem">● Hoạt động</span>
                <?php else: ?>
                  <span style="color:#ef4444;font-weight:700;font-size:.75rem">● Ngừng HĐ</span>
                <?php endif; ?>
              </td>
              <td style="font-size:.75rem;color:var(--muted)">
                <?= $s['LanDangNhapCuoi'] ? date('d/m/Y H:i', strtotime($s['LanDangNhapCuoi'])) : '—' ?>
              </td>
              <td style="white-space:nowrap">
                <?php if ($s['TenTK'] !== $adminId): ?>
                  <?php if ($s['TrangThai'] === 'Hoạt động'): ?>
                  <form method="POST" style="display:inline"
                        onsubmit="return confirm('Vô hiệu hóa tài khoản <?= esc($s['TenTK']) ?>?')">
                    <input type="hidden" name="action" value="delete_staff">
                    <input type="hidden" name="ten_tk" value="<?= esc($s['TenTK']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">✕ Vô hiệu</button>
                  </form>
                  <?php else: ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="restore_staff">
                    <input type="hidden" name="ten_tk" value="<?= esc($s['TenTK']) ?>">
                    <button type="submit" class="btn btn-restore btn-sm">↩ Khôi phục</button>
                  </form>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="font-size:.72rem;color:#94a3b8">← Bạn</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>

        <!-- Form tạo tài khoản -->
        <div class="form-card" style="position:sticky;top:80px">
          <div class="section-title" style="margin-bottom:16px">➕ Thêm Nhân Viên Mới</div>
          <form method="POST">
            <input type="hidden" name="action" value="create_staff">
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group">
                <label class="lbl">Họ Tên <span class="req">*</span></label>
                <input type="text" name="ho_ten" class="form-ctrl" placeholder="Nguyễn Văn A" required>
              </div>
              <div class="form-group">
                <label class="lbl">Số Điện Thoại <span class="req">*</span></label>
                <input type="text" name="so_dt" class="form-ctrl" placeholder="09xxxxxxxx" required>
              </div>
              <div class="form-group">
                <label class="lbl">Email</label>
                <input type="email" name="email" class="form-ctrl" placeholder="nv@easyhome.vn">
              </div>
              <div class="form-group">
                <label class="lbl">Chức Vụ</label>
                <select name="chuc_vu" class="form-ctrl">
                  <option value="Lễ tân">Lễ tân</option>
                  <option value="Quản lý">Quản lý</option>
                </select>
              </div>
              <div class="form-group">
                <label class="lbl">Tên Tài Khoản <span class="req">*</span></label>
                <input type="text" name="ten_tk" class="form-ctrl" placeholder="letan03" required>
              </div>
              <div class="form-group">
                <label class="lbl">Mật Khẩu <span class="req">*</span></label>
                <input type="password" name="mat_khau" class="form-ctrl" placeholder="Tối thiểu 6 ký tự" required minlength="6">
              </div>
              <div class="form-group">
                <label class="lbl">Vai Trò Hệ Thống</label>
                <select name="vai_tro" class="form-ctrl">
                  <option value="nhanvien">Nhân viên</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
            </div>
            <div class="form-footer" style="margin-top:16px;padding-top:12px">
              <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                ➕ Tạo Tài Khoản
              </button>
            </div>
          </form>
        </div>

      </div>

    <?php /* ════════════ TAB: BÁO CÁO DOANH THU ════════════ */ elseif ($tab === 'report'): ?>

      <div class="report-grid">

        <!-- Doanh thu theo tháng -->
        <div class="form-card">
          <div class="section-title" style="margin-bottom:16px">📅 Doanh Thu 6 Tháng Gần Nhất</div>
          <?php if (empty($revMonth)): ?>
            <div class="empty-state" style="padding:24px"><div class="empty-icon" style="font-size:2rem">📊</div><div class="empty-text">Chưa có dữ liệu.</div></div>
          <?php else: ?>
          <?php $maxRev = max(array_column($revMonth, 'DoanhThu') ?: [1]); ?>
          <?php foreach ($revMonth as $r): ?>
          <div class="bar-row">
            <div class="bar-label"><?= esc($r['Thang']) ?></div>
            <div class="bar-track">
              <div class="bar-fill" style="width:<?= $maxRev > 0 ? round($r['DoanhThu']/$maxRev*100) : 0 ?>%">
                <?php if ($r['DoanhThu'] > 0): ?><?= $r['SoHieuLuc'] ?> đơn<?php endif; ?>
              </div>
            </div>
            <div class="bar-amt"><?= fmt($r['DoanhThu']) ?>đ</div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Doanh thu theo loại phòng -->
        <div class="form-card">
          <div class="section-title" style="margin-bottom:16px">🛏 Doanh Thu Theo Loại Phòng</div>
          <?php foreach ($revType as $r): ?>
          <div class="bar-row">
            <div class="bar-label"><?= esc($r['LoaiPhong']) ?></div>
            <div class="bar-track">
              <div class="bar-fill" style="width:<?= $maxRevType > 0 ? round($r['DoanhThu']/$maxRevType*100) : 0 ?>%;background:linear-gradient(90deg,#10b981,#059669)">
                <?php if ($r['DoanhThu'] > 0): ?><?= $r['TongDon'] ?> đơn<?php endif; ?>
              </div>
            </div>
            <div class="bar-amt"><?= fmt($r['DoanhThu']) ?>đ</div>
          </div>
          <?php endforeach; ?>
        </div>

      </div>

      <!-- Bảng chi tiết theo tháng -->
      <div class="tbl-wrap">
        <div class="tbl-top">
          <div class="tbl-top-title">📊 Chi Tiết Theo Tháng</div>
        </div>
        <?php if (empty($revMonth)): ?>
          <div class="empty-state"><div class="empty-icon">📭</div><div class="empty-text">Chưa có dữ liệu đặt phòng.</div></div>
        <?php else: ?>
        <table>
          <thead><tr>
            <th>Tháng</th>
            <th class="text-center">Tổng Đơn</th>
            <th class="text-center">Hiệu Lực</th>
            <th class="text-center">Đã Hủy</th>
            <th class="text-right">Doanh Thu (VNĐ)</th>
            <th class="text-right">TB / Đơn</th>
          </tr></thead>
          <tbody>
          <?php $grandTotal = 0; ?>
          <?php foreach ($revMonth as $r): ?>
          <?php $grandTotal += $r['DoanhThu']; ?>
          <tr>
            <td><strong><?= esc($r['Thang']) ?></strong></td>
            <td class="text-center"><?= $r['TongDon'] ?></td>
            <td class="text-center" style="color:#16a34a;font-weight:700"><?= $r['SoHieuLuc'] ?></td>
            <td class="text-center" style="color:#ef4444"><?= $r['SoHuy'] ?></td>
            <td class="text-right"><strong><?= fmt($r['DoanhThu']) ?>đ</strong></td>
            <td class="text-right" style="color:var(--muted)">
              <?= $r['SoHieuLuc'] > 0 ? fmt($r['DoanhThu'] / $r['SoHieuLuc']) . 'đ' : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:var(--blue-pale)">
              <td colspan="4" style="padding:10px 14px;font-weight:700;color:var(--blue-dark)">Tổng Cộng</td>
              <td class="text-right" style="padding:10px 14px;font-weight:700;color:var(--blue-dark);font-size:1rem"><?= fmt($grandTotal) ?>đ</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
// Tự động tính ngày checkout khi chọn check-in
const cin = document.querySelector('input[name="check_in"]');
const cout = document.querySelector('input[name="check_out"]');
if (cin && cout) {
    cin.addEventListener('change', function() {
        const next = new Date(this.value);
        next.setDate(next.getDate() + 1);
        const y = next.getFullYear();
        const m = String(next.getMonth()+1).padStart(2,'0');
        const d = String(next.getDate()).padStart(2,'0');
        cout.min = `${y}-${m}-${d}`;
        if (!cout.value || cout.value <= this.value) cout.value = `${y}-${m}-${d}`;
    });
}
// Tự động ẩn alert sau 5s
const alert = document.querySelector('.alert');
if (alert) setTimeout(() => { alert.style.transition='opacity .5s'; alert.style.opacity='0'; setTimeout(()=>alert.remove(),500); }, 5000);
</script>
</body>
</html>
