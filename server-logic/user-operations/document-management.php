<?php

declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
global $conn;


$rejected_doc_id = $_SESSION['rejected_doc_id'] ?? 0;
// Clear the session value after use to prevent accidental reuse

error_log('ania');
// Now use $rejected_doc_id in your logic
if ($rejected_doc_id > 0) {
    // Handle rejected document flow
}
// --- Utilities ---
function sanitize_str($input, $maxLen = 255)
{
    $input = strip_tags($input, '<b><i><u><strong><em><ul><ol><li><p><br>');
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $input = trim($input);
    if ($maxLen > 0) $input = mb_substr($input, 0, $maxLen);
    return $input;
}

function sanitize_textarea($input, $maxLen = 3000)
{
    $allowed = '<b><i><u><strong><em><ul><ol><li><p><br><a><h1><h2><h3><h4><h5><h6><blockquote><pre><code><table><thead><tbody><tr><td><th>';
    $input = strip_tags($input, $allowed);

    // Fixed: Changed to preg_replace_callback
    $input = preg_replace_callback(
        '/<([a-z][a-z0-9]*)((?:\s+[a-z0-9\-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?)*)\s*(\/?)\s*>/i',
        function ($matches) {
            $tag = $matches[1];
            $attrs = $matches[2];
            $attrs = preg_replace('/\s+(on\w+|style|class|id|data-\w+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $attrs);

            if ($tag === 'a') {
                // Fixed: Changed inner preg_replace to preg_replace_callback
                $attrs = preg_replace_callback(
                    '/\s+style\s*=\s*("[^"]*"|\'[^\']*\')/i',
                    function ($m) {
                        $style = trim($m[1], '"\'');
                        // Allow only color-related styles
                        if (preg_match('/^color\s*:\s*[^;]+;?$/i', $style)) {
                            return ' style="' . htmlspecialchars($style) . '"';
                        }
                        return '';
                    },
                    $attrs
                );
            }

            $close = $matches[3];
            return "<$tag$attrs$close>";
        },
        $input
    );

    $input = trim($input);
    if ($maxLen > 0) $input = mb_substr($input, 0, $maxLen);
    return $input;
}

function filter_action($action)
{
    $action = strip_tags($action);
    $action = htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $action = trim($action);
    return $action;
}

// --- Session User ---
$user_id = SessionManager::get('user')['id'] ?? 0;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Not logged in.']);
    exit;
}
try {
    $rejected_doc_id_from_url = $_GET['rejected_doc_id'] ?? '';
    if (!empty($rejected_doc_id_from_url)) {
        echo json_encode([
            'success' => true,
            'message' => 'Rejected document detected.'
        ]);
        exit;
    }

    // Continue with processing $_POST data if no rejected_doc_id in the URL

    // --- Gather Data from $_POST ---
    $action_type = sanitize_str($_POST['action'] ?? "save_draft", 32);
    $draft_id = intval($_POST['draft_id'] ?? 0);
    $subject = sanitize_str($_POST['subject'] ?? '', 255);
    $remarks = sanitize_textarea($_POST['remarks'] ?? '', 3000);
    if (trim($remarks) === '<p><br></p>') {
        $remarks = ''; // Convert empty summernote content to empty string
    }
    $actions = $_POST['actions'] ?? [];
    $other_action = sanitize_str($_POST['other_action'] ?? '', 100);
    $urgency = in_array($_POST['urgency'] ?? 'medium', ['low', 'medium', 'high']) ? $_POST['urgency'] : 'medium';
    $offices = isset($_POST['offices']) && is_array($_POST['offices']) ? $_POST['offices'] : [];

    // --- Process the Uploaded File or USE OLD FILE if exists ---
    $file_uploaded = false;
    if (isset($_FILES['uploaded_file']) && $_FILES['uploaded_file']['error'] === UPLOAD_ERR_OK) {
        // Retrieve file properties
        $originalName = basename($_FILES['uploaded_file']['name']);
        $file_name = sanitize_str($originalName, 255);
        $file_type = sanitize_str($_FILES['uploaded_file']['type'] ?? '', 100);
        $file_size = intval($_FILES['uploaded_file']['size'] ?? 0);

        // Define your target directory (adjust the path as needed)
        $targetDir = __DIR__ . '/../../documents/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        // Create a unique file name to prevent conflicts
        $newFileName = uniqid() . '_' . $file_name;
        $targetFile = $targetDir . $newFileName;

        // Attempt to move the uploaded file to your documents folder
        if (move_uploaded_file($_FILES['uploaded_file']['tmp_name'], $targetFile)) {
            $file_path = sanitize_str($targetFile, 255);
            $file_uploaded = true;
        } else {
            $file_path = '';
        }
    } elseif ($draft_id > 0) {
        if (isset($_POST['uploaded_file']) && trim($_POST['uploaded_file']) === '') {
            // User cleared the file; override previous file data.
            $file_name = '';
            $file_type = '';
            $file_size = 0;
            $file_path = '';
        } else {
            // No new file was uploaded, so pull the previous file info
            $qry = $conn->prepare("SELECT file_name, file_path, file_type, file_size FROM tbl_documents WHERE document_id = ? AND user_id = ?");
            $qry->bind_param("ii", $draft_id, $user_id);
            $qry->execute();
            $prev = $qry->get_result()->fetch_assoc();
            if ($prev) {
                $file_name = $prev['file_name'] ?? '';
                $file_path = $prev['file_path'] ?? '';
                $file_type = $prev['file_type'] ?? '';
                $file_size = intval($prev['file_size'] ?? 0);
            }
        }
    } else {
        // No file uploaded—assign empty values
        $file_name = '';
        $file_type = '';
        $file_size = 0;
        $file_path = '';
    }
    $name = '';
    $profilePicturePath = '';

    $stmt = $conn->prepare("
        SELECT `name`, `profile_picture_path`
        FROM `tbl_users`
        WHERE `user_id` = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    // Bind both result columns
    $stmt->bind_result($name, $profilePicturePath);
    if (!$stmt->fetch()) {
        // Handle case where user's name isn't found
        throw new Exception("User not found.");
    }
    $stmt->close();
    // --- Append any other custom processing for actions and offices ---
    // Prepare Actions
    if ($other_action !== '') {
        $actions[] = $other_action;
    }
    $actions = array_filter($actions, function ($a) {
        return filter_action($a) !== '';
    });
    $actions = array_map('filter_action', $actions);

    // Prepare Offices (basic sanitation and validation)
    $offices = array_filter($offices, function ($office) {
        return (bool)preg_match('/^[\w\s\-]+$/u', $office);
    });

    // --- Validation ---
    if ($action_type === "save_draft") {
        if ($subject === "") {
            echo json_encode(['success' => false, 'message' => 'Subject is required to save draft.']);
            exit;
        }
    } elseif ($action_type === "finalize_route") {
        if ($subject === "" || empty($actions) || empty($offices)) {
            echo json_encode([
                'success' => false,
                'message' => 'Please ensure subject, file upload, at least one action, and at least one recipient office are provided.'
            ]);
            exit;
        }
    }

    // --- Save/Update Document ---
    $is_new = ($draft_id == 0);

    if ($is_new) {
        $stmt = $conn->prepare(
            "INSERT INTO tbl_documents (user_id, subject, remarks, file_name, file_path, file_type, file_size, urgency, status, creator_name, profile_picture_path, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        // For a new record it makes sense to timestamp updated_at, regardless.
        $status = ($rejected_doc_id > 0) ? 'active' : 'draft';
        $stmt->bind_param("isssssissss", $user_id, $subject, $remarks, $file_name, $file_path, $file_type, $file_size, $urgency, $status, $name, $profilePicturePath);
        $stmt->execute();
        $document_id = $conn->insert_id;
    } else {
        if ($rejected_doc_id > 0) {
            // For rejected documents, update without updating the 'updated_at' field.
            $stmt = $conn->prepare(
                "UPDATE tbl_documents 
             SET subject = ?, remarks = ?, file_name = ?, file_path = ?, file_type = ?, file_size = ?, urgency = ?, status = ?, creator_name = ?, profile_picture_path = ?, updated_at = NOW()
             WHERE document_id = ? AND user_id = ?"
            );
            // Here, $status will be 'active' based on your logic when rejected.
            $status = 'active';
            $stmt->bind_param("sssssissssii", $subject, $remarks, $file_name, $file_path, $file_type, $file_size, $urgency, $status, $name, $profilePicturePath, $draft_id, $user_id);
        } else {
            // For regular updates, update and refresh the 'updated_at' timestamp.
            $stmt = $conn->prepare(
                "UPDATE tbl_documents 
             SET subject = ?, remarks = ?, file_name = ?, file_path = ?, file_type = ?, file_size = ?, urgency = ?, status = ?, creator_name = ?, updated_at = NOW()
             WHERE document_id = ? AND user_id = ?"
            );
            $status = 'draft'; // Or, if you want, you can use the ternary expression here as well.
            $stmt->bind_param("sssssisssii", $subject, $remarks, $file_name, $file_path, $file_type, $file_size, $urgency, $status, $name, $draft_id, $user_id);
        }
        $stmt->execute();
        $document_id = $draft_id;
    }


    // --- Save Actions ---
    $conn->query("DELETE FROM tbl_document_actions WHERE document_id = $document_id");
    if (!empty($actions)) {
        $actionStmt = $conn->prepare("INSERT INTO tbl_document_actions (document_id, action) VALUES (?, ?)");
        foreach ($actions as $action) {
            $actionStmt->bind_param("is", $document_id, $action);
            $actionStmt->execute();
        }
    }

    // --- Save Routes ---
    if (!empty($offices)) {

        if ($rejected_doc_id > 0) {
            // ---------- REJECTED DOCUMENT LOGIC: Partial Update ----------

            // (a) Build new sequence (from form data) – an ordered list of recipient user IDs.
            $new_sequence = [];
            foreach ($offices as $office_name) {
                $stmt_seq = $conn->prepare(
                    "SELECT user_id 
                 FROM tbl_users 
                 WHERE office_name = ? 
                   AND user_id != ?"
                );
                $stmt_seq->bind_param("si", $office_name, $user_id);
                $stmt_seq->execute();
                $res_seq = $stmt_seq->get_result();
                while ($row_seq = $res_seq->fetch_assoc()) {
                    $new_sequence[] = (int)$row_seq['user_id'];
                }
                $stmt_seq->close();
            }

            // (b) Retrieve the existing routes in order.
            // We also build an array of the existing recipient user IDs.
            $existing_routes = [];
            $existing_sequence = [];
            $result = $conn->query(
                "SELECT * 
             FROM tbl_document_routes 
             WHERE document_id = $document_id
             ORDER BY route_id ASC"
            );
            while ($row = $result->fetch_assoc()) {
                $existing_routes[] = $row;
                $existing_sequence[] = (int)$row['to_user_id'];
            }
            error_log("Offices: " . json_encode($offices));
            error_log("New Sequence: " . json_encode($new_sequence));
            error_log("Existing Sequence: " . json_encode($existing_sequence));


            // (c) Find the boundary based on unique rejected routes.
            // Instead of simply taking the last rejected route by order,
            // we create an array keyed by to_user_id so that duplicate rejections are counted once.
            $unique_rejected_indices = [];
            for ($i = 0; $i < count($existing_routes); $i++) {
                if (strtolower(trim($existing_routes[$i]['status'])) === 'rejected') {
                    // For the same to_user_id, this will override prior indices.
                    $unique_rejected_indices[$existing_routes[$i]['to_user_id']] = $i;
                }
            }

            // Now, if there is at least one unique rejected route, set the boundary index.
            if (!empty($unique_rejected_indices)) {
                $boundary_index = max($unique_rejected_indices);
            } else {
                $boundary_index = null;
            }

            if ($boundary_index === null) {
                // No unique rejected route found: fall back to normal (full update) logic.
                $conn->query("DELETE FROM tbl_document_routes WHERE document_id = $document_id");
                foreach ($new_sequence as $to_user_id) {
                    // Get recipient details.
                    $stmt_user = $conn->prepare(
                        "SELECT name, profile_picture_path FROM tbl_users WHERE user_id = ?"
                    );
                    $stmt_user->bind_param("i", $to_user_id);
                    $stmt_user->execute();
                    $res_user = $stmt_user->get_result();
                    if ($row_user = $res_user->fetch_assoc()) {
                        $recipient_name = $row_user['name'];
                        $profilePicturePath = $row_user['profile_picture_path'];
                    } else {
                        $recipient_name = "";
                        $profilePicturePath = "";
                    }
                    $stmt_user->close();

                    $status = 'pending';
                    $stmt_insert = $conn->prepare(
                        "INSERT INTO tbl_document_routes
             (document_id, from_user_id, to_user_id, recipient_name, profile_picture_path, status, in_at, out_at, comments, esig_path)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)"
                    );
                    $stmt_insert->bind_param("iiisss", $document_id, $user_id, $to_user_id, $recipient_name, $profilePicturePath, $status);
                    $stmt_insert->execute();
                    $stmt_insert->close();
                }
            } else {
                error_log("Unique rejected boundary found at index: " . $boundary_index);
                error_log("New Sequence: " . json_encode($new_sequence));
                error_log("Existing Sequence: " . json_encode($existing_sequence));

                // Option 2 EX: Build a prefix that ignores rejected routes entirely.
                $nonRejected_existing = [];
                foreach ($existing_routes as $route) {
                    if (strtolower(trim($route['status'])) !== 'rejected') {
                        $nonRejected_existing[] = (int)$route['to_user_id'];
                    }
                }
                error_log("Non-Rejected Existing Prefix: " . json_encode($nonRejected_existing));

                // Use the count of the non-rejected prefix as expected.
                $expected_prefix_length = count($nonRejected_existing);

                if (count($new_sequence) < $expected_prefix_length) {
                    error_log("New sequence count (" . count($new_sequence) . ") is less than expected non-rejected prefix length ($expected_prefix_length).");
                    $full_update_required = false;  // Decide your policy here.
                } else {
                    $new_prefix = array_slice($new_sequence, 0, $expected_prefix_length);
                    if ($new_prefix === $nonRejected_existing) {
                        error_log("New and non-rejected existing prefixes match; no full update required.");
                        $full_update_required = false;
                    } else {
                        error_log("New and non-rejected existing prefixes do not match; full update required.");
                        $full_update_required = true;
                    }
                }

                if ($full_update_required) {
                    // Update document status to 'draft'
                    $updateDocStmt = $conn->prepare("UPDATE tbl_documents SET status='draft', updated_at=NOW() WHERE document_id=?");
                    $updateDocStmt->bind_param("i", $document_id);
                    $updateDocStmt->execute();
                    $updateDocStmt->close();
                    // Full update: Delete and reinsert all routes.
                    $conn->query("DELETE FROM tbl_document_routes WHERE document_id = $document_id");
                    foreach ($new_sequence as $to_user_id) {
                        $stmt_user = $conn->prepare(
                            "SELECT name, profile_picture_path FROM tbl_users WHERE user_id = ?"
                        );
                        $stmt_user->bind_param("i", $to_user_id);
                        $stmt_user->execute();
                        $res_user = $stmt_user->get_result();
                        if ($row_user = $res_user->fetch_assoc()) {
                            $recipient_name = $row_user['name'];
                            $profilePicturePath = $row_user['profile_picture_path'];
                        } else {
                            $recipient_name = "";
                            $profilePicturePath = "";
                        }
                        $stmt_user->close();

                        $status = 'pending';
                        $stmt_insert = $conn->prepare(
                            "INSERT INTO tbl_document_routes
                 (document_id, from_user_id, to_user_id, recipient_name, profile_picture_path, status, in_at, out_at, comments, esig_path)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)"
                        );
                        $stmt_insert->bind_param("iiisss", $document_id, $user_id, $to_user_id, $recipient_name, $profilePicturePath, $status);
                        $stmt_insert->execute();
                        $stmt_insert->close();
                    }
                } else {
                    error_log("Proceeding with partial tail rebuild using unique rejected route (Option 2).");
                    // (e) Partial Tail Rebuild with Rejected Row Copy:
                    // Step 1: Delete existing routes after the unique rejected boundary.
                    for ($i = $boundary_index + 1; $i < count($existing_routes); $i++) {
                        $stmtDel = $conn->prepare("DELETE FROM tbl_document_routes WHERE route_id = ?");
                        $stmtDel->bind_param("i", $existing_routes[$i]['route_id']);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }

                    // Step 2: Copy the (latest) rejected row's basic info as a new pending row.
                    $rejectedRow = $existing_routes[$boundary_index];
                    $document_id = $rejectedRow['document_id'];
                    $from_user_id_copy = $rejectedRow['from_user_id'];
                    $to_user_id_copy = $rejectedRow['to_user_id'];
                    $recipient_name = $rejectedRow['recipient_name'];
                    $profilePicturePath = $rejectedRow['profile_picture_path'];

                    // Insert a new copy but with pending status.
                    $status = 'pending';
                    $stmt_copy = $conn->prepare(
                        "INSERT INTO tbl_document_routes
                (document_id, from_user_id, to_user_id, recipient_name, profile_picture_path, status, in_at, out_at, comments, esig_path)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)"
                    );
                    $stmt_copy->bind_param("iiisss", $document_id, $from_user_id_copy, $to_user_id_copy, $recipient_name, $profilePicturePath, $status);
                    $stmt_copy->execute();
                    $stmt_copy->close();

                    // Step 3: Rebuild the tail routes from new_sequence after this rejection.
                    $tail = array_slice($new_sequence, $boundary_index + 1);
                    foreach ($tail as $to_user_id) {
                        $stmt_user = $conn->prepare("SELECT name, profile_picture_path FROM tbl_users WHERE user_id = ?");
                        $stmt_user->bind_param("i", $to_user_id);
                        $stmt_user->execute();
                        $res_user = $stmt_user->get_result();
                        if ($row_user = $res_user->fetch_assoc()) {
                            $recipient_name = $row_user['name'];
                            $profilePicturePath = $row_user['profile_picture_path'];
                        } else {
                            $recipient_name = "";
                            $profilePicturePath = "";
                        }
                        $stmt_user->close();

                        $status = 'pending';
                        $stmt_insert = $conn->prepare(
                            "INSERT INTO tbl_document_routes
                    (document_id, from_user_id, to_user_id, recipient_name, profile_picture_path, status, in_at, out_at, comments, esig_path)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)"
                        );
                        $stmt_insert->bind_param("iiisss", $document_id, $user_id, $to_user_id, $recipient_name, $profilePicturePath, $status);
                        $stmt_insert->execute();
                        $stmt_insert->close();
                    }
                    unset($_SESSION['rejected_doc_id']);
                }
            }
        } else {
            error_log("Nia rako.");

            // ---------- NORMAL (Non-Rejected) DOCUMENT LOGIC ----------
            // This is your usual logic: update existing routes, insert new ones, and delete those that no longer exist.
            $existing_routes = [];
            $result = $conn->query(
                "SELECT route_id, to_user_id, comments, esig_path 
             FROM tbl_document_routes 
             WHERE document_id = $document_id"
            );
            while ($row = $result->fetch_assoc()) {
                $existing_routes[$row['to_user_id']] = $row;
            }

            $new_to_user_ids = [];

            foreach ($offices as $office_name) {
                $stmt = $conn->prepare(
                    "SELECT user_id, name, profile_picture_path 
                 FROM tbl_users 
                 WHERE office_name = ? 
                   AND user_id != ?"
                );
                $stmt->bind_param("si", $office_name, $user_id);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($row = $res->fetch_assoc()) {
                    $to_user_id         = (int)$row['user_id'];
                    $recipient_name     = $row['name'];
                    $profilePicturePath = $row['profile_picture_path'];
                    $status             = 'pending';

                    $new_to_user_ids[]  = $to_user_id;

                    if (isset($existing_routes[$to_user_id])) {
                        $route_id = $existing_routes[$to_user_id]['route_id'];
                        $stmt2 = $conn->prepare(
                            "UPDATE tbl_document_routes
                         SET document_id = ?, 
                             from_user_id = ?, 
                             recipient_name = ?, 
                             profile_picture_path = ?, 
                             status = ?
                         WHERE route_id = ?"
                        );
                        $stmt2->bind_param(
                            "iisssi",
                            $document_id,
                            $user_id,
                            $recipient_name,
                            $profilePicturePath,
                            $status,
                            $route_id
                        );
                        $stmt2->execute();
                    } else {
                        $routeStmt = $conn->prepare(
                            "INSERT INTO tbl_document_routes
                         (document_id, from_user_id, to_user_id, recipient_name, profile_picture_path, status, in_at, out_at, comments, esig_path)
                         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)"
                        );
                        $routeStmt->bind_param(
                            "iiisss",
                            $document_id,
                            $user_id,
                            $to_user_id,
                            $recipient_name,
                            $profilePicturePath,
                            $status
                        );
                        $routeStmt->execute();
                    }
                }
                $stmt->close();
            }

            if (!empty($existing_routes)) {
                error_log("ajos");
                $to_keep = array_flip($new_to_user_ids);
                foreach ($existing_routes as $old_to_user_id => $route_data) {
                    if (!isset($to_keep[$old_to_user_id])) {
                        $stmtDel = $conn->prepare(
                            "DELETE FROM tbl_document_routes
                         WHERE route_id = ?"
                        );
                        $stmtDel->bind_param("i", $route_data['route_id']);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }
                }
            }
        }
    }


    function getLocalIpAddress()
    {
        unset($_SESSION['rejected_doc_id']);
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new Exception("Socket creation failed: " . socket_strerror(socket_last_error()));
        }
        if (!@socket_connect($socket, '8.8.8.8', 53)) {
            socket_close($socket);
            throw new Exception("Socket connection failed: " . socket_strerror(socket_last_error()));
        }
        socket_getsockname($socket, $localIp);
        socket_close($socket);
        return $localIp;
    }

    try {
        $localIp = getLocalIpAddress();
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => "Unable to determine local IP: " . $e->getMessage()
        ]);
        exit;
    }

    // Make sure these variables are defined earlier in your code:
    /// $file_path holds the absolute filesystem path. Example:
    /// "C:/Users/canet/OneDrive/Desktop/kikay/THESIS FILES/xampp/htdocs/DTS/documents/682aed5672f9e_print1.pdf"
    /// $is_new and $document_id should be set as appropriate.


    // Normalize the file path to use forward slashes.
    $normalizedFilePath = str_replace('\\', '/', $file_path);

    // Find the last occurrence of '/DTS/' to extract the relative portion.
    // Using strrpos ensures we capture the DTS folder that sits within your htdocs directory.
    $pos = strrpos($normalizedFilePath, '/DTS/');
    if ($pos !== false) {
        // +strlen('/DTS/') moves past the DTS folder in the path.
        $relativePath = substr($normalizedFilePath, $pos + strlen('/DTS/'));
    } else {
        // Optionally, if '/DTS/' is not found, handle the error or fallback:
        // For now, we fallback to using the normalized file path.
        $relativePath = $normalizedFilePath;
    }

    // Ensure that the relative path starts with a slash.
    if (substr($relativePath, 0, 1) !== '/') {
        $relativePath = '/' . $relativePath;
    }

    // Build the final URL using the local IP and the relative path.
    // This should create a URL similar to:
    // http://192.168.1.3/DTS/documents/682aed5672f9e_print1.pdf
    $finalUrl = "http://{$localIp}/DTS" . $relativePath;

    echo json_encode([
        'success'   => true,
        'message'   => $is_new
            ? 'Document created and data saved successfully.'
            : 'Document updated and data saved successfully.',
        'draft_id'  => $document_id,
        'file_path' => $finalUrl
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
