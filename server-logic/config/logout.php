<?php
require_once __DIR__ . '/session_init.php'; // adjust the path

SessionManager::logout(); // Call the public destroy method
header('Location: /DTS/index.php'); // Redirect to login page
exit;
