# CLAUDE.md — Easyhome Hotel Management System
> **Project memory cho Claude AI** — Đọc file này trước khi làm bất kỳ việc gì trong project.
> Cập nhật lần cuối: 26/05/2026 (v31: PHPMailer + Email xác nhận + Forgot-password flow)

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
├── logout.php                     ✅ Dedicated logout — session_destroy() + redirect (DONE v17)
├── booking.php                    ✅ Trung gian đặt phòng: redirect KH đã đăng nhập, landing page nếu chưa (DONE v21)
├── forgot-password.php            ✅ Quên mật khẩu — nhập email → token 1h → link reset (DONE v31)
├── reset-password.php             ✅ Đặt MK mới từ token — verify + strength bar + PRG (DONE v31)
├── 404.php                        ✅ Custom error page — branded, auto-redirect 15s (DONE v19)
│
├── config/
│   ├── db.php                     ✅ PDO connection + helpers + CSRF (DONE)
│   ├── session.php                ✅ Session name EASYHOME_SID, SameSite=Lax (DONE)
│   └── mailer.php                 ✅ PHPMailer wrapper — SMTP Gmail, 3 hàm gửi mail (DONE v31)
│
├── assets/images/
│   ├── logo.jpg                   Logo Easyhome script font, nền đen
│   ├── room1-6.jpg                6 ảnh slideshow phòng thật
│   └── promo1-4.jpg               4 ảnh gallery
│
├── staff/
│   ├── login.php                  ✅ Redirect về ../login.php (file cũ v1 đã được thay — DONE v28)
│   ├── dashboard.php              ✅ Quản lý đặt phòng + sơ đồ phòng + thông tin NV (DONE)
│   ├── checkin.php                ✅ Check-in form: DV, KM, thu cọc đa phương thức (DONE)
│   ├── checkout.php               ✅ Check-out + in hóa đơn PDF (DONE)
│   └── profile.php                ✅ NV đổi mật khẩu + cập nhật SĐT/email (DONE v18)
│
├── admin/
│   ├── dashboard.php              ✅ Tổng quan + quản lý đặt phòng + nhân viên (DONE)
│   ├── rooms.php                  ✅ CRUD phòng, sơ đồ theo tầng (DONE)
│   ├── accounts.php               ✅ CRUD TAI_KHOAN + khóa/mở KH, edit KH/NV (DONE v27)
│   ├── services.php               ✅ CRUD dịch vụ — bảng DICH_VU (DONE)
│   ├── promotions.php             ✅ CRUD khuyến mãi — bảng KHUYEN_MAI (DONE v20)
│   ├── invoices.php               ✅ Danh sách hóa đơn toàn hệ thống — stats, filter, modal, mark paid (DONE v24)
│   ├── reports.php                ✅ Báo cáo doanh thu: stats, Chart.js, top 5, xuất CSV (DONE v25)
│   ├── housekeeping.php           ✅ Phân công & theo dõi dọn phòng (DONE v19)
│   └── calendar.php               ✅ Lịch đặt phòng dạng calendar tháng (DONE v19)
│
├── customer/
│   ├── dashboard.php              ✅ Trang KH: profile, đặt phòng, lịch sử + KM + gửi email xác nhận (DONE v31)
│   ├── profile.php                ✅ KH đổi MK + cập nhật HoTen/SĐT/Email/CCCD/DiaChi/GioiTinh/NgaySinh (DONE v26)
│   └── booking-detail.php         ✅ Chi tiết đặt phòng: DV, hóa đơn, cọc, điểm, in PDF (DONE v18)
│
├── vendor/                        ✅ Composer packages — PHPMailer 7.1.1 (DONE v31)
├── composer.json                  ✅ require: phpmailer/phpmailer
├── .htaccess                      ✅ 404 route + Options -Indexes + bảo vệ .md/.sql (DONE v30)
├── config/.htaccess               ✅ Require all denied — chặn truy cập HTTP vào config/ (DONE v30)
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
- CCCD | SoDienThoai | Email | GioiTinh | NgaySinh | DiaChi | QuocTich
- TichDiem | NgayTao | TrangThai
- **ResetToken** VARCHAR(64) NULL — token 64 ký tự cho forgot-password *(thêm v31)*
- **ResetExpires** DATETIME NULL — thời điểm hết hạn token (1 giờ) *(thêm v31)*

### NHAN_VIEN
- MaNV PK | HoTen | SoDienThoai | Email
- ChucVu (Lễ tân/Quản lý) | NgayVaoLam | TrangThai

### TAI_KHOAN (đăng nhập nội bộ)
- TenTK PK | MatKhau (password_hash)
- VaiTro (**admin/nhanvien**) | MaNV FK | TrangThai | LanDangNhapCuoi

