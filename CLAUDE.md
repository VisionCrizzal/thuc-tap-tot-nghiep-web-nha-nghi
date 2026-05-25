# CLAUDE.md — Easyhome Hotel Management System
> **Project memory cho Claude AI** — Đọc file này trước khi làm bất kỳ việc gì trong project.
> Cập nhật lần cuối: 26/05/2026 (v29: login.php — brute-force protection)

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
├── forgot-password.php            🔲 Quên mật khẩu (TODO)
├── 404.php                        ✅ Custom error page — branded, auto-redirect 15s (DONE v19)
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
│   ├── login.php                  ⚠️  File cũ (dùng session_start trực tiếp) — không link từ đâu, nên redirect về login.php
│   ├── dashboard.php              ✅ Quản lý đặt phòng + sơ đồ phòng + thông tin NV (DONE)
│   ├── checkin.php                ✅ Check-in form: DV, KM, thu cọc đa phương thức (DONE)
│   ├── checkout.php               ✅ Check-out + in hóa đơn PDF (DONE)
│   └── profile.php                ✅ NV đổi mật khẩu + cập nhật SĐT/email (DONE v18)
│
├── admin/
│   ├── dashboard.php              ✅ Tổng quan + quản lý đặt phòng + nhân viên (DONE)
│   ├── rooms.php                  ✅ CRUD phòng, sơ đồ theo tầng (DONE)
│   ├── accounts.php               ✅ CRUD TAI_KHOAN + khóa/mở KH, thêm cột TrangThai KH (DONE v17)
│   ├── services.php               ✅ CRUD dịch vụ — bảng DICH_VU (DONE)
│   ├── promotions.php             ✅ CRUD khuyến mãi — bảng KHUYEN_MAI (DONE v20)
│   ├── invoices.php               ✅ Danh sách hóa đơn toàn hệ thống — stats 5 cards, filter, search, modal chi tiết, mark paid/unpaid (DONE v24)
│   ├── reports.php                ✅ Báo cáo doanh thu: stats, Chart.js (line+bar), top 5 phòng/DV/NV, xuất CSV (DONE v25)
│   ├── housekeeping.php           ✅ Phân công & theo dõi dọn phòng (DONE v19)
│   └── calendar.php               ✅ Lịch đặt phòng dạng calendar tháng (DONE v19)
│
├── customer/
│   ├── dashboard.php              ✅ Trang KH: profile (read-only), đặt phòng, lịch sử + KM (DONE)
│   ├── profile.php                🔲 KH đổi mật khẩu + cập nhật SĐT/email/địa chỉ (TODO)
│   ├── profile.php                ✅ KH đổi MK + cập nhật HoTen/SĐT/Email/CCCD(optional)/DiaChi/GioiTinh/NgaySinh (DONE v26)
│   └── booking-detail.php         ✅ Chi tiết đặt phòng: DV, hóa đơn, cọc, điểm, in PDF (DONE v18)
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
| 16 | Quản lý dịch vụ admin | admin/services.php — CRUD DICH_VU, toggle trạng thái, emoji picker, preview ảnh, stat cards lượt dùng & doanh thu DV | v16 |
| 17 | Logout + Quản lý tài khoản | logout.php (session_destroy tập trung) + admin/accounts.php (CRUD TAI_KHOAN, khóa/mở KH, thêm cột TrangThai vào KHACH_HANG, update login.php) | v17 |
| 18 | Tích điểm + Chi tiết đặt phòng + Hồ sơ NV | staff/checkout.php cộng TichDiem (1.000đ=1đ) + customer/booking-detail.php (DV, hóa đơn, điểm, in PDF) + staff/profile.php (đổi MK, SĐT, email) + customer/dashboard.php (nút "Chi tiết", fix logout) | v18 |
| 19 | Dọn phòng + Lịch calendar + 404 | admin/housekeeping.php (room cards, đổi TinhTrang, staff panel) + admin/calendar.php (month grid, day detail, keyboard nav) + 404.php (branded, auto-redirect, smart back link) + cập nhật sidebar toàn bộ admin pages | v19 |
| 20 | Quản lý khuyến mãi | admin/promotions.php — CRUD KHUYEN_MAI, toggle Đang áp dụng/Tạm dừng, computed EffStatus (Hết hạn/Chưa bắt đầu), stats 5 cards, filter tabs, loại % vs VNĐ, days-left badge, form validate, cập nhật sidebar 6 admin pages | v20 |
| 21 | Fix dead link booking.php (BUG #11) | booking.php mới: landing page có room info + nút login/register; KH đã login → redirect thẳng vào ?tab=booking&room_type=X; login.php thêm ?next= redirect với sanitize chống open-redirect | v21 |
| 22 | Fix index.php session + navbar user chip (BUG #12) | index.php: thay session_start() → config/session.php; navbar: user chip với avatar + dropdown (tên KH/NV, link tài khoản, đăng xuất); footer Tài Khoản đồng bộ với session | v22 |
| 23 | Fix staff/dashboard.php logout inline (BUG #13) | Dòng 31: thay session_destroy()+header(login.php) → header(../logout.php)+exit; scan toàn project — không còn file nào dùng session_destroy() inline | v23 |
| 24 | admin/invoices.php — Quản lý hóa đơn | Stats 5 cards, filter tabs+search+tháng/năm/NV, table HOA_DON với JOINs, modal chi tiết (bảng tiền+DV), mark paid/unpaid PRG, in PDF, sidebar thêm 🧾 Hóa Đơn vào 8 admin pages | v24 |
| 25 | admin/reports.php — Báo cáo doanh thu | Stats 4 cards, bộ lọc năm/tháng/quý, Chart.js line chart DT + bar chart lượt đặt phòng, top 5 phòng/DV/NV, bảng chi tiết 12 tháng có progress bar, xuất CSV (BOM UTF-8), in báo cáo, sidebar thêm 📈 Báo Cáo vào 8 admin pages | v25 |
| 26 | customer/profile.php — KH chỉnh sửa hồ sơ | Form cập nhật HoTen/SĐT/Email/DiaChi/GioiTinh/NgaySinh; CCCD optional với checkbox toggle + privacy note; form đổi MK với strength bar + PRG redirect; thêm nút ✏️ Chỉnh sửa trong dashboard profile tab; CCCD hiển thị "🔒 Đã cung cấp" thay vì lộ số | v26 |
| 27 | admin/accounts.php — Edit thông tin KH + NV | Tab edit_kh: sửa HoTen/SĐT/Email/CCCD/DiaChi bảng KHACH_HANG; tab edit_nv: sửa HoTen/SĐT/Email/ChucVu/NgayVaoLam/TrangThai bảng NHAN_VIEN; nút ✏️ Sửa trong bảng KH, nút 👤 Sửa NV trong bảng staff; fix duplicate sidebar link báo cáo | v27 |
| 28 | Kiểm tra toàn dự án — audit bugs còn lại | Phát hiện BUG #14 (staff/login.php orphaned), BUG #15 (sidebar stale link ×5 trang), BUG #16 (services.php thiếu calendar); cập nhật TODO đầy đủ | audit |
| 29 | login.php — Brute-force protection | IP-based lockout (temp JSON file): max 5 lần sai → khóa 15 phút; dots indicator; countdown timer JS; auto-reset sau 1h; reset on success | v29 |

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
| 11 | **Dead link booking.php** — Click "Đặt Ngay" trên card phòng ở index.php (dòng 826) → 404 | index.php trỏ đến `booking.php?room=...` nhưng file không tồn tại | ✅ **ĐÃ FIX v21** — Tạo booking.php: redirect thẳng nếu KH đã login, landing page + login/register nếu chưa |
| 12 | **index.php dùng session_start() thay vì config/session.php** | Nếu KH đăng nhập từ index.php, session name khác → không nhận diện được KH đã đăng nhập khi sang trang khác | ✅ **ĐÃ FIX v22** — Đổi `session_start()` → `require_once __DIR__ . '/config/session.php'`; thêm user chip + dropdown trong navbar; đồng bộ footer "Tài Khoản" |
| 13 | **staff/dashboard.php còn inline session_destroy() ở `?logout`** (dòng 31) | Khi user truy cập `?logout`, trang tự `session_destroy()` thay vì redirect về logout.php — bỏ qua centralized logout logic | ✅ **ĐÃ FIX v23** — `session_destroy(); header('Location:../login.php')` → `header('Location: ../logout.php'); exit` |
| 14 | **staff/login.php — file v1 orphaned với 3 vấn đề** | (a) dùng raw `session_start()` thay vì `config/session.php` → session name sai; (b) hardcoded test creds lộ trong HTML (`admin/password`, `letan01/letan02`); (c) thừa 1 thẻ `</div>` (21 mở, 22 đóng) | ⏳ **CHƯA FIX** — File không được link từ đâu nhưng vẫn accessible tại `/staff/login.php`. Nên thay toàn bộ nội dung bằng `header('Location: ../login.php'); exit;` |
| 15 | **5 admin pages còn link sidebar Báo Cáo trỏ về `dashboard.php?tab=report`** thay vì `reports.php` | v25 thêm reports.php vào sidebar nhưng chỉ fix một số trang, 5 trang còn lại (housekeeping, calendar, promotions, rooms, services) vẫn còn link cũ trỏ về tab không tồn tại | ⏳ **CHƯA FIX** — housekeeping.php:299, calendar.php:318, promotions.php:460, rooms.php:421, services.php:431 |
| 16 | **admin/services.php sidebar thiếu link `calendar.php`** | Tất cả 8 admin pages khác đều có `📅 Lịch → calendar.php` trong sidebar, riêng services.php không có | ⏳ **CHƯA FIX** — sidebar services.php thiếu entry `<a href="calendar.php">📅 Lịch</a>` |

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
[x] logout.php             — Dedicated logout: session_destroy() + redirect (DONE v17)
[x] admin/accounts.php     — CRUD TAI_KHOAN (reset MK NV, khóa/mở KH, tạo TK NV) (DONE v17)
[x] admin/services.php     — CRUD dịch vụ, toggle trạng thái, stat cards (DONE v16)
[x] admin/promotions.php   — CRUD khuyến mãi, EffStatus, stats, filter tabs (DONE v20)
[x] Tích điểm KH (TichDiem) — Cộng điểm checkout + hiển thị trong hóa đơn (DONE v18)
[x] customer/booking-detail.php — Chi tiết đặt phòng: DV, hóa đơn, cọc, điểm, PDF (DONE v18)
[x] staff/profile.php      — NV đổi MK, cập nhật SĐT & email (DONE v18)
[x] admin/housekeeping.php — Room cards, đổi TinhTrang, staff panel (DONE v19)
[x] admin/calendar.php     — Month grid, count đặt phòng, day detail, keyboard nav (DONE v19)
[x] 404.php                — Branded 404, auto-redirect 15s, smart back link (DONE v19)

─────────────── CẦN LÀM — ƯU TIÊN CAO (BUG / BROKEN) ───────────────
[x] Fix dead link booking.php trong index.php (BUG #11) — DONE v21
    — booking.php mới: KH đã login → redirect customer/dashboard.php?tab=booking&room_type=X
    — Chưa login → landing page: hiện thông tin phòng + nút Đăng Nhập / Đăng Ký
    — login.php thêm ?next= redirect (sanitized), hidden input truyền qua form
    — Sau login KH thành công → về $next (tab booking) thay vì customer/dashboard.php mặc định

[x] Fix index.php dùng session_start() thay vì config/session.php (BUG #12) — DONE v22
    — index.php dòng 2: session_start() → require_once __DIR__ . '/config/session.php'
    — Navbar: thêm user chip + dropdown menu khi KH/NV đã đăng nhập (tên, avatar chữ cái, link tài khoản, đăng xuất)
    — Footer "Tài Khoản": hiển thị link phù hợp theo session (KH, NV/Admin, hoặc chưa đăng nhập)

[x] Fix staff/dashboard.php còn ?logout inline (BUG #13) — DONE v23
    — Dòng 31: session_destroy()+header('../login.php') → header('../logout.php')+exit
    — Scan toàn project: không còn file nào dùng session_destroy() inline

[x] Fix staff/login.php — file v1 orphaned (BUG #14) — DONE
    — Thay toàn bộ 381 dòng bằng 5 dòng: header('Location: ../login.php'); exit;
    — Loại bỏ: raw session_start(), test creds lộ HTML, extra </div>
    — Truy cập /staff/login.php → redirect về login.php trung tâm

[x] Fix sidebar "Báo Cáo" stale link trong 5 admin pages (BUG #15) — DONE
    — Xóa dòng thừa dashboard.php?tab=report trong:
      housekeeping.php, calendar.php, promotions.php, rooms.php, services.php
    — Mỗi file đã có sẵn link đúng reports.php ở vị trí trước đó trong sidebar

[x] Fix admin/services.php sidebar thiếu calendar.php (BUG #16) — DONE
    — Thêm <a href="calendar.php">📅 Lịch Đặt Phòng</a> vào dòng 420
    — Vị trí: giữa "Quản Lý Đặt Phòng" và "Đặt Phòng Mới" (đồng nhất với tất cả admin pages khác)

──────────────────────── CẦN LÀM — ƯU TIÊN TRUNG ──────────────────────
[x] admin/invoices.php     — DONE v24: stats 5 cards (tổng/đã TT/chưa TT/đã thu/chưa thu),
                             filter tabs + search + tháng/năm/NV, table đầy đủ, modal chi tiết
                             (thông tin KH + phòng + bảng tiền + dịch vụ), mark paid/unpaid,
                             in hóa đơn (window.print), sidebar cập nhật 8 admin pages

[x] admin/reports.php      — DONE v25: stats 4 cards (tổng DT/HĐ/lượt đặt/DT DV),
                             filter năm + toggle tháng/quý, Chart.js line chart DT + bar chart bookings,
                             top 5 phòng/DV/NV với rank badge vàng/bạc/đồng,
                             bảng 12 tháng có progress bar inline, xuất CSV (UTF-8 BOM), in báo cáo,
                             sidebar 📈 Báo Cáo thêm vào 8 admin pages còn lại

[x] customer/profile.php   — DONE v26: form chỉnh sửa HoTen/SĐT/Email/DiaChi/GioiTinh/NgaySinh;
                             CCCD optional (checkbox toggle, privacy note, hiển thị "🔒 Đã cung cấp" trong dashboard);
                             form đổi MK với strength bar; PRG; nút ✏️ trong dashboard profile tab

[x] admin/accounts.php     — DONE v27: tab edit_kh (KH: HoTen/SĐT/Email/CCCD/DiaChi),
                             tab edit_nv (NV: HoTen/SĐT/Email/ChucVu/NgayVaoLam/TrangThai),
                             nút ✏️ Sửa trong bảng KH, nút 👤 Sửa NV trong bảng staff accounts

[x] Thêm invoices.php + reports.php vào sidebar tất cả admin pages — DONE v24+v25

──────────────────────── CẦN LÀM — ƯU TIÊN THẤP ───────────────────────
[ ] forgot-password.php    — Flow quên mật khẩu: nhập email → tạo token → gửi link
                             reset qua email (cần PHPMailer hoặc PHP mail())

[ ] admin/rooms.php        — Upload ảnh phòng: thay text input HinhAnh bằng file upload
                             + lưu vào assets/images/rooms/ + hiển thị preview

[ ] rooms.php + index.php  — Hiển thị ảnh thật phòng (HinhAnh từ DB) trong room cards
                             Hiện tại chỉ dùng SVG giường illustration, không có ảnh thật

[x] index.php navbar       — DONE v22: user chip + dropdown (tên KH/NV, avatar, link tài khoản, đăng xuất)

[ ] Email xác nhận         — PHPMailer: gửi email xác nhận ngay khi KH đặt phòng
                             thành công + email nhắc nhở check-in 1 ngày trước

[ ] Export Excel           — reports.php đã có xuất CSV (DONE v25); còn thiếu Excel .xlsx
                             (cần PhpSpreadsheet) + xuất danh sách đặt phòng từ admin/staff

[x] Tìm kiếm + phân trang  — DONE: admin/dashboard.php + staff/dashboard.php
                             — Search bar: tìm theo tên KH, SĐT, mã ĐP, mã phòng (WHERE LIKE)
                             — Pagination: 20 bản ghi/trang, hiển thị X–Y/tổng, nút ‹ 1 2 3 ›
                             — Filter tabs giữ nguyên search khi click, URL: ?tab=bookings&filter=X&search=Y&page=Z
                             — admin: filter tabs hiện số đếm từ $stats (chính xác, không bị cắt bởi LIMIT)
                             — admin: overview tab dùng $pendingBookings riêng (không bị ảnh hưởng pagination)

[ ] CSRF protection        — Tất cả POST forms chưa có CSRF token
                             Nguy cơ: Cross-Site Request Forgery trên các action quan trọng
                             (hủy phòng, đổi MK, khóa TK, reset MK NV)
                             Fix: thêm $_SESSION['csrf_token'] + hidden input + verify khi POST

[x] Brute-force protection — DONE v29: IP-based tracking (md5 hash, temp JSON /tmp/easyhome_bf.json)
                             Max 5 lần sai → khóa 15 phút; dots indicator (đỏ/vàng) từ lần 1+;
                             cảnh báo "còn N lần" từ lần 3+; locked state: ẩn form, nút countdown,
                             JS đếm ngược MM:SS, auto-reload khi hết; reset on successful login;
                             auto-prune entries hết hạn; không tính lần thử khi account bị khóa bởi admin

[ ] 404.php session name   — 404.php dùng @session_start() thay vì config/session.php
                             Hậu quả: session name khác EASYHOME_SID → không đọc được
                             $_SESSION['staff_role'] để tạo back link đúng cho admin
                             Fix: đổi @session_start() → require_once '/path/to/config/session.php'

[ ] 404 route mapping      — Apache/XAMPP chưa được cấu hình để trả 404.php khi URL sai
                             Hiện tại lỗi 404 trả trang mặc định của Apache, không phải 404.php
                             Fix: thêm ErrorDocument 404 /khachsan/404.php vào .htaccess
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
