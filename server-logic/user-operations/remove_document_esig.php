<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !$_SESSION['user']['id']) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit;
}

$user_id = intval($_SESSION['user']['id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['document_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$document_id = intval($_POST['document_id']);

// Clear esig for this user's route entry (must be to_user_id)
$stmt = $conn->prepare("UPDATE tbl_document_routes SET esig_path = NULL WHERE document_id = ? AND to_user_id = ?");
$stmt->bind_param("ii", $document_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Signature removed from the routing sheet.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Could not remove signature.']);
}
$stmt->close();
