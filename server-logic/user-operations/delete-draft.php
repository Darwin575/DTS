<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';



$id = intval($_POST['id'] ?? 0);
$user_id = SessionManager::get('user')['id'] ?? 0;
if ($id && $user_id) {
    // Delete actions first
    $conn->query("DELETE FROM tbl_document_actions WHERE document_id = $id");
    // Then delete the draft
    $res = $conn->query("DELETE FROM tbl_documents WHERE document_id = $id AND user_id = $user_id AND status = 'draft'");
    echo json_encode(['success' => $res ? true : false]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
