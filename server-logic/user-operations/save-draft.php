<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$user_id = SessionManager::get('user')['id'] ?? 0;

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $draft_id = intval($data['draft_id'] ?? 0);
    $subject = trim($data['subject'] ?? '');
    $remarks = trim($data['remarks'] ?? '');
    $actions = $data['actions'] ?? [];
    $other_action = trim($data['other_action'] ?? '');
    $urgency = trim($data['urgency'] ?? '');
    $file_name = $data['file_name'] ?? '';
    $file_path = $data['file_path'] ?? '';
    $file_type = $data['file_type'] ?? '';
    $file_size = $data['file_size'] ?? 0;

    // Merge other_action into actions if provided
    if ($other_action !== '') {
        $actions[] = $other_action;
    }
    $actions = array_filter($actions, function ($a) {
        return trim($a) !== '';
    });

    if ($draft_id > 0) {
        // Fetch current draft for change detection
        $stmt = $conn->prepare("SELECT subject, remarks, file_name, file_path, file_type, file_size, urgency FROM tbl_documents WHERE document_id=? AND user_id=?");
        $stmt->bind_param("ii", $draft_id, $user_id);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($cur_subject, $cur_remarks, $cur_file_name, $cur_file_path, $cur_file_type, $cur_file_size, $cur_urgency);
        $stmt->fetch();

        // Compare all fields
        $noChange = (
            $subject === $cur_subject &&
            $remarks === $cur_remarks &&
            $file_name === $cur_file_name &&
            $file_path === $cur_file_path &&
            $file_type === $cur_file_type &&
            intval($file_size) === intval($cur_file_size) &&
            $urgency === $cur_urgency
        );

        // Compare actions
        $existingActions = [];
        $res = $conn->query("SELECT action FROM tbl_document_actions WHERE document_id = $draft_id");
        while ($row = $res->fetch_assoc()) {
            $existingActions[] = $row['action'];
        }
        sort($existingActions);
        $submittedActions = $actions;
        sort($submittedActions);

        if ($noChange && $existingActions == $submittedActions) {
            echo json_encode([
                'success' => false,
                'message' => 'No changes detected. Please update at least one field before saving. This draft already exists with the current values.'
            ]);
            exit;
        }

        // Update existing draft by ID and user
        $stmt = $conn->prepare("
            UPDATE tbl_documents SET subject=?, remarks=?, file_name=?, file_path=?, file_type=?, file_size=?, urgency=?, updated_at=NOW()
            WHERE document_id=? AND user_id=?
        ");
        $stmt->bind_param("sssssisii", $subject, $remarks, $file_name, $file_path, $file_type, $file_size, $urgency, $draft_id, $user_id);
        $stmt->execute();
        $document_id = $draft_id;

        // Remove old actions
        $conn->query("DELETE FROM tbl_document_actions WHERE document_id = $document_id");
        if (!empty($actions)) {
            $actionStmt = $conn->prepare("INSERT INTO tbl_document_actions (document_id, action) VALUES (?, ?)");
            foreach ($actions as $action) {
                $actionStmt->bind_param("is", $document_id, $action);
                $actionStmt->execute();
            }
        }

        // Only try to unlink if $cur_file_path is set (editing)
        if ($cur_file_path && $cur_file_path !== $file_path && file_exists($cur_file_path)) {
            @unlink($cur_file_path);
        }

        echo json_encode(['success' => true, 'message' => 'Draft saved successfully']);
        exit;
    } else {
        // Check for duplicate subject only when inserting a new draft
        $check = $conn->prepare("SELECT document_id FROM tbl_documents WHERE subject = ? AND user_id = ? AND status = 'draft'");
        $check->bind_param("si", $subject, $user_id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'A draft with this subject already exists. Please use a different subject.'
            ]);
            exit;
        }

        // Insert new draft
        $stmt = $conn->prepare("
            INSERT INTO tbl_documents 
                (user_id, subject, remarks, file_name, file_path, file_type, file_size, urgency, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())
        ");
        $stmt->bind_param(
            "isssssis",
            $user_id,
            $subject,
            $remarks,
            $file_name,
            $file_path,
            $file_type,
            $file_size,
            $urgency
        );
        $stmt->execute();
        $document_id = $conn->insert_id;
    }


    // Insert actions into tbl_document_actions
    if (!empty($actions)) {
        $actionStmt = $conn->prepare("INSERT INTO tbl_document_actions (document_id, action) VALUES (?, ?)");
        foreach ($actions as $action) {
            $actionStmt->bind_param("is", $document_id, $action);
            $actionStmt->execute();
        }
    }

    echo json_encode(['success' => true, 'message' => 'Draft saved successfully']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
