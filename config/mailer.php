<?php
// config/mailer.php — PHPMailer wrapper cho Easyhome

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Dùng Composer autoload:
require_once __DIR__ . '/../vendor/autoload.php';

// Hoặc nếu tải thủ công:
// require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
// require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
// require_once __DIR__ . '/../lib/PHPMailer/Exception.php';

// ── Thông tin SMTP ────────────────────────────────────────────────────────
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'nguyengocvi94tn@gmail.com');
define('MAIL_PASSWORD', '***REMOVED***');    // App Password — Easyhome
define('MAIL_FROM',     'nguyengocvi94tn@gmail.com');
define('MAIL_FROM_NAME','Easyhome Hotel');

/**
 * Tạo PHPMailer instance đã cấu hình sẵn SMTP
 */
function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);  // true = throw exceptions
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';
    // Fix SSL certificate verify trên XAMPP local (OpenSSL không có CA bundle đầy đủ)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ];
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    return $mail;
}

/**
 * Gửi email xác nhận đặt phòng
 */
function sendBookingConfirmation(
    string $toEmail,
    string $toName,
    array  $booking   // [maDP, loaiPhong, checkin, checkout, tongGia]
): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = '✅ Xác nhận đặt phòng #' . $booking['maDP'] . ' — Easyhome';
        $mail->Body    = emailBookingHTML($toName, $booking);
        $mail->AltBody = "Xác nhận đặt phòng #{$booking['maDP']} tại Easyhome.\n"
                       . "Phòng: {$booking['loaiPhong']}\n"
                       . "Check-in: {$booking['checkin']}\n"
                       . "Check-out: {$booking['checkout']}\n"
                       . "Tổng giá: " . number_format($booking['tongGia']) . " VNĐ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Gửi email nhắc nhở check-in (chạy bằng cron job 1 ngày trước)
 */
function sendCheckinReminder(
    string $toEmail,
    string $toName,
    array  $booking
): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = '🔔 Nhắc nhở: Check-in ngày mai — Easyhome';
        $mail->Body    = emailReminderHTML($toName, $booking);
        $mail->AltBody = "Nhắc nhở: Bạn có lịch check-in vào ngày mai tại Easyhome.\n"
                       . "Phòng: {$booking['loaiPhong']} — Mã ĐP: #{$booking['maDP']}";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $e->getMessage());
        return false;
    }
}

// ── Email templates ───────────────────────────────────────────────────────

function emailBookingHTML(string $name, array $b): string {
    $tongGia = number_format($b['tongGia'], 0, ',', '.');
    return <<<HTML
    <!DOCTYPE html>
    <html lang="vi"><head><meta charset="UTF-8"></head>
    <body style="font-family:Calibri,Arial,sans-serif;background:#f0f6ff;margin:0;padding:20px">
      <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;
                  box-shadow:0 4px 24px rgba(29,78,216,.10);overflow:hidden">
        <!-- Header -->
        <div style="background:#1e3a8a;padding:28px 32px;text-align:center">
          <h1 style="color:#fff;margin:0;font-size:24px">🏨 Easyhome Hotel</h1>
          <p style="color:#bfdbfe;margin:6px 0 0">Nhà nghỉ theo giờ — TP. Huế</p>
        </div>
        <!-- Body -->
        <div style="padding:32px">
          <h2 style="color:#1d4ed8;margin-top:0">✅ Đặt phòng thành công!</h2>
          <p>Xin chào <strong>{$name}</strong>,</p>
          <p>Easyhome đã nhận được yêu cầu đặt phòng của bạn. Chi tiết:</p>
          <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr style="background:#eff6ff">
              <td style="padding:10px 14px;border:1px solid #bfdbfe;font-weight:600">Mã đặt phòng</td>
              <td style="padding:10px 14px;border:1px solid #bfdbfe">#{$b['maDP']}</td>
            </tr>
            <tr>
              <td style="padding:10px 14px;border:1px solid #bfdbfe;font-weight:600">Loại phòng</td>
              <td style="padding:10px 14px;border:1px solid #bfdbfe">{$b['loaiPhong']}</td>
            </tr>
            <tr style="background:#eff6ff">
              <td style="padding:10px 14px;border:1px solid #bfdbfe;font-weight:600">Check-in</td>
              <td style="padding:10px 14px;border:1px solid #bfdbfe">{$b['checkin']}</td>
            </tr>
            <tr>
              <td style="padding:10px 14px;border:1px solid #bfdbfe;font-weight:600">Check-out</td>
              <td style="padding:10px 14px;border:1px solid #bfdbfe">{$b['checkout']}</td>
            </tr>
            <tr style="background:#eff6ff">
              <td style="padding:10px 14px;border:1px solid #bfdbfe;font-weight:600">Tổng giá</td>
              <td style="padding:10px 14px;border:1px solid #bfdbfe;color:#1d4ed8;font-weight:700">{$tongGia} VNĐ</td>
            </tr>
          </table>
          <p style="color:#64748b;font-size:14px">
            📍 16.4602181, 107.5945346 — TP. Huế<br>
            📞 Hotline: <strong>0768.466.686</strong>
          </p>
        </div>
        <!-- Footer -->
        <div style="background:#eff6ff;padding:16px 32px;text-align:center;
                    color:#64748b;font-size:13px;border-top:1px solid #bfdbfe">
          Email tự động từ hệ thống Easyhome — Vui lòng không reply
        </div>
      </div>
    </body></html>
    HTML;
}

function emailReminderHTML(string $name, array $b): string {
    return <<<HTML
    <!DOCTYPE html>
    <html lang="vi"><head><meta charset="UTF-8"></head>
    <body style="font-family:Calibri,Arial,sans-serif;background:#f0f6ff;margin:0;padding:20px">
      <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;
                  box-shadow:0 4px 24px rgba(29,78,216,.10);overflow:hidden">
        <div style="background:#1e3a8a;padding:28px 32px;text-align:center">
          <h1 style="color:#fff;margin:0">🏨 Easyhome Hotel</h1>
        </div>
        <div style="padding:32px">
          <h2 style="color:#f59e0b;margin-top:0">🔔 Nhắc nhở check-in ngày mai</h2>
          <p>Xin chào <strong>{$name}</strong>,</p>
          <p>Bạn có lịch <strong>check-in ngày mai</strong> tại Easyhome.</p>
          <p>Mã đặt phòng: <strong>#{$b['maDP']}</strong> — Phòng: <strong>{$b['loaiPhong']}</strong></p>
          <p>Vui lòng đến trước <strong>14:00</strong> hoặc liên hệ <strong>0768.466.686</strong> nếu cần thay đổi.</p>
        </div>
        <div style="background:#eff6ff;padding:16px;text-align:center;color:#64748b;font-size:13px">
          Email tự động từ hệ thống Easyhome
        </div>
      </div>
    </body></html>
    HTML;
}