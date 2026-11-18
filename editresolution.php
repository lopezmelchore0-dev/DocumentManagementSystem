<?php

if(isset($_POST['save'])){
    include("connect.php");
    error_reporting(0);

    $id = intval($_POST['id']);
    $resoNo = mysqli_real_escape_string($conn, $_POST['resoNo']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $dateAdopted = mysqli_real_escape_string($conn, $_POST['dateAdopted']);
    $authorSponsor = mysqli_real_escape_string($conn, $_POST['authorSponsor']);
    $coAuthor = mysqli_real_escape_string($conn, $_POST['coAuthor']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks']);
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    $dateForwarded = mysqli_real_escape_string($conn, $_POST['dateForwarded']);
    $dateSigned = mysqli_real_escape_string($conn, $_POST['dateSigned']);
    $spResoNo = mysqli_real_escape_string($conn, $_POST['spResoNo']);
    $dateApproved = mysqli_real_escape_string($conn, $_POST['dateApproved']);

    // Handle file uploads. If the new file cannot be saved, the old attachment is kept.
    require_once __DIR__ . "/includes/upload_helper.php";
    require_once __DIR__ . "/includes/scanner.php";
    $uploadError = null;
    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name'])) {
        $attachmentPath = dms_store_upload($_FILES['attachment'], $uploadError);
    }
    if ($attachmentPath === null) {
        $attachmentQuery = "SELECT attachment FROM resolution WHERE id = $id";
        $result = mysqli_query($conn, $attachmentQuery);
        $row = mysqli_fetch_assoc($result);
        $attachmentPath = $row ? $row['attachment'] : '';
    }
    $attachmentPath = mysqli_real_escape_string($conn, $attachmentPath);
    $sql = "UPDATE `resolution` SET 
                    `reso_no`='$resoNo', 
                    `title`='$title', 
                    `d_adopted`='$dateAdopted', 
                    `author_sponsor`='$authorSponsor', 
                    `co_author`='$coAuthor', 
                    `d_forward`='$dateForwarded',
                    `d_signed`='$dateSigned',
                    `sp_resoNo`='$spResoNo',
                    `d_approved`='$dateApproved',
                    `remarks`='$remarks',
                    `notes`='$notes',
                    `attachment`='$attachmentPath' WHERE id = $id";

    $query = mysqli_query($conn, $sql);
    
    $log_sql = "INSERT INTO history_log (action, file_type, file_id, title) 
    VALUES ('Edited', 'Resolution', $id, '$title')";
    $conn->query($log_sql);

    if($query) {
        // Extra PDFs from the scanner (Separate PDFs) → additional attachments
        $scanErrors = dms_ra_store_posted_extras($conn, 'resolution', $id, 'scan_extra');
        echo dms_scan_clear_script('resolution:' . $id);
        echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: " . json_encode($uploadError ? 'warning' : 'success') . ",
                        title: " . json_encode($uploadError ? 'Updated, but the file was not uploaded' : 'Resolution Updated') . ",
                        text: " . json_encode(($uploadError ? $uploadError . ' The previous attachment was kept.' : 'The resolution has been successfully updated.') . dms_scan_errors_note($scanErrors)) . ",
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // OK, Esc or the phone's Back: return to the list already in history
                        DmsNav.returnTo('files-resolution.php');
                    });
                });
              </script>";    
    } else {
        echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'There was an error updating the resolution.',
                        confirmButtonText: 'OK'
                    });
                });
              </script>";
        header("Location: files-resolution.php");
        exit;    
    }
}    
?>

<!DOCTYPE html>
<html lang="en">

<?php include "header.php"; require_once __DIR__ . "/includes/scanner.php"; ?>

<head>
    <!-- Include SweetAlert CSS and JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const requiredFields = ["resoNo", "title", "dateAdopted", "authorSponsor"];

        function validateField(field) {
            let inputElement = document.getElementById(field);
            let errorElement = document.getElementById(field + "-error");

            if (!inputElement.value.trim() || (field === "remarks" && inputElement.value === "Choose...")) {
                if (!errorElement) {
                    let errorMsg = document.createElement("div");
                    errorMsg.id = field + "-error";
                    errorMsg.className = "text-danger mt-1";
                    errorMsg.textContent = "Required missing field.";
                    inputElement.parentNode.appendChild(errorMsg);
                }
            } else {
                if (errorElement) {
                    errorElement.remove();
                }
            }
        }

        // Add event listeners for real-time validation
        requiredFields.forEach(function (field) {
            let inputElement = document.getElementById(field);

            if (inputElement) {
                // "input" event - Hide error while typing
                inputElement.addEventListener("input", function () {
                    validateField(field);
                });

                // "change" event for dropdown validation
                if (field === "remarks") {
                    inputElement.addEventListener("change", function () {
                        validateField(field);
                    });
                }

                // "focusout" event - Show error if empty when user leaves field
                inputElement.addEventListener("focusout", function () {
                    validateField(field);
                });
            }
        });

        // Form submit validation
        document.querySelector("form").addEventListener("submit", function (event) {
            let isValid = true;
            requiredFields.forEach(function (field) {
                validateField(field);
                if (!document.getElementById(field).value.trim() || 
                    (field === "remarks" && document.getElementById(field).value === "Choose...")) {
                    isValid = false;
                }
            });

            if (!isValid) {
                event.preventDefault();
            }
        });
    });
