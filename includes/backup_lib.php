<?php
/**
 * Backup & Restore helpers (no output). Used by backup.php and backup_action.php.
 *
 * Backup types (named by filename prefix, stored in ./backups/):
 *   db_YYYYmmdd_His.sql          Database only (pure-PHP dump, no mysqldump needed)
 *   full_YYYYmmdd_His.zip        database.sql + manifest.json + uploads/*
 *   prerestore_YYYYmmdd_His.sql  Automatic safety copy made right before every restore
 *   uploaded_YYYYmmdd_His.sql|zip  A file uploaded on the Backup & Restore page
 * Any other *.sql / *.zip copied into the folder by hand is listed as "Other".
 */

date_default_timezone_set('Asia/Manila');

define('DMS_APP_ROOT', dirname(__DIR__));
define('DMS_UPLOADS_DIR', DMS_APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
define('DMS_BACKUP_MAX_SQL_BYTES', 256 * 1024 * 1024);

/** Absolute path of the backups folder; created (and locked against direct web access) on first use. */
function dms_backup_dir()
{
    $dir = DMS_APP_ROOT . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "# Backups are only served through backup_action.php\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
    return $dir;
}

/** Full path of a backup if $name is a plain, existing *.sql / *.zip file in the backups folder; otherwise false. */
function dms_backup_path($name)
{
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(sql|zip)$/i', $name)) {
        return false;
    }
    $path = dms_backup_dir() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : false;
}

/** Backup type label from the filename prefix. */
function dms_backup_type($name)
{
    $isZip = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
    if (strpos($name, 'full_') === 0)       return 'Full';
    if (strpos($name, 'db_') === 0)         return 'Database';
    if (strpos($name, 'prerestore_') === 0) return 'Pre-restore';
    if (strpos($name, 'uploaded_') === 0)   return $isZip ? 'Uploaded (Full)' : 'Uploaded (Database)';
    return $isZip ? 'Other (Full)' : 'Other (Database)';
}

