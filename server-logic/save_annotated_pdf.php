<?php
// server-logic/save_annotated_pdf.php
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['pdf']) || !isset($_POST['file_name'])) {
    echo json_encode(['success' => false, 'error' => 'Missing file or file name']);
    exit;
}

$file = $_FILES['pdf'];
$fileName = basename($_POST['file_name']);
$targetDir = realpath(__DIR__ . '/../documents');
if (!$targetDir) {
    echo json_encode(['success' => false, 'error' => 'Target directory not found']);
    exit;
}
$targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

// Move uploaded file (overwrite)
if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
}
