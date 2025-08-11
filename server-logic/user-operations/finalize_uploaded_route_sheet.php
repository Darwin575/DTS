<?php
// /server-logic/user-operations/finalize_uploaded_route_sheet.php
require_once("../config/db.php");
header('Content-Type: application/json');

$document_id = $_POST['document_id'] ?? null;

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'Missing document_id']);
    exit;
}

// Set document status to active
$conn->query("UPDATE tbl_documents SET status='active', esig_path=NULL WHERE document_id=" . intval($document_id));

// Get all routes ordered by ID
$sql = "SELECT route_id, status FROM tbl_document_routes WHERE document_id=? ORDER BY route_id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $document_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'pending') {
        // Update in_at, out_at, and change the status to 'pending'
        $update = $conn->prepare("UPDATE tbl_document_routes SET in_at=NOW(), out_at=NULL, status='pending' WHERE route_id=?");
        $update->bind_param('i', $row['route_id']);
        $update->execute();
        break; // Only update the first matching route
    }
}

echo json_encode(['success' => true]);
