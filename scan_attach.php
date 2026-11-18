<?php
/**
 * Built-in document scanner (js/doc-scanner.js): attach one scanned PDF to an existing record.
 * The scanner sends one PDF per request so every file has its own progress bar and result.
 *
 * POST (multipart, X-Requested-With: XMLHttpRequest)
 *   module        resolution | ordinance | committee_report | ob
 *   record_id     id of the record
 *   field         attachment column (attachment, attachment_path, genAttachment)
 *   target        main  = becomes the record's attachment (replaces the current one)
 *                 extra = added to the record's additional attachments (record_attachments)
 *   client_token  random id of this PDF; sending it again (double click, retry after a lost
 *                 response) returns the first result instead of attaching a duplicate
 *   paper, orientation   for the attachment list
 *   file          the PDF
 * Admin and master only. Replies JSON: success, message, target, path, name, id (extra rows).
 */
include "session.php";
require_once __DIR__ . "/includes/upload_helper.php";
require_once __DIR__ . "/includes/record_attachments.php";

header('Content-Type: application/json; charset=utf-8');

function dms_scan_reply($code, array $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    dms_scan_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}

// A body larger than post_max_size arrives with empty $_POST / $_FILES.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    dms_scan_reply(413, ['success' => false, 'message' => 'The PDF is larger than the server allows (' . ini_get('post_max_size') . '). Save it as separate PDFs or with fewer pages.']);
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if ($role !== 'admin' && $role !== 'master') {
    dms_scan_reply(403, ['success' => false, 'message' => 'You do not have permission to attach files.']);
}

$str = function ($k) { return isset($_POST[$k]) && !is_array($_POST[$k]) ? trim((string) $_POST[$k]) : ''; };

$module = $str('module');
$cfg = dms_ra_module($module);
if (!$cfg) {
    dms_scan_reply(400, ['success' => false, 'message' => 'Unknown record type.']);
}
$recordId = (int) $str('record_id');
$field = $str('field');
if ($field === '') {
    $field = $cfg['extra'];
}
if (!in_array($field, $cfg['fields'], true)) {
    dms_scan_reply(400, ['success' => false, 'message' => 'Unknown attachment field.']);
}
$target = $str('target') === 'main' ? 'main' : 'extra';
if ($target === 'extra' && $field !== $cfg['extra']) {
    dms_scan_reply(400, ['success' => false, 'message' => 'This attachment holds one file only.']);
}
$token = preg_replace('/[^A-Za-z0-9_-]/', '', $str('client_token'));
$token = substr($token, 0, 64);
$paper = dms_ra_clean_paper($str('paper'));
$orientation = dms_ra_clean_orientation($str('orientation'));

// The record must exist.
$table = $cfg['table'];
$stmt = $conn->prepare("SELECT * FROM `$table` WHERE `id` = ? LIMIT 1");
$row = null;
if ($stmt) {
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$row) {
    dms_scan_reply(404, ['success' => false, 'message' => 'The record was not found. It may have been deleted.']);
}

dms_ra_ensure_schema($conn);

// Same PDF sent again → answer with the first result, attach nothing new.
if ($token !== '') {
    if (isset($_SESSION['dms_scan_tokens'][$token])) {
        $prev = $_SESSION['dms_scan_tokens'][$token];
        $prev['duplicate'] = true;
        dms_scan_reply(200, $prev);
    }
    $q = $conn->prepare("SELECT `id`, `file_path`, `file_name` FROM `record_attachments` WHERE `client_token` = ? LIMIT 1");
    if ($q) {
        $q->bind_param('s', $token);
        $q->execute();
        $dup = $q->get_result()->fetch_assoc();
        $q->close();
        if ($dup) {
            dms_scan_reply(200, ['success' => true, 'duplicate' => true, 'target' => 'extra', 'id' => (int) $dup['id'],
                'path' => $dup['file_path'], 'name' => $dup['file_name'], 'message' => 'Already attached.']);
        }
    }
}

if (empty($_FILES['file']) || is_array($_FILES['file']['name'])) {
    dms_scan_reply(422, ['success' => false, 'message' => 'No PDF was received.']);
}
$file = $_FILES['file'];
if ((int) $file['error'] !== UPLOAD_ERR_OK) {
    dms_scan_reply(422, ['success' => false, 'message' => dms_upload_error_text($file['error'])]);
}
if (!dms_ra_is_pdf($file['tmp_name'])) {
    dms_scan_reply(422, ['success' => false, 'message' => 'Only PDF files can be attached here.']);
}
$file['name'] = dms_ra_pdf_name($file['name']);

$error = null;
$path = dms_store_upload($file, $error);
if ($path === null) {
    dms_scan_reply(500, ['success' => false, 'message' => $error ?: 'The server could not save the file.']);
}
$abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
$size = (int) @filesize($abs);
$pages = dms_ra_pdf_page_count($abs);
$title = isset($row['title']) ? (string) $row['title'] : '';

$reply = ['success' => true, 'target' => $target, 'path' => $path, 'name' => basename($path), 'pages' => $pages, 'size' => $size];

if ($target === 'main') {
    $u = $conn->prepare("UPDATE `$table` SET `$field` = ? WHERE `id` = ?");
    $ok = false;
    if ($u) {
        $u->bind_param('si', $path, $recordId);
        $ok = $u->execute();
        $u->close();
    }
    if (!$ok) {
        dms_ra_unlink($path);
        dms_scan_reply(500, ['success' => false, 'message' => 'The attachment could not be saved to the record.']);
    }
    $reply['message'] = 'Attached as the main file.';
} else {
    $newId = dms_ra_insert($conn, $module, $recordId, $path, [
        'file_name' => basename($path), 'file_size' => $size, 'page_count' => $pages,
        'paper' => $paper, 'orientation' => $orientation, 'source' => 'scan',
        'client_token' => $token, 'uploaded_by' => dms_ra_current_user(),
    ]);
    if (!$newId) {
        dms_ra_unlink($path);
        dms_scan_reply(500, ['success' => false, 'message' => 'The attachment could not be saved to the record.']);
    }
    $reply['id'] = $newId;
    $reply['message'] = 'Added to the additional attachments.';
}

// History: the same "Edited" entry the edit pages write.
if ($module === 'ob') {
    require_once __DIR__ . "/includes/minutes_workflow.php";
    dms_ob_log_history($conn, $recordId, $title, 'Edited', dms_ob_section($row), dms_ob_status($row));
} else {
    $log = $conn->prepare("INSERT INTO history_log (action, file_type, file_id, title) VALUES ('Edited', ?, ?, ?)");
    if ($log) {
        $logType = $cfg['log'];
        $log->bind_param('sis', $logType, $recordId, $title);
        $log->execute();
        $log->close();
    }
}

if ($token !== '') {
    if (!isset($_SESSION['dms_scan_tokens']) || !is_array($_SESSION['dms_scan_tokens'])) {
        $_SESSION['dms_scan_tokens'] = [];
    }
    // Keep the most recent tokens only.
    if (count($_SESSION['dms_scan_tokens']) > 200) {
        $_SESSION['dms_scan_tokens'] = array_slice($_SESSION['dms_scan_tokens'], -100, null, true);
    }
    $_SESSION['dms_scan_tokens'][$token] = $reply;
}

dms_scan_reply(200, $reply);
