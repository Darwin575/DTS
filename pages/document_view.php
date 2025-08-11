<?php
// Enable error reporting during development
ini_set('display_errors', 1);
error_reporting(E_ALL);

// document_view.php
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/db.php';
include '../layouts/header.php';
$other_files = []; // Add this at the top
$document = [];
$can_edit_document = false;

$is_rejected_doc = isset($_GET['rejected_doc_id']);
$document_id = $is_rejected_doc ? intval($_GET['rejected_doc_id']) : (isset($_GET['doc_id']) ? intval($_GET['doc_id']) : 0);
$current_user_id = SessionManager::get('user')['id'] ?? 0;
error_log("Current ID: $current_user_id");
$current_user_role = SessionManager::get('user')['role'];
error_log("Current Role: $current_user_role");
$urgency = '';
// Access Control Check
if ($current_user_role !== 'admin') {
    // Regular user access check
    $access_stmt = $conn->prepare("
        SELECT 1 FROM tbl_documents d
        LEFT JOIN tbl_document_routes r 
            ON d.document_id = r.document_id
        WHERE d.document_id = ?
        AND (
            (d.user_id = ? AND d.status != 'draft') OR 
            (r.to_user_id = ? AND r.in_at IS NOT NULL)
        )
        LIMIT 1
    ");
    $access_stmt->bind_param("iii", $document_id, $current_user_id, $current_user_id);
    $access_stmt->execute();

    if ($access_stmt->get_result()->num_rows === 0) {
        header("Location: /DTS/index.php");
        exit();
    }
} else {
    // Admin access check
    $admin_stmt = $conn->prepare("
        SELECT 1 FROM tbl_documents 
        WHERE document_id = ? 
        AND status != 'draft'
        LIMIT 1
    ");
    $admin_stmt->bind_param("i", $document_id);
    $admin_stmt->execute();


    if ($admin_stmt->get_result()->num_rows === 0) {
        header("Location: /asus.php");
        exit();
    }
}

$action_stmt = $conn->prepare("SELECT action FROM tbl_document_actions WHERE document_id = ?");
$action_stmt->bind_param("i", $document_id);
$action_stmt->execute();
$actions_result = $action_stmt->get_result();

$actions = [];
while ($row = $actions_result->fetch_assoc()) {
    $actions[] = $row['action'];
}


try {
    // Base document query
    $stmt = $conn->prepare("SELECT d.*, u.office_name
FROM tbl_documents d
JOIN tbl_users u ON d.user_id = u.user_id
WHERE d.document_id = ?");

    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();

    if (!$document) {
        header("Location: /DTS/index.php");
        exit();
    }

    // Edit permission logic
    if ($is_rejected_doc && $current_user_role === 'user') {
        // Check if document was rejected and user is the original sender
        $reject_stmt = $conn->prepare("SELECT 1 FROM tbl_document_routes
WHERE document_id = ?
AND from_user_id = ?
AND status = 'rejected'
LIMIT 1");
        $reject_stmt->bind_param("ii", $document_id, $current_user_id);
        $reject_stmt->execute();
        $has_rejection = $reject_stmt->get_result()->num_rows > 0;

        $can_edit_document = $has_rejection && $document['status'] !== 'draft';
    }

    // Get Document Status
    $status_stmt = $conn->prepare("SELECT final_status,
(SELECT COUNT(*) FROM tbl_document_routes
WHERE document_id = ? AND status = 'rejected') as reject_count
FROM tbl_documents
WHERE document_id = ?");
    $status_stmt->bind_param("ii", $document_id, $document_id);
    $status_stmt->execute();
    $status_result = $status_stmt->get_result()->fetch_assoc();

    $display_status = $status_result['final_status'] ??
        ($status_result['reject_count'] > 0 ? 'Rejected' : $document['status']);


    // Calculate Completion Percentage
    // Use a subquery to get the latest route for each distinct to_user_id
    $query = "
    SELECT t.to_user_id, t.route_id, t.status
    FROM tbl_document_routes t
    INNER JOIN (
        SELECT to_user_id, MAX(route_id) AS max_route_id
        FROM tbl_document_routes
        WHERE document_id = ?
        GROUP BY to_user_id
    ) grp
    ON t.to_user_id = grp.to_user_id AND t.route_id = grp.max_route_id
    ORDER BY t.route_id ASC;
";

    $route_stmt = $conn->prepare($query);
    $route_stmt->bind_param("i", $document_id);
    $route_stmt->execute();
    $routes_result = $route_stmt->get_result();

    $routes = [];
    while ($route = $routes_result->fetch_assoc()) {
        $routes[] = $route;
    }

    $total_routes = count($routes);
    $completed_count = 0;

    // Count routes until the first incomplete route is encountered
    foreach ($routes as $route) {
        if ($route['status'] === 'completed') {
            $completed_count++;
        } else {
            break; // Stop counting as soon as an incomplete route is found
        }
    }

    // Calculate the percentage
    $document['completion'] = $total_routes > 0
        ? round(($completed_count / $total_routes) * 100)
        : 0;



    $sql = "
        SELECT GROUP_CONCAT(action SEPARATOR ' / ') AS actions_joined
        FROM tbl_document_actions
        WHERE document_id = ?
      ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $document_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    // $row['actions_joined'] might be "Approve / Review / Sign"
    $actionsJoined = $row['actions_joined'] ?? '';


    // Get Participants
    // 1) Fetch creator info
    $stmtDoc = $conn->prepare("
    SELECT 
      creator_name,
      profile_picture_path
    FROM tbl_documents
    WHERE document_id = ?
");
    $stmtDoc->bind_param("i", $document_id);
    $stmtDoc->execute();
    $stmtDoc->bind_result($creatorName, $creatorProfilePic);
    $stmtDoc->fetch();
    $stmtDoc->close();

    // 2) Fetch all recipients (distinct)
    $stmtRec = $conn->prepare("
    SELECT DISTINCT
      recipient_name,
      profile_picture_path
    FROM tbl_document_routes
    WHERE document_id = ?
");
    $stmtRec->bind_param("i", $document_id);
    $stmtRec->execute();
    $resRec = $stmtRec->get_result();
    $recipients = [];
    while ($row = $resRec->fetch_assoc()) {
        $recipients[] = [
            'recipient_name'                 => $row['recipient_name'],
            'recipient_profile_picture_path' => $row['profile_picture_path'],
        ];
    }
    $stmtRec->close();

    // 3) Assemble into one structure
    $participants = [
        'creator'    => [
            'creator_name'                    => $creatorName,
            'creator_profile_picture_path'    => $creatorProfilePic,
        ],
        'recipients' => $recipients,
    ];

    // Get Timeline
    $timeline_stmt = $conn->prepare("SELECT route_id, status, comments, in_at, out_at,
recipient_name, routing_sheet_path
FROM tbl_document_routes
WHERE document_id = ?
ORDER BY route_id ASC");
    $timeline_stmt->bind_param("i", $document_id);
    $timeline_stmt->execute();
    $timeline = $timeline_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    header("Location: /error.php");
    exit();
}
try {
    // Get timeline data from all three tables
    $timeline_stmt = $conn->prepare("
    SELECT
      dr.in_at,
      dr.out_at,
      dr.comments,
      dr.recipient_name,
      dr.status       AS route_status,
      dr.routing_sheet_path,
      dr.profile_picture_path,
      da.access_time,
      d.final_status,
      u.office_name
    FROM tbl_document_routes dr
    LEFT JOIN tbl_users         u  ON dr.to_user_id = u.user_id
    LEFT JOIN tbl_document_access da
      ON dr.document_id = da.document_id
     AND dr.to_user_id   = da.user_id
    INNER JOIN tbl_documents    d  ON dr.document_id = d.document_id
    WHERE dr.document_id = ?
    ORDER BY dr.route_id ASC
  ");
    $timeline_stmt->bind_param("i", $document_id);
    $timeline_stmt->execute();
    $timeline = $timeline_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    // ─── DEDUPE ─────────────────────────────────────────────────────────
    $filtered = [];
    $prev     = null;
    foreach ($timeline as $event) {
        if (
            $prev
            && $event['in_at']                === $prev['in_at']
            && $event['office_name'] === $prev['office_name']
        ) {
            continue;
        }
        $filtered[] = $event;
        $prev       = $event;
    }
    $timeline = $filtered;
    // Get total routes to identify final recipient
    $total_routes = count($timeline);

    // Pre-process timeline dates
    foreach ($timeline as $key => $event) {
        $timeline[$key]['arrived_at'] = (!empty($event['in_at']) && strtotime($event['in_at'])) ? date('F j, Y g:i A', strtotime($event['in_at'])) : 'Pending';
        $timeline[$key]['received_at'] = (!empty($event['access_time']) && strtotime($event['access_time'])) ? date('F j, Y g:i A', strtotime($event['access_time'])) : 'Not received';
        $timeline[$key]['sent_at'] = (!empty($event['out_at']) && strtotime($event['out_at'])) ? date('F j, Y g:i A', strtotime($event['out_at'])) : 'In progress';
    }
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    header("Location: /error.php");
    exit();
}
$latestOutAt = '';
if (!empty($timeline)) {
    $latestOutAtTimestamp = max(array_map(function ($event) {
        return (!empty($event['out_at']) && strtotime($event['out_at'])) ? strtotime($event['out_at']) : 0;
    }, $timeline));
    if ($latestOutAtTimestamp > 0) {
        $latestOutAt = date('F j, Y g:i A', $latestOutAtTimestamp);
    } else {
        // If all events have null out_at, use the document's updated_at if available
        if (isset($document['updated_at']) && strtotime($document['updated_at'])) {
            $latestOutAt = date('F j, Y g:i A', strtotime($document['updated_at']));
        } else {
            $latestOutAt = 'No updates';
        }
    }
} else {
    // If timeline is empty, use the document's updated_at if available
    if (isset($document['updated_at']) && strtotime($document['updated_at'])) {
        $latestOutAt = date('F j, Y g:i A', strtotime($document['updated_at']));
    } else {
        $latestOutAt = 'No updates';
    }
}


function convertLocalPathToUrl($path)
{
    // 1. If the given path is already a full URL, return it.
    if (preg_match('/^(https?:)?\/\//i', $path)) {
        return $path;
    }

    // 2. Normalize backslashes to forward slashes.
    $path = str_replace('\\', '/', $path);

    // 3. Set the protocol and host dynamically.
    $protocol = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];

    // 4. Define your project folder as it appears in the URLs.
    $projectFolder = '/DTS';

    // Helper: ensure that a string has a leading slash.
    $ensureLeadingSlash = function ($s) {
        return ($s && $s[0] !== '/') ? '/' . $s : $s;
    };

    // 5. Check if the path is an absolute filesystem path (e.g., "C:/...").
    if (preg_match('/^[A-Za-z]:\//', $path)) {
        // Get a normalized document root.
        $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
        if (strpos($path, $docRoot) === 0) {
            // Remove the document root portion to get the relative part.
            $relativePath = substr($path, strlen($docRoot));
            $relativePath = $ensureLeadingSlash($relativePath);
            // Normalize multiple slashes.
            $relativePath = preg_replace('/\/+/', '/', $relativePath);
            // Only prepend the project folder if it isn’t already present.
            if (substr($relativePath, 0, strlen($projectFolder)) !== $projectFolder) {
                $relativePath = $projectFolder . $relativePath;
            }
            return $protocol . '://' . $host . $relativePath;
        }
        // If the file is not under the document root, return as is.
        return $path;
    } else {
        // 6. The path is relative (e.g., from the database).
        $relativePath = $ensureLeadingSlash($path);
        $relativePath = preg_replace('/\/+/', '/', $relativePath);
        // Only prepend the project folder if missing.
        if (substr($relativePath, 0, strlen($projectFolder)) !== $projectFolder) {
            $relativePath = $projectFolder . $relativePath;
        }
        return $protocol . '://' . $host . $relativePath;
    }
}


function resolve_path($path, $type)
{

    if (empty($path)) return '';

    // Check if path exists as-is (absolute or relative)
    if (file_exists($path)) return $path;

    // Try to interpret as relative to /uploads/esig/
    $basename = basename($path);
    if ($type == 'routing_sheet') {
        $relative = __DIR__ . '/../uploads/routing_sheets/' . $basename;
    } else if ($type == 'picture') {
        $relative = __DIR__ . '/../uploads/profile/' . $basename;
    } else if ($type == 'document') {
        $relative = __DIR__ . '/../documents/' . $basename;
    }

    if (file_exists($relative)) return $relative;

    // Try relative to the current dir
    $relative2 = __DIR__ . '/' . $basename;
    if (file_exists($relative2)) return $relative2;

    // If nothing matches, return empty

    return '';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($document['subject']) ?> - Document View</title>
</head>

<body>
    <div id="wrapper">
        <?php
        if ($current_user_role === 'admin') {
            include '../layouts/admin_sidebar.php';
        } else {
            include '../layouts/sidebar.php';
        }
        ?>

        <div id="page-wrapper" class="gray-bg">
            <div class="row border-bottom">
                <?php
                if ($current_user_role === 'admin') {
                    include '../layouts/admin_navbar_top.php';
                } else {
                    include '../layouts/user_navbar_top.php';
                }
                ?>
            </div>
            <div class="row wrapper border-bottom page-heading"></div>
            <div class="wrapper wrapper-content animated fadeInUp">
                <div class="row">
                    <!-- Document Details -->
                    <div class="col-lg-8">
                        <div class="ibox">
                            <div class="ibox-content">
                                <div class="row">
                                    <div class="col-lg-12">
                                        <div class="m-b-md">
                                            <h2><?= htmlspecialchars($document['subject']) ?></h2>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-lg-6">
                                        <dl class="row mb-0">
                                            <div class="col-sm-4 text-sm-right">
                                                <dt>Status:</dt>
                                            </div>
                                            <div class="col-sm-3 text-sm-left">
                                                <dd class="mb-1">
                                                    <span class="label label-primary">
                                                        <?= htmlspecialchars($display_status) ?>
                                                    </span>
                                                </dd>
                                            </div>
                                        </dl>
                                        <dl class="row mb-0">
                                            <div class="col-sm-4 text-sm-right">
                                                <dt>Created by:</dt>
                                            </div>
                                            <div class="col-sm-8 text-sm-left">
                                                <dd class="mb-1">
                                                    <?= htmlspecialchars($document['creator_name'] ? $document['creator_name'] . ' (' . $document['office_name'] . ')' : $document['office_name']) ?>
                                                </dd>
                                            </div>
                                        </dl>
                                        <dl class="row mb-0">
                                            <div class="col-sm-4 text-sm-right">
                                                <dt>Urgency:</dt>
                                            </div>
                                            <div class="col-sm-8 text-sm-left">
                                                <dd class="mb-1 text-<?= $document['urgency'] === 'high' ? 'danger' : ($document['urgency'] === 'medium' ? 'warning' : 'success') ?>">
                                                    <?= ucfirst($document['urgency']) ?>
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                    <div class="col-lg-6" id="cluster_info">
                                        <dl class="row mb-0">
                                            <div class="col-sm-4 text-sm-right">
                                                <dt>Last Updated:</dt>
                                            </div>
                                            <div class="col-sm-8 text-sm-left pt-lg-3">
                                                <dd class="mb-1">
                                                    <?= $latestOutAt ?>
                                                </dd>
                                            </div>
                                        </dl>
                                        <dl class="row mb-0">
                                            <div class="col-sm-4 text-sm-right">
                                                <dt>Created:</dt>
                                            </div>
                                            <div class="col-sm-8 text-sm-left">
                                                <dd class="mb-1">
                                                    <?= date('F j, Y g:i A', strtotime($document['updated_at'])) ?>
                                                </dd>
                                            </div>
                                        </dl>
                                        <dl class="row mb-0">
                                            <div class="col-sm-4 text-sm-right">
                                                <dt>Recipients:</dt>
                                            </div>
                                            <div class="col-sm-8 text-sm-left">
                                                <dd class="project-people mb-1">
                                                    <?php if (!empty($participants)): ?>
                                                        <div class="participant-images">
                                                            <!-- Creator -->
                                                            <?php if (!empty($participants['creator']['creator_profile_picture_path'])): ?>
                                                                <a href="#" title="<?= htmlspecialchars($participants['creator']['creator_name']) ?>">
                                                                    <img
                                                                        alt="Creator"
                                                                        class="rounded-circle"
                                                                        src="<?= htmlspecialchars($participants['creator']['creator_profile_picture_path']) ?>">
                                                                </a>
                                                            <?php endif; ?>

                                                            <!-- Recipients -->
                                                            <?php foreach ($participants['recipients'] as $recipient): ?>
                                                                <?php if (!empty($recipient['recipient_profile_picture_path'])): ?>
                                                                    <a href="#" title="<?= htmlspecialchars($recipient['recipient_name']) ?>">
                                                                        <img
                                                                            alt="<?= htmlspecialchars($recipient['recipient_name']) ?>"
                                                                            class="rounded-circle"
                                                                            src="<?= htmlspecialchars($recipient['recipient_profile_picture_path']) ?>">
                                                                    </a>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-lg-12">
                                        <dl class="row mb-0">
                                            <div class="col-sm-2 text-sm-right">
                                                <dt>Completed:</dt>
                                            </div>
                                            <div class="col-sm-10 text-sm-left">
                                                <dd>
                                                    <div class="progress m-b-1">
                                                        <div style="width: <?= $document['completion'] ?>%;"
                                                            class="progress-bar progress-bar-striped progress-bar-animated">
                                                        </div>
                                                    </div>
                                                    <small>Document progress: <strong><?= $document['completion'] ?>%</strong></small>
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Description & Files & Actions -->
                    <div class="col-lg-4">
                        <div class="wrapper wrapper-content project-manager">
                            <div class="card">
                                <div class="card-body">
                                    <h4>Document Description</h4>
                                    <?php if ($document['file_path']): ?>
                                        <button type="button"
                                            class="btn btn-sm btn-primary mb-3"
                                            data-toggle="modal"
                                            data-target="#routingSheetModal"
                                            data-sheet-path="<?= htmlspecialchars(convertLocalPathToUrl($document['file_path'])) ?>">
                                            <i class="fa fa-eye"></i> View File
                                        </button>
                                    <?php endif; ?>

                                    <div class="remarks-section">
                                        <div class="remarks-content" id="remarksContent">
                                            <?= html_entity_decode(
                                                html_entity_decode($document['remarks'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                                                ENT_QUOTES | ENT_HTML5,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                        <button class="btn btn-link btn-sm d-none" id="readMoreBtn">Read More</button>
                                    </div>

                                    <?php if ($actionsJoined): ?>
                                        <div class="document-actions mt-3">
                                            <h5>Request Actions</h5>
                                            <div class="action-items">
                                                <div class="action-content">
                                                    <span class="text-muted"><?= htmlspecialchars($actionsJoined) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="routing-sheet-section mt-4">
                                        <h5>Routing Sheet</h5>
                                        <ul class="list-unstyled project-files">
                                            <?php if ($document['routing_sheet_path']): ?>
                                                <li>
                                                    <button type="button"
                                                        class="btn btn-sm btn-primary"
                                                        data-toggle="modal"
                                                        data-target="#routingSheetModal"
                                                        data-sheet-path="<?= htmlspecialchars(convertLocalPathToUrl($document['routing_sheet_path'])) ?>">
                                                        <i class="fa fa-file-text"></i> View Routing Sheet
                                                    </button>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <button type="button"
                                                        class="btn btn-sm btn-primary"
                                                        data-toggle="modal"
                                                        data-target="#routingSheetModal"
                                                        data-sheet-path="<?= '../pdf/hala.php?document_id=' . $document_id ?>">
                                                        <i class="fa fa-file-text"></i> View Routing Sheet
                                                    </button>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>

                                    <div class="text-center mt-4">
                                        <a href="#timeline-section" class="btn btn-xs btn-primary">View Timeline</a>
                                        <?php if ($can_edit_document): ?>
                                            <a href="document_management.php?rejected_doc_id=<?= $document_id ?>" class="btn btn-xs btn-warning">Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                </div>

                <!-- Timeline Section -->
                <div class="row mt-5" id="timeline-section">
                    <div class="col-lg-12">
                        <div class="ibox">
                            <div class="ibox-title d-flex justify-content-between align-items-center">
                                <h5>Document Tracking Timeline</h5>
                                <div class="timeline-controls">
                                    <button class="btn btn-xs btn-outline-primary" id="collapseAllBtn"><i class="fa fa-angle-double-up"></i>Collapse All</button>
                                    <button class="btn btn-xs btn-outline-primary" id="expandAllBtn"><i class="fa fa-angle-double-down"></i>Expand All</button>
                                </div>
                            </div>
                            <div class="ibox-content">
                                <!-- Timeline Filter -->
                                <div class="timeline-filter mb-4">
                                    <div class="row">
                                        <div class="col-md-6 col-lg-3 mb-2">
                                            <select class="form-control form-control-sm" id="statusFilter">
                                                <option value="">All Statuses</option>
                                                <option value="pending">Pending</option>
                                                <option value="approved">Approved</option>
                                                <option value="rejected">Rejected</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 col-lg-3">
                                            <input type="text" class="form-control form-control-sm" id="searchTimeline" placeholder="Search timeline...">
                                        </div>
                                    </div>
                                </div>

                                <!-- Timeline Progress Bar -->
                                <div class="timeline-progress mb-4">
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $document['completion'] ?>%"
                                            aria-valuenow="<?= $document['completion'] ?>" aria-valuemin="0" aria-valuemax="100">
                                            <?= $document['completion'] ?>%
                                        </div>
                                    </div>
                                </div>

                                <!-- Timeline Container with Scroll -->
                                <div class="timeline-scroll-container">
                                    <div id="vertical-timeline" class="vertical-container dark-timeline center-orientation">
                                        <?php foreach ($timeline as $index => $event):
                                            $is_final = ($index === $total_routes - 1);
                                            $current_step = empty($event['out_at']);
                                            $status_class = '';
                                            if (isset($event['route_status'])) {
                                                $status_class = $event['route_status'] === 'rejected' ? 'timeline-rejected' : ($event['route_status'] === 'approved' ? 'timeline-approved' : 'timeline-pending');
                                            }
                                        ?>
                                            <div class="vertical-timeline-block <?= $current_step ? 'current-step' : '' ?> <?= $status_class ?>"
                                                data-status="<?= isset($event['route_status']) ? $event['route_status'] : 'pending' ?>">
                                                <div class="vertical-timeline-icon navy-bg">
                                                    <i class="fa fa-<?= $current_step ? 'clock-o' : 'check' ?>"></i>
                                                </div>

                                                <div class="vertical-timeline-content">
                                                    <div class="timeline-header" data-toggle="collapse" data-target="#timelineContent<?= $index ?>"
                                                        role="button" aria-expanded="true">
                                                        <div class="row align-items-center">
                                                            <div class="col-auto">
                                                                <?php if (!empty($event['profile_picture_path'])): ?>
                                                                    <img src="<?= htmlspecialchars($event['profile_picture_path']) ?>"
                                                                        class="img-circle"
                                                                        alt="Recipient"
                                                                        style="width:40px;height:40px;object-fit:cover">
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="col">
                                                                <h4 class="font-weight-bold mb-1">
                                                                    <?php
                                                                    $who = $event['recipient_name']
                                                                        ? $event['recipient_name'] . ' (' . $event['office_name'] . ')'
                                                                        : $event['office_name'];
                                                                    echo htmlspecialchars($who);
                                                                    ?>
                                                                </h4> <span class="text-muted small">
                                                                    <i class="fa fa-calendar"></i> <?= !empty($event['in_at']) ? date('M j, Y', strtotime($event['in_at'])) : 'Date not set' ?>
                                                                </span>
                                                            </div>
                                                            <div class="col-auto">
                                                                <span class="badge badge-<?=
                                                                                            $event['route_status'] === 'rejected' ? 'danger' : ($event['route_status'] === 'approved' ? 'success' : 'primary')
                                                                                            ?>">
                                                                    <?= ucfirst($event['route_status'] ?? 'Pending') ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="collapse show" id="timelineContent<?= $index ?>">
                                                        <div class="timeline-body mt-3">
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <dl class="row mb-0">
                                                                        <dt class="col-sm-4">Arrived:</dt>
                                                                        <dd class="col-sm-8"><?= $event['arrived_at'] ?></dd>

                                                                        <dt class="col-sm-4">Received:</dt>
                                                                        <dd class="col-sm-8"><?= $event['received_at'] ?></dd>

                                                                        <?php if (!empty($event['out_at'])): ?>
                                                                            <dt class="col-sm-4">Sent Out:</dt>
                                                                            <dd class="col-sm-8"><?= $event['sent_at'] ?></dd>
                                                                        <?php endif; ?>
                                                                    </dl>
                                                                </div>

                                                                <?php if (!empty($event['comments'])): ?>
                                                                    <div class="col-md-6">
                                                                        <div class="comments-section">
                                                                            <h6 class="font-weight-bold">Comments</h6>
                                                                            <div class="comments-content">
                                                                                <?= $event['comments'] ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>

                                                            <?php if (!empty($event['routing_sheet_path'])): ?>
                                                                <div class="timeline-footer mt-3">
                                                                    <button type="button"
                                                                        class="btn btn-xs btn-primary"
                                                                        data-toggle="modal"
                                                                        data-target="#routingSheetModal"
                                                                        data-sheet-path="<?= htmlspecialchars(convertLocalPathToUrl($event['routing_sheet_path'])) ?>">
                                                                        <i class="fa fa-file-text"></i> View Routing Sheet
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Mobile Timeline Navigation -->
                                <div class="d-md-none mt-4">
                                    <div class="btn-group d-flex">
                                        <button class="btn btn-outline-primary btn-sm" id="prevTimelineItem">
                                            <i class="fa fa-chevron-left"></i> Previous
                                        </button>
                                        <button class="btn btn-outline-primary btn-sm" id="nextTimelineItem">
                                            Next <i class="fa fa-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Routing Sheet Modal -->
                <!-- Routing Sheet Modal -->
                <div class="modal fade" id="routingSheetModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-xl" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Routing Sheet</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body p-0">
                                <iframe id="routingSheetFrame" style="width:100%;height:80vh;border:none;"></iframe>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="footer">
                <div class="text-right">
                    <a href="/DTS/asus.html">
                        <small>
                            Developed by <strong>Team BJMP Peeps </strong>
                        </small>
                    </a>
                </div>
            </div>
        </div>
    </div>


    <style>
        .document-actions {
            border-left: 2px solid #1ab394;
            padding-left: 15px;
            margin: 15px 0;
        }

        .action-item {
            padding: 5px 0;
            font-size: 0.95em;
            color: #676a6c;
        }

        .action-item i {
            font-size: 0.9em;
        }

        /* Vertical Timeline Styling */
        .vertical-timeline-block.current-step .vertical-timeline-icon {
            box-shadow: 0 0 0 4px #1ab394;
            border: 2px solid #fff;
        }

        .vertical-timeline-block.current-step .vertical-timeline-content {
            border-left: 3px solid #1ab394;
            margin-left: -3px;
            background: #f8f9fa;
        }

        .remarks-box {
            background: #fff;
            border: 1px solid #eee;
            padding: 10px;
            border-radius: 4px;
        }

        .timeline-header h3 {
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .progress-indicator {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: calc(100% - 120px);
            background: #eee;
            margin-top: 60px;
        }

        .progress-line {
            background: #1ab394;
            transition: height 0.5s ease;
        }

        .vertical-timeline-icon {
            background: #fff;
            box-shadow: 0 0 0 4px #e7eaec;
        }

        .progress-dot {
            position: absolute;
            width: 16px;
            height: 16px;
            background: #fff;
            border: 3px solid #1ab394;
            border-radius: 50%;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            z-index: 2;
        }

        .progress-dot.first {
            border-color: #23c6c8;
        }

        .progress-dot.last {
            border-color: #ed5565;
        }

        .current-step .vertical-timeline-icon {
            box-shadow: 0 0 0 4px #1ab394;
            animation: pulse 2s infinite;
        }

        .glowing {
            box-shadow: 0 0 15px rgba(26, 179, 148, .2);
            border-radius: 5px;
        }

        /* Mobile Optimization */
        @media (max-width: 768px) {
            .vertical-timeline-block {
                margin-left: 20px;
            }

            .vertical-timeline-icon {
                left: -20px !important;
            }

            .vertical-timeline-content {
                margin-left: 40px !important;
            }

            .mobile-progress {
                padding: 10px;
                background: #f8f9fa;
                margin: -15px -15px 15px;
            }
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(26, 179, 148, .4);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(26, 179, 148, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(26, 179, 148, 0);
            }
        }

        /* Description Section Styles */
        .remarks-section {
            position: relative;
            margin: 15px 0;
        }

        .remarks-content {
            max-height: 200px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .remarks-content.expanded {
            max-height: none;
        }

        .remarks-content img {
            max-width: 100%;
            height: auto;
        }

        .action-items {
            max-height: 150px;
            overflow-y: auto;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
        }

        /* Timeline Section Styles */
        .timeline-filter select,
        .timeline-filter input {
            border-radius: 3px;
            border: 1px solid #e5e6e7;
        }

        .timeline-controls {
            display: flex;
            gap: 10px;
        }

        .vertical-timeline-block {
            margin: 2em 0;
            opacity: 1;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        /* Filter styles */
        .vertical-timeline-block.filtered {
            display: none !important;
            /* Use !important to override mobile display:block */
        }

        /* Clear filters button */
        #clearFilters {
            width: 100%;
            margin-top: 8px;
        }

        @media (max-width: 768px) {
            .timeline-filter .row {
                margin: 0 -5px;
            }

            .timeline-filter .col-md-6 {
                padding: 0 5px;
                margin-bottom: 8px;
            }
        }

        .vertical-timeline-block .timeline-header {
            cursor: pointer;
            padding: 15px;
            border-radius: 4px;
            transition: background-color 0.2s ease;
        }

        .vertical-timeline-block .timeline-header:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .timeline-body {
            padding: 15px;
            background: #fff;
            border-radius: 0 0 8px 8px;
        }

        .comments-section {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #eee;
        }

        .comments-content {
            max-height: 150px;
            overflow-y: auto;
            padding-right: 10px;
        }

        /* Timeline Container Styles */
        .timeline-scroll-container {
            max-height: 600px;
            overflow-y: auto;
            padding-right: 10px;
            margin-right: -10px;
            scrollbar-width: thin;
            scrollbar-color: #1ab394 #f3f3f4;
            -webkit-overflow-scrolling: touch;
            /* Enable smooth scrolling on iOS */
        }

        .timeline-scroll-container::-webkit-scrollbar {
            width: 6px;
        }

        .timeline-scroll-container::-webkit-scrollbar-track {
            background: #f3f3f4;
            border-radius: 3px;
        }

        .timeline-scroll-container::-webkit-scrollbar-thumb {
            background: #1ab394;
            border-radius: 3px;
        }

        /* Enable touch scrolling on mobile while keeping button navigation */
        @media (max-width: 768px) {
            .timeline-scroll-container {
                -webkit-overflow-scrolling: touch;
                scroll-behavior: smooth;
                overscroll-behavior-y: contain;
                touch-action: pan-y pinch-zoom;
                padding-bottom: 60px;
                /* Add space for the navigation buttons */
            }

            /* Ensure buttons don't interfere with scrolling */
            .btn-group.d-flex {
                position: sticky;
                bottom: 0;
                background: rgba(255, 255, 255, 0.9);
                padding: 10px 0;
                z-index: 100;
                margin: 0 -15px;
                width: calc(100% + 30px);
            }
        }

        /* Status Colors and Icons */
        .vertical-timeline-icon {
            width: 40px;
            height: 40px;
            line-height: 40px;
            text-align: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .timeline-approved .vertical-timeline-icon {
            background-color: #1ab394;
        }

        .timeline-rejected .vertical-timeline-icon {
            background-color: #ed5565;
        }

        .timeline-pending .vertical-timeline-icon {
            background-color: #1c84c6;
        }

        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .timeline-scroll-container {
                max-height: 60vh;
                -webkit-overflow-scrolling: touch;
                overflow-y: auto !important;
                display: block !important;
            }

            .vertical-timeline-content {
                margin-left: 40px;
                display: block !important;
            }

            .vertical-timeline-block {
                display: block !important;
                opacity: 1 !important;
            }

            .vertical-timeline-icon {
                left: 0;
                margin-left: 0;
                width: 30px;
                height: 30px;
                line-height: 30px;
                font-size: 14px;
            }

            .timeline-header h4 {
                font-size: 16px;
            }

            .timeline-body {
                padding: 10px;
            }

            /* Stack elements on mobile */
            .timeline-header .row {
                flex-direction: column;
            }

            .timeline-header .col-auto {
                margin-bottom: 10px;
            }

            /* Improve spacing on mobile */
            .timeline-body .row {
                margin: 0 -5px;
            }

            .timeline-body .col-md-6 {
                padding: 0 5px;
                margin-bottom: 10px;
            }

            /* Full width elements on mobile */
            .comments-section {
                margin-top: 15px;
            }

            .timeline-footer {
                text-align: center;
            }
        }

        /* Animation for new items */
        .vertical-timeline-block {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Custom Scrollbar */
        .comments-content::-webkit-scrollbar,
        .action-items::-webkit-scrollbar {
            width: 6px;
        }

        .comments-content::-webkit-scrollbar-track,
        .action-items::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .comments-content::-webkit-scrollbar-thumb,
        .action-items::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }

        /* Timeline Progress Bar */
        .timeline-progress {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #fff;
            padding: 10px 0;
        }

        .timeline-progress .progress {
            height: 8px;
            background-color: #f5f5f5;
            border-radius: 4px;
            overflow: hidden;
        }

        .timeline-progress .progress-bar {
            background-color: #1ab394;
            transition: width 0.6s ease;
        }
    </style>
    <?php include '../layouts/footer.php'; ?>
    <script src="/DTS/server-logic/config/auto_logout.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.0/mammoth.browser.min.js"></script>
    <script>
        $(document).ready(function() {
            // When the modal is about to be shown
            $('#routingSheetModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var sheetPath = button.data('sheet-path');
                var $body = $('#routingSheetModal .modal-body');

                // clear out old content
                $body.empty();

                // helper to show download fallback
                function showFallback() {
                    $body.append(
                        '<p>Preview not available for this file type.</p>' +
                        '<a class="btn btn-primary" href="' + sheetPath + '" download>Download File</a>'
                    );
                }

                // if .docx → fetch & render via Mammoth
                if (/\.docx(\?.*)?$/i.test(sheetPath)) {
                    console.log('Attempting DOCX preview for:', sheetPath);
                    $body.append('<p>Loading preview…</p>');
                    fetch(sheetPath)
                        .then(res => res.arrayBuffer())
                        .then(buf => mammoth.convertToHtml({
                            arrayBuffer: buf
                        }))
                        .then(result => {
                            $body.empty().append(result.value);
                            $body.css({
                                height: '580px',
                                'overflow-y': 'auto'
                            });
                            console.log('Mammoth messages:', result.messages);
                        })
                        .catch(err => {
                            console.error('Mammoth preview failed:', err);
                            showFallback();
                        });

                    // if direct PDF/image file
                } else if (/\.(pdf|png|jpe?g|gif)(\?.*)?$/i.test(sheetPath)
                    // or PHP streams under /pdf/ folder (e.g. hala.php?document_id=...)
                    ||
                    /\/pdf\//i.test(sheetPath)
                ) {
                    $body.append(
                        '<iframe id="routingSheetFrame" ' +
                        'style="width:100%;height:80vh;border:none" ' +
                        'src="' + sheetPath + '"></iframe>'
                    );

                    // anything else → fallback download
                } else {
                    showFallback();
                }
            });

            // cleanup on close
            $('#routingSheetModal').on('hidden.bs.modal', function() {
                $('#routingSheetModal .modal-body').empty();
            });
        });

        $(document).ready(function() {
            // Timeline UI demo
            $('#lightVersion').click(function(event) {
                event.preventDefault();
                $('#ibox-content').removeClass('ibox-content');
                $('#vertical-timeline').removeClass('dark-timeline');
                $('#vertical-timeline').addClass('light-timeline');
            });

            $('#darkVersion').click(function(event) {
                event.preventDefault();
                $('#ibox-content').addClass('ibox-content');
                $('#vertical-timeline').removeClass('light-timeline');
                $('#vertical-timeline').addClass('dark-timeline');
            });

            $('#leftVersion').click(function(event) {
                event.preventDefault();
                $('#vertical-timeline').toggleClass('center-orientation');
            });
        });

        $(document).ready(function() {
            // Remarks section read more functionality
            const remarksContent = document.querySelector('.remarks-content');
            const readMoreBtn = document.querySelector('#readMoreBtn');

            if (remarksContent && remarksContent.scrollHeight > 200) {
                readMoreBtn.classList.remove('d-none');
                readMoreBtn.addEventListener('click', function() {
                    remarksContent.classList.toggle('expanded');
                    readMoreBtn.textContent = remarksContent.classList.contains('expanded') ? 'Read Less' : 'Read More';
                });
            }

            // Function to apply both filters
            function applyFilters() {
                const status = $('#statusFilter').val().toLowerCase();
                const searchTerm = $('#searchTimeline').val().toLowerCase();

                $('.vertical-timeline-block').each(function() {
                    const $block = $(this);
                    const blockStatus = $block.data('status');
                    const content = $block.text().toLowerCase();

                    const hideByStatus = status !== '' && blockStatus !== status;
                    const hideBySearch = searchTerm !== '' && !content.includes(searchTerm);

                    $block.toggleClass('filtered', hideByStatus || hideBySearch);
                });
            }

            // Timeline filtering
            $('#statusFilter').on('change', function() {
                applyFilters();
            });

            // Timeline search with debounce
            let searchTimeout;
            $('#searchTimeline').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    applyFilters();
                }, 150); // Small delay to prevent rapid firing
            });

            // Clear filters button
            // $('.timeline-filter').append(
            //     '<div class="col-md-6 col-lg-3">' +
            //     '<button class="btn btn-outline-secondary btn-sm" id="clearFilters">' +
            //     'Clear Filters</button></div>'
            // );

            $('#clearFilters').on('click', function() {
                $('#statusFilter').val('');
                $('#searchTimeline').val('');
                $('.vertical-timeline-block').removeClass('filtered');
            });

            // Expand/Collapse functionality
            $('#collapseAllBtn').click(function() {
                $('.vertical-timeline-block .collapse').collapse('hide');
            });

            $('#expandAllBtn').click(function() {
                $('.vertical-timeline-block .collapse').collapse('show');
            });

            // Mobile navigation
            let currentBlock = 0;
            const totalBlocks = $('.vertical-timeline-block').length;

            $('#prevTimelineItem').click(function() {
                if (currentBlock > 0) {
                    currentBlock--;
                    const targetBlock = $('.vertical-timeline-block').eq(currentBlock);
                    $('.timeline-scroll-container').animate({
                        scrollTop: targetBlock.position().top
                    }, 500);
                }
            });

            $('#nextTimelineItem').click(function() {
                if (currentBlock < totalBlocks - 1) {
                    currentBlock++;
                    const targetBlock = $('.vertical-timeline-block').eq(currentBlock);
                    $('.timeline-scroll-container').animate({
                        scrollTop: targetBlock.position().top
                    }, 500);
                }
            });
        });
    </script>
</body>

</html>