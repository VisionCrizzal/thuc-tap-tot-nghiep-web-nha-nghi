<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

// ── Search parameters ────────────────────────────────────────────────────────
$checkin     = trim($_GET['checkin']  ?? '');
$checkout    = trim($_GET['checkout'] ?? '');
$typeFilter  = trim($_GET['type']     ?? '');

$hasSearch   = false;
$searchError = '';
if ($checkin || $checkout) {
    if (!$checkin || !$checkout) {
        $searchError = 'Vui lòng nhập đầy đủ ngày nhận và ngày trả phòng.';
    } elseif ($checkin >= $checkout) {
        $searchError = 'Ngày trả phòng phải sau ngày nhận.';
    } elseif ($checkin < date('Y-m-d')) {
        $searchError = 'Ngày nhận không thể là ngày trong quá khứ.';
    } else {
        $hasSearch = true;
    }
}

$allowedTypes = ['Đơn','Đôi','Gia đình','VIP'];
$validType    = (in_array($typeFilter, $allowedTypes)) ? $typeFilter : '';

// ── Query phòng ──────────────────────────────────────────────────────────────
if ($hasSearch) {
    $sql = "SELECT p.* FROM PHONG p
            WHERE p.TinhTrang != 'Bảo trì'
              AND p.MaPhong NOT IN (
                  SELECT MaPhong FROM DAT_PHONG
                  WHERE TrangThai NOT IN ('Đã hủy','Đã trả phòng')
                    AND NgayCheckIn  < :cout
                    AND NgayCheckOut > :cin
              )" . ($validType ? " AND p.LoaiPhong = :lt" : "") . "
            ORDER BY FIELD(p.TinhTrang,'Trống','Đang dọn','Đang ở'), p.GiaPhong";
    $stmt = $pdo->prepare($sql);
    $p = [':cin' => $checkin, ':cout' => $checkout];
    if ($validType) $p[':lt'] = $validType;
    $stmt->execute($p);
} else {
    $sql = "SELECT * FROM PHONG WHERE TinhTrang != 'Bảo trì'"
         . ($validType ? " AND LoaiPhong = :lt" : "")
         . " ORDER BY FIELD(TinhTrang,'Trống','Đang dọn','Đang ở'), GiaPhong";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($validType ? [':lt' => $validType] : []);
}
$rooms = $stmt->fetchAll();

// Đếm theo loại phòng (cho filter badges)
if ($hasSearch) {
    $tcSql = "SELECT LoaiPhong, COUNT(*) AS c FROM PHONG
              WHERE TinhTrang != 'Bảo trì'
                AND MaPhong NOT IN (
                    SELECT MaPhong FROM DAT_PHONG
                    WHERE TrangThai NOT IN ('Đã hủy','Đã trả phòng')
                      AND NgayCheckIn < :cout AND NgayCheckOut > :cin
                ) GROUP BY LoaiPhong";
    $tcStmt = $pdo->prepare($tcSql);
    $tcStmt->execute([':cin' => $checkin, ':cout' => $checkout]);
} else {
    $tcStmt = $pdo->query("SELECT LoaiPhong, COUNT(*) AS c FROM PHONG WHERE TinhTrang != 'Bảo trì' GROUP BY LoaiPhong");
}
$typeCounts = $tcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAvail = array_sum($typeCounts);

// Số đêm
$nights = 1;
if ($hasSearch) {
    $nights = max(1, (int)ceil((strtotime($checkout) - strtotime($checkin)) / 86400));
}

// Session
$isCustomer = isset($_SESSION['kh_id']);
$isStaff    = isset($_SESSION['staff_id']);
$userName   = $isCustomer ? $_SESSION['kh_name'] : ($isStaff ? $_SESSION['staff_name'] : '');

// Booking URL builder
function bookingUrl(string $maPhong, string $loaiPhong, string $checkin, string $checkout, bool $isCustomer): string {
    if (!$isCustomer) return 'login.php';
    $p = http_build_query(['tab'=>'booking','room_type'=>$loaiPhong,'checkin'=>$checkin,'checkout'=>$checkout,'ma_phong'=>$maPhong]);
    return "customer/dashboard.php?$p";
}

