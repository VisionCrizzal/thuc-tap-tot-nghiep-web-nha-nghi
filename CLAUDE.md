# CLAUDE.md — Easyhome Hotel Management System
> **Project memory cho Claude AI** — Đọc file này trước khi làm bất kỳ việc gì trong project.
> Cập nhật lần cuối: 24/05/2026 (thêm TODO đầy đủ)

---

## 🎯 PROJECT OVERVIEW

**Tên dự án:** Hệ thống quản lý khách sạn Easyhome  
**Loại:** Web app (PHP + MySQL + HTML/CSS/JS)  
**Server:** XAMPP (Apache + MySQL)  
**URL local:** `http://localhost/khachsan/`  
**OS:** macOS  
**Trường:** ĐHKH Huế — Nhóm 22  
**Thực thể:** Nhà nghỉ theo giờ Easyhome, TP. Huế  
**Hotline:** 0768.466.686  
**Google Maps:** 16.4602181, 107.5945346

---

## 🍎 macOS — ĐƯỜNG DẪN & LỆNH

| Mục đích | Đường dẫn |
|----------|-----------|
| **Thư mục project** | `/Applications/XAMPP/xamppfiles/htdocs/khachsan/` |
| **Mở XAMPP Manager** | `/Applications/XAMPP/manager-osx.app` |
| **phpMyAdmin** | `http://localhost/phpmyadmin` |
| **Web app** | `http://localhost/khachsan/` |
| **Error log Apache** | `/Applications/XAMPP/xamppfiles/logs/error_log` |
| **PHP config** | `/Applications/XAMPP/xamppfiles/etc/php.ini` |

