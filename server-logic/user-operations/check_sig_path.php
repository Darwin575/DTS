<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !$_SESSION['user']['id']) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.', 'sig_path_exists' => false]);
    exit;
}

$user_id = intval($_SESSION['user']['id']);

$stmt = $conn->prepare("SELECT esig_path FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($esig_path);
$stmt->fetch();
$stmt->close();

// error_log("esig_path (from DB): " . $esig_path);

// Convert to absolute path if it starts with '/'
$absolute_path = (strpos($esig_path, '/') === 0)
    ? $_SERVER['DOCUMENT_ROOT'] . $esig_path
    : $esig_path; // fallback if already absolute

// error_log("absolute_path (for file_exists): " . $absolute_path);
// error_log("file_exists: " . (file_exists($absolute_path) ? "yes" : "no"));
// error_log("is_readable: " . (is_readable($absolute_path) ? "yes" : "no"));

if ($esig_path && file_exists($absolute_path) && is_readable($absolute_path)) {
    echo json_encode(['status' => 'success', 'sig_path_exists' => true, 'message' => 'Your e-signature is set.']);
    exit;
} else {
    echo json_encode(['status' => 'success', 'sig_path_exists' => false, 'message' => 'No e-signature found in your profile. Please upload your e-signature in profile settings.']);
    exit;
}
