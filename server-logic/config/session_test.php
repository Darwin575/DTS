<?php
require_once __DIR__ . '/session_init.php';

$_SESSION['test'] = time();

echo json_encode([
    'session_id' => session_id(),
    'session_data' => $_SESSION
]);