</script>

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
                                <div><h4 class="card-title mb-0">Edit Resolution</h4><p class="dms-card-sub">Update the details below, then save your changes.</p></div>
                            </div>
                            <?php 
                                include "connect.php";
                                $id = $_GET['id'];
                                $sql = "SELECT * FROM resolution WHERE id = $id LIMIT 1";
                                $result= mysqli_query($conn, $sql);   
                                $row = mysqli_fetch_assoc($result); 

                                $sql2 = "SELECT remarks FROM resolution WHERE id = '$id'";
                                $result2 = mysqli_query($conn, $sql2);
                                $row2 = mysqli_fetch_assoc($result2);

                                $selectedRemarks = $row2['remarks']; 
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
                                            <label class="col-sm-3 col-form-label">Resolution No: </label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" value="<?php echo $row['reso_no']?>" id="resoNo" name="resoNo">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Title:</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" id="title" name="title" rows="1" style="resize: none; overflow: hidden;"><?php echo htmlspecialchars_decode($row['title']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Date Adopted:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" value="<?php echo $row['d_adopted']?>" id="dateAdopted" name="dateAdopted">
                                            </div>
                                        </div>
                                        <!-- <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Description:</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" style="resize: none;" rows="4" value=" id="description" name="description"></textarea>
                                            </div>
                                        </div> -->
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Author / Sponsor:</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" id="authorSponsor" name="authorSponsor" rows="1" style="resize: none; overflow: hidden;"><?php echo htmlspecialchars_decode($row['author_sponsor']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Co-Author:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" value="<?php echo $row['co_author']?>" id="coAuthor" name="coAuthor">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Status:</label>
                                            <div class="col-sm-9">
                                                <select id="remarks" name="remarks" class="form-control" onchange="toggleDateFields()">
                                                    <option value="" <?php echo ($selectedRemarks == '') ? 'selected' : ''; ?>>Choose...</option>
                                                    <option value="Forwarded to LCE" <?php echo ($selectedRemarks == 'Forwarded to LCE') ? 'selected' : ''; ?>
                                                        <?php echo ($selectedRemarks == 'Signed by LCE' || $selectedRemarks == 'SP Approval') ? 'selected' : ''; ?>>
                                                        Forwarded to LCE
                                                    </option>
                                                    <option value="Signed by LCE" <?php echo ($selectedRemarks == 'Signed by LCE') ? 'selected' : ''; ?>
                                                        <?php echo ($selectedRemarks == 'SP Approval') ? 'selected' : ''; ?>>
                                                        Signed by LCE
                                                    </option>
                                                    <option value="SP Approval" <?php echo ($selectedRemarks == 'SP Approval') ? 'selected' : ''; ?>>
                                                        SP Approval
                                                    </option>
                                                    <option value="Disapprove" <?php echo ($selectedRemarks == 'Disapprove') ? 'selected' : ''; ?>>Disapprove</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div id="dateFields">
                                            <div class="form-group row" id="forwardedDateField" style="display: none;">
                                                <label class="col-sm-3 col-form-label">Date Forwarded to LCE:</label>
                                                <div class="col-sm-9">
                                                    <input type="date" class="form-control" value="<?php echo $row['d_forward']?>" id="dateForwarded" name="dateForwarded">
                                                </div>
                                            </div>
                                            <div class="form-group row" id="signedDateField" style="display: none;">
                                                <label class="col-sm-3 col-form-label">Date Signed by LCE:</label>
                                                <div class="col-sm-9">
                                                    <input type="date" class="form-control" value="<?php echo $row['d_signed']?>" id="dateSigned" name="dateSigned">
                                                </div>
                                            </div>
                                            <div class="form-group row" id="spResoNoField" style="display: none;">
                                                <label class="col-sm-3 col-form-label">SP Resolution No:</label>
                                                <div class="col-sm-9">
                                                    <input type="text" class="form-control" value="<?php echo $row['sp_resoNo']?>" id="spResoNo" name="spResoNo">
                                                </div>
                                            </div>
                                            <div class="form-group row" id="sbApprovalDateField" style="display: none;">
                                                <label class="col-sm-3 col-form-label">SP Approval:</label>
                                                <div class="col-sm-9">
                                                    <input type="date" class="form-control" value="<?php echo $row['d_approved']?>" id="dateApproved" name="dateApproved">
                                                </div>
                                            </div>
                                            <div class="form-group row" id="notesField" style="display: none;">
                                                <label class="col-sm-3 col-form-label">Remarks/Notes:</label>
                                                <div class="col-sm-9">
                                                    <textarea class="form-control" id="notes" name="notes" rows="1" style="resize: none; overflow: hidden;"><?php echo htmlspecialchars_decode($row['notes']); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
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
                                                <?php echo dms_scan_button(['mode' => 'form', 'module' => 'resolution', 'record_id' => $row['id'], 'field' => 'attachment', 'input' => 'attachment', 'extra_field' => 'scan_extra[]', 'label' => 'Resolution ' . $row['reso_no']]); ?>
                                                <button class="btn btn-danger" type="button" onclick="removeFile()"><i class="fa fa-close"></i></button>
                                            </div>
                                        </div>
                                        <?php dms_ra_render_list($conn, 'resolution', $row['id'], true); ?>
                                        <div class="form-group row d-flex justify-content-center dms-form-actions">
                                            <button type="submit" class="btn btn-primary" id="save_btn" name="save" value="Save Data">Update</button>
                                            <a href="#" class="btn btn-light" id="cancel_btn" name="cancel" value="Cancel" data-href="files-resolution.php">Cancel</a>
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

        function removeFile() {
            const fileInput = document.getElementById("attachment");
            const fileLabel = fileInput.nextElementSibling;

            fileInput.value = ""; // Clear file inputs
            fileLabel.textContent = "Choose file"; // Reset labels
        }

        document.addEventListener("DOMContentLoaded", function () {
        toggleDateFields(); 
        });

        function autoResizeTextarea(textarea) {
            textarea.style.height = 'auto'; // Reset height to recalculate
            textarea.style.height = textarea.scrollHeight + 'px'; // Set to scrollHeight
        }

        document.addEventListener("DOMContentLoaded", function() {
            const textareas = [document.getElementById("title"), document.getElementById("authorSponsor")];

            textareas.forEach(textarea => {
                if (textarea) {
                    // Resize on input
                    textarea.addEventListener("input", function() {
                        autoResizeTextarea(this);
                    });

                    // Resize initially in case there's preloaded content
                    autoResizeTextarea(textarea);
                }
            });
        });

        function toggleDateFields() {
        var status = document.getElementById("remarks").value;

        document.getElementById("forwardedDateField").style.display = "none";
        document.getElementById("signedDateField").style.display = "none";
        document.getElementById("spResoNoField").style.display = "none";
        document.getElementById("sbApprovalDateField").style.display = "none";
        document.getElementById("notesField").style.display = "none";

        if (status === "Forwarded to LCE") {
            document.getElementById("forwardedDateField").style.display = "flex";
        } else if (status === "Signed by LCE") {
            document.getElementById("forwardedDateField").style.display = "flex";
            document.getElementById("signedDateField").style.display = "flex";
        } else if (status === "SP Approval") {
            document.getElementById("forwardedDateField").style.display = "flex";
            document.getElementById("signedDateField").style.display = "flex";
            document.getElementById("spResoNoField").style.display = "flex";
            document.getElementById("sbApprovalDateField").style.display = "flex";
        } else if (status === "Disapprove") {
            document.getElementById("notesField").style.display = "flex";
        }

    }

    function updateMinDate(fieldId, targetIds) {
        let selectedDate = document.getElementById(fieldId).value;
        if (selectedDate) {
            targetIds.forEach(targetId => {
                document.getElementById(targetId).min = selectedDate;
            });
        }
    }

    document.getElementById("dateAdopted").addEventListener("change", function () {
        updateMinDate("dateAdopted", ["dateForwarded", "dateSigned", "dateApproved"]);
    });

    document.getElementById("dateForwarded").addEventListener("change", function () {
        updateMinDate("dateForwarded", ["dateSigned", "dateApproved"]);
    });

    document.getElementById("dateSigned").addEventListener("change", function () {
        updateMinDate("dateSigned", ["dateApproved"]);
    });
    </script>

    <script>
        document.getElementById('cancel_btn').addEventListener('click', function(e) {
            e.preventDefault(); // Prevent immediate navigation
            const redirectUrl = this.getAttribute('data-href');

            Swal.fire({
                title: 'Are you sure?',
                text: "All unsaved changes will be lost.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, cancel it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    DmsNav.returnTo(redirectUrl);
                }
            });
        });
    </script>
    
    <?php dms_scanner_assets(); // Scan Document (includes/scanner.php) ?>
</body>

</html>