/** All backups, newest first: [name, type, size, mtime]. */
function dms_backup_list()
{
    $out = [];
    foreach (glob(dms_backup_dir() . DIRECTORY_SEPARATOR . '*') as $path) {
        $name = basename($path);
        if (!is_file($path) || !dms_backup_path($name)) {
            continue;
        }
        $out[] = [
            'name'  => $name,
            'type'  => dms_backup_type($name),
            'size'  => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
    usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
    return $out;
}

/** "3.0 GB" style size. */
function dms_backup_human_size($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $bytes = (float) $bytes;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
}

/** Files directly inside uploads/ (the folder is flat). */
function dms_backup_upload_files()
{
    $files = [];
    if (is_dir(DMS_UPLOADS_DIR)) {
        foreach (scandir(DMS_UPLOADS_DIR) as $f) {
            $p = DMS_UPLOADS_DIR . DIRECTORY_SEPARATOR . $f;
            if ($f !== '.' && $f !== '..' && is_file($p)) {
                $files[] = $f;
            }
        }
    }
    return $files;
}

/** [file count, total bytes] of uploads/. */
function dms_backup_uploads_stats()
{
    $count = 0;
    $bytes = 0;
    foreach (dms_backup_upload_files() as $f) {
        $count++;
        $bytes += filesize(DMS_UPLOADS_DIR . DIRECTORY_SEPARATOR . $f);
    }
    return [$count, $bytes];
}

/** Smallest of upload_max_filesize / post_max_size, in bytes. */
function dms_backup_upload_limit()
{
    $toBytes = function ($v) {
        $v = trim((string) $v);
        if ($v === '') return 0;
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return (int) $n;
    };
    $a = $toBytes(ini_get('upload_max_filesize'));
    $b = $toBytes(ini_get('post_max_size'));
    if ($a <= 0) return $b;
    if ($b <= 0) return $a;
    return min($a, $b);
}

/** A not-yet-used filename like "db_20261112_153000.sql" (adds -2, -3 … within the same second). */
function dms_backup_new_name($prefix, $ext)
{
    $dir = dms_backup_dir();
    $base = $prefix . '_' . date('Ymd_His');
    $name = $base . '.' . $ext;
    $n = 2;
    while (file_exists($dir . DIRECTORY_SEPARATOR . $name) || file_exists($dir . DIRECTORY_SEPARATOR . $name . '.part')) {
        $name = $base . '-' . $n++ . '.' . $ext;
    }
    return $name;
}

/** Name of the database the connection is using. */
function dms_backup_db_name($conn)
{
    $res = $conn->query('SELECT DATABASE() AS d');
    return $res ? (string) $res->fetch_assoc()['d'] : '';
}

/**
 * Write a complete SQL dump of every base table to the open file handle $fh.
 * Throws RuntimeException on failure.
 */
function dms_backup_dump_db($conn, $fh)
{
    $conn->set_charset('utf8mb4');
    $w = function ($s) use ($fh) {
        if (fwrite($fh, $s) === false) {
            throw new RuntimeException('Could not write the backup file (disk full?).');
        }
    };

    $db = dms_backup_db_name($conn);
    $w("-- Document Management System - database backup\n");
    $w('-- Database: `' . $db . "`\n");
    $w('-- Created: ' . date('Y-m-d H:i:s') . "\n");
    $w('-- Server: MySQL/MariaDB ' . $conn->server_info . "\n\n");
    $w("SET NAMES utf8mb4;\n");
    $w("SET FOREIGN_KEY_CHECKS=0;\n");
    $w("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    $w("SET time_zone='+00:00';\n\n");

    // Dump TIMESTAMP values in UTC so they come back identical whatever the server zone is.
    $conn->query("SET time_zone='+00:00'");

    $tables = [];
    $res = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if (!$res) {
        throw new RuntimeException('Could not list tables: ' . $conn->error);
    }
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    $res->free();

    $binaryTypes = [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB,
                    MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_GEOMETRY];

    foreach ($tables as $table) {
        $qt = '`' . str_replace('`', '``', $table) . '`';

        $res = $conn->query("SHOW CREATE TABLE $qt");
        if (!$res) {
            throw new RuntimeException("Could not read structure of $table: " . $conn->error);
        }
        $create = $res->fetch_row()[1];
        $res->free();

        $w("--\n-- Table $qt\n--\n\n");
        $w("DROP TABLE IF EXISTS $qt;\n");
        $w($create . ";\n\n");

        // Unbuffered: rows stream from the server instead of being loaded into PHP memory.
        $res = $conn->query("SELECT * FROM $qt", MYSQLI_USE_RESULT);
        if (!$res) {
            throw new RuntimeException("Could not read rows of $table: " . $conn->error);
        }
        $fields = $res->fetch_fields();
        $cols = [];
        $kind = []; // 'bin' | 'num' | 'str'
        foreach ($fields as $f) {
            $cols[] = '`' . str_replace('`', '``', $f->name) . '`';
            if ($f->charsetnr == 63 && in_array($f->type, $binaryTypes, true)) {
                $kind[] = 'bin';
            } elseif ($f->type == MYSQLI_TYPE_BIT) {
                $kind[] = 'bit';
            } elseif ($f->flags & MYSQLI_NUM_FLAG) {
                $kind[] = 'num';
            } else {
                $kind[] = 'str';
            }
        }
        $insertHead = "INSERT INTO $qt (" . implode(', ', $cols) . ") VALUES\n";

        $batch = [];
        $flush = function () use (&$batch, $insertHead, $w) {
            if ($batch) {
                $w($insertHead . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        };
        while ($row = $res->fetch_row()) {
            $vals = [];
            foreach ($row as $i => $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif ($kind[$i] === 'bin') {
                    $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                } elseif ($kind[$i] === 'bit') {
                    $vals[] = "b'" . decbin(hexdec(bin2hex($v))) . "'";
                } elseif ($kind[$i] === 'num' && is_numeric($v)) {
                    $vals[] = $v;
                } else {
                    $vals[] = "'" . $conn->real_escape_string($v) . "'";
                }
            }
            $batch[] = '(' . implode(', ', $vals) . ')';
            if (count($batch) >= 200) {
                $flush();
            }
        }
        $flush();
        $res->free();
        $w("\n");
    }

    $w("SET FOREIGN_KEY_CHECKS=1;\n");
    $w("-- End of backup\n");
}

/**
 * Create a backup. $type: 'db' | 'full' | 'prerestore'.
 * Returns the new file name. Throws RuntimeException on failure.
 */
function dms_backup_create($conn, $type)
{
    @set_time_limit(0);
    ignore_user_abort(true);

    $dir = dms_backup_dir();
    if (!is_writable($dir)) {
        throw new RuntimeException('The backups folder is not writable: ' . $dir);
    }

    if ($type === 'db' || $type === 'prerestore') {
        $name = dms_backup_new_name($type, 'sql');
        $final = $dir . DIRECTORY_SEPARATOR . $name;
        $part = $final . '.part';
        $fh = fopen($part, 'wb');
        if (!$fh) {
            throw new RuntimeException('Could not create the backup file.');
        }
        try {
            dms_backup_dump_db($conn, $fh);
            fclose($fh);
        } catch (Exception $e) {
            fclose($fh);
            @unlink($part);
            throw $e;
        }
        if (!rename($part, $final)) {
            @unlink($part);
            throw new RuntimeException('Could not finish the backup file.');
        }
        return $name;
    }

    if ($type !== 'full') {
        throw new RuntimeException('Unknown backup type.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled, so a full backup cannot be made.');
    }

    list($fileCount, $uploadBytes) = dms_backup_uploads_stats();
    $free = @disk_free_space($dir);
    if ($free !== false && $free < $uploadBytes + 200 * 1024 * 1024) {
        throw new RuntimeException('Not enough free disk space for a full backup. Needed about '
            . dms_backup_human_size($uploadBytes + 200 * 1024 * 1024) . ', available ' . dms_backup_human_size($free) . '.');
    }

    $name = dms_backup_new_name('full', 'zip');
    $final = $dir . DIRECTORY_SEPARATOR . $name;
    $part = $final . '.part';
    $sqlTmp = $dir . DIRECTORY_SEPARATOR . $name . '.sql.part';

    try {
        $fh = fopen($sqlTmp, 'wb');
        if (!$fh) {
            throw new RuntimeException('Could not create a temporary file for the database dump.');
        }
        try {
            dms_backup_dump_db($conn, $fh);
        } finally {
            fclose($fh);
        }

        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file.');
        }
        $zip->addFile($sqlTmp, 'database.sql');
        $zip->addFromString('manifest.json', json_encode([
            'app'          => 'Document Management System',
            'type'         => 'full',
            'created'      => date('c'),
            'database'     => dms_backup_db_name($conn),
            'upload_files' => $fileCount,
            'upload_bytes' => $uploadBytes,
        ], JSON_PRETTY_PRINT));
        foreach (dms_backup_upload_files() as $f) {
            $entry = 'uploads/' . $f;
            $zip->addFile(DMS_UPLOADS_DIR . DIRECTORY_SEPARATOR . $f, $entry);
            // PDFs and images are already compressed; storing them is much faster and barely bigger.
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not write the zip file: ' . $zip->getStatusString());
        }
        @unlink($sqlTmp);
        if (!rename($part, $final)) {
            throw new RuntimeException('Could not finish the backup file.');
        }
    } catch (Exception $e) {
        @unlink($sqlTmp);
        @unlink($part);
        throw $e;
    }
    return $name;
}

/**
 * Split an SQL script into statements and run them one by one.
 * Handles '…', "…", `…` (with backslash escapes), -- / # / block comments. Keeps
 * MySQL conditional comments (/*! … *\/) since phpMyAdmin dumps rely on them.
 * Returns the number of statements run. Throws RuntimeException at the first failure.
 */
function dms_backup_exec_sql_file($conn, $path)
{
    $size = filesize($path);
    if ($size === false || $size > DMS_BACKUP_MAX_SQL_BYTES) {
        throw new RuntimeException('The SQL file is missing or too large to restore here.');
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Could not read the SQL file.');
    }
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3); // UTF-8 BOM
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET SESSION sql_mode = ''");
    $conn->query('SET FOREIGN_KEY_CHECKS = 0');

    $len = strlen($sql);
    $i = 0;
    $stmt = '';
    $hasContent = false; // statement has something other than whitespace/comments
    $count = 0;

    $run = function () use (&$stmt, &$hasContent, &$count, $conn) {
        $q = trim($stmt);
        $stmt = '';
        if (!$hasContent || $q === '') {
            $hasContent = false;
            return;
        }
        $hasContent = false;
        $count++;
        if (preg_match('/^DELIMITER\b/i', $q)) {
            throw new RuntimeException('DELIMITER statements (stored procedures/triggers) are not supported in a restore file.');
        }
        if (!$conn->query($q)) {
            $err = $conn->error;
            $conn->query('SET FOREIGN_KEY_CHECKS = 1');
            throw new RuntimeException('Statement #' . $count . ' failed: ' . $err . ' — near: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160));
        }
        // Drain any extra result sets.
        while ($conn->more_results() && $conn->next_result()) { /* nothing */ }
    };

    while ($i < $len) {
        // Copy plain text up to the next character that matters.
        $span = strcspn($sql, "'\"`-#/;", $i);
        if ($span > 0) {
            $chunk = substr($sql, $i, $span);
            $stmt .= $chunk;
            if (!$hasContent && trim($chunk) !== '') $hasContent = true;
            $i += $span;
            if ($i >= $len) break;
        }
        $c = $sql[$i];

        if ($c === ';') {
            $i++;
            $run();
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            // Quoted string / identifier.
            $j = $i + 1;
            while ($j < $len) {
                $d = $sql[$j];
                if ($d === '\\' && $c !== '`') {
                    $j += 2;
                    continue;
                }
                if ($d === $c) {
                    if ($j + 1 < $len && $sql[$j + 1] === $c) { // doubled quote
                        $j += 2;
                        continue;
                    }
                    break;
                }
                $j++;
            }
            $stmt .= substr($sql, $i, $j - $i + 1);
            $hasContent = true;
            $i = $j + 1;
            continue;
        }

        if ($c === '#' || ($c === '-' && $i + 2 <= $len && substr($sql, $i, 2) === '--'
                && ($i + 2 === $len || ctype_space($sql[$i + 2])))) {
            // Line comment: skip to end of line.
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl + 1;
            $stmt .= "\n";
            continue;
        }

        if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $end = $end === false ? $len : $end + 2;
            if ($i + 2 < $len && $sql[$i + 2] === '!') {
                // Conditional comment: MySQL executes its contents, so keep it.
                $stmt .= substr($sql, $i, $end - $i);
                $hasContent = true;
            } else {
                $stmt .= ' ';
            }
            $i = $end;
            continue;
        }

        // A lone '-' or '/'.
        $stmt .= $c;
        $hasContent = true;
        $i++;
    }
    $run();

    $conn->query('SET FOREIGN_KEY_CHECKS = 1');
    return $count;
}

/** Upload filenames we never write into uploads/ during a restore (they could run as code). */
function dms_backup_blocked_upload($name)
{
    return (bool) preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|asp|aspx|jsp|exe|bat|cmd|sh)$/i', $name)
        || strtolower($name) === '.htaccess' || strtolower($name) === 'web.config';
}

/**
 * Restore a backup from the backups folder.
 * Returns ['statements' => n, 'files' => n, 'skipped' => n, 'safety' => prerestore file name].
 * Throws RuntimeException on failure.
 */
function dms_backup_restore($conn, $name, $sessionEmail = null, $sessionToken = null)
{
    @set_time_limit(0);
    ignore_user_abort(true);

    $path = dms_backup_path($name);
    if (!$path) {
        throw new RuntimeException('Backup file not found.');
    }
    $isZip = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';

    $zip = null;
    if ($isZip) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is not enabled, so a full backup cannot be restored.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The zip file could not be opened. It may be damaged.');
        }
        if ($zip->locateName('database.sql') === false) {
            $zip->close();
            throw new RuntimeException('This zip is not a DMS backup (database.sql is missing).');
        }
    }

    // 1. Safety copy of the current database.
    $safety = dms_backup_create($conn, 'prerestore');

    // 2. Database.
    $sqlPath = $path;
    $tmp = null;
    if ($isZip) {
        $tmp = dms_backup_dir() . DIRECTORY_SEPARATOR . 'restore_' . bin2hex(random_bytes(6)) . '.sql.part';
        $in = $zip->getStream('database.sql');
        $out = fopen($tmp, 'wb');
        if (!$in || !$out) {
            $zip->close();
            throw new RuntimeException('Could not extract database.sql from the zip.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $sqlPath = $tmp;
    }

    try {
        $statements = dms_backup_exec_sql_file($conn, $sqlPath);
    } catch (RuntimeException $e) {
        if ($tmp) @unlink($tmp);
        if ($zip) $zip->close();
        throw new RuntimeException($e->getMessage() . ' The database may be partly restored; the copy made just before ('
            . $safety . ') can be restored to undo this.');
    }
    if ($tmp) @unlink($tmp);

    // 3. Uploaded files (full backups only): add/overwrite, never delete.
    $files = 0;
    $skipped = 0;
    if ($zip) {
        if (!is_dir(DMS_UPLOADS_DIR)) {
            @mkdir(DMS_UPLOADS_DIR, 0775, true);
        }
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $entry = $zip->getNameIndex($k);
            if (strpos($entry, 'uploads/') !== 0 || substr($entry, -1) === '/') {
                continue;
            }
            $base = substr($entry, 8);
            // Only flat "uploads/<file>" entries: no sub-paths, no traversal, nothing executable.
            if ($base === '' || $base !== basename($base) || strpos($base, '\\') !== false
                || $base === '.' || $base === '..' || dms_backup_blocked_upload($base)) {
                $skipped++;
                continue;
            }
            $in = $zip->getStream($entry);
            $out = $in ? fopen(DMS_UPLOADS_DIR . DIRECTORY_SEPARATOR . $base, 'wb') : false;
            if (!$in || !$out) {
                if ($in) fclose($in);
                $skipped++;
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $files++;
        }
        $zip->close();
    }

    // 4. Keep the person who restored signed in (session.php checks the per-device token in
    //    account_sessions; the restored data may not have this device's token, or the table at all).
    $signedIn = false;
    if ($sessionEmail && $sessionToken) {
        require_once __DIR__ . '/auth_sessions.php';
        try {
            dms_auth_ensure_schema($conn, true);
            $find = @$conn->prepare('SELECT id FROM accounts WHERE email = ? LIMIT 1');
            if ($find) {
                $find->bind_param('s', $sessionEmail);
                $find->execute();
                $acc = $find->get_result()->fetch_assoc();
                if ($acc) {
                    $accId = (int) $acc['id'];
                    $ins = @$conn->prepare('INSERT IGNORE INTO account_sessions (account_id, token) VALUES (?, ?)');
                    if ($ins) {
                        $ins->bind_param('is', $accId, $sessionToken);
                        @$ins->execute();
                    }
                    $signedIn = dms_auth_valid($conn, $sessionEmail, $sessionToken);
                }
            }
        } catch (Throwable $e) {
            $signedIn = false;
        }
    }

    return ['statements' => $statements, 'files' => $files, 'skipped' => $skipped, 'safety' => $safety,
            'signed_in' => $signedIn];
}

/** History entry (dashboard "Recent activities" shows it as "Backup: <file>"). Failures are ignored. */
function dms_backup_log($conn, $action, $name, $status = '')
{
    $stmt = @$conn->prepare("INSERT INTO history_log (action, file_type, file_id, title, status) VALUES (?, 'Backup', NULL, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('sss', $action, $name, $status);
        @$stmt->execute();
    }
}
