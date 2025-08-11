<?php
// Helper to fetch document core data
function get_document_by_id($conn, $doc_id)
{
    $stmt = $conn->prepare("
        SELECT d.*, u.office_name AS sender_office
        FROM tbl_documents d 
        JOIN tbl_users u ON d.user_id = u.user_id 
        WHERE d.document_id = ? AND d.status = 'active'
    ");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function hasRoutingOrEsig($conn, $doc_id, $user_id): bool
{
    $isIt = isInSystem($conn, $doc_id);
    // Prepare the query to get the latest matching row by ordering descending.
    $sql = "
        SELECT routing_sheet_path, esig_path
        FROM tbl_document_routes
        WHERE to_user_id = ? 
          AND document_id = ?
        ORDER BY route_id DESC
        LIMIT 1;
    ";
    if (! $stmt = $conn->prepare($sql)) {
        // handle error if needed
        return false;
    }

    // Bind params and execute.
    $stmt->bind_param('ii', $user_id, $doc_id);
    $stmt->execute();
    $routingPath = null;
    $esigPath    = null;
    $stmt->bind_result($routingPath, $esigPath);

    // Fetch the single row (if any)
    if ($stmt->fetch()) {
        $stmt->close();
        // Check that at least one of them is not null/empty
        if (
            (!is_null($routingPath) && trim($routingPath) !== '') ||
            (!is_null($esigPath)     && trim($esigPath) !== '') ||
            $isIt <= 0
        ) {
            return true;
        }
    }

    return false;
}


function isInSystem($conn, $document_id)
{
    $stmt = $conn->prepare(
        "SELECT routing_sheet_path, esig_path
    FROM tbl_documents
    WHERE document_id = ?"
    );
    $stmt->bind_param('i', $document_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    // empty() treats NULL and '' (empty string) both as “empty”
    if (empty($row['routing_sheet_path']) && empty($row['esig_path'])) {
        // both are either NULL or ''
        return 0;
    } elseif (!empty($row['routing_sheet_path'])) {
        return 1;
    } elseif (!empty($row['esig_path'])) {
        return 2;
    } else {
        return 0; // added default return
    }
}


// Helper to fetch if this user has a route in tbl_document_routes
function get_user_document_route($conn, $doc_id, $user_id)
{
    $stmt = $conn->prepare("
        SELECT * FROM tbl_document_routes
        WHERE document_id = ? AND to_user_id = ? 
        AND (status = 'pending' AND in_at IS NOT NULL)
        LIMIT 1
    ");
    $stmt->bind_param("ii", $doc_id, $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Helper for remarks (include name and office)
function get_document_remarks($conn, $doc_id)
{
    $stmt = $conn->prepare("
        SELECT r.comments, r.recipient_name, u.office_name
        FROM tbl_document_routes r
        LEFT JOIN tbl_users u ON r.to_user_id = u.user_id
        WHERE r.document_id = ? AND r.comments IS NOT NULL AND TRIM(r.comments) != ''
        ORDER BY r.in_at ASC
    ");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_document_actions($conn, $doc_id)
{
    $stmt = $conn->prepare("SELECT action FROM tbl_document_actions WHERE document_id = ?");
    $stmt->bind_param("i", $doc_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get all pending route rows for a document—pending means status is 'pending' and not completed/rejected/noted.
 * @param mysqli $conn
 * @param int $document_id
 * @return array
 */
function get_pending_document_routes($conn, $document_id)
{
    $stmt = $conn->prepare("SELECT * FROM tbl_document_routes WHERE document_id = ? AND status = 'pending' ORDER BY route_id ASC");
    $stmt->bind_param('i', $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}
function formatNameWithOffice($conn, $providedName, $user_id)
{
    $stmt = $conn->prepare("SELECT office_name FROM tbl_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $office = isset($row['office_name']) ? trim($row['office_name']) : '';
    $providedName = trim($providedName);
    if ($providedName !== '') {
        return $providedName . ' (' . $office . ')';
    } else {
        return $office;
    }
}

/**
 * Fallback function.
 * If no valid routing_sheet_path is found via the normal logic, this function
 * checks tbl_documents for an esig_path and returns a URL pointing to hala.php.
 */
function fallbackRoutingSheetPath($conn, $doc_id)
{
    $stmt3 = $conn->prepare("
        SELECT esig_path 
        FROM tbl_documents 
        WHERE document_id = ? 
          AND esig_path IS NOT NULL 
          AND esig_path != '' 
        LIMIT 1
    ");
    $stmt3->bind_param("i", $doc_id);
    $stmt3->execute();
    $row3 = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();
    if ($row3) {
        return [
            'routing_sheet_path' => '/DTS/pdf/hala.php?document_id=' . $doc_id,
            'creator'            => '',
            'chain'              => []
        ];
    }
    return null;
}
// Routing Sheet Path Resolver: Returns the most recent existing one, else fallback
function find_all_routing_sheet_paths_chain($conn, $doc_id, $user_id)
{
    error_log("[ROUTE_SHEET] --- find_all_routing_sheet_paths_chain called for doc_id=$doc_id, user_id=$user_id ---");
    // Step 1: Try to find a routing sheet uploaded by the current user
    $stmt = $conn->prepare(
        "SELECT routing_sheet_path 
         FROM tbl_documents 
         WHERE document_id = ? 
           AND user_id = ? 
           AND routing_sheet_path IS NOT NULL 
           AND routing_sheet_path != '' 
         LIMIT 1"
    );
    $stmt->bind_param("ii", $doc_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $fullPath = __DIR__ . '/../../' . $row['routing_sheet_path'];
        error_log("[ROUTE_SHEET] User($user_id) routing_sheet_path: " . $row['routing_sheet_path']);
        error_log("[ROUTE_SHEET] User($user_id) routing_sheet_path exists: " . (file_exists($fullPath) ? 'YES' : 'NO') . " (" . $fullPath . ")");
        if (file_exists($fullPath)) {
            $stmt->close();
            error_log("[ROUTE_SHEET] Returning user's own routing sheet.");
            return [
                'routing_paths' => [[
                    'route_id' => null,
                    'routing_sheet_path' => '/DTS/' . ltrim($row['routing_sheet_path'], '/'),
                    'creator' => formatNameWithOffice($conn, '', $user_id)
                ]]
            ];
        }
    }
    $stmt->close();

    // Step 2: Get all routes for this document, ordered by route_id ASC
    $stmtRoutes = $conn->prepare("
        SELECT * 
        FROM tbl_document_routes 
        WHERE document_id = ? 
        ORDER BY route_id ASC
    ");
    $stmtRoutes->bind_param("i", $doc_id);
    $stmtRoutes->execute();
    $routesResult = $stmtRoutes->get_result();
    $routes = [];
    while ($row = $routesResult->fetch_assoc()) {
        $routes[] = $row;
    }
    $stmtRoutes->close();
    error_log("[ROUTE_SHEET] Total routes found: " . count($routes));
    if (empty($routes)) {
        error_log("[ROUTE_SHEET] No routes found. Using fallback.");
        $fallback = fallbackRoutingSheetPath($conn, $doc_id);
        return $fallback ? $fallback : null;
    }

    // Step 3: Find the target route (first route with to_user_id == $user_id)
    $targetRoute = null;
    foreach ($routes as $r) {
        if ($r['to_user_id'] == $user_id) {
            $targetRoute = $r;
            break;
        }
    }
    if (!$targetRoute) {
        error_log("[ROUTE_SHEET] No target route for user $user_id. Using fallback.");
        $fallback = fallbackRoutingSheetPath($conn, $doc_id);
        return $fallback ? $fallback : null;
    }
    $target_route_id = (int)$targetRoute['route_id'];
    error_log("[ROUTE_SHEET] Target route_id for user $user_id: $target_route_id");

    // Step 4: Look for the most recent routing sheet uploaded by any previous user in the route chain
    $previous_routes = array_filter($routes, function($r) use ($target_route_id) {
        return (int)$r['route_id'] < $target_route_id;
    });
    usort($previous_routes, function($a, $b) {
        return (int)$b['route_id'] - (int)$a['route_id'];
    });
    error_log("[ROUTE_SHEET] Previous routes to check: " . count($previous_routes));
    foreach ($previous_routes as $route) {
        $from_user = $route['from_user_id'];
        $stmtDoc = $conn->prepare("
            SELECT routing_sheet_path, creator_name 
            FROM tbl_documents 
            WHERE document_id = ? 
              AND user_id = ? 
              AND routing_sheet_path IS NOT NULL 
              AND routing_sheet_path != '' 
            LIMIT 1
        ");
        $stmtDoc->bind_param("ii", $doc_id, $from_user);
        $stmtDoc->execute();
        $docRow = $stmtDoc->get_result()->fetch_assoc();
        $stmtDoc->close();
        error_log("[ROUTE_SHEET] Previous user($from_user) routing_sheet_path: " . ($docRow['routing_sheet_path'] ?? 'NONE'));
        if ($docRow) {
            $fullPath = __DIR__ . '/../../' . $docRow['routing_sheet_path'];
            error_log("[ROUTE_SHEET] Previous user($from_user) routing_sheet_path exists: " . (file_exists($fullPath) ? 'YES' : 'NO') . " (" . $fullPath . ")");
            if (file_exists($fullPath)) {
                error_log("[ROUTE_SHEET] Returning previous user's routing sheet (user_id=$from_user, route_id=" . $route['route_id'] . ")");
                return [
                    'routing_paths' => [[
                        'route_id' => $route['route_id'],
                        'routing_sheet_path' => '/DTS/' . ltrim($docRow['routing_sheet_path'], '/'),
                        'creator' => formatNameWithOffice($conn, $docRow['creator_name'], $from_user)
                    ]]
                ];
            }
        }
    }

    // Step 4b: If there are no previous routes, check the document creator's routing sheet
    if (empty($previous_routes)) {
        $stmtCreator = $conn->prepare("SELECT user_id, routing_sheet_path, creator_name FROM tbl_documents WHERE document_id = ? LIMIT 1");
        $stmtCreator->bind_param("i", $doc_id);
        $stmtCreator->execute();
        $creatorRow = $stmtCreator->get_result()->fetch_assoc();
        $stmtCreator->close();
        if ($creatorRow && !empty($creatorRow['routing_sheet_path'])) {
            $fullPath = __DIR__ . '/../../' . $creatorRow['routing_sheet_path'];
            error_log('[ROUTE_SHEET] Creator user(' . $creatorRow['user_id'] . ') routing_sheet_path: ' . $creatorRow['routing_sheet_path']);
            error_log('[ROUTE_SHEET] Creator routing_sheet_path exists: ' . (file_exists($fullPath) ? 'YES' : 'NO') . ' (' . $fullPath . ')');
            if (file_exists($fullPath)) {
                error_log('[ROUTE_SHEET] Returning creator\'s routing sheet.');
                return [
                    'routing_paths' => [[
                        'route_id' => null,
                        'routing_sheet_path' => '/DTS/' . ltrim($creatorRow['routing_sheet_path'], '/'),
                        'creator' => formatNameWithOffice($conn, $creatorRow['creator_name'], $creatorRow['user_id'])
                    ]]
                ];
            }
        }
    }

    // Step 5: If still not found, fallback
    error_log("[ROUTE_SHEET] No valid routing sheet found for user $user_id, previous users, or creator. Using fallback.");
    $fallback = fallbackRoutingSheetPath($conn, $doc_id);
    return $fallback ? $fallback : null;
}
