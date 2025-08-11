<?php
require_once __DIR__ . '/../config/session_init.php';

if (!SessionManager::get('user_id')) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
} else {
    echo json_encode(['success' => true, 'message' => 'User authenticated', 'user_id' => SessionManager::get('user_id')]);
}
