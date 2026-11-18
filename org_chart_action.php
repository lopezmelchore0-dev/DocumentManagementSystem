<?php
/**
 * Organizational Chart edits from files-officials.php (js/org-chart.js). POST only, JSON out,
 * master/admin only. See includes/org_chart.php for the data model.
 */
include 'session.php';
require_once __DIR__ . '/includes/org_chart.php';

header('Content-Type: application/json');

function org_out($ok, $extra = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok], $extra));
    exit;
}

function org_fail($message, $code = 400)
{
    org_out(false, ['message' => $message], $code);
}

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['master', 'admin'], true)) {
    org_fail('You are not allowed to edit the organizational chart.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    org_fail('POST required.', 405);
}

dms_org_ensure_schema($conn);

$action = $_POST['action'] ?? '';
$str = function ($key, $max = 255) {
    $v = trim((string) ($_POST[$key] ?? ''));
    $v = preg_replace('/\s+/u', ' ', $v);
    return mb_substr($v, 0, $max);
};
$int = function ($key) {
    return isset($_POST[$key]) ? (int) $_POST[$key] : 0;
};

/** Renumbers groups 1..n in their current order; returns their ids in that order. */
$normalizeGroups = function () use ($conn) {
    $ids = array_map(function ($g) { return $g['id']; }, dms_org_groups($conn));
    $stmt = $conn->prepare("UPDATE `org_chart_groups` SET `sort_order` = ? WHERE `id` = ?");
    foreach ($ids as $i => $id) {
        $o = $i + 1;
        $stmt->bind_param('ii', $o, $id);
        $stmt->execute();
    }
    return $ids;
};

/** Validates an uploaded image in $_FILES[$key] and stores it; returns "uploads/..." or fails. */
$storePhoto = function ($key) {
    $file = $_FILES[$key];
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        org_fail('Please choose a JPG, PNG, GIF or WebP image.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && @getimagesize($file['tmp_name']) === false) {
        org_fail('That file is not a valid image.');
    }
    $error = null;
    $path = dms_store_upload($file, $error);
    if ($path === null) {
        org_fail($error ?: 'The photo could not be saved.');
    }
    return $path;
};

try {
    switch ($action) {

        case 'meta': {
            $key = $_POST['key'] ?? '';
            if (!array_key_exists($key, dms_org_default_meta())) {
                org_fail('Unknown setting.');
            }
            $value = $str('value');
            if ($key === 'title' && $value === '') {
                $value = dms_org_default_meta()['title'];
            }
            $stmt = $conn->prepare("INSERT INTO `org_chart_meta` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)");
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
            org_out(true, ['value' => $value]);
        }

        case 'group_add': {
            $title = $str('title', 150);
            if ($title === '') {
                $title = 'New Group';
            }
            $palette = dms_org_palette();
            $count = count(dms_org_groups($conn));
            $color = $palette[$count % count($palette)];
            $res = $conn->query("SELECT COALESCE(MAX(`sort_order`), 0) + 1 AS n FROM `org_chart_groups`");
            $order = (int) $res->fetch_assoc()['n'];
            $stmt = $conn->prepare("INSERT INTO `org_chart_groups` (`title`, `color`, `sort_order`) VALUES (?, ?, ?)");
            $stmt->bind_param('ssi', $title, $color, $order);
            $stmt->execute();
            org_out(true, ['id' => (int) $conn->insert_id]);
        }

        case 'group_update': {
            $id = $int('id');
            if (!dms_org_group_exists($conn, $id)) {
                org_fail('That group no longer exists.', 404);
            }
            if (isset($_POST['title'])) {
                $title = $str('title', 150);
                if ($title === '') {
                    org_fail('The group title cannot be empty.');
                }
                $stmt = $conn->prepare("UPDATE `org_chart_groups` SET `title` = ? WHERE `id` = ?");
                $stmt->bind_param('si', $title, $id);
                $stmt->execute();
            }
            if (isset($_POST['color'])) {
                $color = strtoupper(trim((string) $_POST['color']));
                if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
                    org_fail('Invalid colour.');
                }
                $stmt = $conn->prepare("UPDATE `org_chart_groups` SET `color` = ? WHERE `id` = ?");
                $stmt->bind_param('si', $color, $id);
                $stmt->execute();
            }
            org_out(true);
        }

        case 'group_move': {
            $id = $int('id');
            $ids = $normalizeGroups();
            $i = array_search($id, $ids, true);
            if ($i === false) {
                org_fail('That group no longer exists.', 404);
            }
            $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
            if ($j < 0 || $j >= count($ids)) {
                org_out(true);
            }
            $stmt = $conn->prepare("UPDATE `org_chart_groups` SET `sort_order` = ? WHERE `id` = ?");
            $a = $j + 1; $b = $i + 1;
            $stmt->bind_param('ii', $a, $ids[$i]);
            $stmt->execute();
            $stmt->bind_param('ii', $b, $ids[$j]);
            $stmt->execute();
            org_out(true);
        }

        case 'group_delete': {
            $id = $int('id');
            $ids = $normalizeGroups();
            $i = array_search($id, $ids, true);
            if ($i === false) {
                org_fail('That group no longer exists.', 404);
            }
            if (count($ids) < 2) {
                org_fail('The chart needs at least one group.');
            }
            $target = $ids[$i > 0 ? $i - 1 : 1];
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE `officials` SET `group_id` = ?, `sort_order` = `sort_order` + 1000 WHERE `group_id` = ?");
            $stmt->bind_param('ii', $target, $id);
            $stmt->execute();
            $moved = $stmt->affected_rows;
            $stmt = $conn->prepare("DELETE FROM `org_chart_groups` WHERE `id` = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $conn->commit();
            org_out(true, ['moved' => $moved]);
        }

        case 'official_move': {
            $id = $int('id');
            $group = $int('group_id');
            if (!dms_org_group_exists($conn, $group)) {
                org_fail('That group no longer exists.', 404);
            }
            $order = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['order'] ?? '')))));
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE `officials` SET `group_id` = ? WHERE `id` = ?");
            $stmt->bind_param('ii', $group, $id);
            $stmt->execute();
            $stmt = $conn->prepare("UPDATE `officials` SET `sort_order` = ? WHERE `id` = ? AND `group_id` = ?");
            foreach ($order as $i => $oid) {
                $o = $i + 1;
                $stmt->bind_param('iii', $o, $oid, $group);
                $stmt->execute();
            }
            $conn->commit();
            org_out(true);
        }

        case 'official_photo': {
            $id = $int('id');
            if (empty($_FILES['photo'])) {
                org_fail('No photo was selected.');
            }
            $chk = $conn->prepare("SELECT 1 FROM `officials` WHERE `id` = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            if (!$chk->get_result()->fetch_row()) {
                org_fail('That official no longer exists.', 404);
            }
            // The old photo is kept on disk: Published Terms may still show it.
            $path = $storePhoto('photo');
            $stmt = $conn->prepare("UPDATE `officials` SET `photo_path` = ? WHERE `id` = ?");
            $stmt->bind_param('si', $path, $id);
            $stmt->execute();
            org_out(true, ['photo_path' => $path]);
        }

        case 'official_quick_add': {
            $first = $str('firstname', 100);
            $middle = $str('middlename', 100);
            $sur = $str('surname', 100);
            $position = $str('position', 100);
            $group = $int('group_id');
            $start = trim((string) ($_POST['term_start'] ?? ''));
            $end = trim((string) ($_POST['term_end'] ?? ''));

            if ($first === '' || $sur === '' || $position === '') {
                org_fail('First name, surname and position are required.');
            }
            foreach ([$start, $end] as $d) {
                if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    org_fail('Invalid term date.');
                }
            }
            if (!dms_org_group_exists($conn, $group)) {
                org_fail('That group no longer exists.', 404);
            }
            $limits = dms_org_position_limits();
            if (isset($limits[$position])) {
                $c = $conn->prepare("SELECT COUNT(*) AS c FROM `officials` WHERE `position` = ?");
                $c->bind_param('s', $position);
                $c->execute();
                if ((int) $c->get_result()->fetch_assoc()['c'] >= $limits[$position]) {
                    org_fail("The chart already has the maximum number of {$position} ({$limits[$position]}).");
                }
            }

            $photo = '';
            if (!empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $photo = $storePhoto('photo');
            }

            $o = $conn->prepare("SELECT COALESCE(MAX(`sort_order`), 0) + 1 AS n FROM `officials` WHERE `group_id` = ?");
            $o->bind_param('i', $group);
            $o->execute();
            $order = (int) $o->get_result()->fetch_assoc()['n'];

            $startV = $start !== '' ? $start : null;
            $endV = $end !== '' ? $end : null;
            $stmt = $conn->prepare("INSERT INTO `officials`
                (`position`, `term_start`, `term_end`, `surname`, `firstname`, `middlename`, `photo_path`, `group_id`, `sort_order`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssssssii', $position, $startV, $endV, $sur, $first, $middle, $photo, $group, $order);
            $stmt->execute();
            org_out(true, ['id' => (int) $conn->insert_id]);
        }

        default:
            org_fail('Unknown action.');
    }
} catch (Throwable $e) {
    org_fail('The change could not be saved: ' . $e->getMessage(), 500);
}