### DAT_PHONG
- MaDP PK | MaKH FK | MaPhong FK | NgayCheckIn | NgayCheckOut
- SoLuongKhach | TienCoc | TongGia | GhiChu | NgayDat
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

// CSRF (dùng trong mọi form POST):
csrfToken(): string        // tạo/lấy token từ session
csrfField(): string        // <input type="hidden" name="csrf_token" value="...">
csrfVerify(): void         // kiểm tra token, die(403) nếu sai
```

### config/mailer.php helpers (PHPMailer — v31)
```php
createMailer(): PHPMailer                              // SMTP Gmail đã cấu hình sẵn
sendBookingConfirmation(email, name, booking[]): bool  // email xác nhận đặt phòng
sendCheckinReminder(email, name, booking[]): bool      // nhắc check-in ngày mai
sendPasswordReset(email, name, resetLink): bool        // link reset mật khẩu 1h

// SMTP: smtp.gmail.com:587 STARTTLS
// Tài khoản gửi: nguyengocvi94tn@gmail.com
// App Password: xem config/mail-secret.php (gitignored — KHÔNG commit)
// Tạo App Password: myaccount.google.com/apppasswords → Tên ứng dụng: Easyhome
// SSL verify_peer: false (fix cho XAMPP local OpenSSL)
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
- `?reset=1` → hiện banner "Đặt lại mật khẩu thành công"

