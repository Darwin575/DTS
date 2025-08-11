<?php
require_once __DIR__ . '/../config/session_init.php';
require_once __DIR__ . '/../config/db.php';

global $conn;

function upsertDocumentSummary($conn, int $userId, string $summaryType, DateTime $asOf = null)
{
  $asOf = $asOf ?: new DateTime();

  // Calculate $start, $end, and $summaryDate based on the summary type
  switch ($summaryType) {
    case 'daily':
      $start = $asOf->format('Y-m-d 00:00:00');
      $end   = $asOf->format('Y-m-d 23:59:59');
      $summaryDate = $asOf->format('Y-m-d');
      break;

    case 'weekly':
      $startOfWeek = (clone $asOf)->modify('monday this week');
      $endOfWeek   = (clone $startOfWeek)->modify('sunday this week');
      // If run on Sunday, adjust to the previous week for consistent boundaries.
      if ($asOf->format('N') == '7') {
        $startOfWeek->modify('-7 days');
        $endOfWeek->modify('-7 days');
      }
      $start = $startOfWeek->format('Y-m-d 00:00:00');
      $end   = $endOfWeek->format('Y-m-d 23:59:59');
      // Use only the starting date as a representative date for the week.
      $summaryDate = $startOfWeek->format('Y-m-d');
      break;

    case 'monthly':
      $startOfMonth = (clone $asOf)->modify('first day of this month');
      $endOfMonth   = (clone $asOf)->modify('last day of this month');
      $start = $startOfMonth->format('Y-m-d 00:00:00');
      $end   = $endOfMonth->format('Y-m-d 23:59:59');
      // Use the first day of the month as summary date.
      $summaryDate = $startOfMonth->format('Y-m-d');
      break;

    case 'yearly':
      $startOfYear = (clone $asOf)->setDate((int)$asOf->format('Y'), 1, 1);
      $endOfYear   = (clone $asOf)->setDate((int)$asOf->format('Y'), 12, 31);
      $start = $startOfYear->format('Y-m-d 00:00:00');
      $end   = $endOfYear->format('Y-m-d 23:59:59');
      // Use consistent format (match fetch query)
      $summaryDate = $startOfYear->format('Y') . '_yearly';
      break;

    default:
      // Fallback to daily summary if unknown type
      $start = $asOf->format('Y-m-d 00:00:00');
      $end   = $asOf->format('Y-m-d 23:59:59');
      $summaryDate = $asOf->format('Y-m-d');
  }
  $onRoute = 0;
  $completed = 0;

  // 1) Count documents based on status and update time
  $sql = "
      SELECT
        SUM(status = 'active')   AS on_route,
        SUM(status = 'archived') AS completed
      FROM tbl_documents
      WHERE user_id = ?
        AND updated_at BETWEEN ? AND ?
    ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('iss', $userId, $start, $end);
  $stmt->execute();
  $stmt->bind_result($onRoute, $completed);
  $stmt->fetch();
  $stmt->close();

  $summaryId = null;

  // 2) Check if a summary record already exists for the given user, date, and type
  $check = $conn->prepare("
      SELECT summary_id
      FROM tbl_document_summary
      WHERE user_id = ? AND summary_date = ? AND summary_type = ?
    ");
  $check->bind_param('iss', $userId, $summaryDate, $summaryType);
  $check->execute();
  $check->bind_result($summaryId);
  $exists = (bool) $check->fetch();
  $check->close();

  if ($exists) {
    // 3a) UPDATE the existing summary record
    $upd = $conn->prepare("
          UPDATE tbl_document_summary
          SET
            on_route_documents  = ?,
            completed_documents = ?,
            updated_at          = NOW()
          WHERE summary_id = ?
        ");
    $upd->bind_param('iii', $onRoute, $completed, $summaryId);
    $upd->execute();
    $upd->close();
  } else {
    // 3b) INSERT a new summary record
    $ins = $conn->prepare("
          INSERT INTO tbl_document_summary
            (user_id, on_route_documents, completed_documents,
             summary_date, summary_type, created_at, updated_at)
          VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
    $ins->bind_param('iiiss', $userId, $onRoute, $completed, $summaryDate, $summaryType);
    $ins->execute();
    $ins->close();
  }
}