**Terminal — cd nhanh vào project:**
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/khachsan
```

**Bật error trong PHP khi debug** (thêm đầu file .php):
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

**Xem log lỗi Apache:**
```bash
tail -f /Applications/XAMPP/xamppfiles/logs/error_log
```

---

## 🗂️ CẤU TRÚC THƯ MỤC

```
khachsan/                          ← Root: /Applications/XAMPP/xamppfiles/htdocs/khachsan/
├── index.php                      ✅ Trang chủ (DONE)
├── login.php                      ✅ Đăng nhập chung — 1 form tự nhận diện vai trò (DONE)
├── register.php                   ✅ Đăng ký khách hàng (DONE)
├── rooms.php                      ✅ Trang tìm kiếm & kết quả phòng trống (DONE)
├── logout.php                     🔲 Dedicated logout — session_destroy() + redirect (TODO)
├── forgot-password.php            🔲 Quên mật khẩu (TODO)
├── 404.php                        🔲 Custom error page (TODO)
│
├── config/
│   ├── db.php                     ✅ PDO connection + helpers (DONE)
│   └── session.php                ✅ Session name EASYHOME_SID, SameSite=Lax (DONE)
│
├── assets/images/
│   ├── logo.jpg                   Logo Easyhome script font, nền đen
│   ├── room1-6.jpg                6 ảnh slideshow phòng thật
│   └── promo1-4.jpg               4 ảnh gallery
│
├── staff/
│   ├── login.php                  ✅ Đăng nhập NV (DONE)
│   ├── dashboard.php              ✅ Quản lý đặt phòng + sơ đồ phòng + thông tin NV (DONE)
│   ├── checkin.php                ✅ Check-in form: DV, KM, thu cọc đa phương thức (DONE)
│   ├── checkout.php               ✅ Check-out + in hóa đơn PDF (DONE)
│   └── profile.php                🔲 NV đổi mật khẩu + cập nhật thông tin (TODO)
│
├── admin/
│   ├── dashboard.php              ✅ Tổng quan + quản lý đặt phòng + nhân viên (DONE)
│   ├── rooms.php                  ✅ CRUD phòng, sơ đồ theo tầng (DONE)
│   ├── accounts.php               🔲 Quản lý tài khoản nâng cao (TODO)
│   ├── services.php               🔲 CRUD dịch vụ — bảng DICH_VU (TODO)
│   ├── promotions.php             🔲 CRUD khuyến mãi — bảng KHUYEN_MAI (TODO)
│   ├── invoices.php               🔲 Danh sách hóa đơn toàn hệ thống (TODO)
│   ├── reports.php                🔲 Báo cáo doanh thu chi tiết + xuất CSV (TODO)
│   ├── housekeeping.php           🔲 Phân công & theo dõi dọn phòng (TODO)
│   └── calendar.php               🔲 Lịch đặt phòng dạng calendar (TODO)
│
├── customer/
│   ├── dashboard.php              ✅ Trang KH: profile, đặt phòng, lịch sử + KM (DONE)
│   └── booking-detail.php         🔲 Chi tiết đặt phòng: DV, hóa đơn, trạng thái cọc (TODO)
│
├── hotel_db.sql                   ✅ Schema đầy đủ + data mẫu
├── HUONG_DAN.md                   Cài đặt XAMPP
└── CLAUDE.md                      ← FILE NÀY
```

---

## 🗄️ DATABASE SCHEMA (khachsan_db, utf8mb4)

### PHONG
- MaPhong PK | LoaiPhong (Đơn/Đôi/Gia đình/VIP) | GiaPhong | SoNguoiToiDa
- TinhTrang (**Trống/Đang ở/Đang dọn/Bảo trì**) | MoTa | HinhAnh | Tang

### KHACH_HANG
- MaKH PK | HoTen | TenTaiKhoan UNIQUE | MatKhau (password_hash)
- CCCD | SoDienThoai | Email | TichDiem | NgayTao

### NHAN_VIEN
- MaNV PK | HoTen | SoDienThoai | Email
- ChucVu (Lễ tân/Quản lý) | NgayVaoLam | TrangThai

### TAI_KHOAN (đăng nhập nội bộ)
- TenTK PK | MatKhau (password_hash)
- VaiTro (**admin/nhanvien**) | MaNV FK | TrangThai | LanDangNhapCuoi

### DAT_PHONG
- MaDP PK | MaKH FK | MaPhong FK | NgayCheckIn | NgayCheckOut
- SoLuongKhach | TienCoc | TongGia
- TrangThai (Chờ xác nhận/Đã nhận phòng/Đã trả phòng/Đã hủy)
- MaNV_XuLy FK

### DICH_VU — DAT_DICH_VU — HOA_DON — KHUYEN_MAI
> Xem chi tiết trong hotel_db.sql

**Hash mặc định trong SQL = "password"** (Laravel hash `$2y$10$92IXU...`)  
Đổi thực tế: `echo password_hash('MatKhauMoi', PASSWORD_DEFAULT);`

---

## 🎨 DESIGN SYSTEM

### Fonts (KHÔNG thay đổi)
```css
--font:  Calibri, 'Calibri Light', Arial, sans-serif;  /* Body — system font */
--serif: 'Playfair Display', Georgia, serif;             /* Headings */
```
> ❌ KHÔNG dùng Be Vietnam Pro (đã bỏ từ v4)

### Color Palette
```css
--blue:       #1d4ed8;   /* Primary */
--blue-light: #3b82f6;   /* Hover/icons */
--blue-dark:  #1e3a8a;   /* Headers/dark areas */
--blue-pale:  #eff6ff;   /* Card backgrounds */
--blue-mid:   #dbeafe;   /* Badges */
--bg:         #f0f6ff;   /* Section backgrounds */
--text:       #1e293b;
--muted:      #64748b;
--border:     #bfdbfe;
--shadow:     0 4px 24px rgba(29,78,216,.10);
--shadow-lg:  0 12px 48px rgba(29,78,216,.15);
--radius:     10px;
```

### Status Colors phòng
```
Trống: #3b82f6 | Đang ở: #ef4444 | Đang dọn: #f59e0b | Bảo trì: #6b7280
```

---

## 🛏️ BED SVG FUNCTIONS (index.php — KHÔNG viết lại)

```php
singleBedSVG()          → Phòng Đơn (1 gối, nền xanh nhạt)
doubleBedSVG(false)     → Phòng Đôi (2 gối, nền xanh)
doubleBedSVG(true)      → Phòng VIP (2 gối + cánh hoa đỏ, nền tím)
familyBedSVG()          → Phòng Gia Đình (2 giường riêng, nền xanh lá)