### Forgot-password flow (v31)
```
forgot-password.php (nhập email)
  → tạo token bin2hex(32) + lưu ResetToken/ResetExpires vào KHACH_HANG
  → sendPasswordReset() → email link: /khachsan/reset-password.php?token=xxx
    → reset-password.php xác minh token (SELECT WHERE ResetToken=? AND ResetExpires>NOW())
      → form đổi MK + strength bar
        → UPDATE MatKhau + SET ResetToken=NULL, ResetExpires=NULL
          → redirect login.php?reset=1
```

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
| 15 | Check-in, Check-out, thanh toán đa phương thức, in hóa đơn PDF, khuyến mãi | staff/checkin.php + staff/checkout.php (cash/card/QR/Apple Pay/Google Pay/Samsung Pay, in PDF) + promo trong customer/dashboard.php | v15 |
| 16 | Quản lý dịch vụ admin | admin/services.php — CRUD DICH_VU, toggle trạng thái, emoji picker, preview ảnh, stat cards | v16 |
| 17 | Logout + Quản lý tài khoản | logout.php (session_destroy tập trung) + admin/accounts.php (CRUD TAI_KHOAN, khóa/mở KH, thêm cột TrangThai) | v17 |
| 18 | Tích điểm + Chi tiết đặt phòng + Hồ sơ NV | staff/checkout.php cộng TichDiem + customer/booking-detail.php + staff/profile.php | v18 |
| 19 | Dọn phòng + Lịch calendar + 404 | admin/housekeeping.php + admin/calendar.php + 404.php + cập nhật sidebar toàn bộ admin pages | v19 |
| 20 | Quản lý khuyến mãi | admin/promotions.php — CRUD KHUYEN_MAI, toggle, EffStatus, stats, filter tabs | v20 |
| 21 | Fix dead link booking.php (BUG #11) | booking.php mới + login.php thêm ?next= redirect sanitized | v21 |
| 22 | Fix index.php session + navbar user chip (BUG #12) | index.php → config/session.php; navbar user chip + dropdown | v22 |
| 23 | Fix staff/dashboard.php logout inline (BUG #13) | header('../logout.php')+exit; scan toàn project sạch | v23 |
| 24 | admin/invoices.php — Quản lý hóa đơn | Stats 5 cards, filter+search, modal chi tiết, mark paid/unpaid, in PDF | v24 |
| 25 | admin/reports.php — Báo cáo doanh thu | Stats 4 cards, Chart.js, top 5 phòng/DV/NV, bảng 12 tháng, xuất CSV | v25 |
| 26 | customer/profile.php — KH chỉnh sửa hồ sơ | Form HoTen/SĐT/Email/DiaChi/GioiTinh/NgaySinh; CCCD optional; đổi MK strength bar | v26 |
| 27 | admin/accounts.php — Edit thông tin KH + NV | Tab edit_kh + edit_nv; nút ✏️ Sửa trong bảng KH và NV | v27 |
| 28 | Fix BUG #14 #15 #16 + audit toàn dự án | staff/login.php → redirect; sidebar stale links sửa ×5; services.php thêm calendar | v28 |
| 29 | login.php — Brute-force protection | IP lockout JSON, max 5 → khóa 15 phút, dots indicator, countdown JS | v29 |
| 30 | .htaccess — 404 route mapping + bảo mật | ErrorDocument 404; Options -Indexes; FilesMatch .md/.sql; config/.htaccess deny | v30 |
| 31 | PHPMailer + Email xác nhận + Forgot-password | Composer PHPMailer 7.1.1; config/mailer.php (3 hàm + 3 templates); customer/dashboard.php gửi mail sau đặt phòng; forgot-password.php + reset-password.php; KHACH_HANG thêm ResetToken+ResetExpires; login.php thêm link quên MK + banner reset=1 | v31 |

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
| 7 | Xóa nhân viên lỗi FK constraint | TAI_KHOAN.MaNV FK → NHAN_VIEN | Soft delete: TrangThai='Ngừng hoạt động' |
| 8 | NVARCHAR không tồn tại MySQL | MySQL alias VARCHAR với utf8mb4 | utf8mb4 ở database level — OK |
| 9 | Admin login không vào được trang admin | login.php 3 tab chọn sai tab → báo không quyền | Xóa tab, 1 form tự nhận diện từ VaiTro |
| 10 | Session không persist — session_id() rỗng | phpMyAdmin dùng PHPSESSID cùng localhost, conflict | config/session.php: EASYHOME_SID, SameSite=Lax |
| 11 | Dead link booking.php → 404 | index.php trỏ booking.php nhưng file không tồn tại | ✅ **DONE v21** — Tạo booking.php mới |
| 12 | index.php dùng session_start() thay vì config/session.php | Session name khác → KH đăng nhập không nhận diện được | ✅ **DONE v22** — Đổi sang config/session.php; thêm user chip navbar |
| 13 | staff/dashboard.php còn inline session_destroy() | Bỏ qua centralized logout logic | ✅ **DONE v23** — header('../logout.php'); exit |
| 14 | staff/login.php — file v1 orphaned: raw session_start, test creds lộ HTML, thừa </div> | File không được link nhưng accessible | ✅ **DONE v28** — Thay toàn bộ bằng redirect ../login.php |
| 15 | 5 admin pages sidebar "Báo Cáo" trỏ dashboard.php?tab=report thay vì reports.php | v25 thêm reports.php nhưng chỉ fix một số trang | ✅ **DONE v28** — Fix housekeeping, calendar, promotions, rooms, services |
| 16 | admin/services.php sidebar thiếu link calendar.php | Bỏ sót khi tạo sidebar | ✅ **DONE v28** — Thêm 📅 Lịch Đặt Phòng vào sidebar |

---

## 🔲 TODO (ưu tiên cao → thấp)

```
─────────────────────────── ĐÃ HOÀN THÀNH ───────────────────────────
[x] register.php                — Form đăng ký KH (DONE v11)
[x] customer/dashboard.php      — Profile + đặt phòng + lịch sử + gửi email xác nhận (DONE v11, v31)
[x] staff/dashboard.php         — Quản lý đặt phòng + sơ đồ phòng + thông tin NV (DONE v12)
[x] admin/dashboard.php         — Tổng quan, quản lý đặt phòng, nhân viên (DONE v8)
[x] admin/rooms.php             — CRUD phòng, đổi trạng thái, sơ đồ theo tầng (DONE v13)
[x] rooms.php                   — Trang tìm kiếm & kết quả phòng trống (DONE v14)
[x] staff/checkin.php           — Check-in: DV, KM, thu cọc đa phương thức (DONE v15)
[x] staff/checkout.php          — Check-out + in hóa đơn PDF + tích điểm (DONE v15, v18)
[x] logout.php                  — Dedicated logout: session_destroy() + redirect (DONE v17)
[x] admin/accounts.php          — CRUD TAI_KHOAN, khóa/mở KH, edit KH/NV (DONE v17, v27)
[x] admin/services.php          — CRUD dịch vụ, toggle trạng thái, stat cards (DONE v16)
[x] admin/promotions.php        — CRUD khuyến mãi, EffStatus, stats, filter tabs (DONE v20)
[x] customer/booking-detail.php — Chi tiết đặt phòng: DV, hóa đơn, cọc, điểm, PDF (DONE v18)
[x] staff/profile.php           — NV đổi MK, cập nhật SĐT & email (DONE v18)
[x] admin/housekeeping.php      — Room cards, đổi TinhTrang, staff panel (DONE v19)
[x] admin/calendar.php          — Month grid, count đặt phòng, day detail, keyboard nav (DONE v19)
[x] 404.php                     — Branded 404, auto-redirect 15s, smart back link (DONE v19)
[x] admin/invoices.php          — Stats 5 cards, filter+search, modal, mark paid/unpaid (DONE v24)
[x] admin/reports.php           — Stats, Chart.js, top 5 phòng/DV/NV, xuất CSV (DONE v25)
[x] customer/profile.php        — HoTen/SĐT/Email/CCCD/DiaChi/GioiTinh/NgaySinh + đổi MK (DONE v26)
[x] index.php navbar            — User chip + dropdown (tên, avatar, link tài khoản, đăng xuất) (DONE v22)
[x] CSRF protection             — csrfToken/csrfField/csrfVerify trong config/db.php; mọi form POST (DONE)
[x] Brute-force protection      — IP lockout JSON, max 5 → 15 phút, dots indicator, countdown JS (DONE v29)
[x] 404.php session name        — @session_start() → config/session.php (DONE v29)
[x] 404 route mapping           — .htaccess: ErrorDocument, Options -Indexes, FilesMatch .md/.sql (DONE v30)
[x] Fix BUG #11 booking.php     — booking.php mới + login.php ?next= redirect (DONE v21)
[x] Fix BUG #12 session index   — config/session.php + user chip navbar (DONE v22)
[x] Fix BUG #13 logout inline   — header('../logout.php')+exit (DONE v23)
[x] Fix BUG #14 staff/login.php — Redirect về ../login.php (DONE v28)
[x] Fix BUG #15 sidebar stale   — Sửa 5 admin pages (DONE v28)
[x] Fix BUG #16 calendar link   — Thêm vào services.php sidebar (DONE v28)
[x] Email xác nhận đặt phòng   — PHPMailer Gmail SMTP; sendBookingConfirmation() sau INSERT (DONE v31)
[x] forgot-password.php         — Token 64 ký tự, hết hạn 1h, 1 lần dùng; sendPasswordReset() (DONE v31)
[x] reset-password.php          — Verify token + form đổi MK + strength bar + PRG (DONE v31)

──────────────────────── CẦN LÀM — ƯU TIÊN THẤP ───────────────────────
[ ] admin/rooms.php             — Upload ảnh phòng: thay text input HinhAnh bằng file upload
                                  + lưu vào assets/images/rooms/ + hiển thị preview

[ ] rooms.php + index.php       — Hiển thị ảnh thật phòng (HinhAnh từ DB) trong room cards
                                  Hiện tại chỉ dùng SVG giường illustration, không có ảnh thật

[ ] Export Excel (.xlsx)        — reports.php đã có xuất CSV (DONE v25); còn thiếu Excel .xlsx
                                  (cần PhpSpreadsheet) + xuất danh sách đặt phòng từ admin/staff

[ ] Email nhắc check-in         — sendCheckinReminder() đã có trong mailer.php
                                  Cần cron job hoặc script chạy tay mỗi ngày để gọi hàm này
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

### PHP — Gửi email (sau khi require config/mailer.php)
```php
require_once __DIR__ . '/../config/mailer.php';
sendBookingConfirmation($email, $name, [
    'maDP'      => $maDP,
    'loaiPhong' => "Phòng {$loai} ({$room['MaPhong']})",
    'checkin'   => date('d/m/Y H:i', strtotime($checkin)),
    'checkout'  => date('d/m/Y H:i', strtotime($checkout)),
    'tongGia'   => $tongGia,
]);
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
6. PHPMailer lỗi → xem `error_log` Apache hoặc thêm `$mail->SMTPDebug = SMTP::DEBUG_SERVER`

### Thêm trang mới
1. Tạo file PHP với `require_once config/session.php` + `config/db.php`
2. Copy CSS variables từ index.php vào `<style>`
3. Thêm link vào navbar/footer của index.php
4. Cập nhật CLAUDE.md (TODO → DONE)

### Sửa file có sẵn
- Dùng Edit tool cho thay đổi nhỏ/vừa (< 30% file)
- Dùng Write tool khi thay đổi > 30% file
- Luôn verify sau khi sửa: `grep -n "keyword" file.php`
- Luôn `php -l file.php` kiểm tra syntax

---

## 📁 FILES KHÔNG SỬA (stable)

| File | Lý do |
|------|-------|
| hotel_db.sql | Schema ổn định — nếu thêm cột thì ALTER TABLE trực tiếp |
| config/db.php | Connection + CSRF helpers OK |
| config/session.php | Session config ổn định |
| assets/images/*.jpg | Ảnh thật, không thay |
| SVG functions trong index.php | Tọa độ 3D tính toán chính xác |
| vendor/ | Composer-managed — không sửa tay |

---

*File này được tạo tự động bởi Claude — cập nhật mỗi khi có thay đổi lớn.*
