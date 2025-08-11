<?php
require_once __DIR__ . '/../config/db.php';
include __DIR__ . '/../config/session_init.php';
// Add this after the fail() function
function sanitize_textarea($input, $maxLen = 3000)
{
    $allowed = '<b><i><u><strong><em><ul><ol><li><p><br><a><h1><h2><h3><h4><h5><h6><blockquote><pre><code><table><thead><tbody><tr><td><th>';
    $input = strip_tags($input, $allowed);

    $input = preg_replace_callback(
        '/<([a-z][a-z0-9]*)((?:\s+[a-z0-9\-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?)*)\s*(\/?)\s*>/i',
        function ($matches) {
            $tag = $matches[1];
            $attrs = $matches[2];
            $attrs = preg_replace('/\s+(on\w+|style|class|id|data-\w+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $attrs);

            if ($tag === 'a') {
                $attrs = preg_replace_callback(
                    '/\s+href\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i',
                    function ($m) {
                        $url = trim($m[1], '\'"');
                        if (preg_match('/^(https?:\/\/|mailto:|tel:)/i', $url)) {
                            return ' href="' . $url . '"';
                        }
                        return '';
                    },
                    $attrs
                );
            }

            $close = $matches[3];
            return "<$tag$attrs$close>";
        },
        $input
    );

    $input = trim($input);
    if ($maxLen > 0) $input = mb_substr($input, 0, $maxLen);
    return $input;
}
function fail($msg)
{
    http_response_code(400);
    die($msg);
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') fail("Invalid method");

// Validate and collect
$route_id = intval($_POST['route_id'] ?? 0);
$document_id = intval($_POST['document_id'] ?? 0);
$action = $_POST['action'] ?? 'upload';
$comment = sanitize_textarea($_POST['comment'] ?? '');
if (trim($comment) === '<p><br></p>') {
    $comment = ''; // Convert empty summernote content to empty string
}
$user_id = SessionManager::get('user')['id'] ?? null;

if (!$route_id || !$document_id || !$user_id) fail("Missing parameters.");

// File upload
$uploaded_path = '';
if (!empty($_FILES['routeSheetUpload']) && $_FILES['routeSheetUpload']['error'] == UPLOAD_ERR_OK) {
    $f = $_FILES['routeSheetUpload'];
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    $target_dir = __DIR__ . "/../../uploads/routing_sheets/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0775, true);
    $target_name = "route_" . $route_id . "_" . time() . "." . $ext;
    $target_path = $target_dir . $target_name;
    if (!move_uploaded_file($f['tmp_name'], $target_path)) {
        fail("Could not save uploaded file.");
    }
    $uploaded_path = "/uploads/routing_sheets/" . $target_name;
}

// Get current route details for safe execution
$stmt = $conn->prepare("SELECT * FROM tbl_document_routes WHERE route_id=? AND document_id=? LIMIT 1");
$stmt->bind_param("ii", $route_id, $document_id);
$stmt->execute();
$route_row = $stmt->get_result()->fetch_assoc();
if (!$route_row) fail("Route not found.");

// Find next route for this document
$next_route_rs = $conn->query("SELECT route_id FROM tbl_document_routes WHERE document_id=$document_id AND route_id>$route_id ORDER BY route_id ASC LIMIT 1");
$next_route_id = null;
if ($next_route_rs && $nr = $next_route_rs->fetch_assoc()) {
    $next_route_id = $nr['route_id'];
}


// Logic based on action
$status = $route_row['status']; // Default to existing
$final_doc_status = null;

if ($action == 'approved' || $action == 'approve') {
    // 1. Set current as completed + out_at
    $status = 'completed';
    $stmt = $conn->prepare("UPDATE tbl_document_routes SET status=?, out_at=NOW(), comments=? WHERE route_id=? AND document_id=?");
    $stmt->bind_param("ssii", $status, $comment, $route_id, $document_id);
    if (!$stmt->execute()) fail("Failed to update route: " . $stmt->error);

    // 2. If next exists, set in_at for the next route
    if ($next_route_id) {
        $conn->query("UPDATE tbl_document_routes SET in_at=NOW() WHERE route_id=$next_route_id");
    } else {
        // 3. If last recipient, update tbl_documents as completed
        $final_doc_status = 'approved';
    }
} else if ($action == 'disapproved' || $action == 'reject') {
    // 1. Set current as rejected + out_at
    $status = 'rejected';
    $stmt = $conn->prepare("UPDATE tbl_document_routes SET status=?, out_at=NOW(), comments=? WHERE route_id=? AND document_id=?");
    $stmt->bind_param("ssii", $status, $comment, $route_id, $document_id);
    if (!$stmt->execute()) fail("Failed to update route: " . $stmt->error);

    // 2. If no next route_id for this doc, set doc as rejected
    // if (!$next_route_id) {
    //     $final_doc_status = 'disapproved';
    // }
} else if ($action == 'noted') {
    $status = 'completed';
    $stmt = $conn->prepare("UPDATE tbl_document_routes SET status=?, out_at=NOW(), comments=? WHERE route_id=? AND document_id=?");
    $stmt->bind_param("ssii", $status, $comment, $route_id, $document_id);
    if (!$stmt->execute()) fail("Failed to update route: " . $stmt->error);

    // If last recipient (no next), update documents to noted
    if (!$next_route_id) {
        $final_doc_status = 'noted';
    }
} else {
    // Just file/comment upload, or fallback
    $stmt = $conn->prepare("UPDATE tbl_document_routes SET routing_sheet_path=?, comments=? WHERE route_id=? AND document_id=?");
    $stmt->bind_param("ssii", $uploaded_path, $comment, $route_id, $document_id);
    if (!$stmt->execute()) fail("Failed to update route: " . $stmt->error);
}
$completed = 'archived';
// If final status needed, set in tbl_documents
if ($final_doc_status) {
    $stmt = $conn->prepare("UPDATE tbl_documents SET final_status=?, status=? WHERE document_id=?");
    $stmt->bind_param("ssi", $final_doc_status, $completed, $document_id);
    if (!$stmt->execute()) fail("Failed to update document: " . $stmt->error);
}

echo json_encode(['success' => true, 'final_doc_status' => $final_doc_status]);
exit;
