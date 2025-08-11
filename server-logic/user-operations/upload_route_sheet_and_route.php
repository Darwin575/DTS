<?php
// /server-logic/user-operations/upload_route_sheet_and_route.php

require_once("../config/db.php"); // modify to correct relative path if needed
require_once("../config/session_init.php");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$document_id = $_POST['document_id'] ?? null;
$user_id = SessionManager::get('user')['id'] ?? null;
error_log("User ID: uploading here " . $user_id);
if (!$document_id || !$user_id || !isset($_FILES['route_sheet_file']) || !$_FILES['route_sheet_file']['tmp_name']) {
    echo json_encode(['success' => false, 'message' => 'Missing file, user_id, or document ID.']);
    exit;
}

// Use safe file naming
$upload_dir = __DIR__ . '/../../routing_sheets/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true);
}
$ext = strtolower(pathinfo($_FILES['route_sheet_file']['name'], PATHINFO_EXTENSION));
$basename = 'route_' . $document_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$target_path = $upload_dir . $basename;
$public_path = 'routing_sheets/' . $basename;

if (!move_uploaded_file($_FILES['route_sheet_file']['tmp_name'], $target_path)) {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
    exit;
}

// First, try to update the user's recipient route row
$sql = "UPDATE tbl_document_routes SET routing_sheet_path=? WHERE document_id=? AND to_user_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sii', $public_path, $document_id, $user_id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    // Fallback: is the user the document's creator? put in tbl_documents instead
    $sql = "UPDATE tbl_documents SET routing_sheet_path=? WHERE document_id=? AND user_id=?";
    $stmt2 = $conn->prepare($sql);
    $stmt2->bind_param('sii', $public_path, $document_id, $user_id);
    $stmt2->execute();
    if ($stmt2->affected_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No matching route or creator row to update.']);
        exit;
    }
}

// Return for frontend to display modal with uploaded file
echo json_encode([
    'success' => true,
    'file_url' => '../' . $public_path,
    'document_id' => $document_id,
    'user_id' => $user_id
]);
exit;
