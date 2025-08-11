<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_init.php';

$user = SessionManager::get('user');
$from_user_id = $user['id'] ?? 0;
if (!$from_user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Get the raw POST data and decode it
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Set $draft_id if present
$draft_id = isset($data['draft_id']) ? intval($data['draft_id']) : 0;

try {

    $offices = isset($data['offices']) ? $data['offices'] : [];
    if (!$draft_id || empty($offices) || !is_array($offices)) {
        echo json_encode(['success' => false, 'message' => 'Missing draft_id or offices.']);
        exit;
    }

    // Remove previous routes for this document
    $conn->query("DELETE FROM tbl_document_routes WHERE document_id = $draft_id");

    $route_doc_id = null;
    foreach ($offices as $office_name) {
        $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE office_name = ? AND role = 'user' LIMIT 1");
        $stmt->bind_param("s", $office_name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $to_user_id = $row['user_id'];
            $status = 'pending';
            $in_at = null;
            $out_at = null;
            $stmt2 = $conn->prepare("INSERT INTO tbl_document_routes (document_id, from_user_id, to_user_id, status, in_at, out_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param("iiisss", $draft_id, $from_user_id, $to_user_id, $status, $in_at, $out_at);
            $stmt2->execute();
            if ($route_doc_id === null) {
                $route_doc_id = $conn->insert_id;
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Recipients routed in order!', 'route_doc_id' => $route_doc_id]);
    exit;
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
