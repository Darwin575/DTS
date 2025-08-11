<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/document-handler.php';

$documentId = $_GET['id'] ?? null;
$token = $_GET['token'] ?? null;
$accessType = $_GET['type'] ?? 'direct';

try {
    $handler = new DocumentHandler($conn);

    // Find document by either ID or token
    if ($token) {
        $column = $accessType === 'qr' ? 'qr_token' : 'embed_token';
        $stmt = $conn->prepare("SELECT * FROM tbl_documents WHERE $column = ?");
        $stmt->bind_param("s", $token);
    } else {
        $stmt = $conn->prepare("SELECT * FROM tbl_documents WHERE document_id = ?");
        $stmt->bind_param("s", $documentId);
    }

    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();

    if (!$document || !file_exists($document['file_path'])) {
        throw new Exception("Document not found");
    }

    // Track access
    if ($token) {
        $trackStmt = $conn->prepare("
            INSERT INTO tbl_document_access (
                document_id, user_id, access_type, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $trackStmt->bind_param(
            "iisss",
            $document['document_id'],
            $_SESSION['user_id'] ?? null,
            $accessType,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        );
        $trackStmt->execute();
    }

    // Output the file
    header('Content-Type: ' . mime_content_type($document['file_path']));
    header('Content-Disposition: inline; filename="' . basename($document['file_path']) . '"');
    readfile($document['file_path']);
} catch (Exception $e) {
    http_response_code(404);
    echo $e->getMessage();
}
