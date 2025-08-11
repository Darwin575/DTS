<?php
include_once __DIR__ . '/../config/session_init.php';
header('Content-Type: application/json');
$user = SessionManager::get('user', []);
$id = $user['id'];
// Check authentication
if (!$id || !is_array($user) || empty($user)) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

// Validate input
if (empty($_POST['route_id']) || !is_numeric($_POST['route_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid route_id']);
    exit;
}

$route_id = intval($_POST['route_id']);
$user_id = $_SESSION['user']['id'];

require_once('../../server-logic/config/db.php');

// Get the e-signature path for the user
$stmt = $conn->prepare("SELECT esig_path FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
if (!$row || !$row['esig_path']) {
    echo json_encode(['success' => false, 'message' => 'Signature not found in user profile']);
    exit;
}
$user_sig_path = $row['esig_path'];

// Update the sig_path for this route
$stmt = $conn->prepare("UPDATE tbl_document_routes SET esig_path = ? WHERE route_id = ?");
$stmt->bind_param("si", $user_sig_path, $route_id);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to update signature']);
    exit;
}

echo json_encode(['success' => true, 'sig_path' => $user_sig_path]);
