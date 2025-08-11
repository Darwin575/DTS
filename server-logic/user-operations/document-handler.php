<?php

class DocumentHandler
{
    private $conn;

    private $uploadDir = __DIR__ . '/../../documents/';
    private $qrDir = '../documents/qrcodes/';

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->createDirectories();
    }

    private function createDirectories()
    {
        if (!file_exists($this->uploadDir)) mkdir($this->uploadDir, 0755, true);
        if (!file_exists($this->qrDir)) mkdir($this->qrDir, 0755, true);
    }

    public function handleUpload($file)
    {
        $allowedTypes = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        $maxSize = 25 * 1024 * 1024; // 25MB

        if (!array_key_exists($file['type'], $allowedTypes)) {
            throw new Exception("Only PDF and DOCX files are allowed.");
        }

        if ($file['size'] > $maxSize) {
            throw new Exception("File size exceeds 25MB limit.");
        }

        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $file['name']);
        $filepath = $this->uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            error_log("Failed to move uploaded file to $filepath");
            throw new Exception("Failed to save uploaded file.");
        }
        $response = [
            'filename' => $filename,
            'filepath' => $filepath,
            'original_name' => $file['name'],
            'filetype' => $file['type'],
            'filesize' => $file['size']
        ];
        error_log("File upload response: " . json_encode($response));
        echo json_encode($response);
        exit;
    }

    public function getDocumentPath($documentId)
    {
        $stmt = $this->conn->prepare("SELECT file_path FROM tbl_documents WHERE document_id = ?");
        $stmt->bind_param("i", $documentId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['file_path'] ?? null;
    }
}
require_once '../config/db.php';
header('Content-Type: application/json');

try {
    $handler = new DocumentHandler($conn);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
        $handler->handleUpload($_FILES['file']);
    } else {
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
