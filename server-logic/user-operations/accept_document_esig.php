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

// Get user's e-signature
$stmt = $conn->prepare("SELECT esig_path FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($esig_path);
$stmt->fetch();
$stmt->close();
$absolute_path = (strpos($esig_path, '/') === 0)
    ? $_SERVER['DOCUMENT_ROOT'] . $esig_path
    : $esig_path; // fallback if already absolute
if (empty($esig_path) || !file_exists($absolute_path) || !is_readable($absolute_path)) {
    echo json_encode(['status' => 'error', 'message' => 'No valid e-signature on file.']);
    exit;
}
$routing_sheet_path = null;
// Save e-sig for the current user's route entry
$stmt = $conn->prepare("UPDATE tbl_documents SET esig_path = ?, routing_sheet_path = ? WHERE document_id = ? AND user_id = ?");
$stmt->bind_param("ssii", $esig_path, $routing_sheet_path, $document_id, $user_id);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Could not append your e-signature.']);
    $stmt->close();
    exit;
}
$stmt->close();

// Activate document
$stmt = $conn->prepare("UPDATE tbl_documents SET status='active' WHERE document_id=?");
$stmt->bind_param("i", $document_id);
$stmt->execute();
$stmt->close();

// Find the "current route" by route_id order.
// The lowest route_id for this document with status='pending' or 'rejected'
$stmt = $conn->prepare("
    SELECT route_id 
    FROM tbl_document_routes 
    WHERE document_id=? 
      AND (status='pending') 
    ORDER BY route_id ASC 
    LIMIT 1
");
$stmt->bind_param("i", $document_id);
$stmt->execute();
$stmt->bind_result($route_id);

if ($stmt->fetch() && $route_id) {
    $stmt->close();
    // Update in_at, reset out_at, and convert status to 'pending'
    $stmt2 = $conn->prepare("UPDATE tbl_document_routes SET in_at=NOW(), out_at=NULL, status='pending', routing_sheet_path=NULL WHERE route_id=?");
    $stmt2->bind_param("i", $route_id);
    $stmt2->execute();
    $stmt2->close();
} else {
    $stmt->close();
}


echo json_encode(['status' => 'success', 'message' => 'E-signature routed, document is now active.']);
exit;
