<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_init.php';

header('Content-Type: application/json');
$user_id = SessionManager::get('user')['id'] ?? 0;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}



echo json_encode([
    'success' => true,
    'uploaded_file_name' => $_FILES['file']['name'],
    'uploaded_file_url' => $web_path,
    'filetype' => $file_type,
    'filesize' => $file_size,
    'draft_id' => $draft_id
]);
exit;
