<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';

$user_id = SessionManager::get('user')['id'] ?? 0;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 5;
$search = trim($_GET['search'] ?? '');

$offset = ($page - 1) * $perPage;

$where = "user_id = ? AND status = 'draft'";
$params = [$user_id];
$types = "i";

if ($search !== '') {
    $where .= " AND subject LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM tbl_documents WHERE $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$drafts = $result->fetch_all(MYSQLI_ASSOC);

$totalRes = $conn->query("SELECT FOUND_ROWS() as total")->fetch_assoc();
$total = $totalRes['total'] ?? 0;

echo json_encode([
    'drafts' => $drafts,
    'total' => intval($total),
    'page' => $page,
    'perPage' => $perPage
]);
