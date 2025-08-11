<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_init.php';

$currentUserId = SessionManager::get('user')['id'] ?? 0;
error_log("Current User ID: " . $currentUserId);

// Debug output
header('Content-Type: application/json');

if (!$currentUserId) {
    echo json_encode(['error' => 'No user ID found', 'debug' => ['session' => $_SESSION]]);
    exit;
}
$tab = isset($_GET['tab']) ? intval($_GET['tab']) : 1;

// Initialize document arrays
$docsByUser = [];
$docsRoutedToUser = [];

// 1. Documents created by the user (based on tab) - NEWEST FIRST
if ($tab == 1 || $tab == 3) {
    $statusCondition = ($tab == 1) ? 'active' : 'archived';
    $query = "SELECT d.*, 
        GROUP_CONCAT(a.action SEPARATOR ' / ') AS actions,
        u.office_name AS uploaded_by
        FROM tbl_documents d
        LEFT JOIN tbl_document_actions a ON d.document_id = a.document_id
        LEFT JOIN tbl_users u ON d.user_id = u.user_id
        WHERE d.user_id = ? AND d.status = ?
        GROUP BY d.document_id
        ORDER BY d.updated_at DESC";
    error_log("Query 1: " . $query);
    error_log("Status condition: " . $statusCondition);
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $currentUserId, $statusCondition);

    if (!$stmt->execute()) {
        error_log("Query execution error: " . $stmt->error);
    }
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docsByUser[$row['document_id']] = $row;
    }
}

// 2. Documents routed to the user (based on tab) - NEWEST FIRST
if ($tab == 2 || $tab == 3) {
    $statusCondition = ($tab == 2) ? 'active' : 'archived';
    $stmt2 = $conn->prepare("SELECT r.*, d.*, 
        GROUP_CONCAT(a.action SEPARATOR ' / ') AS actions,
        u.office_name AS uploaded_by
        FROM tbl_document_routes r
        JOIN tbl_documents d ON r.document_id = d.document_id
        LEFT JOIN tbl_document_actions a ON d.document_id = a.document_id
        LEFT JOIN tbl_users u ON r.from_user_id = u.user_id
        WHERE r.to_user_id = ? AND r.in_at IS NOT NULL AND d.status = ?
        GROUP BY r.route_id
        ORDER BY r.in_at DESC"); // Added sorting
    $stmt2->bind_param("is", $currentUserId, $statusCondition);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $docsRoutedToUser[$row['document_id']] = $row;
    }
}

// Merge all documents based on tab
if ($tab == 1) {
    $allDocs = $docsByUser;
} elseif ($tab == 2) {
    $allDocs = $docsRoutedToUser;
} else {
    $allDocs = $docsByUser + $docsRoutedToUser;
}

// Process each document to add display_status BEFORE filtering
foreach ($allDocs as $key => $doc) {
    $final_status = strtolower(trim($doc['final_status'] ?? ''));
    if ($final_status === 'noted' || $final_status === 'approved') {
        $allDocs[$key]['display_status'] = strtolower($final_status);
    } else {
        // Get latest route with out_at not null for this document
        $doc_id = $doc['document_id'];
        $routeRes = $conn->query("SELECT status FROM tbl_document_routes WHERE document_id = $doc_id AND out_at IS NOT NULL AND out_at != '' ORDER BY route_id DESC LIMIT 1");
        $route = $routeRes ? $routeRes->fetch_assoc() : null;
        if ($route) {
            $route_status = strtolower($route['status']);
            if ($route_status === 'rejected') {
                $allDocs[$key]['display_status'] = 'rejected';
            } elseif ($route_status === 'completed' || $route_status === 'pending') {
                $allDocs[$key]['display_status'] = 'active';
            } else {
                $allDocs[$key]['display_status'] = strtolower($route_status);
            }
        } else {
            $allDocs[$key]['display_status'] = strtolower($doc['status'] ?? '');
        }
    }
}

// --- FILTERING LOGIC ---
$filteredDocs = array_filter($allDocs, function ($doc) {
    $docName = strtolower($doc['subject'] ?? '');
    $type = strtolower($doc['actions'] ?? '');
    $uploadedBy = strtolower($doc['uploaded_by'] ?? '');
    $status = strtolower($doc['status'] ?? '');
    $computedStatus = strtolower($doc['computed_status'] ?? '');

    // Get filter values
    $filterName = strtolower(trim($_GET['document_name'] ?? ''));
    $filterType = strtolower(trim($_GET['type'] ?? ''));
    $filterUploader = strtolower(trim($_GET['uploaded_by'] ?? ''));
    $filterStatus = strtolower(trim($_GET['status'] ?? ''));

    // Document Title filter
    if ($filterName && strpos($docName, $filterName) === false) return false;
    // Type filter
    if ($filterType && strpos($type, $filterType) === false) return false;
    // Uploaded By filter
    if ($filterUploader && strpos($uploadedBy, $filterUploader) === false) return false;
    // Status filter (Active/Approved/Rejected/Noted)
    if ($filterStatus && strtolower(trim($doc['display_status'] ?? '')) !== $filterStatus) return false;
    return true;
});
$sortedDocs = array_values($filteredDocs);
usort($sortedDocs, function ($a, $b) {
    // Get most relevant date for each document
    $dateA = strtotime($a['updated_at'] ?? $a['in_at'] ?? '');
    $dateB = strtotime($b['updated_at'] ?? $b['in_at'] ?? '');

    // Sort descending (newest first)
    return $dateB <=> $dateA;
});

header('Content-Type: application/json');
echo json_encode($sortedDocs);
// Removed HTML output after JSON response. Only JSON is returned for AJAX.