// Dispatcher — tái sử dụng trong mọi trang hiển thị phòng:
getBedSVG(string $loaiPhong): string
```

---

## 🔐 AUTH SYSTEM

### config/db.php helpers
```php
loginStaff(PDO $pdo, string $username, string $password): array|false
getAllRooms(PDO $pdo): array
getAvailableRooms(PDO $pdo, string $checkin, string $checkout): array
getAllServices(PDO $pdo): array
```

### Session keys
```php
// Staff/Admin:    staff_id | staff_name | staff_role ('admin'|'nhanvien') | staff_maNV
// Khách hàng:     kh_id | kh_name | kh_role ('khachhang')
```

### Login routing (login.php — 1 form, tự nhận diện)
- Thử KHACH_HANG trước → nếu khớp → customer/dashboard.php
- Thử TAI_KHOAN sau → VaiTro='nhanvien' → staff/dashboard.php
- Thử TAI_KHOAN sau → VaiTro='admin' → admin/dashboard.php

---

## ✅ LỊCH SỬ YÊU CẦU (Conversation log)

| # | Yêu cầu | Kết quả | File thay đổi |
|---|---------|---------|---------------|
| 1 | Tạo hệ thống quản lý KS, kết nối XAMPP, giao diện trang chủ | Tạo hotel_db.sql + config/db.php + index.php + staff/login.php | v1 |
| 2 | Chèn ảnh thật vào slideshow, logo Easyhome, Google Maps, gallery | Slideshow 6 ảnh, logo từ ảnh thật, map embed, gallery 4 ảnh | v2 |
| 3 | Thêm ảnh 3D giường vào card phòng | SVG inline: 1/2/family/VIP bed illustrations | v3 |
| 4 | Đổi theme xanh, bỏ CSDL badge, redesign login đồng bộ | Blue theme, font Calibri, login page mới | v4 |
| 5 | Đổi nút "Nhân Viên/Quản Lý" → "Đăng Nhập/Đăng Ký", xoá hint test | Navbar đổi, hint box xoá | v5 |
| 6 | Tạo login.php (bị 404), fix CSS warning | login.php 3 tab + fix empty CSS ruleset | v6 |
| 7 | Room dashboard filter + thu gọn, tạo CLAUDE.md | Dashboard filter tabs + CLAUDE.md | v7 |
| 8 | Trang admin dashboard đầy đủ chức năng quản lý | admin/dashboard.php — sidebar, 5 tab, CRUD đặt phòng + nhân viên + báo cáo | v8 |
| 9 | Fix bug đăng nhập admin, xóa 3 tab vai trò | login.php viết lại: 1 form thống nhất, tự nhận diện vai trò từ DB | v9 |
| 10 | Fix session conflict với phpMyAdmin | Tạo config/session.php — session name EASYHOME_SID, cookie SameSite=Lax | v10 |
| 11 | Trang đăng ký + trang khách hàng | register.php + customer/dashboard.php (3 tab: profile, đặt phòng, lịch sử) | v11 |
| 12 | Trang nhân viên | staff/dashboard.php — stats, quản lý đặt phòng (check-in/out/hủy), sơ đồ phòng, thông tin NV | v12 |
| 13 | Quản lý phòng cho admin | admin/rooms.php — CRUD phòng, đổi trạng thái nhanh, sơ đồ phòng theo tầng, filter theo loại/trạng thái | v13 |
| 14 | Trang kết quả tìm kiếm | rooms.php — search form, filter loại phòng, room cards với SVG giường, pre-fill booking form | v14 |
| 15 | Check-in, Check-out, thanh toán đa phương thức, in hóa đơn PDF, khuyến mãi | staff/checkin.php + staff/checkout.php (cash/card/QR/Apple Pay/Google Pay/Samsung Pay, in PDF) + promo trong customer/dashboard.php + sửa staff/dashboard.php | v15 |

---

## 🐛 BUGS ĐÃ GẶP

| # | Lỗi | Nguyên nhân | Fix |
|---|-----|-------------|-----|
| 1 | 404 login.php | Đổi link navbar chưa tạo file | Tạo login.php root |
| 2 | CSS duplicate `.rooms-section` | Định nghĩa 2 lần → conflict | Xoá duplicate |
| 3 | VSCode warning `.header-text{}` rỗng | Xoá content nhưng giữ selector | str_replace xoá dòng |
| 4 | Đăng nhập thất bại | Hash SQL = "password", user nhập sai | Dùng "password" khi test |
| 5 | Tiếng Việt lỗi | Charset sai | utf8mb4 trong DB + PDO DSN |
| 6 | Scroll reveal conflict với filter | obs.observe sau khi filter ẩn card | Filter trước, reveal sau |
| 7 | Xóa nhân viên lỗi FK constraint | TAI_KHOAN.MaNV FK → NHAN_VIEN, xóa hard sẽ fail nếu có DAT_PHONG | Dùng soft delete: TrangThai='Ngừng hoạt động' thay vì DELETE |
| 8 | NVARCHAR không tồn tại MySQL | MySQL không hỗ trợ NVARCHAR native (alias VARCHAR với utf8mb4) | Đã dùng utf8mb4 ở database level, hoạt động bình thường |
| 9 | Admin login không vào được trang admin | login.php dùng 3 tab: nếu chọn sai tab thì báo "không có quyền" dù đúng mật khẩu | Xóa tab, dùng 1 form, tự nhận diện vai trò từ VaiTro trong TAI_KHOAN |
| 10 | Session không persist giữa các trang — session_id() rỗng sau session_start() | phpMyAdmin cũng dùng cookie PHPSESSID trên localhost, trình duyệt lẫn lộn hai cookie, session_start() thất bại hoàn toàn | Tạo config/session.php: đổi session name → EASYHOME_SID, set cookie params rõ ràng (SameSite=Lax). Dùng file này thay vì gọi session_start() trực tiếp |

---

## 🔲 TODO (ưu tiên cao → thấp)

```
─────────────────────────── ĐÃ HOÀN THÀNH ───────────────────────────
[x] register.php           — Form đăng ký KH (DONE v11)
[x] customer/dashboard.php — Profile + đặt phòng + lịch sử (DONE v11)
[x] staff/dashboard.php    — Quản lý đặt phòng + sơ đồ phòng + thông tin NV (DONE v12)
[x] admin/dashboard.php    — Tổng quan, quản lý đặt phòng, nhân viên (DONE v8)
[x] admin/rooms.php        — CRUD phòng, đổi trạng thái, sơ đồ theo tầng (DONE v13)
[x] rooms.php              — Trang tìm kiếm & kết quả phòng trống (DONE v14)
[x] staff/checkin.php      — Check-in: DV, KM, thu cọc đa phương thức (DONE v15)
[x] staff/checkout.php     — Check-out + in hóa đơn PDF (DONE v15)
[x] Khuyến mãi trong booking — auto-detect + mã thủ công (DONE v15)
[x] In hóa đơn PDF         — window.print() + @media print CSS (DONE v15)

