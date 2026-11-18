<?php
/**
 * Built-in document scanner: page helpers (the scanner itself is js/doc-scanner.js).
 *
 *  dms_scanner_assets()      CSS + JS tags, printed once per page (call it anywhere in <body>).
 *  dms_scan_button($o)       the "Scan Document" button.
 *  dms_scan_clear_script($k) after a successful save: drop the scan session kept on this device.
 *
 * Two modes:
 *  form   add / edit pages: the first (or combined) PDF is put into the existing file input
 *         ($o['input'] = its id), extra PDFs into a hidden multi-file input ($o['extra_field'] = its
 *         name, '' = this field holds one file only). The form's normal Save stores them.
 *  record view pages: PDFs are uploaded straight to scan_attach.php and attached to the record.
 */
require_once __DIR__ . '/record_attachments.php';

function dms_scanner_assets()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $root = dirname(__DIR__);
    $v = function ($rel) use ($root) {
        $f = $root . '/' . $rel;
        return $rel . '?v=' . (is_file($f) ? filemtime($f) : '1');
    };
    echo '<link rel="stylesheet" href="./' . $v('css/doc-scanner.css') . '">' . "\n";
    // Versioned URLs (relative to the app root) for the scanner's Web Workers.
    echo '<script>window.DMS_SCANNER_ASSETS=' . json_encode([
        'worker' => $v('js/scanner/dsc-worker.js'),
        'core' => $v('js/scanner/dsc-core.js'),
        'cv' => $v('vendor/opencv/opencv.js'),
    ], JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
    echo '<script src="./' . $v('vendor/sortablejs/Sortable.min.js') . '" defer></script>' . "\n";
    echo '<script src="./' . $v('vendor/jspdf/jspdf.umd.min.js') . '" defer></script>' . "\n";
    echo '<script src="./' . $v('js/scanner/dsc-core.js') . '" defer></script>' . "\n";
    echo '<script src="./' . $v('js/doc-scanner.js') . '" defer></script>' . "\n";
}

/** True for accounts that may add or replace attachments. */
function dms_scan_can_edit()
{
    $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
    return $role === 'admin' || $role === 'master';
}

/**
 * $o: mode (form|record), module, record_id, field, input (form), extra_field (form: hidden input
 *     name such as "scan_extra[]"; record: any non-empty value = additional PDFs allowed), label,
 *     label_from (id of a form field appended to the label, e.g. the record number on add forms),
 *     has_main (record), session (scan session key), class, text
 */
function dms_scan_button(array $o)
{
    $mode = isset($o['mode']) && $o['mode'] === 'record' ? 'record' : 'form';
    $module = isset($o['module']) ? (string) $o['module'] : '';
    $recordId = isset($o['record_id']) ? (int) $o['record_id'] : 0;
    $field = isset($o['field']) ? (string) $o['field'] : '';
    $session = isset($o['session']) ? (string) $o['session']
        : $module . ':' . ($recordId > 0 ? $recordId : 'add') . ':' . $field;
    $attrs = [
        'data-dms-scan' => '1',
        'data-mode' => $mode,
        'data-module' => $module,
        'data-record-id' => $recordId > 0 ? (string) $recordId : '',
        'data-field' => $field,
        'data-input' => isset($o['input']) ? (string) $o['input'] : '',
        'data-extra-field' => isset($o['extra_field']) ? (string) $o['extra_field'] : '',
        'data-label' => isset($o['label']) ? (string) $o['label'] : '',
        'data-label-from' => isset($o['label_from']) ? (string) $o['label_from'] : '',
        'data-has-main' => !empty($o['has_main']) ? '1' : '0',
        'data-session' => $session,
        // Server limits, so the scanner can warn before a save would be rejected.
        'data-max-post' => (string) min(dms_scan_ini_bytes(ini_get('post_max_size')), dms_scan_ini_bytes(ini_get('upload_max_filesize'))),
        'data-max-files' => (string) (int) ini_get('max_file_uploads'),
    ];
    $html = '<button type="button" class="btn dms-scan-btn ' . htmlspecialchars(isset($o['class']) ? $o['class'] : '', ENT_QUOTES) . '"';
    foreach ($attrs as $k => $val) {
        $html .= ' ' . $k . '="' . htmlspecialchars($val, ENT_QUOTES) . '"';
    }
    $text = isset($o['text']) ? (string) $o['text'] : 'Scan Document';
    $html .= ' title="Scan with the camera"><i class="fa fa-camera"></i><span class="dms-scan-btn-text"> '
        . htmlspecialchars($text, ENT_QUOTES) . '</span></button>';
    return $html;
}

/** Sentence appended to a save message when some additional scanned PDFs could not be stored. */
function dms_scan_errors_note($errors)
{
    if (!$errors) {
        return '';
    }
    return ' However, ' . count($errors) . ' additional scanned PDF' . (count($errors) === 1 ? ' was' : 's were')
        . ' not saved (' . implode(' ', $errors) . '). Scan ' . (count($errors) === 1 ? 'it' : 'them') . ' again from the View page.';
}

/** "40M" → bytes (0 / unlimited → 40 MB). */
function dms_scan_ini_bytes($v)
{
    $v = trim((string) $v);
    $n = (float) $v;
    $unit = strtolower(substr($v, -1));
    if ($unit === 'g') { $n *= 1073741824; } elseif ($unit === 'm') { $n *= 1048576; } elseif ($unit === 'k') { $n *= 1024; }
    return $n > 0 ? (int) $n : 41943040;
}

/** <script> run after a successful save: forget the scans kept on this device for that form. */
function dms_scan_clear_script($key)
{
    return '<script>window.__dmsScanClear=(window.__dmsScanClear||[]);window.__dmsScanClear.push('
        . json_encode((string) $key) . ');</script>';
}
