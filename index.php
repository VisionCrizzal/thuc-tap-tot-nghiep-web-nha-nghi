<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

$rooms    = $pdo->query("SELECT * FROM PHONG ORDER BY FIELD(TinhTrang,'Trống','Đang dọn','Đang ở','Bảo trì'), GiaPhong")->fetchAll();
$services = getAllServices($pdo);
$stats    = $pdo->query("SELECT TinhTrang, COUNT(*) as c FROM PHONG GROUP BY TinhTrang")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalRooms    = array_sum($stats);
$emptyRooms    = $stats['Trống']    ?? 0;
$occupiedRooms = $stats['Đang ở']   ?? 0;
$cleaningRooms = $stats['Đang dọn'] ?? 0;

// Đếm phòng theo LoaiPhong cho dashboard filter
$roomsByType = [];
foreach ($rooms as $r) {
    $t = $r['LoaiPhong'];
    $roomsByType[$t] = ($roomsByType[$t] ?? 0) + 1;
}
$roomTypes = ['Đơn','Đôi','Gia đình','VIP'];

function roomStatusColor(string $s): string {
    return match($s){ 'Trống'=>'#3b82f6','Đang ở'=>'#ef4444','Đang dọn'=>'#f59e0b','Bảo trì'=>'#6b7280',default=>'#94a3b8' };
}

/* ============================================================
   BED SVG ILLUSTRATIONS — 3D Perspective
   ViewBox: 0 0 300 185 | Perspective: front-right elevated view
   Base bed: FL=(78,143) FR=(222,143) BR=(192,115) BL=(48,115)
   Depth: -30px X, -28px Y per bed-depth unit
============================================================ */

