<?php
/**
 * Delete several Resolutions, Ordinances or Committee Reports at once
 * (Select / Select all on files-resolution.php, files-ordinances.php and
 * files-committee-report.php, js/bulk-select.js).
 *
 * POST type (resolution | ordinance | committee), ids (comma-separated),
 * password (the master account's password, the same check as
 * validate_password.php). Admin and master only.
 *
 * Each deleted record gets a 'Deleted' history_log entry, like the single
 * delete pages. Attachment files are removed the way the single delete pages
 * do (resolutions and committee reports), but only when no other record still
 * points at the same file: older data has files shared between records.
 * Responds with JSON: success, deleted (ids).
 */
include "session.php";

header('Content-Type: application/json; charset=utf-8');

function dms_bulk_delete_reply($code, array $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// table, title column, attachment column (null = keep the file, like deleteordinance.php), history_log file_type
$types = [
    'resolution' => ['table' => 'resolution',        'file' => 'attachment',      'log' => 'Resolution',       'noun' => 'resolutions'],
    'ordinance'  => ['table' => 'ordinance',         'file' => null,              'log' => 'Ordinance',        'noun' => 'ordinances'],
    'committee'  => ['table' => 'committee_reports', 'file' => 'attachment_path', 'log' => 'Committee Report', 'noun' => 'committee reports'],
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    dms_bulk_delete_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}

$type = isset($_POST['type']) && !is_array($_POST['type']) ? (string) $_POST['type'] : '';
if (!isset($types[$type])) {
    dms_bulk_delete_reply(400, ['success' => false, 'message' => 'Unknown record type.']);
}
$cfg = $types[$type];

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if ($role !== 'admin' && $role !== 'master') {
    dms_bulk_delete_reply(403, ['success' => false, 'message' => 'You do not have permission to delete ' . $cfg['noun'] . '.']);
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
    dms_bulk_delete_reply(403, ['success' => false, 'message' => 'Incorrect password. Try again.']);
}

$raw = isset($_POST['ids']) ? (is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string) $_POST['ids'])) : [];
$ids = array_values(array_unique(array_filter(array_map('intval', $raw), function ($v) { return $v > 0; })));
if (!$ids) {
    dms_bulk_delete_reply(422, ['success' => false, 'message' => 'Nothing was selected.']);
}
if (count($ids) > 1000) {
    // js/bulk-select.js sends large selections in chunks of 500, so this is only a guard.
    dms_bulk_delete_reply(422, ['success' => false, 'message' => 'Too many records at once (at most 1000).']);
}

$table = $cfg['table'];
$fileCol = $cfg['file'];
// Attachment columns may hold a stored file (blob) instead of a path: only short values are paths.
$fileSql = $fileCol ? ", IF(LENGTH(`$fileCol`) > 1000, '', `$fileCol`) AS file_path" : ", '' AS file_path";

$fetch = $conn->prepare("SELECT title $fileSql FROM `$table` WHERE id = ?");
$del   = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
$log   = $conn->prepare("INSERT INTO history_log (action, file_type, file_id, title) VALUES ('Deleted', ?, ?, ?)");
if (!$fetch || !$del || !$log) {
    dms_bulk_delete_reply(500, ['success' => false, 'message' => 'The records could not be deleted.']);
}

/**
 * Of $paths, the ones some record (of any type) still points at, as path => true.
 * Returns null when it cannot tell (a query failed): then no file is removed.
 * One query per table/column for the whole list, not one per file.
 */
function dms_bulk_files_in_use($conn, array $paths)
{
    $checks = [
        ['resolution', 'attachment'],
        ['ordinance', 'attachment'],
        ['minutes', 'attachment'],
        ['minutes', 'genAttachment'],
        ['committee_reports', 'attachment_path'],
    ];
    $used = [];
    foreach (array_chunk($paths, 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $kinds = str_repeat('s', count($chunk));
        foreach ($checks as $c) {
            $q = $conn->prepare("SELECT DISTINCT `{$c[1]}` AS p FROM `{$c[0]}` WHERE `{$c[1]}` IN ($marks)");
            if (!$q) {
                return null;
            }
            $q->bind_param($kinds, ...$chunk);
            if (!$q->execute()) {
                $q->close();
                return null;
            }
            $res = $q->get_result();
            while ($r = $res->fetch_assoc()) {
                $used[(string) $r['p']] = true;
            }
            $q->close();
        }
    }
    return $used;
}

$deleted = [];
$files = [];
// One transaction for the whole request: far faster than a commit per row,
// and a failure part-way leaves nothing half-deleted.
$conn->begin_transaction();
$ok = true;
foreach ($ids as $id) {
    $fetch->bind_param('i', $id);
    if (!$fetch->execute()) {
        $ok = false;
        break;
    }
    $row = $fetch->get_result()->fetch_assoc();
    if (!$row) {
        continue;
    }
    $del->bind_param('i', $id);
    if (!$del->execute()) {
        $ok = false;
        break;
    }
    if ($del->affected_rows > 0) {
        $title = (string) $row['title'];
        $logType = $cfg['log'];
        $log->bind_param('sis', $logType, $id, $title);
        $log->execute();
        $deleted[] = $id;
        if ((string) $row['file_path'] !== '') {
            $files[(string) $row['file_path']] = true;
        }
    }
}
$fetch->close();
$del->close();
$log->close();
if (!$ok || !$conn->commit()) {
    $conn->rollback();
    dms_bulk_delete_reply(500, ['success' => false, 'message' => 'The records could not be deleted.']);
}

// Additional (scanned) attachments of the deleted records; files follow the type's rule above.
require_once __DIR__ . '/includes/record_attachments.php';
$raModules = ['resolution' => 'resolution', 'ordinance' => 'ordinance', 'committee' => 'committee_report'];
foreach ($deleted as $delId) {
    dms_ra_delete_for_record($conn, $raModules[$type], $delId, $cfg['file'] !== null);
}

// Remove attachment files no remaining record uses (only files inside uploads/).
$uploads = realpath(__DIR__ . '/uploads');
$inUse = $files ? dms_bulk_files_in_use($conn, array_map('strval', array_keys($files))) : [];
if ($uploads && $inUse !== null) {
    foreach (array_keys($files) as $path) {
        $path = (string) $path;
        if (isset($inUse[$path])) {
            continue;
        }
        $full = realpath(__DIR__ . '/' . ltrim($path, '/\\'));
        if (!$full || !is_file($full) || strpos($full, $uploads . DIRECTORY_SEPARATOR) !== 0) {
            continue;
        }
        @unlink($full);
    }
}

dms_bulk_delete_reply(200, [
    'success' => true,
    'deleted' => $deleted,
]);
