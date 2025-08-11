<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_init.php';

// CSRF validation
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}

$userId = SessionManager::get('user')['id'] ?? 0;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Fetch old profile picture, name, and email
$stmt = $conn->prepare("SELECT profile_picture_path, name, email FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$old = $stmt->get_result()->fetch_assoc();
$oldPic   = $old['profile_picture_path'] ?? null;
$oldName  = $old['name'] ?? '';
$oldEmail = $old['email'] ?? '';

// Handle file upload for profile picture
$profilePicPath = $oldPic;
if (!empty($_FILES['profile_picture']['tmp_name'])) {
    $uploadDir = '/DTS/uploads/profile/';
    $uploadPath = $_SERVER['DOCUMENT_ROOT'] . $uploadDir;

    // Create directory with more restrictive permissions
    if (!is_dir($uploadPath)) {
        if (!mkdir($uploadPath, 0755, true)) {
            // Handle directory creation failure
            error_log("Failed to create upload directory: " . $uploadPath);
            // Optionally return an error to the user
            exit("Error creating upload directory.");
        }
    }

    // Validate file type by content (example for images)
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $uploadedMimeType = mime_content_type($_FILES['profile_picture']['tmp_name']);

    if (!in_array($uploadedMimeType, $allowedMimeTypes)) {
        // Handle invalid file type
        error_log("Invalid file type uploaded: " . $uploadedMimeType);
        // Optionally return an error to the user
        exit("Invalid file type. Only JPG, PNG, and GIF are allowed.");
    }

    // Generate a unique and safe filename
    $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION); // Still get extension for saving
    $safeFilename = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext); // Add random bytes for uniqueness and lowercase extension
    $target = $uploadDir . $safeFilename;
    $fullTargetPath = $uploadPath . $safeFilename;

    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $fullTargetPath)) {
        $profilePicPath = $target;
        // Save $profilePicPath to your database
    } else {
        // Handle file move failure
        error_log("Failed to move uploaded file.");
        // Optionally return an error to the user
        exit("Error uploading file.");
    }

    // Optionally delete old file if not default (uncomment if needed)
    // if ($oldPic && strpos($oldPic, 'a1.jpg') === false && file_exists($_SERVER['DOCUMENT_ROOT'] . $oldPic)) {
    //     unlink($_SERVER['DOCUMENT_ROOT'] . $oldPic);
    // }
}

// Retrieve posted values for password, name, and email.
// For name and email, if not provided we fallback to the old values.
$password = $_POST['password'] ?? '';
$newName  = $_POST['name'] ?? $oldName;
$newEmail = $_POST['email'] ?? $oldEmail;

// Build the update query dynamically.
// We always update the profile picture, name, and email.
$fields = "profile_picture_path=?, name=?, email=?";
$params = [$profilePicPath, $newName, $newEmail];
$types = "sss";

// Append password update if provided.
if (!empty($password)) {
    // Prepend the password column update after profile_picture_path.
    $fields = "profile_picture_path=?, password=?, name=?, email=?";
    array_splice($params, 1, 0, [password_hash($password, PASSWORD_DEFAULT)]);
    $types = "sssss";
}

$params[] = $userId;
$types .= "i";

// Update the user info in tbl_users
$stmt = $conn->prepare("UPDATE tbl_users SET $fields WHERE user_id=?");
$stmt->bind_param($types, ...$params);
$stmt->execute();

// Fetch the updated user details for the session
$stmt = $conn->prepare("SELECT user_id, name, office_name, email, profile_picture_path, esig_path FROM tbl_users WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$updatedUser = $stmt->get_result()->fetch_assoc();

if ($updatedUser) {
    // Update the session with the new details
    SessionManager::set('user', [
        'id'                   => $updatedUser['user_id'],
        'name'                 => $updatedUser['name'],
        'office_name'          => $updatedUser['office_name'],
        'email'                => $updatedUser['email'],
        'profile_picture_path' => $updatedUser['profile_picture_path'],
        'esig_path'            => $updatedUser['esig_path'],
    ]);
}

// Prepare log entries for activity logging
$logs = [];

// Log profile picture update if changed
if ($oldPic !== $profilePicPath) {
    $logs[] = [
        'activity_type' => 'update_profile_picture',
        'old_value'     => $oldPic,
        'new_value'     => $profilePicPath
    ];
}

// Log password change if provided (sensitive info is masked)
if (!empty($password)) {
    $logs[] = [
        'activity_type' => 'change_password',
        'old_value'     => '********',
        'new_value'     => '********'
    ];
}

// Log name change if the name has been altered
if ($oldName !== $newName) {
    $logs[] = [
        'activity_type' => 'update_name',
        'old_value'     => $oldName,
        'new_value'     => $newName
    ];
}

// Log email change if the email has been altered
if ($oldEmail !== $newEmail) {
    $logs[] = [
        'activity_type' => 'update_email',
        'old_value'     => $oldEmail,
        'new_value'     => $newEmail
    ];
}

// Insert each log entry into tbl_user_activity_logs
foreach ($logs as $log) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    $stmt = $conn->prepare("INSERT INTO tbl_user_activity_logs (user_id, activity_type, old_value, new_value, activity_time, ip_address, user_agent) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
    $stmt->bind_param("isssss", $userId, $log['activity_type'], $log['old_value'], $log['new_value'], $ip_address, $user_agent);
    $stmt->execute();
}

echo json_encode(['status' => 'success']);
