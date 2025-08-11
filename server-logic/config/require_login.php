<?php
require_once __DIR__ . '/session_init.php';
$user = SessionManager::get('user'); // <-- ADD THIS LINE
if (empty($user) || empty($user['id'])) {
    header('Location: /DTS/index.php');
    exit;
}
if (empty($_SESSION['otp_verified'])) {
    $_SESSION['redirect_after_otp'] = $_SERVER['REQUEST_URI'];
    header('Location: /DTS/index.php');
    exit;
}
