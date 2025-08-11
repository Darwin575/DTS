<?php

declare(strict_types=1);

// Error reporting
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Start session and headers
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

error_log("Verifying CSRF - Session Token: " . SessionManager::get('otp_csrf'));
error_log("Verifying CSRF - Posted Token: " . ($_POST['csrf_token'] ?? 'NULL'));

// Constants
define('MAX_OTP_ATTEMPTS', 4);
define('OTP_LOCKOUT_DURATION', 1800);

try {
    // 1. Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed', 405);
    }

    // 2. Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals(SessionManager::get('otp_csrf'), $_POST['csrf_token'])) {
        throw new RuntimeException('CSRF validation failed', 403);
    }

    // 3. Validate session
    if (!SessionManager::get('user') || !SessionManager::get('user')['email']) {
        throw new RuntimeException('Session expired', 401);
    }

    $email = SessionManager::get('user')['email'];

    // 4. Get and validate OTP
    $otp = '';
    for ($i = 1; $i <= 6; $i++) {
        $otp .= $_POST["digit$i"] ?? ''; // Match your frontend input names
    }

    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        throw new InvalidArgumentException('Invalid OTP format', 400);
    }

    // 5. Check account status
    $stmt = $conn->prepare("
    SELECT 
        user_id,
        name,
        office_name,
        email,
        profile_picture_path,
        esig_path,
        role,
        otp_code, 
        otp_expiration, 
        otp_attempts,
        account_locked_until,
        TIMESTAMPDIFF(SECOND, NOW(), otp_expiration) as expires_in
    FROM tbl_users 
    WHERE email = ?
    FOR UPDATE
");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        throw new RuntimeException('Account not found', 404);
    }

    // 6. Check lock status
    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
        $remaining = strtotime($user['account_locked_until']) - time();
        throw new RuntimeException(
            "Account locked. Try again in " . gmdate("i:s", $remaining),
            423
        );
    }

    // 7. Check OTP expiration
    if ($user['expires_in'] < 0) {
        throw new RuntimeException('OTP expired. Please request a new code', 410);
    }

    // 8. Verify OTP
    if (!hash_equals($user['otp_code'], $otp)) {
        // Update attempts
        $update = $conn->prepare("
            UPDATE tbl_users 
            SET otp_attempts = otp_attempts + 1,
                account_locked_until = CASE 
                    WHEN otp_attempts + 1 >= ? THEN 
                        DATE_ADD(NOW(), INTERVAL ? SECOND)
                    ELSE NULL
                END
            WHERE email = ?
        ");
        $otp_lockout_durations = OTP_LOCKOUT_DURATION; // Explicitly cast to int
        $max_otp_attempts = MAX_OTP_ATTEMPTS; // Explicitly cast to int
        $update->bind_param("iis", $max_otp_attempts, $otp_lockout_durations, $email);
        $update->execute();

        $remainingAttempts = max(0, MAX_OTP_ATTEMPTS - ($user['otp_attempts'] + 1));
        throw new RuntimeException(
            "Invalid OTP. " . $remainingAttempts . " attempt(s) remaining",
            400
        );
    }

    // 9. Successful verification
    $update = $conn->prepare("
        UPDATE tbl_users 
        SET otp_status = 'verified',
            otp_attempts = 0,
            account_locked_until = NULL,
            last_otp_sent = NOW()
        WHERE email = ?
    ");
    $update->bind_param("s", $email);
    $update->execute();

    // 10. Regenerate session
    // $doc_token = $_SESSION['doc_token'];
    // SessionManager::logout();
    session_regenerate_id(true);
    SessionManager::set('otp_verified', true);
    SessionManager::set('csrf_token', bin2hex(random_bytes(32)));

    if ($user) {
        SessionManager::set('user', [
            'id' => $user['user_id'],
            'name' => $user['name'],
            'office_name' => $user['office_name'],
            'email' => $user['email'],
            'profile_picture_path' => $user['profile_picture_path'],
            'esig_path' => $user['esig_path'],
            'role' => $user['role'],
        ]);
    }


    if (SessionManager::get('pending_esig')) {
        $userId = SessionManager::get('user')['id'];
        $data = SessionManager::get('pending_esig');
        $data = str_replace('data:image/png;base64,', '', $data);
        $data = str_replace(' ', '+', $data);
        $fileData = base64_decode($data);

        // Save new esig
        $esigDir = '/DTS/uploads/esig/';
        $absDir = $_SERVER['DOCUMENT_ROOT'] . $esigDir;
        if (!is_dir($absDir)) mkdir($absDir, 0777, true);

        $esigPath = $esigDir . 'esig_user_' . $userId . '_' . time() . '.png';
        file_put_contents($_SERVER['DOCUMENT_ROOT'] . $esigPath, $fileData);

        $stmt = $conn->prepare("UPDATE tbl_users SET esig_path = ? WHERE user_id = ?");
        $stmt->bind_param("si", $esigPath, $userId);
        $stmt->execute();

        SessionManager::unset('pending_esig');
        $_SESSION['esig_saved'] = true;
    }


    // Check for pending document route that needs e-signature
    $pending_route_esig_doc_id = $_SESSION['route_pending_document_id'] ?? null;
    unset($_SESSION['route_pending_document_id']);
    $user_esig_path = $user['esig_path'] ?? null;
    $user_id = $user['user_id'];

    if ($pending_route_esig_doc_id) {
        if (!$user_esig_path || !file_exists($_SERVER['DOCUMENT_ROOT'] . $user_esig_path)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'You have not set an e-signature yet. Please configure your e-signature in your profile first.',
                'show_toastr' => true
            ]);
            // unset($_SESSION['route_pending_document_id']);
            exit;
        }
        if ($_SESSION['append_esig']) {
            // unset($_SESSION['route_pending_document_id']);
            unset($_SESSION['append_esig']);
            echo json_encode([
                'status' => 'success',
                'redirect' => "/DTS/pages/receive_document.php?append_esig=1&receive_doc_id={$pending_route_esig_doc_id}"
            ]);

            exit;
        }

        // Set user esig_path in tbl_document_routes
        // $update_esig = $conn->prepare("UPDATE tbl_document_routes SET esig_path = ? WHERE document_id = ? AND to_user_id = ?");
        // $update_esig->bind_param("sii", $user_esig_path, $pending_route_esig_doc_id, $user_id);
        // $update_esig->execute();

        // Remove pending flag, respond for modal open (AJAX flow)
        // unset($_SESSION['route_pending_document_id']);
        echo json_encode([
            'status' => 'success',
            'redirect' => "/DTS/pages/document_management.php?e_sig_ready=1&document_id={$pending_route_esig_doc_id}"
        ]);

        exit;
    }
    // Normal redirect flow if no pending e-signature (AJAX expects JSON)
    if (!empty($_SESSION['doc_token'])) {
        $token = $_SESSION['doc_token'];
        unset($_SESSION['doc_token']);
        // Clear after use
        $redirect = '/DTS/pages/receive_document.php?token=' . urlencode($token);
    } elseif ($_SESSION['esig_saved']) {
        $redirect = '/DTS/pages/user_profile.php';
    } else {
        $redirect = SessionManager::get('user')['role'] === 'admin'
            ? '/DTS/pages/admin_page.php'
            : '/DTS/pages/dashboard.php';
    }

    echo json_encode(['status' => 'success', 'redirect' => $redirect]);
    exit;
} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'require_new_otp' => $e->getCode() === 410,
        'attempts_remaining' => $remainingAttempts ?? null
    ]);
}