/* ============================================================
   BED SVG ILLUSTRATIONS (copy từ index.php — KHÔNG thay đổi)
============================================================ */
function singleBedSVG(): string { return '
<svg viewBox="0 0 300 185" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block">
<defs>
  <linearGradient id="sbg" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="#e0f2fe"/><stop offset="100%" stop-color="#bfdbfe"/>
  </linearGradient>
  <linearGradient id="shb" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0%" stop-color="#1e3a8a"/><stop offset="100%" stop-color="#2563eb"/>
  </linearGradient>
  <filter id="sshadow"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="rgba(30,58,138,0.25)"/></filter>
</defs>
<rect width="300" height="185" fill="url(#sbg)"/>
<ellipse cx="150" cy="172" rx="95" ry="8" fill="rgba(30,58,138,0.13)"/>
<polygon points="48,78 60,70 60,115 48,115" fill="#172554"/>
<polygon points="48,78 192,78 204,70 60,70" fill="#1d4ed8"/>
<polygon points="48,78 192,78 192,115 48,115" fill="url(#shb)"/>
<line x1="120" y1="78" x2="120" y2="115" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>
<line x1="120" y1="78" x2="132" y2="70" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>
<polygon points="50,80 190,80 202,72 62,72" fill="rgba(255,255,255,0.12)"/>
<polygon points="48,115 78,143 78,161 48,133" fill="#172554" opacity="0.55"/>
<polygon points="78,143 222,143 222,161 78,161" fill="#1e3a8a"/>
<polygon points="222,143 192,115 192,133 222,161" fill="#2563eb"/>
<line x1="78" y1="143" x2="222" y2="143" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>
<polygon points="78,143 222,143 192,115 48,115" fill="#f8faff"/>
<polygon points="78,143 222,143 218,140 82,140" fill="#dbeafe" opacity="0.5"/>
<line x1="82" y1="139" x2="218" y2="139" stroke="#bfdbfe" stroke-width="0.8"/>
<polygon points="84,133 190,133 177,120 71,120" fill="rgba(147,197,253,0.35)"/>
<polygon points="87,131 187,131 174,119 74,119" fill="#dbeafe" filter="url(#sshadow)"/>
<polygon points="90,130 184,130 173,121 79,121" fill="#eff6ff" opacity="0.6"/>
<line x1="137" y1="131" x2="124" y2="119" stroke="#93c5fd" stroke-width="1.2"/>
<polygon points="87,131 187,131 174,119 74,119" fill="none" stroke="#93c5fd" stroke-width="1"/>
<polygon points="234,136 252,136 244,128 226,128" fill="#1d4ed8" opacity="0.7"/>
<polygon points="234,136 252,136 252,150 234,150" fill="#1e3a8a" opacity="0.7"/>
<polygon points="252,136 244,128 244,142 252,150" fill="#2563eb" opacity="0.6"/>
<polygon points="240,128 248,128 246,120 242,120" fill="#fef9c3" opacity="0.75"/>
<ellipse cx="244" cy="128" rx="6" ry="3" fill="#fef9c3" opacity="0.85"/>
<circle cx="244" cy="125" r="3" fill="#fef3c7" opacity="0.9"/>
<text x="150" y="178" text-anchor="middle" font-family="sans-serif" font-size="9.5" font-weight="600" fill="rgba(30,58,138,0.5)" letter-spacing="2">PHÒNG ĐƠN</text>
</svg>';}

function doubleBedSVG(bool $vip=false): string {
$petals=$vip?'<ellipse cx="110" cy="130" rx="5" ry="3" fill="#fb7185" opacity="0.85" transform="rotate(-20,110,130)"/><ellipse cx="130" cy="122" rx="4" ry="2.5" fill="#f43f5e" opacity="0.8" transform="rotate(15,130,122)"/><ellipse cx="155" cy="127" rx="5" ry="3" fill="#fb7185" opacity="0.85" transform="rotate(-35,155,127)"/><ellipse cx="170" cy="120" rx="4" ry="2.5" fill="#e11d48" opacity="0.75" transform="rotate(10,170,120)"/><ellipse cx="145" cy="134" rx="4" ry="2.5" fill="#fb7185" opacity="0.8" transform="rotate(25,145,134)"/>':'';
return '<svg viewBox="0 0 300 185" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block">
<defs>
  <linearGradient id="dbg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="'.($vip?'#fdf4ff':'#e0f2fe').'"/><stop offset="100%" stop-color="'.($vip?'#ede9fe':'#bfdbfe').'"/></linearGradient>
  <linearGradient id="dhb" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="'.($vip?'#4c1d95':'#1e3a8a').'"/><stop offset="100%" stop-color="'.($vip?'#7c3aed':'#2563eb').'"/></linearGradient>
  <filter id="dshadow"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="rgba(30,58,138,0.25)"/></filter>
</defs>
<rect width="300" height="185" fill="url(#dbg)"/>
<ellipse cx="150" cy="172" rx="95" ry="8" fill="rgba(30,58,138,0.13)"/>
<polygon points="48,78 60,70 60,115 48,115" fill="'.($vip?'#2e1065':'#172554').'"/>
<polygon points="48,78 192,78 204,70 60,70" fill="'.($vip?'#6d28d9':'#1d4ed8').'"/>
<polygon points="48,78 192,78 192,115 48,115" fill="url(#dhb)"/>
<line x1="120" y1="78" x2="120" y2="115" stroke="rgba(255,255,255,0.1)" stroke-width="1.5"/>
<line x1="120" y1="78" x2="132" y2="70" stroke="rgba(255,255,255,0.1)" stroke-width="1.5"/>
<polygon points="50,80 190,80 202,72 62,72" fill="rgba(255,255,255,0.1)"/>
'.($vip?'<polygon points="48,84 192,84 204,76 60,76" fill="rgba(250,204,21,0.25)"/><polygon points="48,109 192,109 204,101 60,101" fill="rgba(250,204,21,0.25)"/>':'').'
<polygon points="48,115 78,143 78,161 48,133" fill="#172554" opacity="0.55"/>
<polygon points="78,143 222,143 222,161 78,161" fill="'.($vip?'#2e1065':'#1e3a8a').'"/>
<polygon points="222,143 192,115 192,133 222,161" fill="'.($vip?'#7c3aed':'#2563eb').'"/>
<line x1="78" y1="143" x2="222" y2="143" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>
<polygon points="78,143 222,143 192,115 48,115" fill="'.($vip?'#faf5ff':'#f8faff').'"/>
<polygon points="78,143 222,143 218,140 82,140" fill="'.($vip?'#ede9fe':'#dbeafe').'" opacity="0.5"/>
<line x1="82" y1="139" x2="218" y2="139" stroke="'.($vip?'#c4b5fd':'#bfdbfe').'" stroke-width="0.8"/>
'.$petals.'
<polygon points="62,133 126,133 113,120 49,120" fill="rgba(147,197,253,0.3)"/>
<polygon points="65,131 123,131 110,119 52,119" fill="'.($vip?'#ede9fe':'#dbeafe').'" filter="url(#dshadow)"/>
<polygon points="68,130 120,130 108,120 56,120" fill="'.($vip?'#f5f3ff':'#eff6ff').'" opacity="0.6"/>
<line x1="94" y1="131" x2="81" y2="119" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1.2"/>
<polygon points="65,131 123,131 110,119 52,119" fill="none" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1"/>
<polygon points="148,133 212,133 199,120 135,120" fill="rgba(147,197,253,0.3)"/>
<polygon points="151,131 209,131 196,119 138,119" fill="'.($vip?'#ede9fe':'#dbeafe').'" filter="url(#dshadow)"/>
<polygon points="154,130 206,130 194,120 142,120" fill="'.($vip?'#f5f3ff':'#eff6ff').'" opacity="0.6"/>
<line x1="180" y1="131" x2="167" y2="119" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1.2"/>
<polygon points="151,131 209,131 196,119 138,119" fill="none" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1"/>
<polygon points="234,136 252,136 244,128 226,128" fill="'.($vip?'#4c1d95':'#1d4ed8').'" opacity="0.7"/>
<polygon points="234,136 252,136 252,150 234,150" fill="'.($vip?'#2e1065':'#1e3a8a').'" opacity="0.7"/>
<polygon points="252,136 244,128 244,142 252,150" fill="'.($vip?'#7c3aed':'#2563eb').'" opacity="0.6"/>
<polygon points="240,128 248,128 246,120 242,120" fill="#fef9c3" opacity="0.75"/>
<ellipse cx="244" cy="128" rx="6" ry="3" fill="#fef9c3" opacity="0.85"/>
<circle cx="244" cy="125" r="3" fill="#fef3c7" opacity="0.9"/>
<text x="150" y="178" text-anchor="middle" font-family="sans-serif" font-size="9.5" font-weight="600" fill="rgba(30,58,138,0.5)" letter-spacing="2">'.($vip?'PHÒNG VIP':'PHÒNG ĐÔI').'</text>
</svg>';}

function familyBedSVG(): string { return '
<svg viewBox="0 0 300 185" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block">
<defs>
  <linearGradient id="fbg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ecfdf5"/><stop offset="100%" stop-color="#bbf7d0"/></linearGradient>
  <linearGradient id="fhb1" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#065f46"/><stop offset="100%" stop-color="#059669"/></linearGradient>
  <linearGradient id="fhb2" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#1e3a8a"/><stop offset="100%" stop-color="#2563eb"/></linearGradient>
  <filter id="fshadow"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="rgba(5,150,105,0.25)"/></filter>
</defs>
<rect width="300" height="185" fill="url(#fbg)"/>
<ellipse cx="150" cy="173" rx="118" ry="7" fill="rgba(5,150,105,0.14)"/>
<polygon points="7,100 18,93 18,137 7,137" fill="#064e3b"/>
<polygon points="7,100 93,100 104,93 18,93" fill="#059669"/>
<polygon points="7,100 93,100 93,137 7,137" fill="url(#fhb1)"/>
<polygon points="7,137 22,152 22,167 7,152" fill="#064e3b" opacity="0.5"/>
<polygon points="22,152 108,152 108,167 22,167" fill="#065f46"/>
<polygon points="108,152 93,137 93,152 108,167" fill="#059669"/>
<polygon points="22,152 108,152 93,137 7,137" fill="#f0fdf4"/>
<polygon points="22,152 108,152 105,149 25,149" fill="#bbf7d0" opacity="0.5"/>
<polygon points="26,145 87,145 76,133 15,133" fill="rgba(52,211,153,0.3)"/>
<polygon points="29,143 84,143 73,132 18,132" fill="#d1fae5" filter="url(#fshadow)"/>
<polygon points="32,142 81,142 71,133 22,133" fill="#ecfdf5" opacity="0.7"/>
<line x1="56" y1="143" x2="45" y2="132" stroke="#6ee7b7" stroke-width="1.1"/>
<polygon points="29,143 84,143 73,132 18,132" fill="none" stroke="#6ee7b7" stroke-width="0.9"/>
<polygon points="163,100 174,93 174,137 163,137" fill="#172554"/>
<polygon points="163,100 249,100 260,93 174,93" fill="#1d4ed8"/>
<polygon points="163,100 249,100 249,137 163,137" fill="url(#fhb2)"/>
<polygon points="163,137 178,152 178,167 163,152" fill="#172554" opacity="0.5"/>
<polygon points="178,152 264,152 264,167 178,167" fill="#1e3a8a"/>
<polygon points="264,152 249,137 249,152 264,167" fill="#2563eb"/>
<polygon points="178,152 264,152 249,137 163,137" fill="#f8faff"/>
<polygon points="178,152 264,152 261,149 181,149" fill="#dbeafe" opacity="0.5"/>
<polygon points="182,145 243,145 232,133 171,133" fill="rgba(147,197,253,0.3)"/>
<polygon points="185,143 240,143 229,132 174,132" fill="#dbeafe" filter="url(#fshadow)"/>
<polygon points="188,142 237,142 227,133 178,133" fill="#eff6ff" opacity="0.7"/>
<line x1="212" y1="143" x2="201" y2="132" stroke="#93c5fd" stroke-width="1.1"/>
<polygon points="185,143 240,143 229,132 174,132" fill="none" stroke="#93c5fd" stroke-width="0.9"/>
<polygon points="126,144 148,144 142,134 120,134" fill="#1e3a8a" opacity="0.35"/>
<polygon points="126,144 148,144 148,158 126,158" fill="#1e3a8a" opacity="0.4"/>
<polygon points="132,134 142,134 140,127 134,127" fill="#fef9c3" opacity="0.7"/>
<ellipse cx="137" cy="134" rx="6" ry="3" fill="#fef9c3" opacity="0.8"/>
<text x="150" y="178" text-anchor="middle" font-family="sans-serif" font-size="9.5" font-weight="600" fill="rgba(30,58,138,0.5)" letter-spacing="2">PHÒNG GIA ĐÌNH</text>
</svg>';}

function getBedSVG(string $loaiPhong): string {
    $t = mb_strtolower($loaiPhong, 'UTF-8');
    if (str_contains($t,'gia đình')||str_contains($t,'gia dinh')) return familyBedSVG();
    if (str_contains($t,'vip'))  return doubleBedSVG(true);
    if (str_contains($t,'đôi')||str_contains($t,'doi'))  return doubleBedSVG(false);
    return singleBedSVG();
}

function roomStatusColor(string $s): string {
    return match($s){'Trống'=>'#3b82f6','Đang ở'=>'#ef4444','Đang dọn'=>'#f59e0b','Bảo trì'=>'#6b7280',default=>'#94a3b8'};
}
function esc($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function fmt($n){ return number_format((float)$n,0,',','.'); }

// Format ngày đẹp
function fmtDate(string $d): string {
    if (!$d) return '';
    return date('d/m/Y', strtotime($d));
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tìm Phòng<?= $hasSearch ? ' — '.fmtDate($checkin).' đến '.fmtDate($checkout) : '' ?> — Easyhome</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;--bg:#f8faff;
  --text:#1e293b;--muted:#64748b;--border:#bfdbfe;
  --shadow:0 4px 24px rgba(29,78,216,.10);--shadow-lg:0 12px 48px rgba(29,78,216,.15);
  --radius:10px;--font:Calibri,'Calibri Light',Arial,sans-serif;--serif:'Playfair Display',serif;
}
body{font-family:var(--font);background:var(--bg);color:var(--text);overflow-x:hidden}

/* ── NAVBAR ── */
.navbar{position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;
  padding:0 5%;height:62px;background:rgba(255,255,255,.97);backdrop-filter:blur(10px);
  border-bottom:1px solid var(--border);box-shadow:0 2px 12px rgba(29,78,216,.07)}
.nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.nav-logo{width:40px;height:40px;border-radius:8px;object-fit:cover;border:2px solid var(--blue-light)}
.brand-name{font-family:var(--serif);font-size:1.3rem;color:var(--blue-dark);font-weight:600}
.brand-tagline{font-size:.58rem;color:var(--muted);letter-spacing:2px;text-transform:uppercase;margin-top:1px}
.nav-links{display:flex;gap:24px;list-style:none}
.nav-links a{font-size:.82rem;font-weight:500;color:var(--muted);text-decoration:none;
  padding:3px 0;border-bottom:2px solid transparent;transition:all .2s}
.nav-links a:hover,.nav-links a.active{color:var(--blue);border-bottom-color:var(--blue-light)}
.nav-actions{display:flex;gap:8px;align-items:center}
.btn-outline{padding:6px 16px;font-size:.77rem;font-weight:600;color:var(--blue);
  background:transparent;border:1.5px solid var(--blue);border-radius:6px;
  cursor:pointer;text-decoration:none;transition:all .22s}
.btn-outline:hover{background:var(--blue);color:#fff}
.btn-primary-nav{padding:6px 16px;font-size:.77rem;font-weight:600;color:#fff;
  background:var(--blue);border:1.5px solid var(--blue);border-radius:6px;
  cursor:pointer;text-decoration:none;box-shadow:0 2px 8px rgba(29,78,216,.28);transition:all .22s}
.btn-primary-nav:hover{background:var(--blue-dark)}
.user-pill{display:flex;align-items:center;gap:6px;padding:5px 13px;background:var(--blue-pale);
  border:1.5px solid var(--border);border-radius:20px;font-size:.77rem;color:var(--blue-dark);font-weight:600}

/* ── SEARCH BAR ── */
.search-section{background:linear-gradient(135deg,var(--blue-dark),var(--blue));padding:28px 5%}
.search-inner{max-width:960px;margin:0 auto}
.search-title{font-family:var(--serif);font-size:1.4rem;color:#fff;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.search-sub{font-size:.78rem;color:rgba(255,255,255,.65);margin-top:-10px;margin-bottom:14px;letter-spacing:.5px}
.search-form{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end}
.sf-group{display:flex;flex-direction:column;gap:5px}
.sf-label{font-size:.65rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.7)}
.sf-input{padding:10px 13px;border:1.5px solid rgba(255,255,255,.25);border-radius:8px;
  font-family:var(--font);font-size:.87rem;color:var(--text);background:rgba(255,255,255,.95);
  outline:none;transition:all .2s}
.sf-input:focus{border-color:#fff;background:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.2)}
.sf-input::placeholder{color:#94a3b8}
.btn-search{padding:10px 26px;font-family:var(--font);font-size:.8rem;font-weight:700;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--blue-dark);background:#fff;border:none;border-radius:8px;
  cursor:pointer;transition:all .25s;box-shadow:0 2px 12px rgba(0,0,0,.15);white-space:nowrap}
.btn-search:hover{background:var(--blue-pale);transform:translateY(-1px)}

/* ── RESULTS AREA ── */
.results-section{max-width:1200px;margin:0 auto;padding:28px 5% 60px}

/* Results header */
.results-hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px}
.results-title{font-family:var(--serif);font-size:1.5rem;color:var(--blue-dark)}
.results-meta{font-size:.8rem;color:var(--muted);margin-top:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.meta-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:var(--blue-pale);
  border:1px solid var(--border);border-radius:20px;font-size:.72rem;color:var(--blue)}

/* Type filter tabs */
.type-filter{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:22px}
.tf-btn{padding:7px 16px;border-radius:20px;font-size:.76rem;font-weight:700;letter-spacing:.5px;
  border:1.5px solid var(--border);background:#fff;color:var(--muted);cursor:pointer;
  text-decoration:none;transition:all .2s;display:flex;align-items:center;gap:5px}
.tf-btn:hover{border-color:var(--blue);color:var(--blue)}
.tf-btn.active{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:0 2px 10px rgba(29,78,216,.3)}
.tf-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;
  padding:0 4px;border-radius:10px;font-size:.65rem;font-weight:700;
  background:rgba(255,255,255,.25);transition:background .2s}
.tf-btn:not(.active) .tf-count{background:var(--blue-mid);color:var(--blue)}

/* Room grid */
.room-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:22px}
.room-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  overflow:hidden;transition:all .3s;opacity:0;transform:translateY(18px)}
.room-card.visible{opacity:1;transform:translateY(0)}
.room-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:var(--blue-light)}
.room-img{width:100%;height:185px;position:relative;overflow:hidden;background:transparent;transition:transform .4s}
.room-card:hover .room-img{transform:scale(1.02)}
.room-type-tag{position:absolute;top:11px;left:11px;z-index:2;font-size:.62rem;font-weight:700;
  letter-spacing:1px;text-transform:uppercase;color:#fff;background:var(--blue);
  padding:4px 10px;border-radius:5px;backdrop-filter:blur(4px)}
.room-status-tag{position:absolute;top:11px;right:11px;z-index:2;font-size:.62rem;font-weight:600;
  color:#fff;padding:4px 10px;border-radius:5px;display:flex;align-items:center;gap:4px;backdrop-filter:blur(4px)}
.room-body{padding:16px 18px}
.room-code{font-size:.65rem;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--blue);margin-bottom:4px}
.room-name{font-family:var(--serif);font-size:1.15rem;color:var(--text);margin-bottom:9px}
.room-amenities{display:flex;gap:10px;margin-bottom:8px;flex-wrap:wrap}
.amenity{font-size:.73rem;color:var(--muted);display:flex;align-items:center;gap:3px}
.room-desc{font-size:.77rem;color:var(--muted);line-height:1.55;min-height:36px}
.room-foot{display:flex;align-items:center;justify-content:space-between;
  padding:12px 18px;border-top:1px solid var(--border);background:var(--blue-pale)}
.price-block{}
.price-amt{font-family:var(--serif);font-size:1.28rem;font-weight:600;color:var(--blue-dark)}
.price-unit{font-size:.62rem;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-top:1px}
.price-total{font-size:.7rem;color:var(--blue);font-weight:600;margin-top:2px}
.btn-book{padding:8px 16px;font-size:.7rem;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
  color:#fff;background:var(--blue);border:none;border-radius:6px;cursor:pointer;
  text-decoration:none;transition:all .22s;display:inline-block;box-shadow:0 2px 8px rgba(29,78,216,.3)}
.btn-book:hover{background:var(--blue-dark);transform:translateY(-1px)}
.btn-book.unavail{background:#cbd5e1;color:#94a3b8;cursor:not-allowed;box-shadow:none;pointer-events:none}
.btn-login-prompt{padding:8px 14px;font-size:.7rem;font-weight:700;letter-spacing:.8px;text-transform:uppercase;
  color:var(--blue);background:#fff;border:1.5px solid var(--blue);border-radius:6px;cursor:pointer;
  text-decoration:none;transition:all .22s}
.btn-login-prompt:hover{background:var(--blue);color:#fff}

/* Alert */
.alert-box{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:9px;
  margin-bottom:18px;font-size:.85rem;border-left:4px solid}
.alert-warn{background:#fffbeb;color:#92400e;border-color:#f59e0b}
.alert-info{background:var(--blue-pale);color:var(--blue-dark);border-color:var(--blue-light)}

/* Empty state */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-icon{font-size:3.5rem;margin-bottom:16px}
.empty-title{font-family:var(--serif);font-size:1.3rem;color:var(--blue-dark);margin-bottom:8px}
.empty-sub{font-size:.87rem;line-height:1.6;margin-bottom:22px}
.btn-reset{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;background:var(--blue);
  color:#fff;border-radius:8px;text-decoration:none;font-size:.82rem;font-weight:700;
  box-shadow:0 2px 10px rgba(29,78,216,.3);transition:all .2s}
.btn-reset:hover{background:var(--blue-dark)}

/* Login prompt banner */
.login-banner{background:var(--blue-pale);border:1.5px solid var(--border);border-radius:10px;
  padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.login-banner-text{font-size:.85rem;color:var(--blue-dark)}
.login-banner-text strong{color:var(--blue)}
.login-banner-actions{display:flex;gap:8px}

@media(max-width:720px){
  .search-form{grid-template-columns:1fr 1fr;gap:10px}
  .search-form .btn-search{grid-column:1/-1}
  .room-grid{grid-template-columns:1fr}
  .nav-links{display:none}
  .results-hdr{flex-direction:column}
}
</style>
</head>
<body>

<!-- ════ NAVBAR ════ -->
<nav class="navbar">
  <a href="index.php" class="nav-brand">
    <img src="assets/images/logo.jpg" alt="Easyhome" class="nav-logo">
    <div>
      <div class="brand-name">Easyhome</div>
      <div class="brand-tagline">Nhà nghỉ theo giờ · Huế</div>
    </div>
  </a>
  <ul class="nav-links">
    <li><a href="index.php">Trang Chủ</a></li>
    <li><a href="rooms.php" class="active">Tìm Phòng</a></li>
    <li><a href="index.php#services">Dịch Vụ</a></li>
    <li><a href="index.php#map">Vị Trí</a></li>
  </ul>
  <div class="nav-actions">
    <?php if ($isCustomer): ?>
      <span class="user-pill">👤 <?= esc($userName) ?></span>
      <a href="customer/dashboard.php" class="btn-outline">Tài Khoản</a>
    <?php elseif ($isStaff): ?>
      <span class="user-pill">🔑 <?= esc($userName) ?></span>
      <a href="<?= $_SESSION['staff_role']==='admin'?'admin/dashboard.php':'staff/dashboard.php' ?>" class="btn-outline">Dashboard</a>
    <?php else: ?>
      <a href="login.php" class="btn-outline">Đăng Nhập</a>
      <a href="register.php" class="btn-primary-nav">Đăng Ký</a>
    <?php endif; ?>
  </div>
</nav>

<!-- ════ SEARCH BAR ════ -->
<div class="search-section">
  <div class="search-inner">
    <div class="search-title">🔍 Tìm Phòng Trống</div>
    <div class="search-sub">Nhập ngày để kiểm tra phòng còn trống — kết quả cập nhật thời gian thực</div>
    <form class="search-form" method="GET" action="rooms.php">
      <div class="sf-group">
        <label class="sf-label">Ngày Nhận Phòng</label>
        <input type="date" name="checkin" class="sf-input"
               min="<?= date('Y-m-d') ?>" value="<?= esc($checkin) ?>">
      </div>
      <div class="sf-group">
        <label class="sf-label">Ngày Trả Phòng</label>
        <input type="date" name="checkout" class="sf-input"
               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= esc($checkout) ?>">
      </div>
      <div class="sf-group">
        <label class="sf-label">Loại Phòng</label>
        <select name="type" class="sf-input">
          <option value="">Tất Cả Loại</option>
          <?php foreach (['Đơn','Đôi','Gia đình','VIP'] as $lt): ?>
          <option value="<?= $lt ?>" <?= $typeFilter===$lt?'selected':'' ?>><?= $lt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-search">🔍 Tìm</button>
    </form>
  </div>
</div>

<!-- ════ RESULTS ════ -->
<div class="results-section">

  <?php if ($searchError): ?>
  <div class="alert-box alert-warn">⚠ <?= esc($searchError) ?></div>
  <?php endif; ?>

  <!-- Login prompt (nếu chưa đăng nhập) -->
  <?php if (!$isCustomer && !$isStaff): ?>
  <div class="login-banner">
    <div class="login-banner-text">
      🔑 <strong>Đăng nhập</strong> để đặt phòng ngay — hoặc
      <strong>đăng ký</strong> tài khoản miễn phí trong vài giây.
    </div>
    <div class="login-banner-actions">
      <a href="login.php" class="btn-outline" style="padding:7px 16px;font-size:.77rem">Đăng Nhập</a>
      <a href="register.php" class="btn-primary-nav" style="padding:7px 16px;font-size:.77rem">Đăng Ký Ngay</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Results header -->
  <div class="results-hdr">
    <div>
      <div class="results-title">
        <?php if ($hasSearch): ?>
          <?= $totalAvail ?> phòng trống
        <?php elseif ($validType): ?>
          Phòng <?= esc($validType) ?>
        <?php else: ?>
          Tất Cả Phòng
        <?php endif; ?>
      </div>
      <div class="results-meta">
        <?php if ($hasSearch): ?>
          <span class="meta-chip">📅 <?= esc(fmtDate($checkin)) ?> → <?= esc(fmtDate($checkout)) ?></span>
          <span class="meta-chip">🌙 <?= $nights ?> đêm</span>
        <?php else: ?>
          <span class="meta-chip">🏨 <?= count($rooms) ?> phòng hiển thị</span>
        <?php endif; ?>
        <?php if ($validType): ?>
          <span class="meta-chip">🛏️ Loại: <?= esc($validType) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Type filter -->
  <div class="type-filter">
    <?php
    $baseUrl = 'rooms.php?'.($checkin?"checkin=".urlencode($checkin)."&":"").($checkout?"checkout=".urlencode($checkout)."&":"");
    ?>
    <a href="<?= $baseUrl ?>type=" class="tf-btn <?= !$validType?'active':'' ?>">
      Tất Cả <span class="tf-count"><?= array_sum($typeCounts) ?></span>
    </a>
    <?php foreach (['Đơn','Đôi','Gia đình','VIP'] as $lt):
      $cnt = $typeCounts[$lt] ?? 0;
      if (!$hasSearch && $cnt === 0) continue;
      $icons = ['Đơn'=>'🛏️','Đôi'=>'👫','Gia đình'=>'👨‍👩‍👦','VIP'=>'⭐'];
    ?>
    <a href="<?= $baseUrl ?>type=<?= urlencode($lt) ?>" class="tf-btn <?= $validType===$lt?'active':'' ?>">
      <?= $icons[$lt]??'' ?> <?= esc($lt) ?>
      <span class="tf-count"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Room cards -->
  <?php if (empty($rooms)): ?>
  <div class="empty-state">
    <div class="empty-icon">🔍</div>
    <div class="empty-title">Không tìm thấy phòng trống</div>
    <div class="empty-sub">
      <?php if ($hasSearch): ?>
        Không có phòng nào trống từ <strong><?= esc(fmtDate($checkin)) ?></strong>
        đến <strong><?= esc(fmtDate($checkout)) ?></strong><?= $validType?" (loại <strong>$validType</strong>)":"" ?>.<br>
        Thử chọn ngày khác hoặc loại phòng khác.
      <?php else: ?>
        Hiện tại không có phòng nào phù hợp.
      <?php endif; ?>
    </div>
    <a href="rooms.php" class="btn-reset">🔄 Xem tất cả phòng</a>
  </div>
  <?php else: ?>
  <div class="room-grid" id="roomGrid">
    <?php
    $roomLabels = [
        'Đơn'     => 'Phòng Đơn',
        'Đôi'     => 'Phòng Đôi',
        'Gia đình'=> 'Phòng Gia Đình',
        'VIP'     => 'Phòng VIP',
    ];
    foreach ($rooms as $i => $r):
        $loai   = $r['LoaiPhong'];
        $status = $r['TinhTrang'];
        $price  = (float)$r['GiaPhong'];
        $stColor= roomStatusColor($status);
        $canBook= ($status === 'Trống');
        $bookHref = bookingUrl($r['MaPhong'], $loai, $checkin, $checkout, $isCustomer);
    ?>
    <div class="room-card" data-type="<?= esc($loai) ?>" style="transition-delay:<?= min($i*0.06, 0.5) ?>s">
      <div class="room-img">
        <?= getBedSVG($loai) ?>
        <div class="room-type-tag"><?= esc($loai) ?></div>
        <div class="room-status-tag" style="background:<?= $stColor ?>">
          <span style="width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.8);display:inline-block"></span>
          <?= esc($status) ?>
        </div>
      </div>
      <div class="room-body">
        <div class="room-code">Phòng <?= esc($r['MaPhong']) ?> · Tầng <?= (int)$r['Tang'] ?></div>
        <div class="room-name"><?= esc($roomLabels[$loai] ?? "Phòng $loai") ?></div>
        <div class="room-amenities">
          <span class="amenity">👥 Tối đa <?= (int)$r['SoNguoiToiDa'] ?> người</span>
          <span class="amenity">🏢 Tầng <?= (int)$r['Tang'] ?></span>
          <?php if ($loai === 'VIP'): ?>
          <span class="amenity">⭐ Cao cấp</span>
          <?php elseif ($loai === 'Gia đình'): ?>
          <span class="amenity">🛏️ 2 giường</span>
          <?php endif; ?>
        </div>
        <div class="room-desc"><?= esc($r['MoTa'] ?: 'Phòng tiện nghi, sạch sẽ, đầy đủ tiện ích cơ bản.') ?></div>
      </div>
      <div class="room-foot">
        <div class="price-block">
          <div class="price-amt"><?= fmt($price) ?><span style="font-size:.75rem;font-family:var(--font);font-weight:400;color:var(--muted)">đ</span></div>
          <div class="price-unit">/ Đêm</div>
          <?php if ($hasSearch && $nights > 1): ?>
          <div class="price-total">Tổng ~<?= fmt($price * $nights) ?>đ (<?= $nights ?> đêm)</div>
          <?php endif; ?>
        </div>
        <?php if (!$canBook): ?>
          <span class="btn-book unavail">Đang Bận</span>
        <?php elseif ($isCustomer): ?>
          <a href="<?= esc($bookHref) ?>" class="btn-book">📅 Đặt Ngay →</a>
        <?php else: ?>
          <a href="login.php" class="btn-login-prompt">🔑 Đăng nhập đặt</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- .results-section -->

<!-- ════ FOOTER ════ -->
<footer style="background:var(--blue-dark);padding:28px 5%;text-align:center">
  <div style="font-family:var(--serif);font-size:1.1rem;color:#fff;margin-bottom:4px">Easyhome</div>
  <div style="font-size:.72rem;color:rgba(255,255,255,.45);letter-spacing:2px;text-transform:uppercase">Nhà Nghỉ Theo Giờ · TP. Huế</div>
  <div style="margin-top:10px;display:flex;justify-content:center;gap:20px;flex-wrap:wrap">
    <a href="tel:0768466686" style="color:rgba(255,255,255,.55);font-size:.78rem;text-decoration:none">📞 0768.466.686</a>
    <a href="index.php" style="color:rgba(255,255,255,.55);font-size:.78rem;text-decoration:none">🏠 Trang chủ</a>
    <a href="index.php#map" style="color:rgba(255,255,255,.55);font-size:.78rem;text-decoration:none">📍 Bản đồ</a>
  </div>
</footer>

<script>
// Scroll reveal for room cards
const cards = document.querySelectorAll('.room-card');
const obs = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      obs.unobserve(e.target);
    }
  });
}, { threshold: 0.06 });
cards.forEach(c => obs.observe(c));

// Date validation
const ci = document.querySelector('input[name="checkin"]');
const co = document.querySelector('input[name="checkout"]');
if (ci && co) {
  ci.addEventListener('change', () => {
    if (ci.value) {
      const next = new Date(ci.value);
      next.setDate(next.getDate() + 1);
      co.min = next.toISOString().split('T')[0];
      if (co.value && co.value <= ci.value) co.value = '';
    }
  });
}
</script>

</body>
</html>
