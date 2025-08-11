<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_init.php';

$period = $_GET['period'] ?? 'daily';
$office = $_GET['office'] ?? null;

// Get current summary date
function getCurrentSummaryDate($period)
{
    $today = new DateTime();
    switch ($period) {
        case 'daily':
            return $today->format('Y-m-d');
        case 'weekly':
            $start = (clone $today)->modify('monday this week');
            return $start->format('Y-m-d') . '_to_' . $start->modify('sunday this week')->format('Y-m-d');
        case 'monthly':
            return (clone $today)->modify('first day of this month')->format('Y-m') . '_monthly';
        case 'yearly':
            return $today->format('Y') . '_yearly';
        default:
            return $today->format('Y-m-d');
    }
}

$currentDate = getCurrentSummaryDate($period);

// Distribution Data
$query = $office ?
    "SELECT 
        COALESCE(SUM(on_route_documents), 0) as on_route,
        COALESCE(SUM(completed_documents), 0) as completed
     FROM tbl_document_summary ds
     JOIN tbl_users u ON ds.user_id = u.user_id
     WHERE ds.summary_type = ? 
     AND ds.summary_date = ?
     AND u.office_name = ?" :

    "SELECT 
        COALESCE(SUM(on_route_documents), 0) as on_route,
        COALESCE(SUM(completed_documents), 0) as completed
     FROM tbl_document_summary ds
     WHERE ds.summary_type = ?
     AND ds.summary_date = ?";

$stmt = $conn->prepare($query);
if ($office) {
    $stmt->bind_param("sss", $period, $currentDate, $office);
} else {
    $stmt->bind_param("ss", $period, $currentDate);
}
$stmt->execute();
$distributionData = $stmt->get_result()->fetch_assoc();

// Recent Activities
$query = $office
    ? "
      SELECT 
          ds.summary_date,
          ds.summary_type,
          u.name             AS user_name,
          u.office_name,
          ds.on_route_documents,
          ds.completed_documents
      FROM tbl_document_summary ds
      JOIN tbl_users u 
        ON ds.user_id = u.user_id
      WHERE 
        ds.summary_type = ?
        AND ds.summary_date = ?
        AND u.office_name = ?
        AND u.role != 'admin'
      ORDER BY ds.summary_date DESC
      LIMIT 5
    "
    : "
      SELECT 
          ds.summary_date,
          ds.summary_type,
          u.name             AS user_name,
          u.office_name,
          ds.on_route_documents,
          ds.completed_documents
      FROM tbl_document_summary ds
      JOIN tbl_users u 
        ON ds.user_id = u.user_id
      WHERE 
        ds.summary_type = ?
        AND ds.summary_date = ?
        AND u.role != 'admin'
      ORDER BY ds.summary_date DESC
      LIMIT 5
    ";

$stmt = $conn->prepare($query);
if ($office) {
    $stmt->bind_param("sss", $period, $currentDate, $office);
} else {
    $stmt->bind_param("ss", $period, $currentDate);
}
$stmt->execute();
$recentActivities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'distributionData' => $distributionData ?: ['on_route' => 0, 'completed' => 0],
    'recentActivities' => $recentActivities ?: []
]);
