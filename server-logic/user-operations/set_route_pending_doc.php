<?php
require_once __DIR__ . '/../config/session_init.php';
SessionManager::unset('pending_esig');
unset($_SESSION['append_esig']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['document_id'])) {
    $_SESSION['route_pending_document_id'] = intval($_POST['document_id']);
    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error']);
exit;
