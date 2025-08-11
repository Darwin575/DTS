<?php
// admin-notification.php

include_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../config/session_init.php';
date_default_timezone_set('Asia/Manila');
global $conn;
header('Content-Type: application/json');

// (Optionally, you may check for admin privileges here.)
$user = SessionManager::get('user');
// (For admin, we allow access regardless of a particular user id.)
if (!$user) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Use the same time-difference helper
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

// Helper to get office name by user_id (works for both from and to)
function get_office_name($conn, $user_id)
{
    $stmt = $conn->prepare("SELECT office_name FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['office_name'] ?? 'Unknown Office';
}

// -------------------------------------------------------------------------
// 1. URGENT DOCUMENTS (Pending Routes)
// -------------------------------------------------------------------------
// For admin, we fetch all pending routes (documents that are “arrived”)
// and that have been received (in_at not null) and not yet sent (out_at is null)
// and that occurred in the last 3 days.
$query = "
    SELECT d.document_id, d.subject, d.urgency, r.route_id, 
           r.from_user_id, r.to_user_id, r.in_at 
    FROM tbl_document_routes r
    JOIN tbl_documents d ON r.document_id = d.document_id
    WHERE r.status = 'pending'
      AND r.in_at IS NOT NULL
      AND r.out_at IS NULL
      AND r.in_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
";
$result = $conn->query($query);
$urgent_docs = [];  // Grouped by urgency (high, medium, low)
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Calculate elapsed time based on in_at.
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
        // Get both sending and receiving office names.
        $row['from_office'] = get_office_name($conn, $row['from_user_id']);
        $row['to_office']   = get_office_name($conn, $row['to_user_id']);
        $urgency = strtolower($row['urgency']);
        $row['timeAgo'] = $timeAgo;
        // Mark these as "arrived" (pending) documents.
        $urgent_docs[$urgency][] = $row;
    }
}

// -------------------------------------------------------------------------
// 2. REJECTED ROUTES
// -------------------------------------------------------------------------
// For admin, show all rejected routes in the last 3 days.
$query = "
    SELECT * 
    FROM tbl_document_routes 
    WHERE status = 'rejected'
      AND out_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
    ORDER BY route_id ASC
";
$result = $conn->query($query);

// Fetch all rejected rows into an array.
$rejectedRows = [];
while ($row = $result->fetch_assoc()) {
    $rejectedRows[] = $row;
}

// Filter the rejected rows based on the criteria:
// If the next row has the same document_id and to_user_id,
// include the current row only if its in_at is null.
$qualifyingRejected = [];
$count = count($rejectedRows);
for ($i = 0; $i < $count; $i++) {
    $current = $rejectedRows[$i];
    $includeRow = true;

    if (isset($rejectedRows[$i + 1])) {
        $next = $rejectedRows[$i + 1];
        if (
            $next['to_user_id'] === $current['to_user_id'] &&
            $next['document_id'] === $current['document_id']
        ) {
            // For rows with the same document_id and to_user_id,
            // only include the current row if its in_at is null.
            if (!is_null($current['in_at'])) {
                $includeRow = false;
            }
        }
    }

    if ($includeRow) {
        $qualifyingRejected[] = $current;
    }
}

