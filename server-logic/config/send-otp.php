<?php
// Set timezone and error reporting at the VERY TOP
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Only start session and set headers when accessed directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/session_init.php';
    header('Content-Type: application/json');
}

// Include files
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/generate_otp.php';

require_once(__DIR__ . '/../../vendor/PHPMailer-master/src/PHPMailer.php');

require_once(__DIR__ . '/../../vendor/PHPMailer-master/src/PHPMailer.php');
require_once(__DIR__ . '/../../vendor/PHPMailer-master/src/SMTP.php');


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Constants
defined('MAX_OTP_ATTEMPTS') or define('MAX_OTP_ATTEMPTS', 3);
defined('LOCKOUT_DURATION') or define('LOCKOUT_DURATION', 3600);

/**
 * Sends OTP email and updates database
 */
function sendOTPByEmail($email, $otp)
{
    global $conn;

    try {
        // Check account lock status
        $stmt = $conn->prepare("SELECT account_locked_until FROM tbl_users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && $user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
            $remaining = strtotime($user['account_locked_until']) - time();
            throw new Exception("Account locked. Try again in " . gmdate("i:s", $remaining));
        }

        // Configure PHPMailer
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['MAIL_USERNAME'];
        $mail->Password = $_ENV['MAIL_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $_ENV['MAIL_PORT'];

        // Email content
        $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Your Login Verification Code';
        $mail->Body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><h2 style="color:#2F4050;">Your Verification Code</h2><p>Here is your one-time verification code:</p><div style="background:#f5f5f5;padding:15px;text-align:center;margin:20px 0;font-size:24px;font-weight:bold;">' . $otp . '</div><p>This code will expire in 10 minutes.</p></div>';
        $mail->AltBody = "Your verification code is: $otp (expires in 10 minutes)";

        // Send and update database
        if (!$mail->send()) {
            throw new Exception("Mailer Error: " . $mail->ErrorInfo);
        }

        $expiration = date('Y-m-d H:i:s', time() + 600);
        $maxAttempts = MAX_OTP_ATTEMPTS;
        $lockoutDuration = LOCKOUT_DURATION;

        $update = $conn->prepare("UPDATE tbl_users SET 
            otp_code = ?,
            otp_expiration = ?,
            otp_status = 'pending',
            otp_attempts = otp_attempts + 1,
            last_otp_sent = NOW(),
            account_locked_until = CASE 
                WHEN otp_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                ELSE NULL
            END
            WHERE email = ?");

        $update->bind_param("ssiis", $otp, $expiration, $maxAttempts, $lockoutDuration, $email);
        $update->execute();

        return true;
    } catch (Exception $e) {
        error_log("OTP Send Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Handles OTP resend requests (for AJAX calls)
 */
function handleOTPResend()
{
    global $conn;

    if (empty($_SESSION['user']['email'])) {
        throw new Exception("Session expired. Please log in again.");
    }

    $email = $_SESSION['user']['email'];
    $otp = generateOTP();

    if (!sendOTPByEmail($email, $otp)) {
        throw new Exception("Failed to send OTP. Please try again later.");
    }

    // Get remaining attempts
    $stmt = $conn->prepare("SELECT otp_attempts FROM tbl_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    return [
        'status' => 'success',
        'message' => 'OTP has been resent successfully.',
        'attempts_remaining' => max(0, MAX_OTP_ATTEMPTS - ($user['otp_attempts'] ?? 0))
    ];
}

// Handle direct AJAX requests
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Method not allowed", 405);
        }

        if (isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
            $response = handleOTPResend();
            echo json_encode($response);
            exit;
        }

        throw new Exception("Invalid request", 400);
    } catch (Exception $e) {
        http_response_code($e->getCode() ?: 400);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}
