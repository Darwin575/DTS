<?php

declare(strict_types=1);

// Initialize session and environment
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

error_log("=== [AUTH.PHP] NEW LOGIN REQUEST ===");
error_log("Session ID: " . session_id());
error_log("Session Data: " . print_r($_SESSION, true));
error_log("Raw POST Data: " . print_r($_POST, true));
error_log("Raw Input: " . file_get_contents('php://input'));
// Set headers  
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Constants
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 15 * 60); // 15 minutes
define('OTP_EXPIRY', 10 * 60);       // 10 minutes


try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method', 405);
    }

    // Support JSON or form-data
    $input = $_POST;
    if (empty($input) && ($raw = file_get_contents('php://input'))) {
        $input = json_decode($raw, true) ?? [];
    }
    error_log("try block - CSRF TOKEN: " . ($input['csrf_token'] ?? 'NOT SET'));

    // Check required fields
    foreach (['csrf_token', 'email', 'password', 'login'] as $field) {
        if (empty($input[$field])) {
            throw new InvalidArgumentException("Missing required field: {$field}", 400);
        }
    }

    // Trim email and password to remove any unwanted whitespace or newlines
    $email = sanitizeInput(trim($input['email']));
    $password = trim($input['password']);
    // Validate CSRF token
    validateCsrfToken($input['csrf_token']);

    $response = handleLogin(
        $email,
        $password
    );

    http_response_code(200);
    echo json_encode($response);
    exit;
} catch (Throwable $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 400;
    http_response_code($code);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $code
    ]);
    exit;
}


// === HELPERS ===

function validateCsrfToken(string $token): void
{
    if (empty($_SESSION['csrf_token'])) {
        throw new RuntimeException('CSRF token missing in session', 403);
    }

    error_log("CSRF Session Token: " . $_SESSION['csrf_token']);
    error_log("CSRF Posted Token: " . $token);

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        throw new RuntimeException('Security token validation failed', 403);
    }
}

function sanitizeInput(string $input): string
{
    return htmlspecialchars(stripslashes(trim($input)), ENT_QUOTES, 'UTF-8');
}

function handleLogin(string $email, string $password): array
{
    global $conn;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email format', 400);
    }

    checkAccountLock($email);

    $stmt = $conn->prepare("
        SELECT user_id, email, password, role, otp_attempts, account_locked_until, is_deactivated 
        FROM tbl_users WHERE email = ? LIMIT 1
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        handleFailedAttempt($email);
        throw new RuntimeException('Invalid credentials', 401);
    }


    $user = $result->fetch_assoc();
    error_log("Fetched User: " . print_r($user, true));

    if ((int)$user['is_deactivated'] === 1) {
        throw new RuntimeException('Account is deactivated', 403);
    }
    if (!password_verify($password, $user['password'])) {
        handleFailedAttempt($email);
        throw new RuntimeException('Invalid credentials', 401);
    }

    if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
        $remaining = strtotime($user['account_locked_until']) - time();
        throw new RuntimeException("Account temporarily locked. Try again in " . gmdate("i:s", $remaining), 403);
    }

    // Generate and save OTP
    require_once __DIR__ . '/generate_otp.php';
    $otp = generateOTP();
    $expiry = date('Y-m-d H:i:s', time() + OTP_EXPIRY);


    error_log("Generated OTP: $otp (Expires: $expiry)");

    $update = $conn->prepare("
        UPDATE tbl_users 
        SET otp_code = ?, otp_expiration = ?, otp_attempts = 0, account_locked_until = NULL 
        WHERE email = ?
    ");
    $update->bind_param('sss', $otp, $expiry, $email);
    $update->execute();

    // Session init
    session_regenerate_id(true);
    SessionManager::set('user', [
        'id' => $user['user_id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'otp_verified' => false
    ]);
    SessionManager::set('ip', $_SERVER['REMOTE_ADDR']);
    SessionManager::set('user_agent', $_SERVER['HTTP_USER_AGENT']);
    SessionManager::set('csrf_token', bin2hex(random_bytes(32)));
    SessionManager::set('auth_csrf', bin2hex(random_bytes(32))); // For OTP phase
    require_once __DIR__ . '/send-otp.php';
    $otp = generateOTP();
    sendOTPByEmail($user['email'], $otp);
    if (!empty($_SESSION['route_pending_document_id']) || !empty(SessionManager::get('pending_esig'))) {
        $response = [
            'status'    => 'success',
            'show_modal' => true
        ];
    } else {
        $response = [
            'status'   => 'success',
            'redirect' => '/DTS/pages/otp.php?' . http_build_query([
                'user_id'   => $user['user_id'],
                'auth_token' => SessionManager::get('auth_csrf')
            ])
        ];
    }

    // Preserve document flow if exists
    if (isset($_SESSION['document_flow'])) {
        $_SESSION['document_flow']['user_id'] = $user['user_id']; // Link to authenticated user
    }

    return $response;
}

function handleFailedAttempt(string $email): void
{
    global $conn;

    $maxAttempts = (int)MAX_LOGIN_ATTEMPTS;  // Explicitly cast to int
    $lockoutDuration = (int)LOCKOUT_DURATION; // Explicitly cast to int

    $stmt = $conn->prepare("
        UPDATE tbl_users 
        SET otp_attempts = otp_attempts + 1,
            account_locked_until = CASE 
                WHEN otp_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                ELSE NULL
            END
        WHERE email = ?
    ");

    // Bind variables directly, not constants
    $stmt->bind_param('iis', $maxAttempts, $lockoutDuration, $email);
    $stmt->execute();
}

function checkAccountLock(string $email): void
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT account_locked_until 
        FROM tbl_users 
        WHERE email = ? AND account_locked_until > NOW()
        LIMIT 1
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $lockedUntil = $result->fetch_assoc()['account_locked_until'];
        $remaining = strtotime($lockedUntil) - time();
        throw new RuntimeException("Account temporarily locked. Try again in " . gmdate("i:s", $remaining), 403);
    }
}
