<?php
include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../config/session_init.php';
date_default_timezone_set('Asia/Manila');

global $conn;
header('Content-Type: application/json');

$user = SessionManager::get('user');
$userId = $user['id'] ?? 0;
if (!$userId) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
function calculateTimeAgo($dateTime)
{
    $past = new DateTime($dateTime);
    $now = new DateTime();
    $diff = $now->diff($past);

    if ($diff->d > 0) {
        return $diff->d . 'd';
    } elseif ($diff->h > 0) {
        return $diff->h . 'h';
    } elseif ($diff->i > 0) {
        return $diff->i . 'm';
    } elseif ($diff->s > 0) {
        return $diff->s . 's';
    } else {
        return 'Just now';
    }
}
// Helper function: Get office name from tbl_users by user_id
function get_office_name($conn, $user_id)
{
    $stmt = $conn->prepare("SELECT office_name FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['office_name'] ?? 'Unknown Office';
}

// ========= 1. URGENT DOCUMENTS =========
// Get pending routes (status 'pending', in_at is not null, AND out_at is NULL)
$stmt = $conn->prepare("
    SELECT d.document_id, d.subject, d.urgency, r.route_id, r.from_user_id, r.in_at 
    FROM tbl_document_routes r
    JOIN tbl_documents d ON r.document_id = d.document_id
    WHERE r.to_user_id = ? 
      AND r.status = 'pending' 
      AND r.in_at IS NOT NULL
      AND r.out_at IS NULL
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$urgent_docs = [];  // Will group documents by urgency (high, medium, low)
while ($row = $result->fetch_assoc()) {
    // Calculate time ago for received (arrived) documents based on in_at
    $timeAgo = "unknown";
    if (!empty($row['in_at'])) {
        $inAtTime = new DateTime($row['in_at']);
        $now = new DateTime();
        $diff = $now->diff($inAtTime);
        if ($diff->d >= 1) {
            $timeAgo = $diff->d . "d";
        } elseif ($diff->h >= 1) {
            $timeAgo = $diff->h . "h";
        } elseif ($diff->i >= 1) {
            $timeAgo = $diff->i . "m";
        } else {
            $timeAgo = "Just now";
        }
    }
    $row['from_office'] = get_office_name($conn, $row['from_user_id']);
    $urgency = strtolower($row['urgency']);
    $row['timeAgo'] = $timeAgo;  // Add computed time difference
    // For arrived documents, we have in_at available (and no custom flag).
    $urgent_docs[$urgency][] = $row;
}

// ========= 2. REJECTED ROUTES =========

// ========= 2. REJECTED ROUTES =========

$stmt = $conn->prepare("
    SELECT *
    FROM tbl_document_routes
    WHERE from_user_id = ?
      AND (status = 'rejected' OR status = 'pending')
    ORDER BY route_id DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$groups = [];
// Group rows based on the composite key: document_id and to_user_id.
foreach ($rows as $row) {
    $key = $row['document_id'] . '_' . $row['to_user_id'];
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    // Since ordered DESC (latest first), the first row is the latest.
    $groups[$key][] = $row;
}

// Process each group and collect qualifying routes based on new rules.
// This array will now hold the *routes* that meet your criteria for display,
// along with the correct timestamp to use for 'timeAgo'.
$qualifyingRoutes = []; // Renamed from $qualifyingRejected for clarity internally
foreach ($groups as $group) {
    if (count($group) === 0) {
        continue;
    }

    $latest_route = $group[0]; // The most recent route for this document_id/to_user_id pair

    // Rule 1: Latest route is 'rejected'
    if (strtolower(trim($latest_route['status'])) === 'rejected') {
        $qualifyingRoutes[] = [
            'route_data' => $latest_route,
            'time_basis' => $latest_route['out_at'] // Use its own out_at for 'timeAgo'
        ];
    }
    // Rule 2: Latest route is 'pending' AND its 'in_at' is NULL, AND there's a previous 'rejected' route
    elseif (strtolower(trim($latest_route['status'])) === 'pending' && is_null($latest_route['in_at'])) {
        if (count($group) > 1) {
            $previous_route = $group[1]; // The second most recent route for this pair
            if (strtolower(trim($previous_route['status'])) === 'rejected') {
                $qualifyingRoutes[] = [
                    'route_data' => $latest_route, // Still pass the pending route's data
                    'time_basis' => $previous_route['out_at'] // Time ago based on the *previous rejection's* out_at
                ];
            }
        }
    }
}


// Process each qualifying route.
// This loop remains very close to your original, simply using the info from $qualifyingRoutes.
foreach ($qualifyingRoutes as $qualified_item) {
    $rejected_route_data = $qualified_item['route_data']; // This is the route data (either the rejected or the pending one)
    $time_basis_for_ago_calc = $qualified_item['time_basis']; // The specific timestamp to use for 'timeAgo'

    $docId    = $rejected_route_data['document_id'];
    $toUserId = $rejected_route_data['to_user_id'];
    $inAt     = $rejected_route_data['in_at'];
    $outAt    = $rejected_route_data['out_at']; // This will be null for the pending_after_rejected case in $rejected_route_data

    // Calculate a "time ago" string based on the correct timestamp.
    $timeAgo = "unknown";
    if (!empty($time_basis_for_ago_calc)) {
        $timeAgo = calculateTimeAgo($time_basis_for_ago_calc);
    }


    // Fetch document details (only if active)
    $stmt_doc = $conn->prepare("
        SELECT document_id, subject, urgency, status
        FROM tbl_documents
        WHERE document_id = ? AND status = 'active'
    ");
    $stmt_doc->bind_param("i", $docId);
    $stmt_doc->execute();
    $result_doc = $stmt_doc->get_result();

    if ($doc = $result_doc->fetch_assoc()) {
        $urgency = strtolower($doc['urgency']);
        $urgent_docs[$urgency][] = [
            'document_id'     => $doc['document_id'],
            'subject'         => $doc['subject'],
            'custom_rejected' => true, // This flag will now be true for both original rejected and the new pending case
            'timeAgo'         => $timeAgo,
            'out_at'          => $outAt, // This is the out_at of the current route itself, not the timeAgo basis
            'to_user_id'      => $toUserId,
            'in_at'           => $inAt, // This is the in_at of the current route itself
            'route_id'        => $rejected_route_data['route_id'] // The route_id of the current route being processed
        ];
    }
}
// ========= 3. NOTIFICATIONS =========
$notifications = [];
// Comments notifications query using only tbl_document_routes (alias r) and tbl_documents.
$stmt = $conn->prepare("
    SELECT DISTINCT
        r.route_id,
        TRIM(r.comments) AS comments,
        d.subject,
        r.document_id,
        r.out_at
    FROM
        tbl_document_routes r
    JOIN
        tbl_documents d ON r.document_id = d.document_id
    WHERE
        -- Only consider comment rows that were marked with my user ID
        r.from_user_id = ?
        -- Only include documents owned by me (i.e. documents that I received)
        AND d.user_id = ?
        -- Drop rows with NULL or blank comments 
        AND r.comments IS NOT NULL
        AND TRIM(r.comments) <> ''
        -- Limit notifications to those in the last 7 days
        AND r.out_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)
");
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Calculate human-readable "time ago" for out_at.
    $outAt = new DateTime($row['out_at']);
    $now   = new DateTime();
    $interval = $now->diff($outAt);

    if ($interval->d >= 1) {
        $timeAgo = $interval->d . "d";
    } elseif ($interval->h >= 1) {
        $timeAgo = $interval->h . "h";
    } elseif ($interval->i >= 1) {
        $timeAgo = $interval->i . "m";
    } else {
        $timeAgo = "Just now";
    }

    // Prepare a short preview of the comment
    $commentPreview = (strlen($row['comments']) > 50)
        ? substr($row['comments'], 0, 50) . "..."
        : $row['comments'];

    // Add the notification message.
    $notifications[] = [
        'type'        => 'comment',
        'message'     => "New comment on '{$row['subject']}': {$commentPreview} <small class='text-muted'>($timeAgo ago)</small>",
        'document_id' => $row['document_id'],
        'time'        => $row['out_at']
    ];
}




// (b) Status notifications
$stmt = $conn->prepare("
    SELECT 
        r.document_id, 
        d.subject,
        r.out_at
    FROM 
        tbl_document_routes   AS r
    JOIN 
        tbl_documents         AS d 
      ON r.document_id = d.document_id
    WHERE 
        -- Find the VERY FIRST completed-route for this doc
        r.route_id > (
            SELECT route_id 
            FROM tbl_document_routes
            WHERE (to_user_id   = ?    -- docs you received
                OR  from_user_id = ?)   -- docs you created
              AND document_id = r.document_id
              AND status      = 'completed'
            ORDER BY route_id
            LIMIT 1
        )
        AND r.status       = 'completed'
        AND d.final_status IS NULL
        AND r.out_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)
");
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $outAt = new DateTime($row['out_at']);
    $now = new DateTime();
    $diff = $now->diff($outAt);
    if ($diff->d >= 1) {
        $timeAgo = $diff->d . 'd';
    } elseif ($diff->h >= 1) {
        $timeAgo = $diff->h . 'h';
    } elseif ($diff->i >= 1) {
        $timeAgo = $diff->i . 'm';
    } else {
        $timeAgo = 'just now';
    }
    $notifications[] = [
        'type'        => 'status',
        'message'     => "Document '{$row['subject']}' has been approved <small class='text-muted'>($timeAgo ago)</small>",
        'document_id' => $row['document_id'],
        'time'        => $row['out_at']
    ];
}
// (c) Final decision notifications
$stmt = $conn->prepare("
    SELECT 
        d.document_id, 
        d.final_status, 
        d.subject,
        (
            SELECT out_at 
            FROM tbl_document_routes 
            WHERE document_id = d.document_id 
            ORDER BY out_at DESC 
            LIMIT 1
        ) AS latest_out_at
    FROM tbl_documents d
    WHERE EXISTS (
        SELECT 1 
        FROM tbl_document_routes r
        WHERE r.document_id = d.document_id
          AND (r.to_user_id   = ?
               OR r.from_user_id = ?)
    )
      AND d.final_status IS NOT NULL
      AND (
          SELECT out_at 
          FROM tbl_document_routes 
          WHERE document_id = d.document_id 
          ORDER BY out_at DESC 
          LIMIT 1
      ) >= DATE_SUB(NOW(), INTERVAL 1 WEEK)
");
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $latestOutAt = new DateTime($row['latest_out_at']);
    $now = new DateTime();
    $diff = $now->diff($latestOutAt);
    if ($diff->days >= 1) {
        $timeAgo = $diff->days . 'd';
    } elseif ($diff->h >= 1) {
        $timeAgo = $diff->h . 'h';
    } elseif ($diff->i >= 1) {
        $timeAgo = $diff->i . 'm';
    } else {
        $timeAgo = 'Just now';
    }
    $notifications[] = [
        'type'        => 'final',
        'message'     => "Document '{$row['subject']}' has been " . ucfirst($row['final_status']) . " <small class='text-muted'>($timeAgo ago)</small>",
        'document_id' => $row['document_id'],
        'time'        => $row['latest_out_at']
    ];
}

// ---------- Render HTML for urgent documents (detailed grid view) ----------
$urgent_html = '';
$urgency_levels = [
    'high'   => ['color' => 'danger', 'title' => 'High'],
    'medium' => ['color' => 'warning', 'title' => 'Medium'],
    'low'    => ['color' => 'primary', 'title' => 'Low']
];
foreach ($urgency_levels as $level => $info) {
    $urgent_html .= '<div class="col-lg-4">
      <div class="ibox">
        <div class="ibox-title bg-' . $info['color'] . ' text-white">
          <h5>Urgency Level: ' . $info['title'] . '</h5>
        </div>
        <div class="ibox-content overflow-auto" style="max-height: 250px;">';
    if (!empty($urgent_docs[$level])) {
        foreach ($urgent_docs[$level] as $doc) {
            if (!empty($doc['custom_rejected'])) {
                $timeAgoText = isset($doc['timeAgo']) ? "<small class='text-muted'>(" . htmlspecialchars($doc['timeAgo']) . " ago)</small>" : '';
                $urgent_html .= '<div class="alert alert-' . $info['color'] . '">
                    <strong>Your document</strong> "' . htmlspecialchars($doc['subject']) . '" was rejected on the way. ' . $timeAgoText . '
                    <a href="document_view.php?rejected_doc_id=' . $doc['document_id'] . '" class="btn btn-xs btn-white view-details-btn">View</a>
                  </div>';
            } else {
                $timeAgoText = isset($doc['timeAgo']) ? "<small class='text-muted'>(" . htmlspecialchars($doc['timeAgo']) . " ago)</small>" : '';
                $urgent_html .= '<div class="alert alert-' . $info['color'] . '">
                    <strong>Incoming Document:</strong><br>
                    From: ' . htmlspecialchars($doc['from_office'] ?? 'Unknown') . '<br>
                    Subject: ' . htmlspecialchars($doc['subject']) . ' ' . $timeAgoText . '
                    <a href="receive_document.php?receive_doc_id=' . $doc['document_id'] . '" class="btn btn-xs btn-white view-details-btn">View</a>
                  </div>';
            }
        }
    } else {
        $urgent_html .= '<div class="alert alert-secondary">No documents in this urgency category</div>';
    }
    $urgent_html .= '</div></div></div>';
}

// ---------- Prepare Menu HTML for Navbar (summary items) ----------
// Split the urgent documents into arrived and rejected groups.
$arrived_docs_total = [];
$rejected_docs_total = [];
if (!empty($urgent_docs)) {
    foreach ($urgent_docs as $level => $docs) {
        foreach ($docs as $doc) {
            if (isset($doc['custom_rejected']) && $doc['custom_rejected'] === true) {
                $rejected_docs_total[] = $doc;
            } else {
                $arrived_docs_total[] = $doc;
            }
        }
    }
}

// Compute counts.
$countArrived = count($arrived_docs_total);
$countRejected = count($rejected_docs_total);
$countNotifications = count($notifications);
$total_count = $countArrived + $countRejected + $countNotifications;

// Helper function to get latest timestamp from an array field.
function getLatestTime($arr, $field)
{
    $latest = 0;
    foreach ($arr as $item) {
        if (!empty($item[$field])) {
            $t = strtotime($item[$field]);
            if ($t > $latest) {
                $latest = $t;
            }
        }
    }
    return $latest ? date("Y-m-d H:i:s", $latest) : null;
}
$latestArrivalTime = getLatestTime($arrived_docs_total, 'in_at');
$latestRejectTime = getLatestTime($rejected_docs_total, 'out_at');
$latestNotificationTime = getLatestTime($notifications, 'time');

// Build the menu HTML for the navbar.
$arrived_menu_html = '<div class="menu-item">' . $countArrived . ' document' . ($countArrived != 1 ? 's' : '') . ' arrived <small class="text-muted">(' . ($latestArrivalTime ? calculateTimeAgo($latestArrivalTime) : 'No Time') . ' ago)</small></div>';
$rejected_menu_html = '<div class="menu-item">' . $countRejected . ' document' . ($countRejected != 1 ? 's' : '') . ' rejected <small class="text-muted">(' . ($latestRejectTime ? calculateTimeAgo($latestRejectTime) : 'No Time') . ' ago)</small></div>';
$notification_menu_html = '<div class="menu-item">' . $countNotifications . ' notification' . ($countNotifications != 1 ? 's' : '') . ' <small class="text-muted">(' . ($latestNotificationTime ? calculateTimeAgo($latestNotificationTime) : 'No Time') . ' ago)</small></div>';

// ---------- Render HTML for notifications (detailed alerts) ----------
$notification_html_full = '';
if (!empty($notifications)) {
    foreach ($notifications as $notification) {
        $alertClass = 'secondary';
        switch ($notification['type']) {
            case 'comment':
                $alertClass = 'info';
                break;
            case 'status':
                $alertClass = 'warning';
                break;
            case 'final':
                $alertClass = 'primary';
                break;
            default:
                $alertClass = 'secondary';
        }
        $notification_html_full .= '<div class="alert alert-' . $alertClass . ' notification-alert">
            ' . $notification['message'] . '
            <a href="document_view.php?doc_id=' . $notification['document_id'] . '" class="btn btn-xs btn-white view-details-btn">View</a>
        </div>';
    }
} else {
    $notification_html_full .= '<div class="alert alert-secondary">No new notifications</div>';
}

// ---------- Output JSON with all required keys ----------
echo json_encode([
    'total_count'            => $total_count,
    'arrived_menu_html'      => $arrived_menu_html,
    'rejected_menu_html'     => $rejected_menu_html,
    'notification_menu_html' => $notification_menu_html,
    'urgent_html'            => $urgent_html,
    'notification_html'      => $notification_html_full
]);

$conn->close();
exit;
