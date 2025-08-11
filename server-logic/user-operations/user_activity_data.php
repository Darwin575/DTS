<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';
global $conn;
function formatFriendlyDate(\DateTime $dt)
{
    $now       = new \DateTime('now', $dt->getTimezone());
    $today     = $now->format('Y-m-d');
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    $d         = $dt->format('Y-m-d');

    if ($d === $today) {
        $prefix = 'Today';
    } elseif ($d === $yesterday) {
        $prefix = 'Yesterday';
    } else {
        $prefix = $dt->format('M j, Y');
    }
    return sprintf('%s at %s', $prefix, $dt->format('h:i:s a'));
}

function getActivityFeed($conn, int $userId): array
{
    $feed = [];

    // 1) User-activity logs
    $sql = "
      SELECT activity_type, old_value, new_value, activity_time
      FROM tbl_user_activity_logs
      WHERE user_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $map = [
            'update_profile_picture' => 'Updated profile picture',
            'change_password'        => 'Changed password',
            'update_name'            => sprintf(
                'You changed name from <strong>%s</strong> to <strong>%s</strong>',
                htmlspecialchars($row['old_value']),
                htmlspecialchars($row['new_value'])
            ),
            'update_email'           => sprintf(
                'You changed email from <strong>%s</strong> to <strong>%s</strong>',
                htmlspecialchars($row['old_value']),
                htmlspecialchars($row['new_value'])
            ),
        ];
        $feed[] = [
            'timestamp' => $row['activity_time'],
            'badge'     => 'Change',
            'message'   => $map[$row['activity_type']],
        ];
    }
    $stmt->close();

    // 2) Document access (received)
    $sql = "
      SELECT a.access_type, a.access_time, d.subject
      FROM tbl_document_access AS a
      JOIN tbl_documents AS d ON d.document_id = a.document_id
      WHERE a.user_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $feed[] = [
            'timestamp' => $row['access_time'],
            'badge'     => 'Receive',
            'message'   => sprintf(
                'You received <strong>%s</strong> via %s',
                htmlspecialchars($row['subject']),
                strtoupper($row['access_type'])
            ),
        ];
    }
    $stmt->close();

    // 3) Routing actions
    // 3) Routing actions (all using out_at)
    $sql = "
SELECT r.status,
       r.out_at,
       r.comments,
       d.subject
FROM tbl_document_routes AS r
JOIN tbl_documents         AS d ON d.document_id = r.document_id
WHERE r.to_user_id = ?
";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        // base timestamp
        $ts = $row['out_at'];

        if ($row['status'] === 'completed') {
            $badge   = 'Approved';
            $message = sprintf(
                'You approved <strong>%s</strong>',
                htmlspecialchars($row['subject'])
            );
        } elseif ($row['status'] === 'rejected') {
            $badge   = 'Rejected';
            $message = sprintf(
                'You rejected <strong>%s</strong>',
                htmlspecialchars($row['subject'])
            );
        } else {
            // if there's a comment, show “Comment” badge
            if (empty($row['comments'])) {
                continue; // no comment → skip entirely
            }
            $badge   = 'Comment';
            $message = sprintf(
                'You commented on <strong>%s</strong>',
                htmlspecialchars($row['subject'])
            );
        }

        $feed[] = [
            'timestamp' => $ts,
            'badge'     => $badge,
            'message'   => $message,
        ];
    }

    $stmt->close();


    // 4) Document creations
    $sql = "
      SELECT subject, updated_at
      FROM tbl_documents
      WHERE user_id = ? AND status = 'active'
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $feed[] = [
            'timestamp' => $row['updated_at'],
            'badge'     => 'Create',
            'message'   => sprintf(
                'You created document <strong>%s</strong>',
                htmlspecialchars($row['subject'])
            ),
        ];
    }
    $stmt->close();

    // 5) E-signature updates
    // (requires you have an `esig_path_changed_at` column on tbl_users)
    // $sql = "
    //   SELECT updated_at
    //   FROM tbl_users
    //   WHERE user_id = ?
    // ";
    // $stmt = $conn->prepare($sql);
    // $stmt->bind_param('i', $userId);
    // $stmt->execute();
    // $res = $stmt->get_result();
    // if ($row = $res->fetch_assoc()) {
    //     if (!empty($row['esig_path_changed_at'])) {
    //         $feed[] = [
    //             'timestamp' => $row['esig_path_changed_at'],
    //             'badge'     => 'Update',
    //             'message'   => 'You updated your e-signature',
    //         ];
    //     }
    // }
    // $stmt->close();

    // sort descending by timestamp
    usort($feed, function ($a, $b) {
        return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
    });

    return $feed;
}
