# 🏨 KHÁCH SẠN HOÀNG CUNG — HƯỚNG DẪN CÀI ĐẶT
Nhóm 22 · Trường ĐHKH Huế

---

## CẤU TRÚC THỰ MỤC

```
khachsan/
├── config/
│   └── db.php          ← Kết nối MySQL (chỉnh sửa thông tin ở đây)
├── staff/
│   └── login.php       ← Đăng nhập nhân viên / lễ tân
├── admin/
│   └── (các trang quản lý)
├── index.php           ← Trang chủ (test kết nối XAMPP)
├── hotel_db.sql        ← Script tạo CSDL + dữ liệu mẫu
└── HUONG_DAN.md        ← File này
```

---

## BƯỚC 1: CÀI XAMPP

1. Tải XAMPP tại https://www.apachefriends.org (chọn Windows)
2. Cài đặt, mở **XAMPP Control Panel**
3. Bật **Apache** và **MySQL** → đảm bảo cả hai có nút **Stop** (đang chạy, màu xanh)

---

## BƯỚC 2: ĐƯA CODE VÀO htdocs

1. Mở thư mục `C:\xampp\htdocs\` (Windows) hoặc `/Applications/XAMPP/htdocs/` (macOS)
2. **Copy toàn bộ thư mục** `khachsan` vào đây
3. Kết quả: `C:\xampp\htdocs\khachsan\`

---

## BƯỚC 3: TẠO CƠ SỞ DỮ LIỆU

### Cách A — phpMyAdmin (khuyến nghị):
1. Mở trình duyệt → vào `http://localhost/phpmyadmin`
2. Click **"New"** (bên trái) → Đặt tên: `khachsan_db` → click **Create**
3. Chọn database `khachsan_db` → click tab **"Import"** (trên thanh menu)
4. Click **"Choose File"** → chọn file `hotel_db.sql`
5. Kéo xuống → click **"Import"** (nút màu xanh)
6. Thấy thông báo "Import has been successfully finished" → thành công ✓

### Cách B — Dùng MySQL command line:
```bash
mysql -u root -p
mysql> CREATE DATABASE khachsan_db CHARACTER SET utf8mb4;
mysql> USE khachsan_db;
mysql> SOURCE C:/xampp/htdocs/khachsan/hotel_db.sql;
```

---

## BƯỚC 4: CẤU HÌNH KẾT NỐI

Mở file `config/db.php` và kiểm tra:

```php
define('DB_HOST',  'localhost');   // Không cần đổi
define('DB_PORT',  '3306');        // Không cần đổi
define('DB_NAME',  'khachsan_db'); // Phải khớp với tên database đã tạo
define('DB_USER',  'root');        // Không cần đổi (XAMPP mặc định)
define('DB_PASS',  '');            // Mật khẩu trống (XAMPP mặc định)
```

> 💡 Nếu XAMPP MySQL của bạn có mật khẩu, điền vào `DB_PASS`.

---

## BƯỚC 5: MỞ TRÊN TRÌNH DUYỆT

```
Trang chủ:           http://localhost/khachsan/
Đăng nhập NV:        http://localhost/khachsan/staff/login.php
phpMyAdmin:          http://localhost/phpmyadmin/
```

---

## TÀI KHOẢN MẪU

| Vai trò    | Tên đăng nhập | Mật khẩu  |
|------------|--------------|-----------|
| Quản lý   | `admin`      | `password`|
| Lễ tân 1  | `letan01`    | `password`|
| Lễ tân 2  | `letan02`    | `password`|

> ⚠️ **Quan trọng:** Các tài khoản trên dùng hash mặc định Laravel (`password`).  
> Để tạo hash thật, chạy PHP:
> ```php
> echo password_hash('MatKhauMoi@123', PASSWORD_DEFAULT);
> ```
> Rồi UPDATE vào bảng TAI_KHOAN.

---

## XỬ LÝ LỖI THƯỜNG GẶP

| Lỗi | Nguyên nhân | Cách sửa |
|-----|-------------|----------|
| `Connection refused` | MySQL chưa bật | Bật MySQL trong XAMPP Control Panel |
| `Unknown database` | Chưa import SQL | Làm Bước 3 |
| `Access denied for user 'root'` | Sai mật khẩu | Kiểm tra DB_PASS trong config/db.php |
| Trang trắng / 500 error | Lỗi PHP | Bật error reporting hoặc xem `C:\xampp\apache\logs\error.log` |
| Chữ bị lỗi tiếng Việt | Charset sai | CSDL đã dùng utf8mb4, kiểm tra lại import |

---

## SƠ ĐỒ CSDL (TÓM TẮT)

```
PHONG ─────────────────── DAT_PHONG ─────── KHACH_HANG
  │                           │
  │                       HOA_DON ──────── NHAN_VIEN
  │                           │
DICH_VU ──── DAT_DICH_VU ────┘
  
NHAN_VIEN ──── TAI_KHOAN (login)

KHUYEN_MAI (độc lập)
```

---

## CÁC TRANG SẼ PHÁT TRIỂN TIẾP

- `index.php` — ✅ Trang chủ (hiện tại)
- `staff/login.php` — ✅ Đăng nhập
- `staff/dashboard.php` — Sơ đồ phòng lễ tân
- `staff/checkin.php` — Check-in
- `staff/checkout.php` — Check-out
- `admin/dashboard.php` — Thống kê doanh thu
- `admin/rooms.php` — Quản lý phòng CRUD
- `admin/accounts.php` — Quản lý tài khoản
- `login.php` — Đăng nhập khách hàng
- `booking.php` — Đặt phòng online

index.php → Đặt Ngay → booking.php?room=P201
  ├── [Chưa login] → Landing page → "Đăng Nhập" → login.php?next=customer/dashboard.php?tab=booking&room_type=Đôi
  │                                                 → Đăng nhập thành công → customer/dashboard.php?tab=booking&room_type=Đôi ✅
  └── [Đã login]  → Redirect ngay → customer/dashboard.php?tab=booking&room_type=Đôi ✅
