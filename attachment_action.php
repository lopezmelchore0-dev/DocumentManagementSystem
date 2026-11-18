<?php
/**
 * Additional attachments (record_attachments): delete one.
 * POST op=delete, id  (X-Requested-With: XMLHttpRequest). Admin and master only.
 * The file is removed from uploads/ as well, and the record gets an "Edited" history entry.
 */
include "session.php";
require_once __DIR__ . "/includes/record_attachments.php";

header('Content-Type: application/json; charset=utf-8');

function dms_att_reply($code, array $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    dms_att_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if ($role !== 'admin' && $role !== 'master') {
    dms_att_reply(403, ['success' => false, 'message' => 'You do not have permission to delete attachments.']);
}
$op = isset($_POST['op']) ? (string) $_POST['op'] : '';
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($op !== 'delete' || $id <= 0) {
    dms_att_reply(422, ['success' => false, 'message' => 'Nothing to do.']);
}

dms_ra_ensure_schema($conn);
$stmt = $conn->prepare("SELECT * FROM `record_attachments` WHERE `id` = ? LIMIT 1");
$att = null;
if ($stmt) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $att = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$att) {
    dms_att_reply(404, ['success' => false, 'message' => 'The attachment was already removed.']);
}

$del = $conn->prepare("DELETE FROM `record_attachments` WHERE `id` = ?");
if (!$del) {
    dms_att_reply(500, ['success' => false, 'message' => 'The attachment could not be deleted.']);
}
$del->bind_param('i', $id);
$del->execute();
$del->close();
dms_ra_unlink((string) $att['file_path']);

// History entry on the record, like the edit pages.
$cfg = dms_ra_module($att['module']);
if ($cfg) {
    $table = $cfg['table'];
    $rid = (int) $att['record_id'];
    $q = $conn->prepare("SELECT * FROM `$table` WHERE `id` = ? LIMIT 1");
    $row = null;
    if ($q) {
        $q->bind_param('i', $rid);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
    }
    if ($row) {
        $title = isset($row['title']) ? (string) $row['title'] : '';
        if ($att['module'] === 'ob') {
            require_once __DIR__ . "/includes/minutes_workflow.php";
            dms_ob_log_history($conn, $rid, $title, 'Edited', dms_ob_section($row), dms_ob_status($row));
        } else {
            $log = $conn->prepare("INSERT INTO history_log (action, file_type, file_id, title) VALUES ('Edited', ?, ?, ?)");
            if ($log) {
                $logType = $cfg['log'];
                $log->bind_param('sis', $logType, $rid, $title);
                $log->execute();
                $log->close();
            }
        }
    }
}

dms_att_reply(200, ['success' => true, 'message' => 'Attachment deleted.']);
