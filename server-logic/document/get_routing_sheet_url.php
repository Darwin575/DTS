<?php
require_once __DIR__ . '/../../server-logic/config/db.php';
require_once __DIR__ . '/../../server-logic/config/session_init.php';
require_once __DIR__ . '/document_helpers.php';

$user_id = SessionManager::get('user')['id'];

$doc_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;
if (!$doc_id) {
    http_response_code(400);
    echo json_encode(null);
    exit;
}

$url = find_all_routing_sheet_paths_chain($conn, $doc_id, $user_id);
header('Content-Type: application/json');
echo json_encode($url);
