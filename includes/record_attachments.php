<?php
/**
 * Additional attachments for a record (scanned PDFs from the built-in scanner, js/doc-scanner.js).
 *
 * Each record keeps its existing single attachment column (resolution.attachment, ordinance.attachment,
 * committee_reports.attachment_path, minutes.attachment / genAttachment). When more than one PDF is
 * attached, the extra files are listed in `record_attachments`. Files are stored the same way as every
 * other attachment: flat in uploads/ through dms_store_upload() (never overwritten).
 *
 * The table is created automatically the first time it is needed (dms_ra_ensure_schema), so
 * database/add_record_attachments.sql only has to be imported by hand on a fresh install if wanted.
 */
require_once __DIR__ . '/upload_helper.php';

/**
 * Record types that take attachments.
 *  table   DB table, number = record number column (for labels), date = its main date column,
 *          session = Order of Business regular session column, log = history_log file_type,
 *  fields  attachment columns that may be replaced by a scan, extra = column whose extra PDFs are kept
 *          in record_attachments (the general Order of Business attachment holds one file only).
 */
function dms_ra_modules()
{
    return [
        'resolution' => [
            'table' => 'resolution', 'number' => 'reso_no', 'date' => 'd_adopted', 'log' => 'Resolution', 'noun' => 'Resolution',
            'fields' => ['attachment'], 'extra' => 'attachment', 'view' => 'viewresolution.php',
        ],
        'ordinance' => [
            'table' => 'ordinance', 'number' => 'mo_no', 'date' => 'date_adopted', 'log' => 'Ordinance', 'noun' => 'Ordinance',
            'fields' => ['attachment'], 'extra' => 'attachment', 'view' => 'viewordinance.php',
        ],
        'committee_report' => [
            'table' => 'committee_reports', 'number' => null, 'date' => 'date_report', 'log' => 'Committee Report', 'noun' => 'Committee Report',
            'fields' => ['attachment_path'], 'extra' => 'attachment_path', 'view' => 'viewCommitteeReports.php',
        ],
        'ob' => [
            'table' => 'minutes', 'number' => 'resNo', 'date' => 'date', 'session' => 'no_regSession', 'log' => 'Minutes', 'noun' => 'Order of Business',
            'fields' => ['attachment', 'genAttachment'], 'extra' => 'attachment', 'view' => 'viewmeetingminutes.php',
        ],
    ];
}

/** The config of one module, or null. */
function dms_ra_module($module)
{
    $all = dms_ra_modules();
    return isset($all[$module]) ? $all[$module] : null;
}

/** Create record_attachments if it does not exist yet (once per request). */
function dms_ra_ensure_schema($conn)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `record_attachments` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `module` varchar(30) NOT NULL,
              `record_id` int(11) NOT NULL,
              `file_path` varchar(500) NOT NULL,
              `file_name` varchar(255) NOT NULL DEFAULT '',
              `file_size` int(11) NOT NULL DEFAULT 0,
              `page_count` int(11) NOT NULL DEFAULT 0,
              `paper` varchar(20) NOT NULL DEFAULT '',
              `orientation` varchar(10) NOT NULL DEFAULT '',
              `source` varchar(20) NOT NULL DEFAULT 'scan',
              `client_token` varchar(64) DEFAULT NULL,
              `uploaded_by` varchar(255) NOT NULL DEFAULT '',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_record_attachments_token` (`client_token`),
              KEY `idx_record_attachments_record` (`module`, `record_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {
        // Listing simply shows nothing if the table cannot be created.
    }
}

