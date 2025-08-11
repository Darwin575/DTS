<?php
require_once __DIR__ . '/../config/session_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}

if (empty($_POST['esig'])) {
    echo json_encode(['status' => 'error', 'message' => 'No signature data']);
    exit;
}
unset($_SESSION['append_esig']);
unset($_SESSION['route_pending_document_id']);
// Store signature in session using SessionManager
SessionManager::set('pending_esig', $_POST['esig']);

// Instead of redirecting to the login page, signal the client to show the login modal.
echo json_encode(['status' => 'modal']);