──────────────────────── CẦN LÀM — ƯU TIÊN CAO ────────────────────────
[ ] logout.php             — Dedicated logout: session_destroy() + redirect
                             ⚠️ BUG bảo mật: admin/dashboard.php & admin/rooms.php
                             hiện redirect thẳng login.php mà KHÔNG destroy session!

[ ] admin/accounts.php     — Quản lý tài khoản: CRUD TAI_KHOAN (reset mật khẩu NV,
                             khóa/mở tài khoản KH, tạo tài khoản NV mới)

[ ] admin/services.php     — CRUD dịch vụ (bảng DICH_VU): thêm/sửa/xóa dịch vụ,
                             đổi trạng thái Khả dụng/Ngừng, cập nhật giá & ảnh

[ ] admin/promotions.php   — CRUD khuyến mãi (bảng KHUYEN_MAI): thêm/sửa/xóa,
                             kích hoạt/vô hiệu hóa, xem thống kê lượt dùng

──────────────────────── CẦN LÀM — ƯU TIÊN TRUNG ──────────────────────
[ ] admin/invoices.php     — Danh sách toàn bộ hóa đơn (HOA_DON): lọc theo tháng/
                             trạng thái/nhân viên, tìm kiếm, xem lại từng hóa đơn,
                             tổng hợp tiền đã thu/chưa thu

[ ] admin/reports.php      — Báo cáo doanh thu: theo tháng/quý/năm, biểu đồ
                             Chart.js (line chart doanh thu, bar chart công suất),
                             top phòng/dịch vụ/NV, xuất báo cáo CSV

[ ] Tích điểm KH (TichDiem) — Cộng điểm sau mỗi checkout thành công
                             (1.000đ = 1 điểm), trừ điểm khi đổi ưu đãi,
                             hiển thị điểm tích lũy trong customer/dashboard.php
                             ⚠️ KHACH_HANG.TichDiem hiện = 0 mãi mãi, chưa cập nhật!

[ ] customer/booking-detail.php — KH xem chi tiết từng đặt phòng: danh sách dịch
                             vụ đã gọi, hóa đơn đầy đủ, trạng thái thanh toán cọc,
                             nút in/tải PDF hóa đơn

