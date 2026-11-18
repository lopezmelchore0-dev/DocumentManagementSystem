<?php
/**
 * Order of Business: set an agenda item's status or move it to another
 * section (right-click menu on files-meetingminutes.php, js/ob-status-menu.js).
 *
 * POST id, op (status | move), value
 *   status: value is one of the item's section options, or '' to clear it
 *   move:   value is a section; the status is cleared
 *
 * Bulk (multi-select): POST ids (comma-separated) instead of id. Each item is
 * checked on its own; items the change does not apply to (status not offered
 * in their section, already in that section/status) are skipped.
 *
 * Each changed item is updated in place and gets one history_log entry.
 * Single: success, id, section, status, cellHtml, summaryHtml, counts.
 * Bulk:   success, items (as above, changed ones only), changed, skipped, counts.
 */
include "session.php";
require_once __DIR__ . "/includes/minutes_workflow.php";

header('Content-Type: application/json; charset=utf-8');

function dms_ob_status_reply($code, array $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dms_ob_status_load($conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM `minutes` WHERE `id` = ? LIMIT 1");
    $row = null;
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    return $row ?: null;
}

/**
 * Apply op/value to one item. Returns ['ok', 'changed', 'code', 'message',
 * 'item' (reply fields, only when ok)].
 */
function dms_ob_status_apply($conn, $id, $op, $value)
{
    $row = dms_ob_status_load($conn, $id);
    if (!$row) {
        return ['ok' => false, 'changed' => false, 'code' => 404, 'message' => 'The agenda item was not found.'];
    }

    $section = dms_ob_section($row);
    $status  = dms_ob_status($row);

    if ($op === 'status') {
        if (!dms_ob_valid_status($section, $value)) {
            return ['ok' => false, 'changed' => false, 'code' => 422, 'message' => '"' . $value . '" is not a status of ' . $section . '.'];
        }
        $in = ['section' => $section, 'status' => $value, 'resolutionNo' => '', 'resolutionDate' => ''];
    } else { // move
        // Each section has its own status options, so moving clears the status.
        $in = ['section' => $value, 'status' => $value === $section ? $status : '', 'resolutionNo' => '', 'resolutionDate' => ''];
    }

    $result = dms_ob_save_item($conn, $id, $in, $row, false);
    $label  = dms_ob_change_label($result);
    if ($label !== '') {
        dms_ob_log_history($conn, $id, (string) $row['title'], $label, $result['section'], $result['status']);
    }

    // Re-read so the returned HTML shows exactly what is stored.
    $fresh = dms_ob_status_load($conn, $id);
    if ($fresh) {
        $row = $fresh;
    }
    $schedules = dms_ob_load_schedules($conn, [$id]);
    $sched     = isset($schedules[$id]) ? $schedules[$id] : [];

    return [
        'ok'      => true,
        'changed' => $label !== '',
        'code'    => 200,
        'message' => '',
        'item'    => [
            'id'          => $id,
            'section'     => dms_ob_section($row),
            'status'      => dms_ob_status($row),
            'cellHtml'    => dms_ob_compact_html($row, $sched),
            'summaryHtml' => dms_ob_summary_html($row, $sched),
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    dms_ob_status_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if ($role !== 'admin' && $role !== 'master') {
    dms_ob_status_reply(403, ['success' => false, 'message' => 'You do not have permission to change the status.']);
}

$op    = isset($_POST['op']) ? (string) $_POST['op'] : '';
$value = isset($_POST['value']) && !is_array($_POST['value']) ? trim((string) $_POST['value']) : '';

if ($op !== 'status' && $op !== 'move') {
    dms_ob_status_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}
if ($op === 'move' && !in_array($value, dms_ob_sections(), true)) {
    dms_ob_status_reply(422, ['success' => false, 'message' => 'Unknown section.']);
}

dms_ob_ensure_schema($conn);

/* ---------- Bulk (multi-select) ---------- */
if (isset($_POST['ids'])) {
    $raw = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string) $_POST['ids']);
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), function ($v) { return $v > 0; })));
    if (!$ids) {
        dms_ob_status_reply(422, ['success' => false, 'message' => 'No agenda items were selected.']);
    }
    if (count($ids) > 1000) {
        dms_ob_status_reply(422, ['success' => false, 'message' => 'Too many items at once (at most 1000).']);
    }

    $items   = [];
    $skipped = 0;
    foreach ($ids as $id) {
        $r = dms_ob_status_apply($conn, $id, $op, $value);
        if ($r['ok'] && $r['changed']) {
            $items[] = $r['item'];
        } else {
            $skipped++;
        }
    }

    dms_ob_status_reply(200, [
        'success' => true,
        'items'   => $items,
        'changed' => count($items),
        'skipped' => $skipped,
        'counts'  => dms_ob_section_counts($conn),
    ]);
}

/* ---------- Single item ---------- */
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$r  = dms_ob_status_apply($conn, $id, $op, $value);
if (!$r['ok']) {
    dms_ob_status_reply($r['code'], ['success' => false, 'message' => $r['message']]);
}

dms_ob_status_reply(200, $r['item'] + ['success' => true, 'counts' => dms_ob_section_counts($conn)]);
