<?php

    if(isset($_POST['save'])) {
        include("connect.php");
        require_once __DIR__ . "/includes/minutes_workflow.php";
        error_reporting(0);
        dms_ob_ensure_schema($conn);

        $id = intval($_POST['id']);
        $no_regSession = $_POST['no_regSession'];
        $date = $_POST['date'];
        $resNo = $_POST['resNo'];
        $title = $_POST['title'];

        // Section, status and resolution details
        $itemIn = dms_ob_item_input(isset($_POST['ob']) ? $_POST['ob'] : []);

        // Current record: kept attachments and the previous section / status.
        $current = ['genAttachment' => '', 'attachment' => '', 'reading' => 'First Reading', 'ob_status' => ''];
        $cur_stmt = $conn->prepare("SELECT genAttachment, attachment, reading, ob_status FROM minutes WHERE id = ?");
        if ($cur_stmt) {
            $cur_stmt->bind_param("i", $id);
            $cur_stmt->execute();
            $found = $cur_stmt->get_result()->fetch_assoc();
            if ($found) {
                $current = $found;
            }
            $cur_stmt->close();
        }

        // Handle file uploads. A file that cannot be saved keeps the old attachment.
        require_once __DIR__ . "/includes/upload_helper.php";
        require_once __DIR__ . "/includes/scanner.php";
        $uploadErrors = [];

        $genAttachmentPath = null;
        if (!empty($_FILES['genAttachment']['name'])) {
            $err = null;
            $genAttachmentPath = dms_store_upload($_FILES['genAttachment'], $err);
            if ($genAttachmentPath === null) {
                $uploadErrors[] = 'Order of Business attachment: ' . $err;
            }
        }
        if ($genAttachmentPath === null) {
            $genAttachmentPath = (string) $current['genAttachment'];
        }

        $attachmentPath = null;
        if (!empty($_FILES['attachment']['name'])) {
            $err = null;
            $attachmentPath = dms_store_upload($_FILES['attachment'], $err);
            if ($attachmentPath === null) {
                $uploadErrors[] = 'Supporting document: ' . $err;
            }
        }
        if ($attachmentPath === null) {
            $attachmentPath = (string) $current['attachment'];
        }

        // Update the record in place. The old status / return columns are left
        // untouched so earlier details are preserved.
        $query = false;
        $stmt = $conn->prepare("UPDATE `minutes` SET `no_regSession` = ?, `date` = ?, `genAttachment` = ?,
                                       `resNo` = ?, `title` = ?, `attachment` = ? WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $no_regSession, $date, $genAttachmentPath, $resNo, $title, $attachmentPath, $id);
            $query = $stmt->execute();
            $stmt->close();
        }

        if ($query) {
            $result = dms_ob_save_item($conn, $id, $itemIn, $current, true);

            // A separate entry when the section or status changed, so the history shows the full trail.
            $changeLabel = dms_ob_change_label($result);
            if ($changeLabel !== '') {
                dms_ob_log_history($conn, $id, $title, $changeLabel, $result['section'], $result['status']);
            }
            dms_ob_log_history($conn, $id, $title, 'Edited', $result['section'], $result['status']);
        }

        if ($query) {
            // Extra PDFs from the scanner (Separate PDFs) → additional attachments
            $uploadErrors = array_merge($uploadErrors, dms_ra_store_posted_extras($conn, 'ob', $id, 'scan_extra'));
            echo dms_scan_clear_script('ob:' . $id);
        }

        if ($query && $uploadErrors) {
            echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'Updated, but a file was not uploaded',
                    text: " . json_encode(implode("\n", $uploadErrors) . "\nThe previous attachment was kept.") . ",
                    showCancelButton: true,
                    confirmButtonText: 'Continue Editing',
                    cancelButtonText: 'Return to Order of Business'
                }).then((result) => {
                    if (!result.isConfirmed) {
                        DmsNav.returnTo('files-meetingminutes.php');
                    }
                });
            });
        </script>";
        } elseif ($query) {
            echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'success',
                    title: 'Order of Business Updated',
                    text: 'The item has been updated and is now in " . htmlspecialchars($result['section'], ENT_QUOTES) . ".',
                    showCancelButton: true,
                    confirmButtonText: 'Continue Editing',
                    cancelButtonText: 'Return to Order of Business'
                }).then((result) => {
                    if (!result.isConfirmed) {
                        DmsNav.returnTo('files-meetingminutes.php');
                    }
                });
            });
        </script>";
        } else {
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'There was an error updating the Order of Business.',
                            confirmButtonText: 'OK'
                        });
                    });
                  </script>";
        }
    }
