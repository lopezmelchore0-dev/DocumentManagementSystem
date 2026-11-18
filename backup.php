<?php
    // Backup & Restore page. Master admin (Sangguniang Bayan Secretary) only.
    // All actions go to backup_action.php; logic lives in includes/backup_lib.php.
    include "session.php";
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'master') {
        header('Location: dashboard.php');
        exit();
    }
    require_once __DIR__ . '/includes/backup_lib.php';

    if (empty($_SESSION['backup_csrf'])) {
        $_SESSION['backup_csrf'] = bin2hex(random_bytes(32));
    }

    $backups = dms_backup_list();
    list($uploadCount, $uploadBytes) = dms_backup_uploads_stats();
    $uploadLimit = dms_backup_upload_limit();
    $dbName = dms_backup_db_name($conn);
    $lastBackup = null;
    foreach ($backups as $b) {
        if ($b['type'] === 'Database' || $b['type'] === 'Full') {
            $lastBackup = $b;
            break;
        }
    }
    $pillClass = [
        'Full' => 'is-green', 'Database' => 'is-blue', 'Pre-restore' => 'is-amber',
    ];
?>
<!DOCTYPE html>
<html lang="en">

    <?php include('header.php'); ?>

    <style>
        .dms-bk-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .dms-bk-stat { background: var(--dms-card); border: 1px solid var(--dms-border); border-radius: var(--dms-radius); padding: 1rem 1.25rem; box-shadow: var(--dms-shadow); }
        .dms-bk-stat small { display: block; color: var(--dms-muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
        .dms-bk-stat strong { display: block; font-size: 1.15rem; font-weight: 600; color: var(--dms-text); margin-top: .2rem; word-break: break-word; }
        .dms-bk-stat span { color: var(--dms-muted); font-size: .82rem; }
        .dms-bk-name { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: .85rem; }
        .dms-bk-actions { display: flex; gap: .35rem; justify-content: center; flex-wrap: wrap; }
        .dms-bk-note { color: var(--dms-muted); font-size: .85rem; margin: .5rem 0 0; }
        .dms-bk-list { margin: 0; padding-left: 1.1rem; color: var(--dms-text-2); font-size: .875rem; }
        .dms-bk-list li { margin-bottom: .35rem; }
        .dms-bk-card-title { font-size: 1rem; font-weight: 600; margin: 0 0 .75rem; color: var(--dms-text); }
    </style>

<body>

    <div id="main-wrapper">

        <?php include('sidebar.php'); ?>

        <div class="content-body">
            <div class="container-fluid">
                <div class="dms-page-head">
                    <div>
                        <h1>Backup &amp; Restore</h1>
                        <p>Save copies of the database and uploaded files, and restore them if something goes wrong.</p>
                    </div>
                    <div class="button-container dms-page-actions">
                        <button type="button" class="btn btn-primary" id="btnBackupDb"><i class="fa fa-database"></i>&nbsp;Back up database</button>
                        <button type="button" class="btn btn-outline-primary" id="btnBackupFull"><i class="fa fa-archive"></i>&nbsp;Full backup (database + files)</button>
                    </div>
                </div>

                <div class="dms-bk-stats">
                    <div class="dms-bk-stat">
                        <small>Last backup</small>
                        <?php if ($lastBackup) { ?>
                            <strong><?php echo date('M j, Y g:i A', $lastBackup['mtime']); ?></strong>
                            <span><?php echo htmlspecialchars($lastBackup['type']); ?> &middot; <?php echo dms_backup_human_size($lastBackup['size']); ?></span>
                        <?php } else { ?>
                            <strong>Never</strong>
                            <span>No backup has been made yet.</span>
                        <?php } ?>
                    </div>
                    <div class="dms-bk-stat">
                        <small>Database</small>
                        <strong><?php echo htmlspecialchars($dbName); ?></strong>
                        <span>All records, accounts and history</span>
                    </div>
                    <div class="dms-bk-stat">
                        <small>Uploaded files</small>
                        <strong><?php echo number_format($uploadCount); ?> files</strong>
                        <span><?php echo dms_backup_human_size($uploadBytes); ?> in the uploads folder</span>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="dms-bk-card-title">Saved backups</h2>
                                <div class="table-responsive">
                                    <table id="backupTable" class="display dms-stack" style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th>File</th>
                                                <th>Type</th>
                                                <th>Size</th>
                                                <th>Created</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($backups as $b) {
                                                $n = htmlspecialchars($b['name']);
                                                $pill = isset($pillClass[$b['type']]) ? $pillClass[$b['type']] : '';
                                            ?>
                                                <tr>
                                                    <td class="dms-bk-name"><?php echo $n; ?></td>
                                                    <td><span class="dms-pill <?php echo $pill; ?>"><?php echo htmlspecialchars($b['type']); ?></span></td>
                                                    <td data-order="<?php echo (int) $b['size']; ?>"><?php echo dms_backup_human_size($b['size']); ?></td>
                                                    <td data-order="<?php echo (int) $b['mtime']; ?>"><?php echo date('M j, Y g:i A', $b['mtime']); ?></td>
                                                    <td>
                                                        <div class="dms-bk-actions" data-stack-actions>
                                                            <a class="btn btn-sm btn-outline-primary" href="backup_action.php?action=download&amp;file=<?php echo rawurlencode($b['name']); ?>" title="Download"><i class="fa fa-download"></i>&nbsp;Download</a>
                                                            <button type="button" class="btn btn-sm btn-warning js-restore" data-file="<?php echo $n; ?>" data-type="<?php echo htmlspecialchars($b['type']); ?>" title="Restore"><i class="fa fa-undo"></i>&nbsp;Restore</button>
                                                            <button type="button" class="btn btn-sm btn-danger js-delete" data-file="<?php echo $n; ?>" title="Delete"><i class="fa fa-trash"></i></button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="dms-bk-card-title">Restore from a file</h2>
                                <form id="uploadForm" enctype="multipart/form-data">
                                    <div class="form-group mb-2">
                                        <input type="file" class="form-control" name="backup_file" id="backupFile" accept=".sql,.zip" required>
                                    </div>
                                    <button type="submit" class="btn btn-warning"><i class="fa fa-upload"></i>&nbsp;Upload and restore</button>
                                </form>
                                <p class="dms-bk-note">
                                    Accepts a <strong>.sql</strong> database backup or a <strong>.zip</strong> full backup, up to
                                    <strong><?php echo dms_backup_human_size($uploadLimit); ?></strong> (the server's upload limit).
                                    For a bigger full backup, copy the .zip into the <code>backups</code> folder of the system on the
                                    server; it then appears in the list above and can be restored from there.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-body">
                                <h2 class="dms-bk-card-title">Before you restore</h2>
                                <ul class="dms-bk-list">
                                    <li>A restore <strong>replaces the whole database</strong> with the backup's copy. Anything added or changed after the backup was made is lost.</li>
                                    <li>Right before every restore, the current database is saved automatically as a <strong>Pre-restore</strong> backup, so a restore can be undone.</li>
                                    <li>A <strong>full</strong> backup also puts its files back into the uploads folder. Files added since then are kept.</li>
                                    <li>Keep a downloaded copy somewhere other than this computer (a USB drive or cloud storage), in case the computer itself fails.</li>
                                    <li>A full backup is about <?php echo dms_backup_human_size($uploadBytes); ?> and can take several minutes. Don't close the page while it runs.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Required vendors -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>

    <!-- Datatable -->
    <script src="./vendor/datatables/js/jquery.dataTables.min.js"></script>

    <script>
    (function () {
        const CSRF = <?php echo json_encode($_SESSION['backup_csrf']); ?>;
        const FULL_SIZE = <?php echo json_encode(dms_backup_human_size($uploadBytes)); ?>;
        let busy = false;

        $('#backupTable').DataTable({
            order: [[3, 'desc']],
            columnDefs: [{ targets: 4, orderable: false, searchable: false }],
            language: { emptyTable: 'No backups yet. Click "Back up database" to make the first one.' }
        });

        window.addEventListener('beforeunload', function (e) {
            if (busy) { e.preventDefault(); e.returnValue = ''; }
        });

        function run(formData, workingText) {
            formData.append('csrf', CSRF);
            busy = true;
            Swal.fire({
                title: workingText,
                html: 'Please wait. <strong>Do not close or reload this page.</strong>',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
            return fetch('backup_action.php', { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(r => r.text().then(t => {
                    try { return JSON.parse(t); }
                    catch (e) { return { success: false, message: 'Unexpected server response (HTTP ' + r.status + '). ' + t.replace(/<[^>]*>/g, ' ').trim().slice(0, 300) }; }
                }))
                .catch(err => ({ success: false, message: 'The request failed: ' + err.message }))
                .then(data => {
                    busy = false;
                    return Swal.fire({
                        icon: data.success ? 'success' : 'error',
                        title: data.success ? 'Done' : 'Something went wrong',
                        text: data.message,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        if (data.success && data.signed_in === false) {
                            window.location.href = 'login.php';
                        } else {
                            window.location.reload();
                        }
                    });
                });
        }

        function action(name, extra) {
            const fd = new FormData();
            fd.append('action', name);
            Object.keys(extra || {}).forEach(k => fd.append(k, extra[k]));
            return fd;
        }

        document.getElementById('btnBackupDb').addEventListener('click', function () {
            run(action('create', { type: 'db' }), 'Backing up the database…');
        });

        document.getElementById('btnBackupFull').addEventListener('click', function () {
            Swal.fire({
                icon: 'question',
                title: 'Make a full backup?',
                html: 'This copies the database and all uploaded files (about <strong>' + FULL_SIZE + '</strong>) into one .zip file. It can take several minutes.',
                showCancelButton: true,
                confirmButtonText: 'Start full backup'
            }).then(res => {
                if (res.isConfirmed) run(action('create', { type: 'full' }), 'Making a full backup…');
            });
        });

        function confirmRestore(label, isFull) {
            return Swal.fire({
                icon: 'warning',
                title: 'Restore this backup?',
                html: '<p>The current database will be <strong>replaced</strong> by <code>' + label + '</code>.'
                    + (isFull ? ' Its files will also be copied back into the uploads folder.' : '')
                    + ' A Pre-restore copy of the current database is saved first.</p>'
                    + '<p>Type <strong>RESTORE</strong> to continue.</p>',
                input: 'text',
                inputPlaceholder: 'RESTORE',
                showCancelButton: true,
                confirmButtonText: 'Restore',
                confirmButtonColor: '#d33',
                preConfirm: v => {
                    if ((v || '').trim().toUpperCase() !== 'RESTORE') {
                        Swal.showValidationMessage('Type RESTORE to confirm.');
                        return false;
                    }
                    return true;
                }
            });
        }

        document.querySelectorAll('.js-restore').forEach(btn => {
            btn.addEventListener('click', function () {
                const file = this.dataset.file;
                const isFull = /\.zip$/i.test(file);
                confirmRestore(file, isFull).then(res => {
                    if (res.isConfirmed) run(action('restore', { file: file }), 'Restoring ' + file + '…');
                });
            });
        });

        document.querySelectorAll('.js-delete').forEach(btn => {
            btn.addEventListener('click', function () {
                const file = this.dataset.file;
                Swal.fire({
                    icon: 'warning',
                    title: 'Delete this backup?',
                    html: '<code>' + file + '</code> will be permanently deleted from the server.',
                    showCancelButton: true,
                    confirmButtonText: 'Delete',
                    confirmButtonColor: '#d33'
                }).then(res => {
                    if (res.isConfirmed) run(action('delete', { file: file }), 'Deleting…');
                });
            });
        });

        document.getElementById('uploadForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const input = document.getElementById('backupFile');
            const f = input.files[0];
            if (!f) return;
            if (!/\.(sql|zip)$/i.test(f.name)) {
                Swal.fire('Wrong file type', 'Choose a .sql or .zip backup file.', 'error');
                return;
            }
            const limit = <?php echo (int) $uploadLimit; ?>;
            if (limit > 0 && f.size > limit) {
                Swal.fire('File too large', 'This file is larger than the server upload limit. Copy it into the backups folder on the server and restore it from the list instead.', 'error');
                return;
            }
            const label = f.name.replace(/[<>&"]/g, '');
            confirmRestore(label, /\.zip$/i.test(f.name)).then(res => {
                if (!res.isConfirmed) return;
                const fd = action('upload');
                fd.append('backup_file', f);
                run(fd, 'Uploading and restoring…');
            });
        });
    })();
    </script>
</body>
</html>