// Process each qualifying rejected route.
foreach ($qualifyingRejected as $rejected) {
    $doc_id = $rejected['document_id'];
    $outAt  = $rejected['out_at'];

    // Compute a "time ago" string based on the out_at timestamp.
    $timeAgo = "unknown";
    if (!empty($outAt)) {
        $outAtTime = new DateTime($outAt);
        $now       = new DateTime();
        $diff      = $now->diff($outAtTime);
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

    // Get details from the documents table (only if the document is active).
    $stmt_doc = $conn->prepare("
        SELECT document_id, subject, urgency, status 
        FROM tbl_documents 
        WHERE document_id = ? AND status = 'active'
    ");
    $stmt_doc->bind_param("i", $doc_id);
    $stmt_doc->execute();
    $result_doc = $stmt_doc->get_result();

    if ($doc = $result_doc->fetch_assoc()) {
        $urgency = strtolower($doc['urgency']);
        // Build the record (marking it as custom rejected) along with office info.
        $urgent_docs[$urgency][] = [
            'document_id'     => $doc['document_id'],
            'subject'         => $doc['subject'],
            'custom_rejected' => true,
            'timeAgo'         => $timeAgo,
            'from_office'     => get_office_name($conn, $rejected['from_user_id']),
            'to_office'       => get_office_name($conn, $rejected['to_user_id']),
            'out_at'          => $rejected['out_at'] ?? null
        ];
    }
}

// -------------------------------------------------------------------------
// 3. NOTIFICATIONS
// -------------------------------------------------------------------------

// We'll prepare an array of notifications (for any comment/status/final decision)
// with messages adapted for admin (including both "From:" and "To:" information).

$notifications = [];

// (a) Comments notifications
$query = "
    SELECT
       r_later.comments,
       d.subject,
       r_later.document_id,
       r_later.out_at,
       r_initial.from_user_id,
       r_initial.to_user_id
    FROM tbl_document_routes r_initial
    JOIN tbl_document_routes r_later ON r_initial.document_id = r_later.document_id
    JOIN tbl_documents d ON r_initial.document_id = d.document_id
    WHERE r_later.comments IS NOT NULL
      AND TRIM(r_later.comments) != ''
      AND r_later.out_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $outAt = new DateTime($row['out_at']);
        $now = new DateTime();
        $diff = $now->diff($outAt);
        if ($diff->d >= 1) {
            $timeAgo = $diff->d . "d";
        } elseif ($diff->h >= 1) {
            $timeAgo = $diff->h . "h";
        } elseif ($diff->i >= 1) {
            $timeAgo = $diff->i . "m";
        } else {
            $timeAgo = "Just now";
        }
        $commentPreview = (strlen($row['comments']) > 50)
            ? substr($row['comments'], 0, 50) . "..."
            : $row['comments'];
        // Get office names for from and to
        $fromOffice = get_office_name($conn, $row['from_user_id']);
        $toOffice   = get_office_name($conn, $row['to_user_id']);
        $notifications[] = [
            'type'        => 'comment',
            'message'     => "New comment on '{$row['subject']}' (From: {$fromOffice}, To: {$toOffice}): {$commentPreview} <small class='text-muted'>($timeAgo ago)</small>",
            'document_id' => $row['document_id'],
            'time'        => $row['out_at']
        ];
    }
}

// (b) Status notifications
$query = "
    SELECT 
       r.document_id, 
       d.subject,
       r.out_at,
       r.from_user_id,
       r.to_user_id
    FROM tbl_document_routes r
    JOIN tbl_documents d ON r.document_id = d.document_id
    WHERE r.status = 'completed'
      AND d.final_status IS NULL
      AND r.out_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
";
$result = $conn->query($query);
if ($result) {
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
            $timeAgo = 'Just now';
        }
        $fromOffice = get_office_name($conn, $row['from_user_id']);
        $toOffice   = get_office_name($conn, $row['to_user_id']);
        $notifications[] = [
            'type'        => 'status',
            'message'     => "Document '{$row['subject']}' (From: {$fromOffice}, To: {$toOffice}) has been Approved <small class='text-muted'>($timeAgo ago)</small>",
            'document_id' => $row['document_id'],
            'time'        => $row['out_at']
        ];
    }
}

// (c) Final decision notifications
$query = "
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
       ) AS latest_out_at,
       (
         SELECT from_user_id 
         FROM tbl_document_routes 
         WHERE document_id = d.document_id 
         ORDER BY out_at DESC 
         LIMIT 1
       ) AS from_user_id,
       (
         SELECT to_user_id 
         FROM tbl_document_routes 
         WHERE document_id = d.document_id 
         ORDER BY out_at DESC 
         LIMIT 1
       ) AS to_user_id
    FROM tbl_documents d
    WHERE d.final_status IS NOT NULL
      AND (
         SELECT out_at 
         FROM tbl_document_routes 
         WHERE document_id = d.document_id 
         ORDER BY out_at DESC 
         LIMIT 1
      ) >= DATE_SUB(NOW(), INTERVAL 3 DAY)
