<?php
/**
 * Order of Business: delete several agenda items at once (multi-select
 * right-click menu on files-meetingminutes.php, js/ob-status-menu.js).
 *
 * POST ids (comma-separated), password (the master account's password, the
 * same check as validate_password.php). Admin and master only.
 * Each deleted item gets a 'Deleted' history_log entry, like
 * deletemeetingminutes.php. Responds with JSON: success, deleted (ids), counts.
 */
include "session.php";
require_once __DIR__ . "/includes/minutes_workflow.php";

header('Content-Type: application/json; charset=utf-8');

function dms_ob_delete_reply($code, array $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    dms_ob_delete_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if ($role !== 'admin' && $role !== 'master') {
    dms_ob_delete_reply(403, ['success' => false, 'message' => 'You do not have permission to delete agenda items.']);
}

// Password check (same rule as validate_password.php), done here so the
// delete cannot be triggered without it.
$password = isset($_POST['password']) && !is_array($_POST['password']) ? (string) $_POST['password'] : '';
$hash = null;
$stmt = $conn->prepare("SELECT password FROM accounts WHERE role = 'master' LIMIT 1");
if ($stmt) {
    $stmt->execute();
    $acct = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $hash = $acct ? $acct['password'] : null;
}
if (!$hash || !password_verify($password, $hash)) {
    dms_ob_delete_reply(403, ['success' => false, 'message' => 'Incorrect password. Try again.']);
}

$raw = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string) $_POST['ids'])) : [];
$ids = array_values(array_unique(array_filter(array_map('intval', $raw), function ($v) { return $v > 0; })));
if (!$ids) {
    dms_ob_delete_reply(422, ['success' => false, 'message' => 'No agenda items were selected.']);
}
if (count($ids) > 1000) {
    dms_ob_delete_reply(422, ['success' => false, 'message' => 'Too many items at once (at most 1000).']);
}

$fetch = $conn->prepare("SELECT title FROM minutes WHERE id = ?");
$del   = $conn->prepare("DELETE FROM minutes WHERE id = ?");
$log   = $conn->prepare("INSERT INTO history_log (action, file_type, file_id, title) VALUES ('Deleted', 'Minutes', ?, ?)");
if (!$fetch || !$del || !$log) {
    dms_ob_delete_reply(500, ['success' => false, 'message' => 'The items could not be deleted.']);
}

$deleted = [];
foreach ($ids as $id) {
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    if (!$row) {
        continue;
    }
    $del->bind_param('i', $id);
    $del->execute();
    if ($del->affected_rows > 0) {
        $title = (string) $row['title'];
        $log->bind_param('is', $id, $title);
        $log->execute();
        $deleted[] = $id;
    }
}
$fetch->close();
$del->close();
$log->close();

// Additional (scanned) attachments: rows removed, files kept (like the items' own files)
require_once __DIR__ . '/includes/record_attachments.php';
foreach ($deleted as $delId) {
    dms_ra_delete_for_record($conn, 'ob', $delId, false);
}

dms_ob_delete_reply(200, [
    'success' => true,
    'deleted' => $deleted,
    'counts'  => dms_ob_section_counts($conn),
]);
