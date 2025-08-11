<?php
require_once '../config/session_init.php';
require_once '../config/db.php';

$user_id = SessionManager::get('user')['id'] ?? 0;
$file_path = $_POST['file_path'] ?? '';

if (!$file_path || !file_exists($file_path)) {
    echo json_encode(['success' => false, 'message' => 'File not found.']);
    exit;
}

// Optional: Check if the file belongs to the user (security)
$stmt = $conn->prepare("SELECT document_id FROM tbl_documents WHERE file_path = ? AND user_id = ?");
$stmt->bind_param("si", $file_path, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (unlink($file_path)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete file.']);
}
