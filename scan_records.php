<?php
/**
 * Record search for the top-bar Scan shortcut (js/doc-scanner.js, "pick" mode): after scanning, the
 * user chooses the existing Resolution / Ordinance / Order of Business item / Committee Report to
 * attach the PDFs to.
 *
 * GET type = recent | resolution | ordinance | committee_report | ob
 *            recent: the most recently added records of every type (from the "Created" history
 *            entries), newest first; with q it searches all four types.
 *     q    = text matched against the record number and title (and the OB session no.); empty = latest
 *     id   = load one record instead of searching (not with recent)
 * Admin and master only. Replies JSON: success, records [{type, id, number, title, date, session, added,
 * view_url, extra_count, fields: [{field, label, has_main, main_exists, extra_allowed}]}].
 */
include "session.php";
require_once __DIR__ . "/includes/upload_helper.php";
require_once __DIR__ . "/includes/record_attachments.php";

header('Content-Type: application/json; charset=utf-8');

function dms_scan_records_reply($code, array $body)
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Records of one type, as reply rows. $mode: 'ids' (array of ids, returned in that order),
 * 'search' (text), 'latest'.
 */
function dms_scan_records_fetch($conn, $type, $mode, $value, $limit)
{
    $cfg = dms_ra_module($type);
    $fieldLabels = [
        'attachment' => $type === 'ob' ? 'Supporting document' : 'Attachment',
        'attachment_path' => 'Attachment',
        'genAttachment' => 'Order of Business attachment',
    ];
    // Attachment columns are paths; very old rows may hold the file itself (blob) — never send those.
    $table = $cfg['table'];
    $cols = ['`id`', '`title`'];
    $cols[] = $cfg['number'] ? "`{$cfg['number']}` AS rec_number" : "'' AS rec_number";
    $cols[] = "`{$cfg['date']}` AS rec_date";
    $cols[] = !empty($cfg['session']) ? "`{$cfg['session']}` AS rec_session" : "'' AS rec_session";
    foreach ($cfg['fields'] as $f) {
        $cols[] = "IF(LENGTH(`$f`) > 1000, '[stored]', `$f`) AS `f_$f`";
    }
    $sql = 'SELECT ' . implode(', ', $cols) . " FROM `$table`";
    $types = '';
    $args = [];
    if ($mode === 'ids') {
        $ids = array_values(array_filter(array_map('intval', (array) $value)));
        if (!$ids) {
            return [];
        }
        $sql .= ' WHERE `id` IN (' . implode(',', $ids) . ')';
    } elseif ($mode === 'search' && $value !== '') {
        // LIKE with the user's text taken literally (% and _ escaped).
        $like = '%' . addcslashes($value, '%_\\') . '%';
        $where = ['`title` LIKE ?'];
        $args[] = $like;
        if ($cfg['number']) { $where[] = "`{$cfg['number']}` LIKE ?"; $args[] = $like; }
        if (!empty($cfg['session'])) { $where[] = "`{$cfg['session']}` LIKE ?"; $args[] = $like; }
        $types = str_repeat('s', count($args));
        if (ctype_digit($value)) { $where[] = '`id` = ?'; $args[] = (int) $value; $types .= 'i'; }
        $sql .= ' WHERE ' . implode(' OR ', $where);
    }
    $sql .= ' ORDER BY `id` DESC LIMIT ' . (int) $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$args);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[(int) $r['id']] = $r;
    }
    $stmt->close();

    // Number of additional attachments per record, in one query.
    $extra = [];
    if ($rows) {
        dms_ra_ensure_schema($conn);
        $er = $conn->query("SELECT record_id, COUNT(*) c FROM record_attachments WHERE module = '" . $conn->real_escape_string($type)
            . "' AND record_id IN (" . implode(',', array_keys($rows)) . ") GROUP BY record_id");
        if ($er) {
            while ($e = $er->fetch_assoc()) { $extra[(int) $e['record_id']] = (int) $e['c']; }
        }
    }

    $order = $mode === 'ids' ? array_values(array_filter(array_map('intval', (array) $value), function ($id) use ($rows) { return isset($rows[$id]); })) : array_keys($rows);
    $out = [];
    foreach ($order as $id) {
        $r = $rows[$id];
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags(htmlspecialchars_decode((string) $r['title']))));
        if (mb_strlen($title) > 160) {
            $title = mb_substr($title, 0, 157) . '…';
        }
        $date = (string) $r['rec_date'];
        if ($date === '0000-00-00') { $date = ''; }
        $fields = [];
        foreach ($cfg['fields'] as $f) {
            $path = (string) $r['f_' . $f];
            $fields[] = [
                'field' => $f,
                'label' => $fieldLabels[$f],
                'has_main' => $path !== '',
                'main_exists' => $path === '[stored]' ? true : dms_attachment_exists($path),
                'extra_allowed' => $f === $cfg['extra'],
            ];
        }
        $out[] = [
            'type' => $type,
            'id' => (int) $id,
            'number' => trim((string) $r['rec_number']),
            'title' => $title,
            'date' => $date,
            'session' => trim((string) $r['rec_session']),
            'added' => '',
            'view_url' => $cfg['view'] . '?id=' . (int) $id,
            'extra_count' => isset($extra[$id]) ? $extra[$id] : 0,
            'fields' => $fields,
        ];
    }
    return $out;
}

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    dms_scan_records_reply(400, ['success' => false, 'message' => 'Invalid request.']);
}
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if ($role !== 'admin' && $role !== 'master') {
    dms_scan_records_reply(403, ['success' => false, 'message' => 'You do not have permission to attach files.']);
}

