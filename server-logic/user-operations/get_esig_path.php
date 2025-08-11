<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$userId = SessionManager::get('user')['id'] ?? 0;
if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$stmt = $conn->prepare("SELECT esig_path FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$esigPath = $row['esig_path'] ?? '';

echo json_encode([
    'status' => 'success',
    'esig_path' => $esigPath
]);
