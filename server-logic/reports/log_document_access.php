<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';

// Always send JSON response
header('Content-Type: application/json; charset=utf-8');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Add diagnostic logging (remove in production!)
// file_put_contents(__DIR__ . '/log_debug.txt', date('c') . "\n" . print_r([
//     'session_user' => $_SESSION['user'] ?? null,
//     'post_data'    => $_POST,
//     'remote_addr'  => $_SERVER['REMOTE_ADDR'] ?? null,
//     'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
// ], true) . "\n", FILE_APPEND);
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
    exit;
}

$document_id = $_POST['document_id'] ?? null;
$access_type = $_POST['access_type'] ?? null;

if (!$document_id || !$access_type) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

$user_id = $_SESSION['user']['id'] ?? null;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$access_time = date('Y-m-d H:i:s');

$stmt = $conn->prepare("INSERT INTO tbl_document_access (document_id, user_id, access_type, access_time, ip_address, user_agent) VALUES (?, ?, ?, NOW(), ?, ?)");
$stmt->bind_param(
    "iisss",
    $document_id,
    $user_id,
    $access_type,
    $ip_address,
    $user_agent
);
try {
    $stmt->execute();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

echo json_encode(['status' => 'success']);