";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $latestOutAt = new DateTime($row['latest_out_at']);
        $now = new DateTime();
        $diff = $now->diff($latestOutAt);
        if ($diff->d >= 1) {
            $timeAgo = $diff->d . 'd';
        } elseif ($diff->h >= 1) {
            $timeAgo = $diff->h . 'h';
        } elseif ($diff->i >= 1) {
            $timeAgo = $diff->i . 'm';
        } else {
            $timeAgo = 'Just now';
        }
        $fromOffice = get_office_name($conn, $row['from_user_id']);
        $toOffice   = get_office_name($conn, $row['to_user_id']);
        $notifications[] = [
            'type'        => 'final',
            'message'     => "Document '{$row['subject']}' (From: {$fromOffice}, To: {$toOffice}) has been "
                . ucfirst($row['final_status']) . " <small class='text-muted'>($timeAgo ago)</small>",
            'document_id' => $row['document_id'],
            'time'        => $row['latest_out_at']
        ];
    }
}

// -------------------------------------------------------------------------
// Build the detailed HTML for urgent documents (grid view)
// -------------------------------------------------------------------------
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
            // For admin, show both From and To offices.
            $fromOffice = htmlspecialchars($doc['from_office'] ?? 'Unknown');
            $toOffice   = htmlspecialchars($doc['to_office'] ?? 'Unknown');
            $timeAgoText = isset($doc['timeAgo']) ? "<small class='text-muted'>(" . htmlspecialchars($doc['timeAgo']) . " ago)</small>" : '';
            if (!empty($doc['custom_rejected'])) {
                $urgent_html .= '<div class="alert alert-' . $info['color'] . '">
                    <strong>Rejected:</strong> "' . htmlspecialchars($doc['subject']) . '"<br>
                    From: ' . $fromOffice . ' | To: ' . $toOffice . ' ' . $timeAgoText . '
                    <a href="document_view.php?rejected_doc_id=' . $doc['document_id'] . '" class="btn btn-xs btn-white view-details-btn">View</a>
                </div>';
            } else {
                $urgent_html .= '<div class="alert alert-' . $info['color'] . '">
                    <strong>Pending Document:</strong><br>
                    From: ' . $fromOffice . ' | To: ' . $toOffice . '<br>
                    Subject: ' . htmlspecialchars($doc['subject']) . ' ' . $timeAgoText . '
                    <a href="document_view.php?receive_doc_id=' . $doc['document_id'] . '" class="btn btn-xs btn-white view-details-btn">View</a>
                </div>';
            }
        }
    } else {
        $urgent_html .= '<div class="alert alert-secondary">No documents in this urgency category</div>';
    }
    $urgent_html .= '</div></div></div>';
}

// -------------------------------------------------------------------------
// Prepare Menu HTML for Navbar (summary items)
// -------------------------------------------------------------------------
// Split urgent_docs into arrived and rejected arrays.
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

// Build the menu HTML for the navbar (summary items).
$arrived_menu_html = '';
if ($countArrived > 0) {
    $arrived_menu_html = '<div class="menu-item">' . $countArrived . ' document' . ($countArrived != 1 ? 's' : '') .
        ' pending <small class="text-muted">(Last: ' . ($latestArrivalTime ? calculateTimeAgo($latestArrivalTime) : 'No Time') . ' ago)</small></div>';
}
$rejected_menu_html = '';
if ($countRejected > 0) {
    $rejected_menu_html = '<div class="menu-item">' . $countRejected . ' document' . ($countRejected != 1 ? 's' : '') .
        ' rejected <small class="text-muted">(Last: ' . ($latestRejectTime ? calculateTimeAgo($latestRejectTime) : 'No Time') . ' ago)</small></div>';
}
$notification_menu_html = '';
if ($countNotifications > 0) {
    $notification_menu_html = '<div class="menu-item">' . $countNotifications . ' notification' . ($countNotifications != 1 ? 's' : '') .
        ' <small class="text-muted">(Last: ' . ($latestNotificationTime ? calculateTimeAgo($latestNotificationTime) : 'No Time') . ' ago)</small></div>';
}

// -------------------------------------------------------------------------
// Render detailed HTML for notifications (alert boxes)
// -------------------------------------------------------------------------
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

// -------------------------------------------------------------------------
// Output JSON with all required keys
// -------------------------------------------------------------------------
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
