<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// Set your documents directory
$targetDir = __DIR__ . '/../../documents/'; // Adjust if your structure is different

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$originalFilename = basename($_POST['original_filename'] ?? $_FILES['file']['name']);
$targetPath = $targetDir . $originalFilename;

// Optionally, add security checks here (file extension, user auth, etc.)

if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    echo json_encode(['success' => true, 'filename' => $originalFilename]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
}
