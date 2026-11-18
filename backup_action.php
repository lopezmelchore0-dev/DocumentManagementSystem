<?php
    // Backup & Restore endpoint. Master admin (Sangguniang Bayan Secretary) only.
    //   GET  ?action=download&file=<name>                 streams a backup file
    //   POST action=create  type=db|full                  makes a new backup
    //   POST action=restore file=<name>                   restores a backup from the list
    //   POST action=delete  file=<name>                   deletes a backup
    //   POST action=upload  backup_file=<.sql|.zip>       stores an uploaded backup, then restores it
    // POST requests must carry csrf=<$_SESSION['backup_csrf']> (set by backup.php).
    include "session.php";
    require_once __DIR__ . '/includes/backup_lib.php';

    function respond($success, $message, $status = 200, $extra = []) {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit();
    }

    $isMaster = isset($_SESSION['role']) && $_SESSION['role'] === 'master';
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

    // ---- Download ---------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
        if (!$isMaster) {
            http_response_code(403);
            exit('Only the Sangguniang Bayan Secretary can download backups.');
        }
        $name = isset($_GET['file']) ? $_GET['file'] : '';
        $path = dms_backup_path($name);
        if (!$path) {
            http_response_code(404);
            exit('Backup file not found.');
        }
        session_write_close();
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $isZip = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
        header('Content-Type: ' . ($isZip ? 'application/zip' : 'application/sql'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $fh = fopen($path, 'rb');
        while (!feof($fh)) {
            echo fread($fh, 8 * 1024 * 1024);
            flush();
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fh);
        exit();
    }

    // ---- POST actions -----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Invalid request method.', 405);
    }
    if (!$isMaster) {
        respond(false, 'Only the Sangguniang Bayan Secretary can manage backups.', 403);
    }

    // A POST bigger than post_max_size arrives with $_POST and $_FILES emptied.
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
        respond(false, 'The file is larger than the server upload limit ('
            . dms_backup_human_size(dms_backup_upload_limit()) . '). Copy it into the backups folder on the server and restore it from the list instead.', 413);
    }

    $csrf = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (empty($_SESSION['backup_csrf']) || !hash_equals($_SESSION['backup_csrf'], $csrf)) {
        respond(false, 'Your session has expired. Reload the page and try again.', 403);
    }

    // Values needed after the session is released (long jobs must not lock the user's other tabs).
    $sessionEmail = isset($_SESSION['email']) ? $_SESSION['email'] : null;
    $sessionToken = isset($_SESSION['token']) ? $_SESSION['token'] : null;

    function restore_message($name, $r) {
        $msg = 'Restored from ' . $name . ' (' . $r['statements'] . ' SQL statements';
        if ($r['files'] || $r['skipped']) {
            $msg .= ', ' . $r['files'] . ' files restored to uploads';
            if ($r['skipped']) {
                $msg .= ', ' . $r['skipped'] . ' skipped';
            }
        }
        $msg .= '). The database as it was just before the restore was saved as ' . $r['safety'] . '.';
        if (!$r['signed_in']) {
            $msg .= ' Your account is not in the restored data, so you will be signed out.';
        }
        return $msg;
    }

    try {
        switch ($action) {
            case 'create':
                $type = isset($_POST['type']) ? $_POST['type'] : '';
                if (!in_array($type, ['db', 'full'], true)) {
                    respond(false, 'Invalid backup type.', 400);
                }
                session_write_close();
                $name = dms_backup_create($conn, $type);
                dms_backup_log($conn, 'Backup created', $name, $type === 'full' ? 'Full backup (database + files)' : 'Database backup');
                $size = dms_backup_human_size(filesize(dms_backup_path($name)));
                respond(true, 'Backup ' . $name . ' (' . $size . ') was created.', 200, ['file' => $name]);

            case 'restore':
                $name = isset($_POST['file']) ? $_POST['file'] : '';
                if (!dms_backup_path($name)) {
                    respond(false, 'Backup file not found.', 404);
                }
                session_write_close();
                $r = dms_backup_restore($conn, $name, $sessionEmail, $sessionToken);
                dms_backup_log($conn, 'Backup restored', $name, 'Safety copy: ' . $r['safety']);
                respond(true, restore_message($name, $r), 200, ['signed_in' => $r['signed_in']]);

            case 'delete':
                $name = isset($_POST['file']) ? $_POST['file'] : '';
                $path = dms_backup_path($name);
                if (!$path) {
                    respond(false, 'Backup file not found.', 404);
                }
                if (!@unlink($path)) {
                    respond(false, 'Could not delete ' . $name . '.', 500);
                }
                respond(true, $name . ' was deleted.');

            case 'upload':
                if (!isset($_FILES['backup_file'])) {
                    respond(false, 'Choose a backup file to upload.', 400);
                }
                $f = $_FILES['backup_file'];
                if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                    respond(false, 'The file is larger than the server upload limit ('
                        . dms_backup_human_size(dms_backup_upload_limit()) . '). Copy it into the backups folder on the server and restore it from the list instead.', 413);
                }
                if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    respond(false, 'The upload failed (error ' . (int) $f['error'] . '). Please try again.', 400);
                }
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['sql', 'zip'], true)) {
                    respond(false, 'Only .sql (database) or .zip (full) backup files can be restored.', 400);
                }
                $name = dms_backup_new_name('uploaded', $ext);
                if (!move_uploaded_file($f['tmp_name'], dms_backup_dir() . DIRECTORY_SEPARATOR . $name)) {
                    respond(false, 'Could not save the uploaded file on the server.', 500);
                }
                session_write_close();
                $r = dms_backup_restore($conn, $name, $sessionEmail, $sessionToken);
                dms_backup_log($conn, 'Backup restored', $name, 'Uploaded file ' . basename($f['name']) . '; safety copy: ' . $r['safety']);
                respond(true, restore_message($name, $r), 200, ['signed_in' => $r['signed_in']]);

            default:
                respond(false, 'Unknown action.', 400);
        }
    } catch (Throwable $e) {
        respond(false, $e->getMessage(), 500);
    }