?>

<!DOCTYPE html>
<html lang="en">

<?php 
    include "header.php"; 
    include "connect.php";
    require_once __DIR__ . "/includes/scanner.php";

?>

<body>

    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php include "sidebar.php"; ?>

        <!--**********************************
            Content body start
        ***********************************-->
        <div class="content-body">
            <div class="container-fluid" >
                <!-- row -->
                <div class="row d-flex justify-content-center">
                    <div class="col-xl-8 col-xxl-12 items-center dms-form-wrap">                        
                        <div class="card" style="align-self: center;">
                            <div class="card-header">
                                <div><h4 class="card-title mb-0">Edit Order of Business</h4><p class="dms-card-sub">Update the details below, then save your changes.</p></div>
                            </div>
                            <?php
                                include("connect.php");
                                require_once __DIR__ . "/includes/minutes_workflow.php";
                                dms_ob_ensure_schema($conn);
                                $id = intval($_GET['id']); // Ensure ID is an integer
                                $sql = "SELECT * FROM `minutes` WHERE id = $id LIMIT 1";
                                $result = mysqli_query($conn, $sql);

                                if ($result && mysqli_num_rows($result) > 0) {
                                    $row = mysqli_fetch_assoc($result);
                                } else {
                                    echo "<script>alert('Invalid Record ID!'); window.location.replace('files-meetingminutes.php');</script>";
                                    exit;
                                }

                                // Earlier details (old action, schedules, referral), shown read-only
                                $schedules = dms_ob_load_schedules($conn, [$id]);
                                $schedules = isset($schedules[$id]) ? $schedules[$id] : [];
                                $obSection = dms_ob_section($row);
                                $obStatus  = dms_ob_status($row);
                            ?>
                            <div class="card-body">
                                <div class="basic-form">
                                    <form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post" enctype="multipart/form-data">
                                        <div class="form-group row">
                                            <div class="col-sm-9">
                                                <input type="hidden" class="form-control" value="<?php echo $row['id']?>" id="id" name="id">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">No. of Regular Session</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" placeholder="Please type here..." value="<?php echo $row['no_regSession']?>" id="no_regSession" name="no_regSession">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Date:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" placeholder="Please type here..." value="<?php echo $row['date']?>" id="date" name="date">
                                            </div>
                                        </div>
                                        <label>Upload Attachment for Order of Business:</label>
                                        <div class="input-group mb-3">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"> <i class="fa fa-paperclip"></i></span>
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" class="custom-file-input" value="genAttachment" id="genAttachment" name="genAttachment" onchange="updateFileName('genAttachmentLabel')">
                                                <label class="custom-file-label" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block;" id="genAttachmentLabel"> 
                                                    <?php echo !empty($row['genAttachment']) ? $row['genAttachment'] : "Choose file"; ?>
                                                </label>
                                            </div>
                                            <div class="input-group-append">
                                                <?php echo dms_scan_button(['mode' => 'form', 'module' => 'ob', 'record_id' => $row['id'], 'field' => 'genAttachment', 'input' => 'genAttachment', 'extra_field' => '', 'label' => 'Order of Business ' . $row['no_regSession']]); ?>
                                                <button class="btn btn-danger" type="button" onclick="removeFile()"><i class="fa fa-close"></i></button>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Item No.:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" value="<?php echo $row['resNo']?>" name="resNo" required>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Title:</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" id="title" name="title" rows="1" style="resize: none; overflow: hidden;"><?php echo htmlspecialchars_decode($row['title']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label" for="ob_section">Section:</label>
                                            <div class="col-sm-9">
                                                <?php dms_ob_render_section_field('ob[section]', 'ob_section', $obSection); ?>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label" for="ob_status">Status:</label>
                                            <div class="col-sm-9">
                                                <?php dms_ob_render_status_field('ob[status]', 'ob_status', $obSection, $obStatus); ?>
                                                <small class="dms-ob-muted">Options depend on the section. Changing the section clears a status it does not offer. Approved moves the item to Third Reading.</small>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label" for="ob_res_no">Resolution / Ordinance No.:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="ob_res_no" name="ob[resolutionNo]" value="<?php echo htmlspecialchars((string) $row['resolutionNo']); ?>" placeholder="e.g. Resolution No. 012-2026">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label" for="ob_res_date">Date approved:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" id="ob_res_date" name="ob[resolutionDate]" value="<?php echo htmlspecialchars(dms_ob_clean_date((string) $row['resolutionDate'])); ?>">
                                            </div>
                                        </div>
                                        <?php if (dms_ob_has_earlier($row, $schedules)) { ?>
                                            <div class="form-group row">
                                                <label class="col-sm-3 col-form-label">Earlier details:</label>
                                                <div class="col-sm-9">
                                                    <div class="dms-ob-summary"><?php dms_ob_render_earlier($row, $schedules); ?></div>
                                                </div>
                                            </div>
                                        <?php } ?>
                                        <label>Upload Attachment as Supporting Documents:</label>
                                        <div class="input-group mb-3">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"> <i class="fa fa-paperclip"></i></span>
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" class="custom-file-input" id="attachment" value="attachment" name="attachment" onchange="updateFileName('attachmentLabel')">
                                                <label class="custom-file-label" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block;" id="attachmentLabel"> 
                                                    <?php echo !empty($row['attachment']) ? $row['attachment'] : "Choose file"; ?>
                                                </label>
                                            </div>
                                            <div class="input-group-append">
                                                <?php echo dms_scan_button(['mode' => 'form', 'module' => 'ob', 'record_id' => $row['id'], 'field' => 'attachment', 'input' => 'attachment', 'extra_field' => 'scan_extra[]', 'label' => 'OB Item ' . $row['resNo']]); ?>
                                                <button class="btn btn-danger" type="button" onclick="removeFile2()"><i class="fa fa-close"></i></button>
                                            </div>
                                        </div>
                                        <?php dms_ra_render_list($conn, 'ob', $row['id'], true); ?>
                                        <div class="form-group row d-flex justify-content-center mt-5 dms-form-actions">
                                            <button type="submit" class="btn btn-primary" id="save_btn" name="save" value="Save Data">Update</button>
                                            <a href="files-meetingminutes.php" class="btn btn-light" id="cancel_btn" name="cancel" value="Cancel" data-dms-return>Cancel</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--**********************************
            Content body end
        ***********************************-->

        
    </div>
    <!--**********************************
        Main wrapper end
    ***********************************-->

    <!--**********************************
        Scripts
    ***********************************-->
    <!-- Required vendors -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>


    <script>
        function updateFileName(labelId) {
            const fileInput = document.getElementById(labelId.replace("Label", ""));
            const fileName = fileInput.files.length > 0 ? fileInput.files[0].name : "Choose file";
            document.getElementById(labelId).textContent = fileName;
        }
    </script>

    <script>
        function autoResizeTextarea(textarea) {
            textarea.style.height = 'auto'; // Reset height to recalculate
            textarea.style.height = textarea.scrollHeight + 'px'; // Set to scrollHeight
        }

        function removeFile() {
            const fileInput = document.getElementById("genAttachment");
            const fileLabel = fileInput.nextElementSibling;

            fileInput.value = ""; // Clear file inputs
            fileLabel.textContent = "Choose file"; // Reset labels
        }

        function removeFile2() {
            const fileInput = document.getElementById("attachment");
            const fileLabel = fileInput.nextElementSibling;

            fileInput.value = ""; // Clear file inputs
            fileLabel.textContent = "Choose file"; // Reset labels
        }

        document.addEventListener("DOMContentLoaded", function() {
            const textarea = document.getElementById("title");

            // Resize on input
            textarea.addEventListener("input", function() {
                autoResizeTextarea(this);
            });

            // Resize initially in case there's preloaded content
            autoResizeTextarea(textarea);
        });
    </script>

    <!-- Status options follow the chosen section (dms_ob_render_status_field) -->
    <script>
        (function () {
            var sectionSel = document.querySelector('[data-ob-section]');
            var statusSel = document.querySelector('[data-ob-status]');
            if (!sectionSel || !statusSel) { return; }
            var options = {};
            try { options = JSON.parse(statusSel.getAttribute('data-ob-status-options') || '{}') || {}; } catch (e) { options = {}; }

            sectionSel.addEventListener('change', function () {
                var keep = statusSel.value;
                var list = options[sectionSel.value] || [];
                while (statusSel.options.length > 1) { statusSel.remove(1); } // keep "— No status —"
                list.forEach(function (label) {
                    statusSel.add(new Option(label, label, false, label === keep));
                });
                if (list.indexOf(keep) === -1) { statusSel.value = ''; }
            });
        })();
    </script>

    
    
    <?php dms_scanner_assets(); // Scan Document (includes/scanner.php) ?>
</body>

</html>