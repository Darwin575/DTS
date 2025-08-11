<?php
include_once __DIR__ . '/../server-logic/config/session_init.php';

include_once __DIR__ . '/../server-logic/config/db.php';
include '../layouts/header.php';
$user_role = SessionManager::get('user')['role'];
if ($user_role !== 'admin') {
    header('Location: /DTS/index.php');
    exit;
}
global $conn;

// Helpers
function formatFriendlyDate(DateTime $dt)
{
    $now = new DateTime('now', $dt->getTimezone());
    $today = $now->format('Y-m-d');
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    $d = $dt->format('Y-m-d');
    if ($d === $today) $prefix = 'Today';
    elseif ($d === $yesterday) $prefix = 'Yesterday';
    else $prefix = $dt->format('M j, Y');
    return sprintf('%s at %s', $prefix, $dt->format('h:i:s a'));
}

function formatNameOffice($name, $office)
{
    return ($name !== null && trim($name) !== '') ? "$name ($office)" : $office;
}

function getOfficeName(mysqli $conn, $userId)
{
    $stmt = $conn->prepare("SELECT office_name FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($office);
    $stmt->fetch();
    $stmt->close();
    return $office ?: '';
}

function getActivityFeed(mysqli $conn, $userId)
{
    $feed = [];

    // User logs
    $stmt = $conn->prepare(
        "SELECT l.activity_type, l.old_value, l.new_value, l.activity_time,
                u.office_name, u.email
         FROM tbl_user_activity_logs l
         JOIN tbl_users u ON u.user_id = l.user_id
         WHERE l.user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $actor = htmlspecialchars("{$row['office_name']} ({$row['email']})");
        switch ($row['activity_type']) {
            case 'update_profile_picture':
                $badge = 'Change';
                $msg = "$actor updated profile picture";
                break;
            case 'change_password':
                $badge = 'Change';
                $msg = "$actor changed password";
                break;
            case 'update_name':
                $badge = 'Change';
                $msg = sprintf(
                    "%s changed name from <strong>%s</strong> to <strong>%s</strong>",
                    $actor,
                    htmlspecialchars($row['old_value']),
                    htmlspecialchars($row['new_value'])
                );
                break;
            case 'update_email':
                $badge = 'Change';
                $msg = sprintf(
                    "%s changed email from <strong>%s</strong> to <strong>%s</strong>",
                    $actor,
                    htmlspecialchars($row['old_value']),
                    htmlspecialchars($row['new_value'])
                );
                break;
            default:
                continue 2;
        }
        $feed[] = ['timestamp' => $row['activity_time'], 'badge' => $badge, 'message' => $msg];
    }
    $stmt->close();

    // Document creations
    $stmt = $conn->prepare(
        "SELECT updated_at,subject,creator_name,user_id
         FROM tbl_documents WHERE status='active' AND user_id=?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $actor = formatNameOffice($row['creator_name'], getOfficeName($conn, $row['user_id']));
        $feed[] = [
            'timestamp' => $row['updated_at'],
            'badge' => 'Create',
            'message' => sprintf("%s created document <strong>%s</strong>", htmlspecialchars($actor), htmlspecialchars($row['subject']))
        ];
    }
    $stmt->close();

    // Routing
    $stmt = $conn->prepare(
        "SELECT r.out_at,r.status,r.recipient_name,r.to_user_id,r.comments,d.subject
         FROM tbl_document_routes r
         JOIN tbl_documents d ON d.document_id=r.document_id
         WHERE r.to_user_id=?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $actor = formatNameOffice($row['recipient_name'], getOfficeName($conn, $row['to_user_id']));
        if ($row['status'] === 'completed') {
            $badge = 'Approved';
            $msg = sprintf("%s approved <strong>%s</strong>", htmlspecialchars($actor), htmlspecialchars($row['subject']));
        } elseif ($row['status'] === 'rejected') {
            $badge = 'Rejected';
            $msg = sprintf("%s rejected <strong>%s</strong>", htmlspecialchars($actor), htmlspecialchars($row['subject']));
        } elseif (!empty($row['comments'])) {
            $badge = 'Comment';
            $msg = sprintf("%s commented on <strong>%s</strong>", htmlspecialchars($actor), htmlspecialchars($row['subject']));
        } else continue;
        $feed[] = ['timestamp' => $row['out_at'], 'badge' => $badge, 'message' => $msg];
    }
    $stmt->close();

    // Access logs
    $stmt = $conn->prepare(
        "SELECT a.access_time,a.access_type,d.subject,d.creator_name,d.user_id
         FROM tbl_document_access a
         JOIN tbl_documents d ON d.document_id=a.document_id
         WHERE a.user_id=?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $actor = formatNameOffice($row['creator_name'], getOfficeName($conn, $row['user_id']));
        $feed[] = [
            'timestamp' => $row['access_time'],
            'badge' => 'Receive',
            'message' => sprintf("%s received <strong>%s</strong> via %s", htmlspecialchars($actor), htmlspecialchars($row['subject']), strtoupper($row['access_type']))
        ];
    }
    $stmt->close();

    usort($feed, function ($a, $b) {
        return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
    });
    return $feed;
}

// Assuming document root is set correctly

// Fetch users
$users = [];
$res = $conn->query("SELECT user_id,office_name,email,profile_picture_path FROM tbl_users ORDER BY office_name");
while ($u = $res->fetch_assoc()) $users[] = $u;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Activity Log</title>

</head>
<style>
    @media (max-width: 768px) {
        .timeline-container .list-group-item {
            flex-wrap: wrap;
            gap: 8px;
        }

        .timeline-container .list-group-item>div:last-child {
            width: 100%;
            text-align: left !important;
        }
    }

    @media (max-width: 768px) {
        .clickable-row td {
            padding: 12px 8px;
            /* More compact padding for mobile */
        }

        .client-avatar img {
            width: 32px !important;
            /* Smaller avatar on mobile */
        }
    }

    /* Add this to ensure tab panes display correctly */
    .tab-pane {
        display: none;
    }

    .tab-pane.active {
        display: block;
    }

    /* Prevent text overflow */
    .list-group-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        word-wrap: break-word;
    }

    .list-group-item>span {
        flex-shrink: 0;
        margin-left: 1rem;
        white-space: nowrap;
    }

    @media (max-width: 768px) {
        .list-group-item {
            flex-direction: column;
        }

        .list-group-item>span {
            margin-left: 0;
            margin-top: 0.5rem;
        }
    }

    /* Add to your existing CSS */
    .clickable-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .clickable-row:hover {
        background-color: #f5f5f5;
    }

    .client-link {
        color: inherit;
        /* Maintain text color consistency */
        text-decoration: none;
        /* Remove underline */
    }

    * Timeline activity fixes */ .list-group-item {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem;
        word-break: break-word;
    }

    .activity-badge {
        flex-shrink: 0;
        margin-top: 2px;
    }

    .activity-message {
        flex: 1;
        min-width: 0;
        /* Fix for flex text overflow */
    }

    .activity-time {
        flex-shrink: 0;
        color: #6c757d;
        font-size: 0.875rem;
    }

    /* Badge colors */
    .badge-approved {
        background-color: #28a745;
    }

    .badge-rejected {
        background-color: #dc3545;
    }

    .badge-change {
        background-color: #17a2b8;
    }

    .badge-create {
        background-color: #ffc107;
        color: #000;
    }

    .badge-comment {
        background-color: #007bff;
    }

    /* Mobile fixes */
    @media (max-width: 768px) {
        .list-group-item {
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .activity-time {
            width: 100%;
            text-align: left !important;
        }

        .activity-badge {
            order: -1;
        }
    }
</style>

<body>
    <div id="wrapper">
        <?php include '../layouts/admin_sidebar.php'; ?>
        <div id="page-wrapper" class="gray-bg">
            <div class="row border-bottom"><?php include '../layouts/admin_navbar_top.php'; ?></div>
            <div class="wrapper wrapper-content animated fadeInRight">
                <div class="row">
                    <div class="col-12 col-md-7">
                        <div class="ibox">
                            <div class="ibox-content">
                                <h2>Users Activity</h2>
                                <div class="input-group mb-3">
                                    <input id="search-user" type="text" placeholder="Search user" class="form-control">
                                    <div class="input-group-append">
                                        <button id="btn-search" class="btn btn-primary"><i class="fa fa-search"></i></button>
                                    </div>
                                </div>
                                <div class="table-responsive"> <!-- Add scroll on mobile -->
                                    <table class="table table-striped table-hover">
                                        <tbody id="user-table">
                                            <?php foreach ($users as $index => $u): ?>
                                                <?php
                                                // Define a default image that is web-accessible
                                                $default_pic = '../uploads/profile/default_profile_pic.jpg';

                                                // Get the profile picture path from user data
                                                $profile_pic = $u['profile_picture_path'];

                                                // Check if a profile picture is set and exists on the server
                                                if (!empty($profile_pic) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $profile_pic)) {
                                                    $pic_to_display = htmlspecialchars($profile_pic);
                                                } else {
                                                    $pic_to_display = $default_pic;
                                                }
                                                ?>
                                                <tr class="clickable-row" data-href="#contact-<?php echo $u['user_id']; ?>">
                                                    <td class="client-avatar">
                                                        <img src="<?= $pic_to_display ?>" class="rounded-circle" style="width:35px">
                                                    </td>
                                                    <td>
                                                        <a href="#contact-<?php echo $u['user_id']; ?>" class="client-link">
                                                            <?php echo htmlspecialchars($u['office_name']); ?>
                                                        </a>
                                                    </td>
                                                    <td><i class="fa fa-envelope"></i></td>
                                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>


                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-5 mt-4 mt-md-0">
                        <div class="ibox selected">
                            <div class="ibox-content">
                                <div class="tab-content">
                                    <?php foreach ($users as $i => $u): $acts = getActivityFeed($conn, $u['user_id']);
                                        // Define the default image URL (using the server's IP or a proper relative path)
                                        $default_pic = '../uploads/profile/default_profile_pic.jpg';

                                        // Get the profile picture path from user data
                                        $profile_pic = $u['profile_picture_path'];

                                        // Check if a profile picture is set and exists on the server
                                        if (!empty($profile_pic) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $profile_pic)) {
                                            $pic_to_display = htmlspecialchars($profile_pic);
                                        } else {
                                            $pic_to_display = $default_pic;
                                        }
                                    ?>
                                        <div id="contact-<?= $u['user_id'] ?>" class="tab-pane<?= $i === 0 ? ' active' : '' ?>">
                                            <div class="row m-b-lg">
                                                <div class="col-lg-4 text-center">
                                                    <img src="<?= $pic_to_display ?>" class="rounded-circle" style="width:62px">
                                                </div>

                                                <div class="col-lg-8">
                                                    <h4><?= htmlspecialchars($u['office_name']) ?></h4>
                                                    <p><?= htmlspecialchars($u['email']) ?></p>
                                                </div>
                                            </div>
                                            <strong>Timeline activity</strong>
                                            <div class="timeline-container" style="max-height:300px; overflow-y:auto; margin-top:10px;">
                                                <ul class="list-group clear-list">
                                                    <?php foreach ($acts as $a):
                                                        $ts = formatFriendlyDate(new DateTime($a['timestamp']));
                                                        $classes = [
                                                            'Change' => 'primary',
                                                            'Create' => 'info',
                                                            'Receive' => 'success',
                                                            'Approved' => 'success',
                                                            'Rejected' => 'danger',
                                                            'Comment' => 'warning'
                                                        ];
                                                        $cls = $classes[$a['badge']] ?? 'default';
                                                    ?>
                                                        <li class="list-group-item" style="display: flex; align-items: baseline; gap: 12px; word-break: break-word;">
                                                            <div style="flex-shrink: 0;">
                                                                <span class="label label-<?= $cls ?>"><?= htmlspecialchars($a['badge']) ?></span>
                                                            </div>
                                                            <div style="flex: 1; min-width: 0;">
                                                                <?= $a['message'] ?>
                                                            </div>
                                                            <div style="flex-shrink: 0; margin-left: auto; color: #6c757d; font-size: 0.9em;">
                                                                <?= $ts ?>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
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
    <?php include '../layouts/footer.php'; ?>
    <script src="/DTS/server-logic/config/auto_logout.js"></script>

    <script>
        $(function() {
            // Handle row clicks
            $(document).on('click', '.clickable-row', function(e) {
                // Prevent triggering if clicking actual links inside
                if (!$(e.target).is('a')) {
                    window.location.hash = $(this).data('href');
                    $($(this).data('href')).addClass('active').siblings().removeClass('active');
                }
            });

            // Keep existing search functionality
            $('#search-user').on('input', function() {
                const term = $(this).val().toLowerCase();
                $('#user-table tr').each(function() {
                    const $row = $(this);
                    const name = $row.find('a.client-link').text().toLowerCase();
                    $row.toggle(name.includes(term));
                });
            });
            if ($(window).width() < 768) {
                $('html, body').animate({
                    scrollTop: $(target).offset().top - 50
                }, 300);
            }
        });
    </script>
</body>

</html>