function singleBedSVG(): string { return '
<svg viewBox="0 0 300 185" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block">
<defs>
  <linearGradient id="sbg" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="#e0f2fe"/>
    <stop offset="100%" stop-color="#bfdbfe"/>
  </linearGradient>
  <linearGradient id="shb" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0%" stop-color="#1e3a8a"/>
    <stop offset="100%" stop-color="#2563eb"/>
  </linearGradient>
  <filter id="sshadow"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="rgba(30,58,138,0.25)"/></filter>
</defs>

<!-- Background -->
<rect width="300" height="185" fill="url(#sbg)"/>
<!-- Floor -->
<ellipse cx="150" cy="172" rx="95" ry="8" fill="rgba(30,58,138,0.13)"/>

<!-- === HEADBOARD === -->
<!-- Left depth face -->
<polygon points="48,78 60,70 60,115 48,115" fill="#172554"/>
<!-- Top face -->
<polygon points="48,78 192,78 204,70 60,70" fill="#1d4ed8"/>
<!-- Front face -->
<polygon points="48,78 192,78 192,115 48,115" fill="url(#shb)"/>
<!-- Panel groove lines -->
<line x1="120" y1="78" x2="120" y2="115" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>
<line x1="120" y1="78" x2="132" y2="70" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>
<!-- Top trim rail -->
<polygon points="50,80 190,80 202,72 62,72" fill="rgba(255,255,255,0.12)"/>

<!-- === BED FRAME === -->
<!-- Left face (shadow) -->
<polygon points="48,115 78,143 78,161 48,133" fill="#172554" opacity="0.55"/>
<!-- Front face -->
<polygon points="78,143 222,143 222,161 78,161" fill="#1e3a8a"/>
<!-- Right face -->
<polygon points="222,143 192,115 192,133 222,161" fill="#2563eb"/>
<!-- Frame top rail highlight -->
<line x1="78" y1="143" x2="222" y2="143" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>

<!-- === MATTRESS / SHEET === -->
<polygon points="78,143 222,143 192,115 48,115" fill="#f8faff"/>
<!-- Sheet fold shadow near foot -->
<polygon points="78,143 222,143 218,140 82,140" fill="#dbeafe" opacity="0.5"/>
<!-- Sheet wrinkle line -->
<line x1="82" y1="139" x2="218" y2="139" stroke="#bfdbfe" stroke-width="0.8"/>

<!-- === SINGLE PILLOW (centered) ===
     Front at y=131: bed-left=65, bed-right=209 (width=144)
     Pillow width=100, centered → from 87 to 187
     Back at y=119: shift 13px left → 74 to 174 -->
<!-- Pillow shadow on sheet -->
<polygon points="84,133 190,133 177,120 71,120" fill="rgba(147,197,253,0.35)"/>
<!-- Pillow body -->
<polygon points="87,131 187,131 174,119 74,119" fill="#dbeafe" filter="url(#sshadow)"/>
<!-- Pillow top highlight -->
<polygon points="90,130 184,130 173,121 79,121" fill="#eff6ff" opacity="0.6"/>
<!-- Pillow center seam -->
<line x1="137" y1="131" x2="124" y2="119" stroke="#93c5fd" stroke-width="1.2"/>
<!-- Pillow edge stitch -->
<polygon points="87,131 187,131 174,119 74,119" fill="none" stroke="#93c5fd" stroke-width="1"/>

<!-- === NIGHTSTAND (right) === -->
<polygon points="234,136 252,136 244,128 226,128" fill="#1d4ed8" opacity="0.7"/>
<polygon points="234,136 252,136 252,150 234,150" fill="#1e3a8a" opacity="0.7"/>
<polygon points="252,136 244,128 244,142 252,150" fill="#2563eb" opacity="0.6"/>
<!-- Lamp -->
<polygon points="240,128 248,128 246,120 242,120" fill="#fef9c3" opacity="0.75"/>
<ellipse cx="244" cy="128" rx="6" ry="3" fill="#fef9c3" opacity="0.85"/>
<circle cx="244" cy="125" r="3" fill="#fef3c7" opacity="0.9"/>

<!-- === ROOM LABEL === -->
<text x="150" y="178" text-anchor="middle" font-family="sans-serif"
  font-size="9.5" font-weight="600" fill="rgba(30,58,138,0.5)" letter-spacing="2">PHÒNG ĐƠN</text>
</svg>
';}

function doubleBedSVG(bool $vip = false): string {
$petals = $vip ? '
<!-- Rose petals (VIP) -->
<ellipse cx="110" cy="130" rx="5" ry="3" fill="#fb7185" opacity="0.85" transform="rotate(-20,110,130)"/>
<ellipse cx="130" cy="122" rx="4" ry="2.5" fill="#f43f5e" opacity="0.8" transform="rotate(15,130,122)"/>
<ellipse cx="155" cy="127" rx="5" ry="3" fill="#fb7185" opacity="0.85" transform="rotate(-35,155,127)"/>
<ellipse cx="170" cy="120" rx="4" ry="2.5" fill="#e11d48" opacity="0.75" transform="rotate(10,170,120)"/>
<ellipse cx="145" cy="134" rx="4" ry="2.5" fill="#fb7185" opacity="0.8" transform="rotate(25,145,134)"/>
' : '';
return '
<svg viewBox="0 0 300 185" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block">
<defs>
  <linearGradient id="dbg" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="'.($vip?'#fdf4ff':'#e0f2fe').'"/>
    <stop offset="100%" stop-color="'.($vip?'#ede9fe':'#bfdbfe').'"/>
  </linearGradient>
  <linearGradient id="dhb" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0%" stop-color="'.($vip?'#4c1d95':'#1e3a8a').'"/>
    <stop offset="100%" stop-color="'.($vip?'#7c3aed':'#2563eb').'"/>
  </linearGradient>
  <filter id="dshadow"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="rgba(30,58,138,0.25)"/></filter>
</defs>

<rect width="300" height="185" fill="url(#dbg)"/>
<ellipse cx="150" cy="172" rx="95" ry="8" fill="rgba(30,58,138,0.13)"/>

<!-- HEADBOARD -->
<polygon points="48,78 60,70 60,115 48,115" fill="'.($vip?'#2e1065':'#172554').'"/>
<polygon points="48,78 192,78 204,70 60,70" fill="'.($vip?'#6d28d9':'#1d4ed8').'"/>
<polygon points="48,78 192,78 192,115 48,115" fill="url(#dhb)"/>
<!-- Panel lines -->
<line x1="120" y1="78" x2="120" y2="115" stroke="rgba(255,255,255,0.1)" stroke-width="1.5"/>
<line x1="120" y1="78" x2="132" y2="70" stroke="rgba(255,255,255,0.1)" stroke-width="1.5"/>
<polygon points="50,80 190,80 202,72 62,72" fill="rgba(255,255,255,0.1)"/>
'.($vip?'<!-- VIP gold trim --><polygon points="48,84 192,84 204,76 60,76" fill="rgba(250,204,21,0.25)"/><polygon points="48,109 192,109 204,101 60,101" fill="rgba(250,204,21,0.25)"/>':'').'

<!-- BED FRAME -->
<polygon points="48,115 78,143 78,161 48,133" fill="#172554" opacity="0.55"/>
<polygon points="78,143 222,143 222,161 78,161" fill="'.($vip?'#2e1065':'#1e3a8a').'"/>
<polygon points="222,143 192,115 192,133 222,161" fill="'.($vip?'#7c3aed':'#2563eb').'"/>
<line x1="78" y1="143" x2="222" y2="143" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>

<!-- MATTRESS / SHEET -->
<polygon points="78,143 222,143 192,115 48,115" fill="'.($vip?'#faf5ff':'#f8faff').'"/>
<polygon points="78,143 222,143 218,140 82,140" fill="'.($vip?'#ede9fe':'#dbeafe').'" opacity="0.5"/>
<line x1="82" y1="139" x2="218" y2="139" stroke="'.($vip?'#c4b5fd':'#bfdbfe').'" stroke-width="0.8"/>

'.$petals.'

<!-- PILLOW 1 (left)
     At y=131: bed-left=65, each pillow width=58, gap=28
     P1: front(65→123), back(52→110) -->
<polygon points="62,133 126,133 113,120 49,120" fill="rgba(147,197,253,0.3)"/>
<polygon points="65,131 123,131 110,119 52,119" fill="'.($vip?'#ede9fe':'#dbeafe').'" filter="url(#dshadow)"/>
<polygon points="68,130 120,130 108,120 56,120" fill="'.($vip?'#f5f3ff':'#eff6ff').'" opacity="0.6"/>
<line x1="94" y1="131" x2="81" y2="119" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1.2"/>
<polygon points="65,131 123,131 110,119 52,119" fill="none" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1"/>

<!-- PILLOW 2 (right)
     P2: front(151→209), back(138→196) -->
<polygon points="148,133 212,133 199,120 135,120" fill="rgba(147,197,253,0.3)"/>
<polygon points="151,131 209,131 196,119 138,119" fill="'.($vip?'#ede9fe':'#dbeafe').'" filter="url(#dshadow)"/>
<polygon points="154,130 206,130 194,120 142,120" fill="'.($vip?'#f5f3ff':'#eff6ff').'" opacity="0.6"/>
<line x1="180" y1="131" x2="167" y2="119" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1.2"/>
<polygon points="151,131 209,131 196,119 138,119" fill="none" stroke="'.($vip?'#c4b5fd':'#93c5fd').'" stroke-width="1"/>

<!-- NIGHTSTAND -->
<polygon points="234,136 252,136 244,128 226,128" fill="'.($vip?'#4c1d95':'#1d4ed8').'" opacity="0.7"/>
<polygon points="234,136 252,136 252,150 234,150" fill="'.($vip?'#2e1065':'#1e3a8a').'" opacity="0.7"/>
<polygon points="252,136 244,128 244,142 252,150" fill="'.($vip?'#7c3aed':'#2563eb').'" opacity="0.6"/>
<polygon points="240,128 248,128 246,120 242,120" fill="#fef9c3" opacity="0.75"/>
<ellipse cx="244" cy="128" rx="6" ry="3" fill="#fef9c3" opacity="0.85"/>
<circle cx="244" cy="125" r="3" fill="#fef3c7" opacity="0.9"/>

<text x="150" y="178" text-anchor="middle" font-family="sans-serif"
  font-size="9.5" font-weight="600" fill="rgba(30,58,138,0.5)" letter-spacing="2">'.($vip?'PHÒNG VIP':'PHÒNG ĐÔI').'</text>
</svg>
';}

function familyBedSVG(): string { return '
<svg viewBox="0 0 300 185" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block">
<defs>
  <linearGradient id="fbg" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="#ecfdf5"/>
    <stop offset="100%" stop-color="#bbf7d0"/>
  </linearGradient>
  <linearGradient id="fhb1" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0%" stop-color="#065f46"/>
    <stop offset="100%" stop-color="#059669"/>
  </linearGradient>
  <linearGradient id="fhb2" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0%" stop-color="#1e3a8a"/>
    <stop offset="100%" stop-color="#2563eb"/>
  </linearGradient>
  <filter id="fshadow"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="rgba(5,150,105,0.25)"/></filter>
</defs>

<rect width="300" height="185" fill="url(#fbg)"/>
<!-- Combined floor shadow -->
<ellipse cx="150" cy="173" rx="118" ry="7" fill="rgba(5,150,105,0.14)"/>

<!-- ========== LEFT TWIN BED ==========
     FL=(22,152) FR=(108,152) BR=(93,137) BL=(7,137)
     Scale 60%: depth 15px left, 15px up
     Headboard up to y=100 -->

<!-- Headboard left -->
<polygon points="7,100 18,93 18,137 7,137" fill="#064e3b"/>
<polygon points="7,100 93,100 104,93 18,93" fill="#059669"/>
<polygon points="7,100 93,100 93,137 7,137" fill="url(#fhb1)"/>
<line x1="50" y1="100" x2="50" y2="137" stroke="rgba(255,255,255,0.1)" stroke-width="1.2"/>
<line x1="50" y1="100" x2="58" y2="93" stroke="rgba(255,255,255,0.1)" stroke-width="1.2"/>
<polygon points="9,103 91,103 102,96 20,96" fill="rgba(255,255,255,0.1)"/>

<!-- Left bed frame -->
<polygon points="7,137 22,152 22,167 7,152" fill="#064e3b" opacity="0.5"/>
<polygon points="22,152 108,152 108,167 22,167" fill="#065f46"/>
<polygon points="108,152 93,137 93,152 108,167" fill="#059669"/>

<!-- Left mattress -->
<polygon points="22,152 108,152 93,137 7,137" fill="#f0fdf4"/>
<polygon points="22,152 108,152 105,149 25,149" fill="#bbf7d0" opacity="0.5"/>

<!-- Left pillow
     At y=143 (9px above front 152): shift 9/15*15=9px
     bed-left at y=143: 22-9=13, bed-right: 108-9=99 → width=86
     Pillow width=55, centered → start at 13+(86-55)/2=29
     Front: (29,143)→(84,143), Back at y=137: (14→69) -->
<polygon points="26,145 87,145 76,133 15,133" fill="rgba(52,211,153,0.3)"/>
<polygon points="29,143 84,143 73,132 18,132" fill="#d1fae5" filter="url(#fshadow)"/>
<polygon points="32,142 81,142 71,133 22,133" fill="#ecfdf5" opacity="0.7"/>
<line x1="56" y1="143" x2="45" y2="132" stroke="#6ee7b7" stroke-width="1.1"/>
<polygon points="29,143 84,143 73,132 18,132" fill="none" stroke="#6ee7b7" stroke-width="0.9"/>

<!-- ========== RIGHT TWIN BED ==========
     FL=(178,152) FR=(264,152) BR=(249,137) BL=(163,137) -->

<!-- Headboard right -->
<polygon points="163,100 174,93 174,137 163,137" fill="#172554"/>
<polygon points="163,100 249,100 260,93 174,93" fill="#1d4ed8"/>
<polygon points="163,100 249,100 249,137 163,137" fill="url(#fhb2)"/>
<line x1="206" y1="100" x2="206" y2="137" stroke="rgba(255,255,255,0.1)" stroke-width="1.2"/>
<line x1="206" y1="100" x2="214" y2="93" stroke="rgba(255,255,255,0.1)" stroke-width="1.2"/>
<polygon points="165,103 247,103 258,96 176,96" fill="rgba(255,255,255,0.1)"/>

<!-- Right bed frame -->
<polygon points="163,137 178,152 178,167 163,152" fill="#172554" opacity="0.5"/>
<polygon points="178,152 264,152 264,167 178,167" fill="#1e3a8a"/>
<polygon points="264,152 249,137 249,152 264,167" fill="#2563eb"/>

<!-- Right mattress -->
<polygon points="178,152 264,152 249,137 163,137" fill="#f8faff"/>
<polygon points="178,152 264,152 261,149 181,149" fill="#dbeafe" opacity="0.5"/>

<!-- Right pillow (same geometry, shifted right by 156px) -->
<polygon points="182,145 243,145 232,133 171,133" fill="rgba(147,197,253,0.3)"/>
<polygon points="185,143 240,143 229,132 174,132" fill="#dbeafe" filter="url(#fshadow)"/>
<polygon points="188,142 237,142 227,133 178,133" fill="#eff6ff" opacity="0.7"/>
<line x1="212" y1="143" x2="201" y2="132" stroke="#93c5fd" stroke-width="1.1"/>
<polygon points="185,143 240,143 229,132 174,132" fill="none" stroke="#93c5fd" stroke-width="0.9"/>

<!-- CENTER DIVIDER / NIGHTSTAND between beds -->
<polygon points="126,144 148,144 142,134 120,134" fill="#1e3a8a" opacity="0.35"/>
<polygon points="126,144 148,144 148,158 126,158" fill="#1e3a8a" opacity="0.4"/>
<!-- Small lamp -->
<polygon points="132,134 142,134 140,127 134,127" fill="#fef9c3" opacity="0.7"/>
<ellipse cx="137" cy="134" rx="6" ry="3" fill="#fef9c3" opacity="0.8"/>

<text x="150" y="178" text-anchor="middle" font-family="sans-serif"
  font-size="9.5" font-weight="600" fill="rgba(30,58,138,0.5)" letter-spacing="2">PHÒNG GIA ĐÌNH</text>
</svg>
';}

function getBedSVG(string $loaiPhong): string {
    $t = mb_strtolower($loaiPhong, 'UTF-8');
    if (str_contains($t, 'gia đình') || str_contains($t, 'gia dinh'))
        return familyBedSVG();
    if (str_contains($t, 'vip'))
        return doubleBedSVG(true);
    if (str_contains($t, 'đôi') || str_contains($t, 'doi'))
        return doubleBedSVG(false);
    return singleBedSVG();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Easyhome — Nhà Nghỉ Theo Giờ Huế</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
:root{
  --blue:#1d4ed8;--blue-light:#3b82f6;--blue-dark:#1e3a8a;
  --blue-pale:#eff6ff;--blue-mid:#dbeafe;
  --bg:#f8faff;--text:#1e293b;--muted:#64748b;--border:#bfdbfe;
  --shadow:0 4px 24px rgba(29,78,216,.10);--shadow-lg:0 12px 48px rgba(29,78,216,.15);
  --radius:10px;--font:Calibri,'Calibri Light',Arial,sans-serif;--serif:'Playfair Display',serif;
}
body{font-family:var(--font);background:var(--bg);color:var(--text);overflow-x:hidden}

/* NAVBAR */
.navbar{position:fixed;top:0;left:0;right:0;z-index:1000;display:flex;align-items:center;
  justify-content:space-between;padding:0 5%;height:66px;
  background:rgba(255,255,255,.96);backdrop-filter:blur(10px);
  border-bottom:1px solid var(--border);box-shadow:0 2px 12px rgba(29,78,216,.08);
  animation:navIn .5s ease}
@keyframes navIn{from{transform:translateY(-100%);opacity:0}to{transform:translateY(0);opacity:1}}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none}
.nav-logo{width:44px;height:44px;border-radius:8px;object-fit:cover;border:2px solid var(--blue-light)}
.brand-text{line-height:1}
.brand-name{font-family:var(--serif);font-size:1.4rem;color:var(--blue-dark);font-weight:600}
.brand-tagline{font-size:0.62rem;color:var(--muted);letter-spacing:2px;text-transform:uppercase;margin-top:2px}
.nav-links{display:flex;gap:28px;list-style:none}
.nav-links a{font-size:0.83rem;font-weight:500;color:var(--muted);text-decoration:none;
  padding:4px 0;border-bottom:2px solid transparent;transition:all .25s}
.nav-links a:hover{color:var(--blue);border-bottom-color:var(--blue-light)}
.nav-actions{display:flex;gap:10px;align-items:center}
.btn-outline{padding:7px 18px;font-size:0.78rem;font-weight:600;color:var(--blue);
  background:transparent;border:1.5px solid var(--blue);border-radius:6px;
  cursor:pointer;text-decoration:none;transition:all .25s}
.btn-outline:hover{background:var(--blue);color:#fff}
.btn-primary{padding:7px 18px;font-size:0.78rem;font-weight:600;color:#fff;
  background:var(--blue);border:1.5px solid var(--blue);border-radius:6px;
  cursor:pointer;text-decoration:none;transition:all .25s;
  box-shadow:0 2px 8px rgba(29,78,216,.3)}
.btn-primary:hover{background:var(--blue-dark);border-color:var(--blue-dark)}

/* User chip — khi đã đăng nhập */
.nav-user{position:relative;display:flex;align-items:center}
.nav-user-chip{display:flex;align-items:center;gap:8px;padding:6px 14px 6px 8px;
  border-radius:22px;background:var(--blue-pale);border:1.5px solid var(--border);
  cursor:pointer;transition:all .2s;user-select:none}
.nav-user-chip:hover{border-color:var(--blue-light);background:var(--blue-mid)}
.nav-user-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);
  display:flex;align-items:center;justify-content:center;font-size:.75rem;
  color:#fff;font-weight:700;flex-shrink:0}
.nav-user-name{font-size:.78rem;font-weight:600;color:var(--blue-dark);max-width:110px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nav-user-caret{font-size:.65rem;color:var(--muted);margin-left:2px;transition:transform .2s}
.nav-user.open .nav-user-caret{transform:rotate(180deg)}
.nav-dropdown{position:absolute;top:calc(100% + 8px);right:0;min-width:190px;
  background:#fff;border:1.5px solid var(--border);border-radius:12px;
  box-shadow:0 8px 28px rgba(29,78,216,.14);overflow:hidden;
  opacity:0;transform:translateY(-6px) scale(.97);pointer-events:none;
  transition:all .18s cubic-bezier(.34,1.56,.64,1);z-index:2000}
.nav-user.open .nav-dropdown{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
.nav-dropdown-header{padding:12px 16px 10px;border-bottom:1px solid var(--border);
  font-size:.72rem;color:var(--muted)}
.nav-dropdown-header strong{display:block;font-size:.84rem;color:var(--text);margin-bottom:2px}
.nav-dropdown a,.nav-dropdown button{display:flex;align-items:center;gap:9px;
  width:100%;padding:10px 16px;font-family:var(--font);font-size:.82rem;
  color:var(--text);text-decoration:none;background:none;border:none;
  cursor:pointer;transition:background .15s;text-align:left}
.nav-dropdown a:hover,.nav-dropdown button:hover{background:var(--blue-pale);color:var(--blue)}
.nav-dropdown .logout-btn{color:#ef4444;border-top:1px solid var(--border);margin-top:4px}
.nav-dropdown .logout-btn:hover{background:#fff5f5;color:#dc2626}

/* SLIDESHOW */
.hero{position:relative;width:100%;height:88vh;min-height:500px;margin-top:66px;overflow:hidden}
.slides-track{display:flex;height:100%;transition:transform .7s cubic-bezier(.4,0,.2,1)}
.slide{min-width:100%;height:100%;position:relative;flex-shrink:0}
.slide img{width:100%;height:100%;object-fit:cover;display:block}
.slide-overlay{position:absolute;inset:0;
  background:linear-gradient(to right,rgba(10,20,50,.78) 0%,rgba(10,20,50,.3) 55%,transparent 100%)}
.slide-content{position:absolute;top:50%;left:7%;transform:translateY(-50%);color:#fff;max-width:520px}
.slide-label{display:inline-block;font-size:0.7rem;font-weight:600;letter-spacing:3px;
  text-transform:uppercase;color:#93c5fd;margin-bottom:14px;
  background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.4);
  padding:4px 14px;border-radius:20px}
.slide-title{font-family:var(--serif);font-size:clamp(2rem,5vw,3.4rem);font-weight:600;
  line-height:1.15;margin-bottom:12px;text-shadow:0 2px 12px rgba(0,0,0,.3)}
.slide-title em{font-style:italic;color:#93c5fd}
.slide-sub{font-size:0.93rem;color:rgba(255,255,255,.72);line-height:1.7;
  margin-bottom:28px;font-weight:300}
.slide-btns{display:flex;gap:12px;flex-wrap:wrap}
.sbtn-main{padding:12px 28px;font-family:var(--font);font-size:0.82rem;font-weight:600;
  color:#fff;background:var(--blue);border:none;border-radius:7px;
  cursor:pointer;text-decoration:none;transition:all .3s;
  box-shadow:0 4px 16px rgba(29,78,216,.4)}
.sbtn-main:hover{background:var(--blue-dark);transform:translateY(-1px)}
.sbtn-sec{padding:12px 28px;font-family:var(--font);font-size:0.82rem;font-weight:500;
  color:#fff;background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.45);
  border-radius:7px;cursor:pointer;text-decoration:none;transition:all .3s;backdrop-filter:blur(6px)}
.sbtn-sec:hover{background:rgba(255,255,255,.22)}
.slide-prev,.slide-next{position:absolute;top:50%;transform:translateY(-50%);z-index:10;
  width:42px;height:42px;border-radius:50%;border:none;cursor:pointer;
  background:rgba(255,255,255,.18);backdrop-filter:blur(6px);
  color:#fff;font-size:1.15rem;display:flex;align-items:center;justify-content:center;transition:all .25s}
.slide-prev{left:18px}.slide-next{right:18px}
.slide-prev:hover,.slide-next:hover{background:var(--blue)}
.slide-dots{position:absolute;bottom:18px;left:50%;transform:translateX(-50%);
  display:flex;gap:8px;z-index:10}
.dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.4);
  border:none;cursor:pointer;transition:all .3s;padding:0}
.dot.active{background:#fff;width:22px;border-radius:4px}

/* STATS */
.stats-bar{background:var(--blue-dark);padding:26px 5%;
  display:flex;justify-content:center;gap:48px;flex-wrap:wrap}
.stat{text-align:center}
.stat-n{font-family:var(--serif);font-size:2.1rem;font-weight:600;color:#fff;display:block;line-height:1}
.stat-l{font-size:0.67rem;letter-spacing:2px;text-transform:uppercase;
  color:rgba(255,255,255,.45);margin-top:5px;display:block}
.sdot{width:6px;height:6px;border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle}

/* SEARCH */
.search-wrap{background:var(--blue-pale);padding:48px 5%}
.search-box{max-width:860px;margin:0 auto;background:#fff;
  border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-lg)}
.search-head{background:var(--blue);padding:17px 26px;
  font-family:var(--serif);font-size:1.15rem;color:#fff;display:flex;align-items:center;gap:10px}
.search-form{padding:22px 26px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:14px;align-items:end}
.fg label{display:block;font-size:0.67rem;font-weight:600;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--muted);margin-bottom:7px}
.fg input,.fg select{width:100%;padding:11px 13px;font-family:var(--font);font-size:0.87rem;
  color:var(--text);background:var(--bg);border:1.5px solid var(--border);border-radius:7px;
  outline:none;transition:border-color .25s}
.fg input:focus,.fg select:focus{border-color:var(--blue)}
.btn-search{padding:11px 24px;font-family:var(--font);font-size:0.78rem;font-weight:700;
  letter-spacing:1px;text-transform:uppercase;color:#fff;background:var(--blue);
  border:none;border-radius:7px;cursor:pointer;transition:all .25s;white-space:nowrap;
  box-shadow:0 2px 10px rgba(29,78,216,.3)}
.btn-search:hover{background:var(--blue-dark)}

/* SECTIONS */
section{padding:76px 5%}
.sec-head{text-align:center;margin-bottom:48px}
.sec-tag{display:inline-flex;align-items:center;gap:8px;font-size:0.68rem;font-weight:600;
  letter-spacing:3px;text-transform:uppercase;color:var(--blue);
  background:var(--blue-mid);padding:4px 14px;border-radius:20px;margin-bottom:14px}
.sec-title{font-family:var(--serif);font-size:clamp(1.8rem,4vw,2.8rem);
  font-weight:600;color:var(--text);line-height:1.15}
.sec-title em{font-style:italic;color:var(--blue)}

/* ROOM DASHBOARD */
.rooms-section{background:#f0f6ff;padding-top:60px;padding-bottom:60px}
.room-dashboard{max-width:1200px;margin:0 auto 36px;
  background:#fff;border:1.5px solid var(--border);border-radius:14px;
  overflow:hidden;box-shadow:var(--shadow)}

/* Dashboard header */
.dash-header{
  padding:20px 28px 0;
  display:flex;align-items:flex-start;justify-content:space-between;
  flex-wrap:wrap;gap:16px;
}
.dash-title-row{display:flex;align-items:center;gap:10px}
.dash-title{font-family:var(--serif);font-size:1.3rem;color:var(--text)}
.dash-count{font-size:0.72rem;font-weight:700;letter-spacing:1px;
  color:var(--blue);background:var(--blue-mid);padding:3px 10px;border-radius:20px}

/* Tóm tắt trạng thái */
.dash-stats{display:flex;gap:8px;flex-wrap:wrap;padding:14px 28px 0}
.ds-chip{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;
  font-size:0.73rem;font-weight:600;border:1px solid transparent}
.ds-empty {background:#eff6ff;color:var(--blue);      border-color:var(--border)}
.ds-busy  {background:#fff0f0;color:#dc2626;           border-color:#fca5a5}
.ds-clean {background:#fffbeb;color:#d97706;           border-color:#fde68a}
.ds-maint {background:#f1f5f9;color:var(--muted);      border-color:#e2e8f0}
.ds-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}

/* Filter tabs */
.dash-filters{
  display:flex;gap:8px;padding:16px 28px;
  overflow-x:auto;scrollbar-width:none;
}
.dash-filters::-webkit-scrollbar{display:none}
.flt-btn{
  display:flex;align-items:center;gap:7px;
  padding:8px 18px;border-radius:8px;border:1.5px solid var(--border);
  background:#fff;font-family:var(--font);font-size:0.8rem;font-weight:600;
  color:var(--muted);cursor:pointer;transition:all .22s;white-space:nowrap;
  position:relative;
}
.flt-btn:hover{border-color:var(--blue-light);color:var(--blue);background:var(--blue-pale)}
.flt-btn.active{background:var(--blue);color:#fff;border-color:var(--blue);
  box-shadow:0 3px 10px rgba(29,78,216,.3)}
.flt-btn .flt-icon{font-size:1rem}
.flt-badge{
  min-width:20px;height:20px;border-radius:10px;padding:0 6px;
  font-size:0.68rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;
  background:rgba(255,255,255,.25);
}
.flt-btn:not(.active) .flt-badge{background:var(--blue-mid);color:var(--blue)}

/* Expand toggle */
.dash-expand{
  padding:0 28px 20px;
  display:flex;align-items:center;justify-content:space-between;
}
.expand-line{flex:1;height:1px;background:var(--border)}
.btn-expand{
  display:flex;align-items:center;gap:6px;padding:7px 16px;
  font-family:var(--font);font-size:0.75rem;font-weight:600;
  color:var(--blue);background:var(--blue-pale);border:1.5px solid var(--border);
  border-radius:20px;cursor:pointer;transition:all .25s;white-space:nowrap;margin:0 12px;
}
.btn-expand:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.expand-arrow{transition:transform .3s}
.btn-expand.expanded .expand-arrow{transform:rotate(180deg)}

/* Không có phòng thông báo */
.no-rooms{text-align:center;padding:48px 20px;color:var(--muted)}
.no-rooms-icon{font-size:2.5rem;margin-bottom:12px}
.no-rooms p{font-size:0.9rem}

/* ROOM CARDS */
.room-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(295px,1fr));
  gap:24px;max-width:1200px;margin:0 auto}
.room-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  overflow:hidden;transition:all .35s}
.room-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:var(--blue-light)}

/* ---- 3D BED IMAGE AREA ---- */
.room-img{
  width:100%;
  height:190px;           /* fixed height, SVG fills it */
  position:relative;
  overflow:hidden;
  background:transparent; /* SVG provides its own bg */
  transition:transform .4s ease;
}
.room-card:hover .room-img{transform:scale(1.02)}
/* badges overlay on top of SVG */
.room-type-tag{position:absolute;top:12px;left:12px;z-index:2;font-size:0.64rem;font-weight:700;
  letter-spacing:1px;text-transform:uppercase;color:#fff;
  background:var(--blue);padding:4px 10px;border-radius:5px;
  backdrop-filter:blur(4px)}
.room-status-tag{position:absolute;top:12px;right:12px;z-index:2;font-size:0.64rem;font-weight:600;
  color:#fff;padding:4px 10px;border-radius:5px;display:flex;align-items:center;gap:4px;
  backdrop-filter:blur(4px)}

.room-body{padding:18px 20px}
.room-code{font-size:0.67rem;font-weight:600;letter-spacing:2px;text-transform:uppercase;
  color:var(--blue);margin-bottom:5px}
.room-name{font-family:var(--serif);font-size:1.25rem;color:var(--text);margin-bottom:10px}
.room-amenities{display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap}
.amenity{font-size:0.75rem;color:var(--muted);display:flex;align-items:center;gap:4px}
.room-desc{font-size:0.79rem;color:var(--muted);line-height:1.55}
.room-foot{display:flex;align-items:center;justify-content:space-between;
  padding:13px 20px;border-top:1px solid var(--border);background:var(--blue-pale)}
.price-amt{font-family:var(--serif);font-size:1.35rem;font-weight:600;color:var(--blue-dark)}
.price-unit{font-size:0.64rem;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-top:1px}
.btn-book{padding:8px 17px;font-size:0.71rem;font-weight:700;letter-spacing:1px;
  text-transform:uppercase;color:#fff;background:var(--blue);border:none;
  border-radius:6px;cursor:pointer;text-decoration:none;transition:all .25s}
.btn-book:hover{background:var(--blue-dark)}
.btn-book.disabled{background:#cbd5e1;color:#94a3b8;cursor:not-allowed;pointer-events:none}

/* SERVICES */
.services-section{background:var(--blue-pale)}
.service-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));
  gap:18px;max-width:1100px;margin:0 auto}
.svc-card{background:#fff;border:1.5px solid var(--border);border-radius:var(--radius);
  padding:26px 18px;text-align:center;transition:all .3s}
.svc-card:hover{transform:translateY(-4px);box-shadow:var(--shadow);border-color:var(--blue-light)}
.svc-icon{width:50px;height:50px;background:var(--blue-mid);border-radius:50%;
  display:flex;align-items:center;justify-content:center;margin:0 auto 13px;font-size:1.25rem}
.svc-name{font-family:var(--serif);font-size:1rem;color:var(--text);margin-bottom:7px}
.svc-desc{font-size:0.75rem;color:var(--muted);line-height:1.5;margin-bottom:11px}
.svc-price{font-size:0.87rem;font-weight:700;color:var(--blue)}

/* POLICY */
.policy-bar{background:var(--blue-dark);padding:52px 5%;
  display:grid;grid-template-columns:repeat(3,1fr)}
.policy-col{padding:0 36px;border-right:1px solid rgba(255,255,255,.12)}
.policy-col:last-child{border-right:none}
.policy-icon{font-size:1.7rem;margin-bottom:13px}
.policy-title{font-family:var(--serif);font-size:1.1rem;color:#fff;margin-bottom:10px}
.policy-text{font-size:0.81rem;color:rgba(255,255,255,.5);line-height:1.85;font-weight:300}
.policy-text span{color:#93c5fd;font-weight:500}

/* GALLERY */
.gallery-section{background:#fff;padding:72px 5%}
.gallery-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;
  max-width:1200px;margin:0 auto}
.gallery-item{border-radius:10px;overflow:hidden;position:relative;
  cursor:pointer;aspect-ratio:3/4;transition:all .3s}
.gallery-item:hover{transform:scale(1.02);box-shadow:var(--shadow-lg)}
.gallery-item img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .4s}
.gallery-item:hover img{transform:scale(1.05)}
.gallery-overlay{position:absolute;inset:0;
  background:linear-gradient(to top,rgba(10,20,50,.7) 0%,transparent 55%);
  opacity:0;transition:opacity .3s;
  display:flex;align-items:flex-end;padding:14px}
.gallery-item:hover .gallery-overlay{opacity:1}
.gallery-label{font-size:0.77rem;font-weight:600;color:#fff;letter-spacing:1px}

/* MAP */
.map-section{background:var(--blue-pale);padding:72px 5%}
.map-wrap{max-width:1100px;margin:0 auto;display:grid;
  grid-template-columns:1fr 1.6fr;gap:40px;align-items:start}
.map-info h3{font-family:var(--serif);font-size:1.75rem;color:var(--text);margin-bottom:14px}
.map-info p{font-size:0.87rem;color:var(--muted);line-height:1.8;margin-bottom:18px}
.map-detail{display:flex;flex-direction:column;gap:11px;margin-bottom:22px}
.map-row{display:flex;align-items:flex-start;gap:9px;font-size:0.83rem;color:var(--text)}
.map-icon{font-size:1.05rem;margin-top:1px;flex-shrink:0}
.btn-dir{display:inline-flex;align-items:center;gap:8px;
  padding:10px 22px;font-size:0.8rem;font-weight:600;color:#fff;
  background:var(--blue);border-radius:7px;text-decoration:none;transition:all .25s;
  box-shadow:0 2px 10px rgba(29,78,216,.3)}
.btn-dir:hover{background:var(--blue-dark)}
.map-frame{border-radius:12px;overflow:hidden;box-shadow:var(--shadow-lg);border:2px solid var(--border)}
.map-frame iframe{width:100%;height:380px;border:none;display:block}

/* FOOTER */
footer{background:var(--blue-dark);padding:48px 5% 24px;color:#fff}
.footer-inner{display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:40px;
  max-width:1100px;margin:0 auto 32px;padding-bottom:32px;
  border-bottom:1px solid rgba(255,255,255,.12)}
.footer-brand-row{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.footer-logo{width:42px;height:42px;border-radius:8px;object-fit:cover;border:2px solid rgba(255,255,255,.25)}
.footer-brand-name{font-family:var(--serif);font-size:1.3rem;color:#fff}
.footer-desc{font-size:0.81rem;color:rgba(255,255,255,.45);line-height:1.7}
.footer-col h4{font-size:0.77rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;
  color:rgba(255,255,255,.55);margin-bottom:13px}
.footer-col ul{list-style:none}
.footer-col li{margin-bottom:8px}
.footer-col a{font-size:0.81rem;color:rgba(255,255,255,.5);text-decoration:none;transition:color .25s}
.footer-col a:hover{color:#fff}
.footer-bottom{text-align:center;font-size:0.71rem;color:rgba(255,255,255,.28);
  max-width:1100px;margin:0 auto}

@media(max-width:900px){
  .nav-links{display:none}
  .search-form{grid-template-columns:1fr 1fr}
  .policy-bar{grid-template-columns:1fr;gap:0}
  .policy-col{border-right:none;border-bottom:1px solid rgba(255,255,255,.12);padding:28px 0}
  .policy-col:last-child{border-bottom:none}
  .gallery-grid{grid-template-columns:1fr 1fr}
  .map-wrap{grid-template-columns:1fr}
  .footer-inner{grid-template-columns:1fr}
}
@media(max-width:580px){
  .search-form{grid-template-columns:1fr}
  .stats-bar{gap:22px}
  .hero{height:75vmax;min-height:400px}
  .slide-content{left:5%;max-width:92%}
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <a href="index.php" class="nav-brand">
    <img src="assets/images/logo.jpg" alt="Easyhome Logo" class="nav-logo">
    <div class="brand-text">
      <div class="brand-name">Easyhome</div>
      <div class="brand-tagline">Nhà nghỉ theo giờ · Huế</div>
    </div>
  </a>
  <ul class="nav-links">
    <li><a href="#rooms">Phòng</a></li>
    <li><a href="#services">Dịch Vụ</a></li>
    <li><a href="#gallery">Hình Ảnh</a></li>
    <li><a href="#map">Vị Trí</a></li>
  </ul>
  <div class="nav-actions">
    <?php if (isset($_SESSION['kh_id'])): ?>
      <?php
        $khName    = $_SESSION['kh_name'] ?? 'Khách';
        $initials  = mb_strtoupper(mb_substr($khName, 0, 1, 'UTF-8'), 'UTF-8');
      ?>
      <div class="nav-user" id="navUser">
        <div class="nav-user-chip" onclick="toggleUserMenu()">
          <div class="nav-user-avatar"><?= htmlspecialchars($initials) ?></div>
          <span class="nav-user-name"><?= htmlspecialchars($khName) ?></span>
          <span class="nav-user-caret">▼</span>
        </div>
        <div class="nav-dropdown">
          <div class="nav-dropdown-header">
            <strong><?= htmlspecialchars($khName) ?></strong>
            Khách hàng
          </div>
          <a href="customer/dashboard.php">🏠 Tài khoản của tôi</a>
          <a href="customer/dashboard.php?tab=booking">🛏 Đặt phòng</a>
          <a href="customer/dashboard.php?tab=history">📋 Lịch sử đặt phòng</a>
          <button class="logout-btn" onclick="location.href='logout.php'">🚪 Đăng Xuất</button>
        </div>
      </div>
    <?php elseif (isset($_SESSION['staff_id'])): ?>
      <?php
        $staffName = $_SESSION['staff_name'] ?? $_SESSION['staff_id'];
        $staffRole = $_SESSION['staff_role'] ?? 'nhanvien';
        $staffDash = ($staffRole === 'admin') ? 'admin/dashboard.php' : 'staff/dashboard.php';
        $staffLabel= ($staffRole === 'admin') ? 'Quản trị viên' : 'Nhân viên';
        $initials  = mb_strtoupper(mb_substr($staffName, 0, 1, 'UTF-8'), 'UTF-8');
      ?>
      <div class="nav-user" id="navUser">
        <div class="nav-user-chip" onclick="toggleUserMenu()">
          <div class="nav-user-avatar"><?= htmlspecialchars($initials) ?></div>
          <span class="nav-user-name"><?= htmlspecialchars($staffName) ?></span>
          <span class="nav-user-caret">▼</span>
        </div>
        <div class="nav-dropdown">
          <div class="nav-dropdown-header">
            <strong><?= htmlspecialchars($staffName) ?></strong>
            <?= $staffLabel ?>
          </div>
          <a href="<?= $staffDash ?>">📊 Bảng điều khiển</a>
          <button class="logout-btn" onclick="location.href='logout.php'">🚪 Đăng Xuất</button>
        </div>
      </div>
    <?php else: ?>
      <a href="login.php" class="btn-outline">Đăng Nhập</a>
      <a href="register.php" class="btn-primary">Đăng Ký</a>
    <?php endif; ?>
  </div>
</nav>

<!-- SLIDESHOW -->
<section class="hero" id="home">
  <div class="slides-track" id="slidesTrack">
    <?php
    $slides = [
      ['src'=>'assets/images/room1.jpg','title'=>'Không Gian <em>Riêng Tư</em>','sub'=>'Giường gỗ tự nhiên, trang trí hoa hồng lãng mạn — ấm áp và tinh tế','label'=>'Phòng Đôi Lãng Mạn'],
      ['src'=>'assets/images/room2.jpg','title'=>'Vệ Sinh <em>Chuẩn 5 Sao</em>','sub'=>'Thiết bị TOTO cao cấp, dọn dẹp sạch sẽ sau mỗi lượt khách','label'=>'Tiêu Chuẩn Vệ Sinh'],
      ['src'=>'assets/images/room3.jpg','title'=>'Góc Thư Giãn <em>Chill</em>','sub'=>'Bàn ghế gỗ tự nhiên, hoa tươi và trái cây chào đón bạn','label'=>'Không Gian Nghỉ Ngơi'],
      ['src'=>'assets/images/room4.jpg','title'=>'Máy Chiếu & <em>Đèn Tâm Trạng</em>','sub'=>'Máy chiếu mini, đèn ngủ ấm áp, loa bluetooth — chill toàn tập','label'=>'Tiện Nghi Độc Đáo'],
      ['src'=>'assets/images/room5.jpg','title'=>'Ghế Tình Yêu <em>Đặc Biệt</em>','sub'=>'Điểm nhấn ấn tượng chỉ có tại Easyhome — trải nghiệm khác biệt','label'=>'Phong Cách Riêng'],
      ['src'=>'assets/images/room6.jpg','title'=>'Check-in <em>Tự Động 24/7</em>','sub'=>'Không cần chờ đợi, không gặp ai — bảo mật và riêng tư tuyệt đối','label'=>'Tự Động Tiện Lợi'],
    ];
    foreach($slides as $i=>$sl): ?>
    <div class="slide">
      <img src="<?=$sl['src']?>" alt="<?=strip_tags($sl['title'])?>" loading="<?=$i===0?'eager':'lazy'?>">
      <div class="slide-overlay"></div>
      <div class="slide-content">
        <span class="slide-label"><?=$sl['label']?></span>
        <h1 class="slide-title"><?=$sl['title']?></h1>
        <p class="slide-sub"><?=$sl['sub']?></p>
        <div class="slide-btns">
          <a href="#rooms" class="sbtn-main">📅 Xem Phòng Trống</a>
          <a href="tel:0768466686" class="sbtn-sec">📞 0768.466.686</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <button class="slide-prev" id="prevBtn">&#8592;</button>
  <button class="slide-next" id="nextBtn">&#8594;</button>
  <div class="slide-dots" id="dotsWrap">
    <?php for($i=0;$i<count($slides);$i++): ?>
    <button class="dot <?=$i===0?'active':''?>" data-idx="<?=$i?>"></button>
    <?php endfor; ?>
  </div>
</section>

<!-- STATS -->
<div class="stats-bar">
  <div class="stat"><span class="stat-n"><?=$totalRooms?></span><span class="stat-l">Tổng Phòng</span></div>
  <div class="stat"><span class="stat-n" style="color:#60a5fa"><?=$emptyRooms?></span>
    <span class="stat-l"><span class="sdot" style="background:#60a5fa"></span>Phòng Trống</span></div>
  <div class="stat"><span class="stat-n" style="color:#f87171"><?=$occupiedRooms?></span>
    <span class="stat-l"><span class="sdot" style="background:#f87171"></span>Đang Có Khách</span></div>
  <div class="stat"><span class="stat-n" style="color:#fbbf24"><?=$cleaningRooms?></span>
    <span class="stat-l"><span class="sdot" style="background:#fbbf24"></span>Đang Dọn</span></div>
  <div class="stat"><span class="stat-n">99K</span><span class="stat-l">Từ / 2 Giờ</span></div>
</div>

<!-- SEARCH -->
<div class="search-wrap">
  <div class="search-box">
    <div class="search-head">🔍 Tìm Phòng Trống — Easyhome Huế</div>
    <form class="search-form" method="GET" action="rooms.php">
      <div class="fg"><label>Ngày Nhận</label>
        <input type="date" name="checkin" min="<?=date('Y-m-d')?>"
               value="<?=htmlspecialchars($_GET['checkin']??'')?>"></div>
      <div class="fg"><label>Ngày Trả</label>
        <input type="date" name="checkout" min="<?=date('Y-m-d',strtotime('+1 day'))?>"
               value="<?=htmlspecialchars($_GET['checkout']??'')?>"></div>
      <div class="fg"><label>Loại Phòng</label>
        <select name="type"><option value="">Tất Cả</option>
          <option>Đơn</option><option>Đôi</option>
          <option>Gia đình</option><option>VIP</option></select></div>
      <button type="submit" class="btn-search">Tìm →</button>
    </form>
  </div>
</div>

<!-- ROOMS DASHBOARD -->
<section class="rooms-section" id="rooms">
  <div class="sec-head">
    <div class="sec-tag">🛏 Phòng Nghỉ</div>
    <h2 class="sec-title">Lựa Chọn <em>Phòng</em></h2>
  </div>

  <!-- ── DASHBOARD PANEL ── -->
  <div class="room-dashboard">

    <!-- Header -->
    <div class="dash-header">
      <div class="dash-title-row">
        <span class="dash-title">Bảng Điều Khiển Phòng</span>
        <span class="dash-count" id="dashCount"><?=count($rooms)?> phòng</span>
      </div>
    </div>

    <!-- Status chips -->
    <div class="dash-stats">
      <div class="ds-chip ds-empty">
        <div class="ds-dot" style="background:#3b82f6"></div>
        Trống: <strong><?=$emptyRooms?></strong>
      </div>
      <div class="ds-chip ds-busy">
        <div class="ds-dot" style="background:#ef4444"></div>
        Đang ở: <strong><?=$occupiedRooms?></strong>
      </div>
      <div class="ds-chip ds-clean">
        <div class="ds-dot" style="background:#f59e0b"></div>
        Dọn phòng: <strong><?=$cleaningRooms?></strong>
      </div>
      <div class="ds-chip ds-maint">
        <div class="ds-dot" style="background:#6b7280"></div>
        Bảo trì: <strong><?=$stats['Bảo trì']??0?></strong>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="dash-filters">
      <button class="flt-btn active" data-filter="all" onclick="filterRooms(this,'all')">
        <span class="flt-icon">🏨</span> Tất Cả
        <span class="flt-badge"><?=count($rooms)?></span>
      </button>
      <?php
      $typeConfig = [
        'Đơn'      => ['icon'=>'🛏','label'=>'Giường Đơn'],
        'Đôi'      => ['icon'=>'🛏🛏','label'=>'Giường Đôi'],
        'Gia đình' => ['icon'=>'👨‍👩‍👧','label'=>'Gia Đình'],
        'VIP'      => ['icon'=>'👑','label'=>'VIP / Suite'],
      ];
      foreach($roomTypes as $t):
        $cnt = $roomsByType[$t] ?? 0;
        $cfg = $typeConfig[$t] ?? ['icon'=>'🛏','label'=>$t];
        if($cnt === 0) continue;
      ?>
      <button class="flt-btn" data-filter="<?=htmlspecialchars($t)?>"
              onclick="filterRooms(this,'<?=htmlspecialchars($t)?>')">
        <span class="flt-icon"><?=$cfg['icon']?></span>
        <?=$cfg['label']?>
        <span class="flt-badge"><?=$cnt?></span>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Expand / Thu gọn -->
    <div class="dash-expand">
      <div class="expand-line"></div>
      <button class="btn-expand" id="btnExpand" onclick="toggleExpand(this)">
        <span>Xem tất cả phòng</span>
        <span class="expand-arrow">▼</span>
      </button>
      <div class="expand-line"></div>
    </div>
  </div>

  <!-- ── ROOM GRID ── -->
  <div class="room-grid" id="roomGrid">
    <?php foreach($rooms as $r):
      $sc = roomStatusColor($r['TinhTrang']);
      $ok = $r['TinhTrang']==='Trống';
      $pr = number_format($r['GiaPhong'],0,',','.');
      $si = $r['TinhTrang']==='Trống'?'✓':($r['TinhTrang']==='Đang ở'?'●':'⟳');
    ?>
    <div class="room-card" data-type="<?=htmlspecialchars($r['LoaiPhong'])?>" data-hidden="0">
      <div class="room-img">
        <?= getBedSVG($r['LoaiPhong']) ?>
        <span class="room-type-tag"><?=htmlspecialchars($r['LoaiPhong'])?></span>
        <span class="room-status-tag" style="background:<?=$sc?>"><?=$si?> <?=htmlspecialchars($r['TinhTrang'])?></span>
      </div>
      <div class="room-body">
        <div class="room-code">Phòng <?=htmlspecialchars($r['MaPhong'])?> · Tầng <?=$r['Tang']?></div>
        <div class="room-name"><?=htmlspecialchars($r['LoaiPhong'])?> Room</div>
        <div class="room-amenities">
          <div class="amenity">👥 <?=$r['SoNguoiToiDa']?> người</div>
          <div class="amenity">📽 Máy chiếu</div>
          <div class="amenity">❤️ Ghế tình yêu</div>
        </div>
        <?php if($r['MoTa']): ?>
        <div class="room-desc"><?=htmlspecialchars($r['MoTa'])?></div>
        <?php endif; ?>
      </div>
      <div class="room-foot">
        <div>
          <div class="price-amt"><?=$pr?>₫</div>
          <div class="price-unit">/ đêm</div>
        </div>
        <?php if($ok): ?>
        <a href="booking.php?room=<?=urlencode($r['MaPhong'])?>" class="btn-book">Đặt Ngay</a>
        <?php else: ?>
        <span class="btn-book disabled"><?=htmlspecialchars($r['TinhTrang'])?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- No results message -->
    <div class="no-rooms" id="noRooms" style="display:none;grid-column:1/-1">
      <div class="no-rooms-icon">🔍</div>
      <p>Không có phòng nào thuộc loại này.</p>
    </div>
  </div>
</section>

<!-- SERVICES -->
<section class="services-section" id="services">
  <div class="sec-head">
    <div class="sec-tag">✨ Dịch Vụ</div>
    <h2 class="sec-title">Tiện Ích <em>Đi Kèm</em></h2>
  </div>
  <div class="service-grid">
    <?php
    $icons=['DV001'=>'💆','DV002'=>'👕','DV003'=>'🚘','DV004'=>'🍳','DV005'=>'🚲'];
    foreach($services as $s):
      $ic=$icons[$s['MaDV']]??'⭐';$pr=number_format($s['GiaDV'],0,',','.');
    ?>
    <div class="svc-card">
      <div class="svc-icon"><?=$ic?></div>
      <div class="svc-name"><?=htmlspecialchars($s['TenDV'])?></div>
      <div class="svc-desc"><?=htmlspecialchars($s['MoTa']??'')?></div>
      <div class="svc-price"><?=$pr?>₫</div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- POLICY -->
<div class="policy-bar">
  <div class="policy-col">
    <div class="policy-icon">⏰</div>
    <div class="policy-title">Giờ Hoạt Động</div>
    <div class="policy-text">
      <span>Nhận phòng:</span> Từ 14:00<br>
      <span>Trả phòng:</span> Trước 12:00<br>
      Theo giờ: <span>99K / 2 giờ</span><br>
      Ở thêm: phụ thu 20% giá phòng
    </div>
  </div>
  <div class="policy-col">
    <div class="policy-icon">💳</div>
    <div class="policy-title">Chính Sách Đặt Cọc</div>
    <div class="policy-text">
      Cọc <span>30%</span> tổng giá trị phòng<br>
      Hủy trước 3 ngày: hoàn <span>100%</span><br>
      Hủy trong 24h: hoàn <span>50%</span><br>
      Hủy trong 2–3h: <span>không hoàn</span>
    </div>
  </div>
  <div class="policy-col">
    <div class="policy-icon">📞</div>
    <div class="policy-title">Liên Hệ</div>
    <div class="policy-text">
      <span>Hotline / Zalo:</span> 0768.466.686<br>
      <span>Check-in:</span> Tự động 24/7<br>
      Phòng riêng tư — không gặp ai<br>
      Wifi mạnh · Máy chiếu · Đèn tâm trạng
    </div>
  </div>
</div>

<!-- GALLERY -->
<section class="gallery-section" id="gallery">
  <div class="sec-head">
    <div class="sec-tag">📸 Hình Ảnh</div>
    <h2 class="sec-title">Khám Phá <em>Easyhome</em></h2>
  </div>
  <div class="gallery-grid">
    <?php
    $promos=[
      ['src'=>'assets/images/promo1.jpg','label'=>'Ưu Đãi Đặc Biệt'],
      ['src'=>'assets/images/promo2.jpg','label'=>'2H Chỉ 99K'],
      ['src'=>'assets/images/promo3.jpg','label'=>'Tiện Nghi Phòng'],
      ['src'=>'assets/images/promo4.jpg','label'=>'Grand Opening'],
    ];
    foreach($promos as $p): ?>
    <div class="gallery-item">
      <img src="<?=$p['src']?>" alt="<?=$p['label']?>" loading="lazy">
      <div class="gallery-overlay"><span class="gallery-label"><?=$p['label']?></span></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- MAP -->
<section class="map-section" id="map">
  <div class="map-wrap">
    <div class="map-info">
      <h3>📍 Vị Trí Easyhome</h3>
      <p>Tọa lạc ngay trung tâm thành phố Huế, Easyhome thuận tiện di chuyển đến mọi điểm du lịch nổi tiếng. Check-in tự động, không cần gặp ai.</p>
      <div class="map-detail">
        <div class="map-row"><span class="map-icon">🏠</span>
          <span>Nhà nghỉ theo giờ Easyhome, Tp. Huế, Thừa Thiên Huế</span></div>
        <div class="map-row"><span class="map-icon">📞</span>
          <span><strong>0768.466.686</strong> — Gọi / Zalo đặt phòng ngay</span></div>
        <div class="map-row"><span class="map-icon">⏰</span>
          <span>Check-in tự động 24/7 — không cần chờ, không gặp nhân viên</span></div>
        <div class="map-row"><span class="map-icon">💰</span>
          <span>Từ <strong>99K / 2 giờ</strong> — Giá tốt nhất khu vực trung tâm Huế</span></div>
      </div>
      <a href="https://www.google.com/maps/place/Nh%C3%A0+ngh%E1%BB%89+theo+gi%E1%BB%9D+Easyhome/@16.4602181,107.5945346,17z"
         target="_blank" rel="noopener" class="btn-dir">
        🗺 Xem Đường Đi → Google Maps
      </a>
    </div>
    <div class="map-frame">
      <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3822.449!2d107.59196!3d16.46022!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3141a16d126a13f3%3A0x37729a25f83270da!2zTmjDoCBuZ2jhu4kgdGhlbyBnaeG7nSBFYXN5aG9tZQ!5e0!3m2!1svi!2svn!4v1715000000001"
        allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
        title="Easyhome Google Maps"></iframe>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div>
      <div class="footer-brand-row">
        <img src="assets/images/logo.jpg" alt="Logo" class="footer-logo">
        <span class="footer-brand-name">Easyhome</span>
      </div>
      <p class="footer-desc">Nhà nghỉ theo giờ — trung tâm Tp. Huế.<br>
        Check-in tự động · Phòng sạch · Riêng tư · Wifi mạnh<br>
        Máy chiếu · Ghế tình yêu · Đèn tâm trạng</p>
    </div>
    <div class="footer-col">
      <h4>Điều Hướng</h4>
      <ul>
        <li><a href="#home">Trang Chủ</a></li>
        <li><a href="#rooms">Phòng Nghỉ</a></li>
        <li><a href="#services">Dịch Vụ</a></li>
        <li><a href="#gallery">Hình Ảnh</a></li>
        <li><a href="#map">Vị Trí</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Tài Khoản</h4>
      <ul>
        <?php if (isset($_SESSION['kh_id'])): ?>
          <li><a href="customer/dashboard.php">👤 Tài khoản của tôi</a></li>
          <li><a href="customer/dashboard.php?tab=booking">🛏 Đặt phòng</a></li>
          <li><a href="logout.php">🚪 Đăng xuất</a></li>
        <?php elseif (isset($_SESSION['staff_id'])): ?>
          <?php $sd = ($_SESSION['staff_role']==='admin') ? 'admin/dashboard.php' : 'staff/dashboard.php'; ?>
          <li><a href="<?= $sd ?>">📊 Bảng điều khiển</a></li>
          <li><a href="logout.php">🚪 Đăng xuất</a></li>
        <?php else: ?>
          <li><a href="login.php">Đăng Nhập KH</a></li>
          <li><a href="login.php">Đăng Nhập NV</a></li>
          <li><a href="register.php">Đăng Ký</a></li>
        <?php endif; ?>
        <li><a href="tel:0768466686">📞 0768.466.686</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    © 2026 Easyhome Hotel · Nhóm 22 – Trường ĐHKH Huế · PHP + MySQL · XAMPP
  </div>
</footer>

<script>
// SLIDESHOW
const track=document.getElementById('slidesTrack');
const dots=document.querySelectorAll('.dot');
let cur=0,total=dots.length,timer;
function goTo(n){cur=(n+total)%total;track.style.transform=`translateX(-${cur*100}%)`;
  dots.forEach((d,i)=>d.classList.toggle('active',i===cur));}
function resetTimer(){clearInterval(timer);timer=setInterval(()=>goTo(cur+1),5000);}
document.getElementById('nextBtn').onclick=()=>{goTo(cur+1);resetTimer();};
document.getElementById('prevBtn').onclick=()=>{goTo(cur-1);resetTimer();};
dots.forEach(d=>d.addEventListener('click',()=>{goTo(+d.dataset.idx);resetTimer();}));
resetTimer();
document.addEventListener('keydown',e=>{
  if(e.key==='ArrowRight'){goTo(cur+1);resetTimer();}
  if(e.key==='ArrowLeft'){goTo(cur-1);resetTimer();}
});

// DATE PICKER
const ci=document.querySelector('input[name="checkin"]');
const co=document.querySelector('input[name="checkout"]');
if(ci&&co){ci.addEventListener('change',()=>{
  const d=new Date(ci.value);d.setDate(d.getDate()+1);
  co.min=d.toISOString().split('T')[0];
  if(co.value&&co.value<=ci.value)co.value=d.toISOString().split('T')[0];
});}

// ── ROOM DASHBOARD: Filter + Expand ──
const PREVIEW_COUNT = 6; // Số phòng hiển thị mặc định (thu gọn)
let currentFilter = 'all';
let isExpanded = false;

function filterRooms(btn, type) {
  // Đổi tab active
  document.querySelectorAll('.flt-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentFilter = type;
  isExpanded = false;

  // Reset nút expand
  const btnExp = document.getElementById('btnExpand');
  btnExp.classList.remove('expanded');
  btnExp.querySelector('span:first-child').textContent = 'Xem tất cả phòng';

  applyFilter();
}

function applyFilter() {
  const cards = document.querySelectorAll('#roomGrid .room-card');
  let visible = 0, total = 0;

  cards.forEach(card => {
    const match = currentFilter === 'all' || card.dataset.type === currentFilter;
    if (match) {
      total++;
      if (isExpanded || total <= PREVIEW_COUNT) {
        card.style.display = '';
        card.style.opacity = '0';
        card.style.transform = 'translateY(16px)';
        // Animate in
        setTimeout(() => {
          card.style.transition = 'opacity .35s ease, transform .35s ease';
          card.style.opacity = '1';
          card.style.transform = 'translateY(0)';
        }, 20 + visible * 40);
        visible++;
      } else {
        card.style.display = 'none';
      }
    } else {
      card.style.display = 'none';
    }
  });

  // No rooms message
  document.getElementById('noRooms').style.display = total === 0 ? 'block' : 'none';

  // Update count badge
  document.getElementById('dashCount').textContent = total + ' phòng';

  // Cập nhật nút expand
  const hidden = total - Math.min(total, PREVIEW_COUNT);
  const btnExp = document.getElementById('btnExpand');
  if (!isExpanded && hidden > 0) {
    btnExp.style.display = '';
    btnExp.querySelector('span:first-child').textContent = `Xem thêm ${hidden} phòng nữa`;
  } else if (isExpanded && total > PREVIEW_COUNT) {
    btnExp.style.display = '';
    btnExp.querySelector('span:first-child').textContent = 'Thu gọn';
  } else {
    btnExp.style.display = 'none';
  }
}

function toggleExpand(btn) {
  isExpanded = !isExpanded;
  btn.classList.toggle('expanded', isExpanded);
  applyFilter();
}

// Khởi động dashboard
applyFilter();

// SCROLL REVEAL
const obs=new IntersectionObserver(entries=>{entries.forEach(e=>{
  if(e.isIntersecting){e.target.style.opacity='1';e.target.style.transform='translateY(0)';}
});},{threshold:.08});
document.querySelectorAll('.room-card,.svc-card,.gallery-item').forEach((el,i)=>{
  el.style.cssText+=`opacity:0;transform:translateY(20px);
    transition:opacity .5s ease ${i*.07}s,transform .5s ease ${i*.07}s`;
  obs.observe(el);
});

// User dropdown toggle
function toggleUserMenu() {
  const el = document.getElementById('navUser');
  if (!el) return;
  el.classList.toggle('open');
}
// Đóng dropdown khi click ra ngoài
document.addEventListener('click', function(e) {
  const el = document.getElementById('navUser');
  if (el && !el.contains(e.target)) el.classList.remove('open');
});
</script>
</body>
</html>