$type = isset($_GET['type']) && !is_array($_GET['type']) ? (string) $_GET['type'] : '';
$q = isset($_GET['q']) && !is_array($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = mb_substr($q, 0, 100);
$oneId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($type === 'recent') {
    $records = [];
    if ($q !== '') {
        // Search every record type; a few best (newest) matches of each.
        foreach (['resolution', 'ordinance', 'ob', 'committee_report'] as $t) {
            $rows = dms_scan_records_fetch($conn, $t, 'search', $q, 8);
            if ($rows === null) {
                dms_scan_records_reply(500, ['success' => false, 'message' => 'The records could not be searched.']);
            }
            $records = array_merge($records, $rows);
        }
    } else {
        // Newest "Created" history entries → the records that still exist, newest first.
        $map = ['Resolution' => 'resolution', 'Ordinance' => 'ordinance', 'Minutes' => 'ob', 'Committee Report' => 'committee_report'];
        $res = $conn->query("SELECT file_type, file_id, MAX(`timestamp`) AS added, MAX(id) AS hid FROM history_log
                             WHERE action = 'Created' AND file_type IN ('Resolution','Ordinance','Minutes','Committee Report')
                             GROUP BY file_type, file_id ORDER BY hid DESC LIMIT 60");
        $want = [];      // [type, id, added] in order
        $byType = [];
        if ($res) {
            while ($h = $res->fetch_assoc()) {
                $t = $map[$h['file_type']];
                $want[] = [$t, (int) $h['file_id'], (string) $h['added']];
                $byType[$t][] = (int) $h['file_id'];
            }
        }
        $found = [];
        foreach ($byType as $t => $ids) {
            foreach ((array) dms_scan_records_fetch($conn, $t, 'ids', $ids, 60) as $row) {
                $found[$t . ':' . $row['id']] = $row;
            }
        }
        foreach ($want as $w) {
            $k = $w[0] . ':' . $w[1];
            if (isset($found[$k])) {
                $row = $found[$k];
                $row['added'] = substr($w[2], 0, 10);
                $records[] = $row;
                unset($found[$k]);
                if (count($records) >= 25) { break; }
            }
        }
    }
    dms_scan_records_reply(200, ['success' => true, 'type' => 'recent', 'records' => $records]);
}

$cfg = dms_ra_module($type);
if (!$cfg) {
    dms_scan_records_reply(400, ['success' => false, 'message' => 'Unknown record type.']);
}
$rows = $oneId > 0
    ? dms_scan_records_fetch($conn, $type, 'ids', [$oneId], 1)
    : dms_scan_records_fetch($conn, $type, $q !== '' ? 'search' : 'latest', $q, 25);
if ($rows === null) {
    dms_scan_records_reply(500, ['success' => false, 'message' => 'The records could not be searched.']);
}
dms_scan_records_reply(200, ['success' => true, 'type' => $type, 'noun' => $cfg['noun'], 'records' => $rows]);
