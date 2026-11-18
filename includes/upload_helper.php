<?php
/**
 * Attachment uploads for resolutions, ordinances, order of business and committee reports.
 *
 * Files go into the flat uploads/ folder and the DB stores "uploads/<name>".
 * - The original filename is kept, but an existing file is never overwritten: a second
 *   "001-A.pdf" is saved as "001-A (2).pdf" (before, it silently replaced another record's file).
 * - A path is only returned when the file really landed on disk, so a failed upload no longer
 *   leaves a record pointing at a file that does not exist (which shows as "404 Not Found").
 */

/** Absolute path of the uploads folder (no trailing slash). */
function dms_uploads_dir()
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
}

/** True when a stored "uploads/..." path points at a file that exists on disk. */
function dms_attachment_exists($path)
{
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return $path !== '';
    }
    return is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
}

/**
 * Read-only attachment field + "View File" button for the view pages (used with js/view-file.js).
 * Shows a red note when the stored file is not on disk, so it is clear it must be re-uploaded.
 */
function dms_render_attachment_field($recordId, $field, $path, $extraButtons = '')
{
    $path = trim((string) $path);
    $exists = $path !== '' && dms_attachment_exists($path);
    $f = htmlspecialchars($field, ENT_QUOTES);
    echo '<div class="mb-3">'
        . '<div class="input-group">'
        . '<input type="text" class="form-control" value="' . htmlspecialchars($path, ENT_QUOTES) . '"'
        . ' id="' . $f . '" name="' . $f . '" data-exists="' . ($path === '' || $exists ? '1' : '0') . '" disabled>'
        . '<div class="input-group-append">'
        . $extraButtons // e.g. the "Scan Document" button (includes/scanner.php)
        . '<button class="btn btn-primary" type="button" onclick="viewFile(' . (int) $recordId . ', \'' . $f . '\')">View File</button>'
        . '</div></div>';
    if ($path !== '' && !$exists) {
        echo '<small class="text-danger d-block mt-1"><i class="fa fa-exclamation-triangle"></i> '
            . 'File missing on the server &mdash; please re-upload it using Edit.</small>';
    }
    echo '</div>';
}

/** Uploaded filenames that are never accepted (they could run as code on the server). */
function dms_upload_blocked_name($name)
{
    return (bool) preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|asp|aspx|jsp|exe|bat|cmd|sh)$/i', $name)
        || strtolower($name) === '.htaccess' || strtolower($name) === 'web.config';
}

/** Human-readable reason for a PHP upload error code. */
function dms_upload_error_text($code)
{
    switch ((int) $code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The file is larger than the allowed upload size (' . ini_get('upload_max_filesize') . ').';
        case UPLOAD_ERR_PARTIAL:
            return 'The file was only partly uploaded. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save the file (temporary folder / disk problem).';
        default:
            return 'The file could not be uploaded.';
    }
}

/**
 * Move one uploaded file into uploads/.
 *
 * $file is a single $_FILES entry, or the pieces of a multi-file field
 * (['name' => ..., 'tmp_name' => ..., 'error' => ...]).
 * Returns "uploads/<saved name>" on success, or null on failure; when $error is passed it receives
 * a message suitable for showing to the user.
 */
function dms_store_upload($file, &$error = null)
{
    $error = null;
    $name = isset($file['name']) ? (string) $file['name'] : '';
    $code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($name === '' || $code === UPLOAD_ERR_NO_FILE) {
        $error = 'No file was selected.';
        return null;
    }
    if ($code !== UPLOAD_ERR_OK) {
        $error = dms_upload_error_text($code);
        return null;
    }

    // Keep only the file name; strip characters Windows does not allow in file names.
    $name = basename(str_replace('\\', '/', $name));
    $clean = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '', $name);
    if ($clean === null) { // not valid UTF-8: strip byte-wise instead
        $clean = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '', $name);
    }
    $name = trim((string) $clean, " .");
    if ($name === '') {
        $name = 'attachment';
    }
    if (dms_upload_blocked_name($name)) {
        $error = 'This type of file is not allowed.';
        return null;
    }

    $dir = dms_uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $error = 'The uploads folder is missing and could not be created.';
        return null;
    }

    // Never overwrite another record's file: "name.pdf" -> "name (2).pdf", "name (3).pdf", ...
    $info = pathinfo($name);
    $base = isset($info['filename']) ? $info['filename'] : $name;
    $ext = (isset($info['extension']) && $info['extension'] !== '') ? '.' . $info['extension'] : '';
    $final = $name;
    for ($i = 2; file_exists($dir . DIRECTORY_SEPARATOR . $final); $i++) {
        $final = $base . ' (' . $i . ')' . $ext;
    }

    $tmp = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    if ($tmp === '' || !@move_uploaded_file($tmp, $dir . DIRECTORY_SEPARATOR . $final)) {
        $error = 'The server could not save the file.';
        return null;
    }

    return 'uploads/' . $final;
}

/** <script> that shows a SweetAlert "upload failed" message (pages already load SweetAlert). */
function dms_upload_error_script($message, $title = 'File not uploaded')
{
    return '<script>document.addEventListener("DOMContentLoaded",function(){Swal.fire({icon:"error",title:'
        . json_encode($title) . ',text:' . json_encode((string) $message) . ',confirmButtonText:"OK"});});</script>';
}

/** The entry for index $key of a multi-file input (name="attachment[]"), in single-file shape. */
function dms_upload_item($files, $key)
{
    return [
        'name'     => isset($files['name'][$key]) ? $files['name'][$key] : '',
        'tmp_name' => isset($files['tmp_name'][$key]) ? $files['tmp_name'][$key] : '',
        'error'    => isset($files['error'][$key]) ? $files['error'][$key] : UPLOAD_ERR_NO_FILE,
    ];
}