──────────────────────── CẦN LÀM — ƯU TIÊN THẤP ───────────────────────
[ ] staff/profile.php      — NV xem/đổi mật khẩu, cập nhật SĐT & email cá nhân
                             (tách riêng khỏi tab profile trong staff/dashboard.php)

[ ] forgot-password.php    — Flow quên mật khẩu: nhập email → tạo token → gửi link
                             reset qua email (cần PHPMailer hoặc PHP mail())

[ ] admin/housekeeping.php — Phân công dọn phòng: danh sách phòng "Đang dọn",
                             assign NV, NV cập nhật hoàn thành → TinhTrang → "Trống"

[ ] admin/calendar.php     — Lịch đặt phòng dạng calendar tháng/tuần: xem phòng
                             nào trống theo ngày, kéo thả đặt phòng mới (FullCalendar.js)

[ ] 404.php / error.php    — Custom error pages đồng bộ brand Easyhome

[ ] Email xác nhận         — PHPMailer: gửi email xác nhận ngay khi KH đặt phòng
                             thành công + email nhắc nhở check-in 1 ngày trước

[ ] Export CSV/Excel       — Xuất danh sách đặt phòng, doanh thu từ admin
                             (PHP fputcsv cho CSV, PhpSpreadsheet cho Excel .xlsx)
```

---

## 📐 CODE PATTERNS (tái sử dụng)

### PHP — Session check (đặt đầu mỗi trang protected)
```php
require_once __DIR__ . '/../config/session.php';   // KHÔNG dùng session_start() trực tiếp
require_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['staff_id'])) {
    header('Location: ../login.php');
    exit;
}
$isAdmin = $_SESSION['staff_role'] === 'admin';
```

### PHP — Query an toàn
```php
$stmt = $pdo->prepare("SELECT * FROM PHONG WHERE MaPhong = :id");
$stmt->execute([':id' => $_GET['id']]);
$room = $stmt->fetch();
```

### CSS — Section mới (copy pattern)
```css
.new-section { background: var(--blue-pale); padding: 72px 5%; }
.new-section .inner { max-width: 1200px; margin: 0 auto; }
```

### JS — Filter + animate (copy từ rooms dashboard)
```js
function filterItems(btn, type) {
  document.querySelectorAll('.flt-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('[data-type]').forEach(el => {
    const show = type === 'all' || el.dataset.type === type;
    el.style.display = show ? '' : 'none';
    if (show) { /* animate in */ }
  });
}
```

### JS — Scroll reveal (copy cho section mới)
```js
const obs = new IntersectionObserver(e => {
  e.forEach(x => { if(x.isIntersecting){
    x.target.style.opacity='1'; x.target.style.transform='translateY(0)';
  }});
}, {threshold:.08});
document.querySelectorAll('.new-card').forEach((el,i) => {
  el.style.cssText += `opacity:0;transform:translateY(20px);
    transition:opacity .5s ease ${i*.07}s,transform .5s ease ${i*.07}s`;
  obs.observe(el);
});
```

---

## ⚙️ APPROACH KHI DEBUG & THÊM FEATURE

### Debug workflow
1. Kiểm tra URL đúng chưa (`http://localhost/khachsan/...`)
2. XAMPP: Apache + MySQL đều xanh chưa?
3. `error_reporting(E_ALL); ini_set('display_errors',1);` đầu file
4. Check phpMyAdmin xem data có đúng không
5. Dùng `var_dump($variable); die();` để trace

### Thêm trang mới
1. Tạo file PHP với header session_start + require config/db.php
2. Copy CSS variables từ index.php vào `<style>`
3. Thêm link vào navbar/footer của index.php
4. Cập nhật CLAUDE.md (TODO → DONE)

### Sửa file có sẵn
- Dùng `str_replace` (bash python3) cho thay đổi nhỏ
- Chỉ ghi đè toàn bộ khi > 30% file thay đổi
- Luôn verify sau khi sửa: `grep -n "keyword" file.php`

---

## 📁 FILES KHÔNG SỬA (stable)

| File | Lý do |
|------|-------|
| hotel_db.sql | Schema ổn định |
| config/db.php | Connection + helpers OK |
| assets/images/*.jpg | Ảnh thật, không thay |
| SVG functions trong index.php | Tọa độ 3D tính toán chính xác |

---

*File này được tạo tự động bởi Claude — cập nhật mỗi khi có thay đổi lớn.*
