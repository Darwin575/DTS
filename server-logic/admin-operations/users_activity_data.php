<?php
include '../config/db.php';
header('Content-Type: application/json');
global $conn;
// Helper functions
function formatFriendlyDate($timestamp)
{
    $dt = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($dt);

    if ($diff->d === 0) {
        return 'Today at ' . $dt->format('h:i A');
    } elseif ($diff->d === 1) {
        return 'Yesterday at ' . $dt->format('h:i A');
    }
    return $dt->format('M j, Y \a\t h:i A');
}

function getFilterCondition(&$params, $selectedUserId, $selectedOffice)
{
    $conditions = [];
    if ($selectedUserId) {
        $conditions[] = 'u.user_id = ?';
        $params[] = $selectedUserId;
    }
    if ($selectedOffice) {
        $conditions[] = 'u.office_name = ?';
        $params[] = $selectedOffice;
    }
    return $conditions ? ' AND ' . implode(' AND ', $conditions) : '';
}

// Handle different actions
$action = $_GET['action'] ?? 'activities';
$userId = $_GET['user_id'] ?? null;
$office = $_GET['office'] ?? null;

try {
    $conn->begin_transaction();

    if ($action === 'profile') {
        // Get user profile
        $stmt = $conn->prepare("
            SELECT user_id, name, email, office_name, profile_picture_path 
            FROM tbl_users 
            WHERE user_id = ?
        ");
        $stmt->bind_param('i', $_GET['id']);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc());
        exit;
    }

    // Get activities
    $feed = [];
    $params = [];
    $whereClause = getFilterCondition($params, $userId, $office);

    // 1. User Activity Logs
    $sql = "SELECT l.*, u.name, u.office_name 
            FROM tbl_user_activity_logs l
            JOIN tbl_users u ON l.user_id = u.user_id
            WHERE 1=1 $whereClause";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    while ($row = $stmt->get_result()->fetch_assoc()) {
        // Activity processing logic
    }

    // 2. Document Creations
    $sql = "SELECT d.*, u.office_name 
            FROM tbl_documents d
            JOIN tbl_users u ON d.user_id = u.user_id
            WHERE 1=1 $whereClause";
    // Similar processing...

    // 3. Routing Actions
    $sql = "SELECT r.*, d.subject, u.office_name 
            FROM tbl_document_routes r
            JOIN tbl_documents d ON r.document_id = d.document_id
            JOIN tbl_users u ON r.to_user_id = u.user_id
            WHERE 1=1 $whereClause";
    // Similar processing...

    // 4. Document Access Logs
    $sql = "SELECT a.*, d.subject, u.office_name 
            FROM tbl_document_access a
            JOIN tbl_documents d ON a.document_id = d.document_id
            JOIN tbl_users u ON d.user_id = u.user_id
            WHERE 1=1 $whereClause";
    // Similar processing...

    // Sort and format
    usort($feed, function ($a, $b) {
        return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
    });

    // Format final output
    $output = array_map(function ($item) {
        return [
            'message' => $item['message'],
            'badge' => $item['badge'],
            'formatted_date' => formatFriendlyDate($item['timestamp'])
        ];
    }, $feed);

    echo json_encode($output);
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
