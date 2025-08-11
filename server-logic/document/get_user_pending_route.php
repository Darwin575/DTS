<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}
if (!isset($_SESSION['user']) || !isset($_GET['document_id'])) {
    echo json_encode(['route_id' => null]);
    exit;
}

$document_id = intval($_GET['document_id']);
$user_id = intval($_SESSION['user']['id']);

$stmt = $conn->prepare("
    SELECT route_id
    FROM tbl_document_routes
    WHERE document_id = ? AND to_user_id = ? 
      AND (status = 'pending' AND in_at IS NOT NULL)
    ORDER BY route_id DESC LIMIT 1
");
$stmt->bind_param("ii", $document_id, $user_id);
$stmt->execute();
$stmt->bind_result($route_id);
if ($stmt->fetch() && $route_id !== null) {
    SessionManager::unset('pending_esig');
    $_SESSION['route_pending_document_id'] = $document_id;
    $_SESSION['append_esig'] = true;
    echo json_encode(['route_id' => $route_id]);
} else {
    echo json_encode(['route_id' => null]);
}
$stmt->close();
