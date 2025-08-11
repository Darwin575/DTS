<?php
// /server-logic/user-operations/delete_uploaded_route_sheet.php

require_once("../config/db.php");
header('Content-Type: application/json');

$file_url = $_POST['file_url'] ?? '';
$document_id = $_POST['document_id'] ?? '';

if ($file_url) {
    $file_path = __DIR__ . '/../../../' . ltrim($file_url, '/');
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}
if ($document_id) {
    $sql = "UPDATE tbl_document_routes SET routing_sheet_path=NULL WHERE document_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $document_id);
    $stmt->execute();
}
echo json_encode(['success' => true]);
