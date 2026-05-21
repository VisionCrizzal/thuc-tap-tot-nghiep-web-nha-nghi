-- ============================================================
--  CSDL QUẢN LÝ KHÁCH SẠN
--  Nhóm 22 - Trường ĐHKH Huế
--  Chạy file này trong phpMyAdmin hoặc MySQL Workbench
-- ============================================================

CREATE DATABASE IF NOT EXISTS khachsan_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE khachsan_db;

-- ============================================================
-- 1. BẢNG PHÒNG (PHONG)
-- ============================================================
CREATE TABLE IF NOT EXISTS PHONG (
    MaPhong       VARCHAR(10)    NOT NULL,
    LoaiPhong     NVARCHAR(50)   NOT NULL COMMENT 'Đơn / Đôi / VIP / Gia đình',
    GiaPhong      FLOAT          NOT NULL COMMENT 'Giá theo đêm (VNĐ)',
    SoNguoiToiDa  INT            NOT NULL DEFAULT 2,
    TinhTrang     NVARCHAR(50)   NOT NULL DEFAULT 'Trống'
                  COMMENT 'Trống / Đang ở / Đang dọn / Bảo trì',
    MoTa          TEXT           NULL,
    HinhAnh       VARCHAR(255)   NULL,
    Tang          INT            NOT NULL DEFAULT 1,
    PRIMARY KEY (MaPhong)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. BẢNG KHÁCH HÀNG (KHACH_HANG)
-- ============================================================
CREATE TABLE IF NOT EXISTS KHACH_HANG (
    MaKH          VARCHAR(10)    NOT NULL,
    HoTen         NVARCHAR(100)  NOT NULL,
    CCCD          VARCHAR(12)    NULL,
    SoDienThoai   VARCHAR(11)    NOT NULL,
    Email         VARCHAR(100)   NULL,
    TenTaiKhoan   VARCHAR(50)    NULL UNIQUE,
    MatKhau       VARCHAR(255)   NOT NULL COMMENT 'Mã hoá bằng password_hash()',
    GioiTinh      NVARCHAR(10)   NULL,
    NgaySinh      DATE           NULL,
    DiaChi        NVARCHAR(200)  NULL,
    QuocTich      NVARCHAR(50)   NULL DEFAULT 'Việt Nam',
    TichDiem      FLOAT          NOT NULL DEFAULT 0,
    NgayTao       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MaKH)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. BẢNG NHÂN VIÊN (NHAN_VIEN)
-- ============================================================
CREATE TABLE IF NOT EXISTS NHAN_VIEN (
    MaNV          VARCHAR(10)    NOT NULL,
    HoTen         NVARCHAR(100)  NOT NULL,
    SoDienThoai   VARCHAR(11)    NOT NULL,
    CCCD          VARCHAR(12)    NULL,
    NgaySinh      DATE           NULL,
    DiaChi        NVARCHAR(200)  NULL,
    Email         VARCHAR(100)   NULL,
    ChucVu        NVARCHAR(50)   NOT NULL COMMENT 'Lễ tân / Quản lý',
    NgayVaoLam    DATE           NULL,
    TrangThai     NVARCHAR(20)   NOT NULL DEFAULT 'Đang làm việc',
    PRIMARY KEY (MaNV)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. BẢNG TÀI KHOẢN NHÂN VIÊN (TAI_KHOAN)
-- ============================================================
CREATE TABLE IF NOT EXISTS TAI_KHOAN (
    TenTK          VARCHAR(50)   NOT NULL,
    MatKhau        VARCHAR(255)  NOT NULL COMMENT 'Mã hoá bằng password_hash()',
    VaiTro         NVARCHAR(20)  NOT NULL COMMENT 'admin / nhanvien',
    MaNV           VARCHAR(10)   NULL,
    TrangThai      NVARCHAR(20)  NOT NULL DEFAULT 'Hoạt động',
    NgayTaoTK      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    LanDangNhapCuoi DATETIME     NULL,
    PRIMARY KEY (TenTK),
    FOREIGN KEY (MaNV) REFERENCES NHAN_VIEN(MaNV)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. BẢNG ĐẶT PHÒNG (DAT_PHONG)
-- ============================================================
CREATE TABLE IF NOT EXISTS DAT_PHONG (
    MaDP          VARCHAR(10)    NOT NULL,
    MaKH          VARCHAR(10)    NOT NULL,
    MaPhong       VARCHAR(10)    NOT NULL,
    NgayCheckIn   DATETIME       NOT NULL,
    NgayCheckOut  DATETIME       NOT NULL,
    SoLuongKhach  INT            NOT NULL DEFAULT 1,
    TienCoc       FLOAT          NOT NULL DEFAULT 0,
    TongGia       FLOAT          NULL,
    TrangThai     NVARCHAR(50)   NOT NULL DEFAULT 'Chờ xác nhận'
                  COMMENT 'Chờ xác nhận / Đã nhận phòng / Đã trả phòng / Đã hủy',
    GhiChu        TEXT           NULL,
    NgayDat       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MaNV_XuLy    VARCHAR(10)    NULL COMMENT 'Nhân viên xử lý check-in/out',
    PRIMARY KEY (MaDP),
    FOREIGN KEY (MaKH)      REFERENCES KHACH_HANG(MaKH)  ON UPDATE CASCADE,
    FOREIGN KEY (MaPhong)   REFERENCES PHONG(MaPhong)     ON UPDATE CASCADE,
    FOREIGN KEY (MaNV_XuLy) REFERENCES NHAN_VIEN(MaNV)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. BẢNG DỊCH VỤ (DICH_VU)
-- ============================================================
CREATE TABLE IF NOT EXISTS DICH_VU (
    MaDV          VARCHAR(10)    NOT NULL,
    TenDV         NVARCHAR(100)  NOT NULL,
    GiaDV         FLOAT          NOT NULL,
    MoTa          NVARCHAR(255)  NULL,
    TrangThai     NVARCHAR(20)   NOT NULL DEFAULT 'Khả dụng',
    HinhAnh       VARCHAR(255)   NULL,
    PRIMARY KEY (MaDV)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. BẢNG ĐẶT DỊCH VỤ (DAT_DICH_VU)
-- ============================================================
CREATE TABLE IF NOT EXISTS DAT_DICH_VU (
    MaDDV         INT            NOT NULL AUTO_INCREMENT,
    MaDP          VARCHAR(10)    NOT NULL,
    MaDV          VARCHAR(10)    NOT NULL,
    SoLuong       INT            NOT NULL DEFAULT 1,
    ThanhTien     FLOAT          NOT NULL,
    NgayDat       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MaDDV),
    FOREIGN KEY (MaDP) REFERENCES DAT_PHONG(MaDP) ON UPDATE CASCADE,
    FOREIGN KEY (MaDV) REFERENCES DICH_VU(MaDV)   ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. BẢNG HÓA ĐƠN (HOA_DON)
-- ============================================================
CREATE TABLE IF NOT EXISTS HOA_DON (
    MaHD          VARCHAR(10)    NOT NULL,
    MaDP          VARCHAR(10)    NOT NULL,
    MaNV          VARCHAR(10)    NOT NULL,
    TienPhong     FLOAT          NOT NULL DEFAULT 0,
    TienDichVu    FLOAT          NOT NULL DEFAULT 0,
    PhuPhi        FLOAT          NOT NULL DEFAULT 0
                  COMMENT 'Trả muộn, thêm người, hỏng đồ...',
    TienCocDaThu  FLOAT          NOT NULL DEFAULT 0,
    TongTien      FLOAT          NOT NULL,
    PhuongThucTT  NVARCHAR(50)   NULL COMMENT 'Tiền mặt / Chuyển khoản / Thẻ',
    NgayLap       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    TrangThai     NVARCHAR(50)   NOT NULL DEFAULT 'Chưa thanh toán',
    GhiChu        TEXT           NULL,
    PRIMARY KEY (MaHD),
    FOREIGN KEY (MaDP) REFERENCES DAT_PHONG(MaDP) ON UPDATE CASCADE,
    FOREIGN KEY (MaNV) REFERENCES NHAN_VIEN(MaNV)  ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. BẢNG KHUYẾN MÃI (KHUYEN_MAI)
-- ============================================================
CREATE TABLE IF NOT EXISTS KHUYEN_MAI (
    MaKM          VARCHAR(10)    NOT NULL,
    TenKM         NVARCHAR(100)  NOT NULL,
    LoaiKM        NVARCHAR(50)   NOT NULL COMMENT 'PhanTram / SoTien',
    GiaTriKM      FLOAT          NOT NULL,
    DieuKien      TEXT           NULL,
    NgayBatDau    DATE           NOT NULL,
    NgayKetThuc   DATE           NOT NULL,
    TrangThai     NVARCHAR(20)   NOT NULL DEFAULT 'Đang áp dụng',
    PRIMARY KEY (MaKM)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DỮ LIỆU MẪU (SAMPLE DATA)
-- ============================================================

-- Phòng mẫu
INSERT INTO PHONG (MaPhong, LoaiPhong, GiaPhong, SoNguoiToiDa, TinhTrang, MoTa, Tang) VALUES
('P101', 'Đơn', 500000,  1, 'Trống',     'Phòng đơn tiêu chuẩn, view sân vườn', 1),
('P102', 'Đơn', 500000,  1, 'Đang ở',    'Phòng đơn tiêu chuẩn, view hồ bơi',  1),
('P103', 'Đơn', 550000,  1, 'Đang dọn',  'Phòng đơn Superior, ban công',        1),
('P201', 'Đôi', 800000,  2, 'Trống',     'Phòng đôi Deluxe, giường đôi lớn',    2),
('P202', 'Đôi', 850000,  2, 'Trống',     'Phòng đôi, 2 giường đơn, view thành phố', 2),
('P203', 'Đôi', 900000,  2, 'Đang ở',    'Phòng đôi Superior, bồn tắm',        2),
('P301', 'Gia đình', 1500000, 4, 'Trống', 'Suite gia đình, phòng khách riêng',  3),
('P302', 'VIP',  2500000, 2, 'Trống',    'Phòng VIP Penthouse, view toàn thành', 3),
('P303', 'VIP',  2800000, 2, 'Bảo trì',  'Suite Executive, phòng họp riêng',    3);

-- Nhân viên mẫu
INSERT INTO NHAN_VIEN (MaNV, HoTen, SoDienThoai, Email, ChucVu, NgayVaoLam) VALUES
('NV001', 'Nguyễn Văn An',  '0901234567', 'an.nv@khachsan.com',    'Quản lý',  '2020-01-15'),
('NV002', 'Trần Thị Bình',  '0912345678', 'binh.tt@khachsan.com',  'Lễ tân',   '2021-06-01'),
('NV003', 'Lê Minh Cường',  '0923456789', 'cuong.lm@khachsan.com', 'Lễ tân',   '2022-03-10');

-- Tài khoản đăng nhập (mật khẩu: Admin@123 và Staff@123)
-- password_hash('Admin@123', PASSWORD_DEFAULT) - dùng PHP để hash, tạm thời dùng plain text hash
INSERT INTO TAI_KHOAN (TenTK, MatKhau, VaiTro, MaNV) VALUES
('admin',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',     'NV001'),
('letan01',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nhanvien',  'NV002'),
('letan02',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nhanvien',  'NV003');
-- Lưu ý: Hash trên tương ứng với mật khẩu "password" (Laravel default hash)
-- Khi chạy thực tế hãy tạo hash mới bằng: echo password_hash('Admin@123', PASSWORD_DEFAULT);

-- Dịch vụ mẫu
INSERT INTO DICH_VU (MaDV, TenDV, GiaDV, MoTa) VALUES
('DV001', 'Spa & Massage',     350000, 'Dịch vụ spa thư giãn toàn thân 60 phút'),
('DV002', 'Giặt ủi',           80000,  'Giặt ủi theo kg, trả trong ngày'),
('DV003', 'Xe đưa đón sân bay',250000, 'Xe 4-7 chỗ, đón/đưa sân bay Phú Bài'),
('DV004', 'Ăn sáng tại phòng', 120000, 'Set ăn sáng Á-Âu phục vụ tại phòng'),
('DV005', 'Thuê xe đạp',       50000,  'Xe đạp khám phá thành phố Huế/ngày');

-- Khuyến mãi mẫu
INSERT INTO KHUYEN_MAI (MaKM, TenKM, LoaiKM, GiaTriKM, DieuKien, NgayBatDau, NgayKetThuc) VALUES
('KM001', 'Đặt sớm 10%',      'PhanTram', 10, 'Đặt trước 1 tuần đến 2 tháng',        '2026-01-01', '2026-12-31'),
('KM002', 'Đặt sớm 15%',      'PhanTram', 15, 'Đặt trước trên 2 tháng',              '2026-01-01', '2026-12-31'),
('KM003', 'Lưu trú dài 10%',  'PhanTram', 10, 'Lưu trú trên 3 ngày',                 '2026-01-01', '2026-12-31'),
('KM004', 'Lưu trú dài 15%',  'PhanTram', 15, 'Lưu trú trên 5 ngày',                 '2026-01-01', '2026-12-31'),
('KM005', 'Nhóm 3-4 phòng',   'PhanTram', 10, 'Đặt từ 3 đến 4 phòng cùng lúc',       '2026-01-01', '2026-12-31'),
('KM006', 'Nhóm 5+ phòng',    'PhanTram', 15, 'Đặt từ 5 phòng trở lên cùng lúc',     '2026-01-01', '2026-12-31');
