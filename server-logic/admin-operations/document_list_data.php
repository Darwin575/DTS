<?php
require_once __DIR__ . '/../config/db.php';

// Pagination setup
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;

// Get search filter
$search = strtolower(trim($_GET['search'] ?? ''));

// 1. Fetch all active or archived documents
$sql = "SELECT d.*, 
            GROUP_CONCAT(a.action SEPARATOR ' / ') AS actions,
            u.office_name AS uploader_office
        FROM tbl_documents d
        LEFT JOIN tbl_document_actions a ON d.document_id = a.document_id
        LEFT JOIN tbl_users u ON d.user_id = u.user_id
        WHERE d.status IN ('active','archived')
        GROUP BY d.document_id
        ORDER BY d.updated_at DESC";
$res = $conn->query($sql);

$docs = [];
while ($row = $res->fetch_assoc()) {
    $docs[$row['document_id']] = $row;
}

// 2. For each document, compute completion %
foreach ($docs as &$doc) {
    $docId = $doc['document_id'];
    // Use the same logic as document_view.php: get the latest route for each to_user_id
    $routes = [];
    $routeRes = $conn->query("SELECT t.to_user_id, t.route_id, t.status
        FROM tbl_document_routes t
        INNER JOIN (
            SELECT to_user_id, MAX(route_id) AS max_route_id
            FROM tbl_document_routes
            WHERE document_id = $docId
            GROUP BY to_user_id
        ) grp
        ON t.to_user_id = grp.to_user_id AND t.route_id = grp.max_route_id
        WHERE t.document_id = $docId
        ORDER BY t.route_id ASC");
    while ($r = $routeRes->fetch_assoc()) {
        $routes[] = $r;
    }
    $total = count($routes);
    $completed = 0;
    foreach ($routes as $route) {
        if ($route['status'] === 'completed') {
            $completed++;
        } else {
            break; // Only count consecutive completed from the start
        }
    }
    $doc['completion'] = $total ? round(($completed / $total) * 100) : 0;

    // Compute status badge logic
    $final_status = strtolower(trim($doc['final_status'] ?? ''));
    if ($final_status === 'noted' || $final_status === 'approved') {
        $doc['computed_status'] = ucfirst($final_status);
    } else {
        // Get latest route with out_at not null for this document
        $routeRes2 = $conn->query("SELECT status FROM tbl_document_routes WHERE document_id = $docId AND out_at IS NOT NULL AND out_at != '' ORDER BY route_id DESC LIMIT 1");
        $route2 = $routeRes2 ? $routeRes2->fetch_assoc() : null;
        if ($route2) {
            $route_status = strtolower($route2['status']);
            if ($route_status === 'rejected') {
                $doc['computed_status'] = 'Rejected';
            } elseif ($route_status === 'completed' || $route_status === 'pending') {
                $doc['computed_status'] = 'Active';
            } else {
                $doc['computed_status'] = ucfirst($route_status);
            }
        } else {
            $doc['computed_status'] = ucfirst($doc['status'] ?? '');
        }
    }
}

// 3. Filter by search input
$filtered = array_filter($docs, function ($doc) use ($search) {
    if (!$search) return true;
    $haystack = strtolower(
        ($doc['subject'] ?? '') . ' ' .
            ($doc['actions'] ?? '') . ' ' .
            ($doc['uploader_office'] ?? '') . ' ' .
            ($doc['status'] ?? '') . ' ' .
            ($doc['computed_status'] ?? '')
    );
    return strpos($haystack, $search) !== false;
});

$totalRows = count($filtered);
$totalPages = max(1, ceil($totalRows / $perPage));
$filtered = array_slice($filtered, ($page - 1) * $perPage, $perPage);


// 4. Output table rows
foreach ($filtered as $doc):
    $status = $doc['computed_status'] ?? ucfirst($doc['status']);
    $statusClass = ($status === 'Approved') ? 'label-success'
        : (($status === 'Noted') ? 'label-success'
            : (($status === 'Disapproved' || $status === 'Rejected') ? 'label-danger'
            : (($status === 'Archived') ? 'label-warning'
            : (($status === 'Active') ? 'label-primary' : 'label-primary'))));
    $actions = $doc['actions'] ?: '-';
    $uploader = $doc['uploader_office'] ?: '-';
    $completion = $doc['completion'];
    $updatedAt = $doc['updated_at'] ? date('M d, Y H:i', strtotime($doc['updated_at'])) : '';

?>
    <tr>
        <!-- Status Column -->
        <td class="align-middle pe-2">
            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
        </td>

        <!-- Subject + Created Column (less strict truncation) -->
        <td class="align-middle">
            <div class="d-flex flex-column">
                <div class="fw-bold mobile-expand"
                    data-fulltext="<?= htmlspecialchars($doc['subject']) ?>">
                    <?=
                    strlen($doc['subject']) > 50
                        ? htmlspecialchars(substr($doc['subject'], 0, 47)) . '...'
                        : htmlspecialchars($doc['subject'])
                    ?>
                </div>
                <small class="text-muted"><?= htmlspecialchars($updatedAt) ?></small>
            </div>
        </td>

        <!-- Actions Column (strict truncation) -->
        <td class="align-middle small mobile-expand"
            data-fulltext="<?= htmlspecialchars($actions) ?>">
            <?=
            strlen($actions) > 30
                ? htmlspecialchars(substr($actions, 0, 27)) . '...'
                : htmlspecialchars($actions)
            ?>
        </td>

        <!-- Uploader Column -->
        <td class="align-middle small text-truncate" style="max-width: 120px;">
            <?= htmlspecialchars($uploader) ?>
        </td>

        <!-- Completion Column -->
        <td class="align-middle">
            <small><?= $completion ?>%</small>
            <div class="progress progress-mini mt-1">
                <div style="width: <?= $completion ?>%;" class="progress-bar"></div>
            </div>
        </td>

        <!-- Action Column -->
        <td class="align-middle">
            <a href="document_view.php?doc_id=<?= urlencode($doc['document_id']) ?>"
                class="btn btn-sm btn-outline-primary"
                title="View document <?= htmlspecialchars($doc['document_id']) ?>">
                View
            </a>
        </td>
    </tr>
<?php
endforeach;

// Output pagination as JSON if AJAX request (only ONCE, after all rows)
if (isset($_GET['ajax'])) {
    echo '||PAGINATION||' . json_encode([
        'page' => $page,
        'totalPages' => $totalPages
    ]);
}