/** Extra attachments of one record, oldest first. */
function dms_ra_list($conn, $module, $recordId)
{
    dms_ra_ensure_schema($conn);
    $rows = [];
    try {
        $stmt = $conn->prepare("SELECT * FROM `record_attachments` WHERE `module` = ? AND `record_id` = ? ORDER BY `id`");
        if ($stmt) {
            $id = (int) $recordId;
            $stmt->bind_param('si', $module, $id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}

/** "uploads/RES 031 (2).pdf" → "uploads/RES%20031%20%282%29.pdf" (each segment encoded, like js/view-file.js). */
function dms_ra_url($path)
{
    $path = (string) $path;
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function dms_ra_size_text($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes > 0 ? $bytes . ' B' : '';
}

/** Number of pages in a PDF file (jsPDF and most writers keep page objects readable). 0 = unknown. */
function dms_ra_pdf_page_count($file)
{
    $data = @file_get_contents($file);
    if ($data === false) {
        return 0;
    }
    $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $data);
    return $n ? (int) $n : 0;
}

/**
 * Insert one extra attachment row. Returns the new row id, or 0.
 * $info: file_name, file_size, page_count, paper, orientation, source, client_token, uploaded_by
 */
function dms_ra_insert($conn, $module, $recordId, $path, array $info)
{
    dms_ra_ensure_schema($conn);
    $stmt = $conn->prepare("INSERT INTO `record_attachments`
            (`module`, `record_id`, `file_path`, `file_name`, `file_size`, `page_count`, `paper`, `orientation`, `source`, `client_token`, `uploaded_by`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return 0;
    }
    $rid = (int) $recordId;
    $name = isset($info['file_name']) ? (string) $info['file_name'] : basename($path);
    $size = isset($info['file_size']) ? (int) $info['file_size'] : 0;
    $pages = isset($info['page_count']) ? (int) $info['page_count'] : 0;
    $paper = isset($info['paper']) ? substr((string) $info['paper'], 0, 20) : '';
    $orient = isset($info['orientation']) ? substr((string) $info['orientation'], 0, 10) : '';
    $source = isset($info['source']) ? substr((string) $info['source'], 0, 20) : 'scan';
    $token = (isset($info['client_token']) && $info['client_token'] !== '') ? substr((string) $info['client_token'], 0, 64) : null;
    $by = isset($info['uploaded_by']) ? substr((string) $info['uploaded_by'], 0, 255) : '';
    $stmt->bind_param('sissiisssss', $module, $rid, $path, $name, $size, $pages, $paper, $orient, $source, $token, $by);
    $ok = $stmt->execute();
    $newId = $ok ? (int) $stmt->insert_id : 0;
    $stmt->close();
    return $newId;
}

/** Paper / orientation sent by the scanner, limited to known values. */
function dms_ra_clean_paper($v)
{
    $v = strtolower(trim((string) $v));
    return in_array($v, ['folio', 'a4', 'letter', 'legal'], true) ? $v : '';
}
function dms_ra_clean_orientation($v)
{
    $v = strtolower(trim((string) $v));
    return in_array($v, ['portrait', 'landscape', 'auto'], true) ? $v : '';
}

/** Current user (for "uploaded by"). */
function dms_ra_current_user()
{
    if (!empty($_SESSION['full_name'])) {
        return (string) $_SESSION['full_name'];
    }
    return isset($_SESSION['email']) ? (string) $_SESSION['email'] : '';
}

/**
 * Store the extra scanned PDFs posted with an add / edit form (hidden <input name="scan_extra[]">,
 * or for Order of Business item cards name="scan_extra_item[IDX][]" → pass $subKey = IDX).
 * Returns a list of error messages (empty = all stored). Only PDFs are accepted.
 */
function dms_ra_store_posted_extras($conn, $module, $recordId, $field, $subKey = null)
{
    $errors = [];
    if (!dms_ra_module($module) || (int) $recordId <= 0 || empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) {
        return $errors;
    }
    $f = $_FILES[$field];
    $names = $f['name'];
    $tmp = $f['tmp_name'];
    $err = $f['error'];
    if ($subKey !== null) {
        if (!isset($names[$subKey])) {
            return $errors;
        }
        $names = $names[$subKey];
        $tmp = $tmp[$subKey];
        $err = $err[$subKey];
    }
    if (!is_array($names)) {
        $names = [$names];
        $tmp = [$tmp];
        $err = [$err];
    }
    $info = [];
    if (!empty($_POST['scan_extra_info'])) {
        $decoded = json_decode((string) $_POST['scan_extra_info'], true);
        if (is_array($decoded)) {
            $info = $decoded;
        }
    }
    foreach ($names as $k => $name) {
        if ((string) $name === '' || (int) $err[$k] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $item = ['name' => $name, 'tmp_name' => $tmp[$k], 'error' => $err[$k]];
        if ((int) $err[$k] === UPLOAD_ERR_OK && !dms_ra_is_pdf($tmp[$k])) {
            $errors[] = $name . ': not a PDF file.';
            continue;
        }
        $item['name'] = dms_ra_pdf_name($name);
        $e = null;
        $path = dms_store_upload($item, $e);
        if ($path === null) {
            $errors[] = $name . ': ' . $e;
            continue;
        }
        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        dms_ra_insert($conn, $module, $recordId, $path, [
            'file_name' => basename($path),
            'file_size' => @filesize($abs),
            'page_count' => dms_ra_pdf_page_count($abs),
            'paper' => dms_ra_clean_paper(isset($info['paper']) ? $info['paper'] : ''),
            'orientation' => dms_ra_clean_orientation(isset($info['orientation']) ? $info['orientation'] : ''),
            'source' => 'scan',
            'uploaded_by' => dms_ra_current_user(),
        ]);
    }
    return $errors;
}

/** True when the (uploaded) file starts with the PDF signature. */
function dms_ra_is_pdf($file)
{
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return false;
    }
    $head = fread($fh, 1024);
    fclose($fh);
    return $head !== false && strpos($head, '%PDF-') !== false;
}

/** Make sure a scanned file name ends in .pdf. */
function dms_ra_pdf_name($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        $name = 'scan';
    }
    if (!preg_match('/\.pdf$/i', $name)) {
        $name = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) . '.pdf';
    }
    return $name;
}

/**
 * Remove a record's extra attachments (called by the delete pages). Files are removed only when
 * $removeFiles is true, following each record type's existing rule (ordinances and Order of Business
 * keep their files when the record is deleted).
 */
function dms_ra_delete_for_record($conn, $module, $recordId, $removeFiles)
{
    $rows = dms_ra_list($conn, $module, $recordId);
    if (!$rows) {
        return;
    }
    $stmt = $conn->prepare("DELETE FROM `record_attachments` WHERE `module` = ? AND `record_id` = ?");
    if ($stmt) {
        $id = (int) $recordId;
        $stmt->bind_param('si', $module, $id);
        $stmt->execute();
        $stmt->close();
    }
    if ($removeFiles) {
        foreach ($rows as $r) {
            dms_ra_unlink((string) $r['file_path']);
        }
    }
}

/** Delete a file, but only inside uploads/. */
function dms_ra_unlink($path)
{
    $uploads = realpath(dms_uploads_dir());
    $abs = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $path));
    if ($uploads && $abs && strpos($abs, $uploads . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * The list of additional attachments under the attachment field (view and edit pages).
 * Everyone can view / download; $canEdit adds Delete (handled by js/doc-scanner.js).
 */
function dms_ra_render_list($conn, $module, $recordId, $canEdit)
{
    $rows = dms_ra_list($conn, $module, $recordId);
    if (!$rows) {
        return;
    }
    $papers = ['folio' => 'Folio', 'a4' => 'A4', 'letter' => 'Letter', 'legal' => 'Legal'];
    echo '<div class="dms-ra" data-ra-module="' . htmlspecialchars($module, ENT_QUOTES) . '" data-ra-record="' . (int) $recordId . '">';
    echo '<div class="dms-ra-head"><i class="fa fa-files-o"></i> Additional attachments <span class="dms-ra-count">' . count($rows) . '</span></div>';
    echo '<ul class="dms-ra-list">';
    foreach ($rows as $r) {
        $path = (string) $r['file_path'];
        $exists = dms_attachment_exists($path);
        $url = htmlspecialchars(dms_ra_url($path), ENT_QUOTES);
        $name = htmlspecialchars($r['file_name'] !== '' ? $r['file_name'] : basename($path), ENT_QUOTES);
        $meta = [];
        if ((int) $r['page_count'] > 0) {
            $meta[] = (int) $r['page_count'] . ((int) $r['page_count'] === 1 ? ' page' : ' pages');
        }
        if (isset($papers[$r['paper']])) {
            $meta[] = $papers[$r['paper']] . ($r['orientation'] === 'landscape' ? ' landscape' : '');
        }
        if ((int) $r['file_size'] > 0) {
            $meta[] = dms_ra_size_text($r['file_size']);
        }
        $time = strtotime((string) $r['created_at']);
        if ($time) {
            $meta[] = date('M j, Y g:i A', $time);
        }
        echo '<li class="dms-ra-item" data-ra-id="' . (int) $r['id'] . '">'
            . '<span class="dms-ra-icon"><i class="fa fa-file-pdf-o"></i></span>'
            . '<span class="dms-ra-main"><span class="dms-ra-name" title="' . $name . '">' . $name . '</span>'
            . '<span class="dms-ra-meta">' . htmlspecialchars(implode(' · ', $meta), ENT_QUOTES)
            . (!$exists ? ' <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> File missing</span>' : '')
            . '</span></span>'
            . '<span class="dms-ra-actions">';
        if ($exists) {
            echo '<a class="btn btn-sm btn-outline-primary" href="' . $url . '" target="_blank" rel="noopener" title="View"><i class="fa fa-eye"></i><span> View</span></a>'
                . '<a class="btn btn-sm btn-outline-secondary" href="' . $url . '" download="' . $name . '" title="Download"><i class="fa fa-download"></i><span> Download</span></a>';
        }
        if ($canEdit) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger" data-ra-delete="' . (int) $r['id'] . '" data-ra-name="' . $name . '" title="Delete"><i class="fa fa-trash"></i><span> Delete</span></button>';
        }
        echo '</span></li>';
    }
    echo '</ul></div>';
}
