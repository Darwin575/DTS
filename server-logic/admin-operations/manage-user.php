<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include the database configuration
include(__DIR__ . '/../config/db.php');
header('Content-Type: application/json');

// Check database connection
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'getUser') {
        $user_id = $_POST['user_id'] ?? 0;

        if (!$user_id) {
            echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
            exit();
        }

        $stmt = $conn->prepare("SELECT user_id, office_name, email, role, is_deactivated FROM tbl_users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        echo $result->num_rows > 0
            ? json_encode(['status' => 'success', 'data' => $result->fetch_assoc()])
            : json_encode(['status' => 'error', 'message' => 'User not found']);
    } elseif ($action === 'saveUser') {
        $operation = $_POST['operation'] ?? '';
        $user_id = $_POST['user_id'] ?? null;
        $office_name = $_POST['officeName'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (empty($operation)) {
            echo json_encode(['status' => 'error', 'message' => 'Operation parameter is required']);
            exit();
        }

        if ($operation === 'addUser') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
                exit();
            }

            $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE email = ? OR office_name = ?");
            $stmt->bind_param("ss", $email, $office_name);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email or Office name already exists.']);
                exit();
            }

            if (empty($password) || strlen($password) < 8 || !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&,])/', $password) || $password !== $confirm) {
                echo json_encode(['status' => 'error', 'message' => 'Password requirements not met or mismatched.']);
                exit();
            }
            $profilePicPath = '/DTS/uploads/profile/default_profile_pic.jpg';
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO tbl_users (office_name, email, password, role, profile_picture_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $office_name, $email, $hashed_password, $role, $profilePicPath);
            echo $stmt->execute()
                ? json_encode(['status' => 'success', 'message' => 'User added successfully.', 'user_id' => $conn->insert_id])
                : json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
        } elseif ($operation === 'editUser' && $user_id) {
            $stmt = $conn->prepare("SELECT office_name, email, role FROM tbl_users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'User not found']);
                exit();
            }

            $current_data = $result->fetch_assoc();

            $office_name = $office_name ?: $current_data['office_name'];
            $email = $email ?: $current_data['email'];
            $role = $role ?: $current_data['role'];

            // Email uniqueness
            if ($email !== $current_data['email']) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? AND user_id != ?");
                $stmt->bind_param("si", $email, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Email already exists in another user.']);
                    exit();
                }
            }

            // Office name uniqueness
            if ($office_name !== $current_data['office_name']) {
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE office_name = ? AND user_id != ?");
                $stmt->bind_param("si", $office_name, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Office name already exists in another user.']);
                    exit();
                }
            }

            // Check for exact match of email + role [+ password if provided] in other user
            if (!empty($password)) {
                if (strlen($password) < 8 || !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&,])/', $password) || $password !== $confirm) {
                    echo json_encode(['status' => 'error', 'message' => 'Password requirements not met or mismatched.']);
                    exit();
                }

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? AND role = ? AND user_id != ?");
                $stmt->bind_param("ssi", $email, $role, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Another user already has the same email and role. Please change the role.']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE tbl_users SET office_name = ?, email = ?, password = ?, role = ? WHERE user_id = ?");
                $stmt->bind_param("ssssi", $office_name, $email, $hashed_password, $role, $user_id);
            } else {
                $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? AND role = ? AND user_id != ?");
                $stmt->bind_param("ssi", $email, $role, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Another user already has the same email and role. Please change the role.']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE tbl_users SET office_name = ?, email = ?, role = ? WHERE user_id = ?");
                $stmt->bind_param("sssi", $office_name, $email, $role, $user_id);
            }

            echo $stmt->execute()
                ? json_encode(['status' => 'success', 'message' => 'User updated successfully.'])
                : json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid operation']);
        }
    } elseif ($action === 'toggleUserStatus') {
        $user_id = $_POST['user_id'] ?? 0;
        if (!$user_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid user ID']);
            exit();
        }

        // 1. Get the target user's role and status
        $stmt = $conn->prepare("
        SELECT role, is_deactivated
        FROM tbl_users
        WHERE user_id = ?
        LIMIT 1
    ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($role, $isDeactivated);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'User not found']);
            exit();
        }
        $stmt->close();

        // 2. If they're an active admin, check how many active admins remain
        if ($role === 'admin' && $isDeactivated == 0) {
            $countStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_users 
            WHERE role = 'admin' 
              AND is_deactivated = 0
        ");
            $countStmt->execute();
            $countStmt->bind_result($activeAdminCount);
            $countStmt->fetch();
            $countStmt->close();

            if ($activeAdminCount <= 1) {
                // can’t deactivate the last admin
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Cannot deactivate the last active admin'
                ]);
                exit();
            }
        }

        // 3. Safe to toggle
        $stmt = $conn->prepare("
        UPDATE tbl_users
        SET is_deactivated = NOT is_deactivated
        WHERE user_id = ?
    ");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User status updated']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $conn->error]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
