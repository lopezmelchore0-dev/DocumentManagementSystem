<?php
    if(isset($_POST['save'])){
        include "connect.php";
        require_once __DIR__ . "/includes/minutes_workflow.php";
        require_once __DIR__ . "/includes/upload_helper.php";
        require_once __DIR__ . "/includes/scanner.php";
        error_reporting(0);
        dms_ob_ensure_schema($conn);

        $no_regSession = $_POST['no_regSession'];
        $date = $_POST['date'];

        // Upload General Attachment. If it fails nothing is saved, so no item points at a missing file.
        $genAttachmentPath  = "";
        $genUploadError = null;
        $itemUploadErrors = [];
        if (!empty($_FILES['genAttachment']['name'])) {
            $genAttachmentPath = dms_store_upload($_FILES['genAttachment'], $genUploadError);
        }

        // One record per agenda item. Cards use explicit indexes (resNo[3], ob[3][...]),
        // so deleting a card on the form never shifts another card's fields.
        $saved = 0;
        if ($genAttachmentPath !== null && isset($_POST['resNo']) && is_array($_POST['resNo'])) {
            foreach ($_POST['resNo'] as $key => $resNo) {
                $title = isset($_POST['title'][$key]) ? $_POST['title'][$key] : '';
                // Section only: new items always start with a blank status.
                $itemIn = dms_ob_item_input(isset($_POST['ob'][$key]) ? $_POST['ob'][$key] : []);
                $itemIn['status'] = '';

                // Handle attachment for each item
                $attachmentPath = "";
                if (!empty($_FILES['attachment']['name'][$key])) {
                    $itemError = null;
                    $attachmentPath = dms_store_upload(dms_upload_item($_FILES['attachment'], $key), $itemError);
                    if ($attachmentPath === null) {
                        // Save the item without the file and tell the user to attach it via Edit.
                        $attachmentPath = "";
                        $itemUploadErrors[] = 'Item ' . ($resNo !== '' ? $resNo : '#' . $key) . ': ' . $itemError;
                    }
                }

                // Insert the record; the old Status / Returned fields are no longer used.
                $sql = "INSERT INTO minutes (no_regSession, date, genAttachment, resNo, title, type, status, returnNo, resolutionNo, attachment, returnDate, resolutionDate)
                        VALUES (?, ?, ?, ?, ?, '', '', '', '', ?, '0000-00-00', '0000-00-00')";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param("ssssss", $no_regSession, $date, $genAttachmentPath, $resNo, $title, $attachmentPath);
                if (!$stmt->execute()) {
                    $stmt->close();
                    continue;
                }
                $newId = $stmt->insert_id;
                $stmt->close();

                // Extra PDFs scanned for this item (Separate PDFs) → additional attachments
                $itemUploadErrors = array_merge($itemUploadErrors, dms_ra_store_posted_extras($conn, 'ob', $newId, 'scan_extra_item', $key));

                // Section (First Reading unless another was chosen); status stays blank.
                $result = dms_ob_save_item($conn, $newId, $itemIn, [], false);
                $saved++;

                dms_ob_log_history($conn, $newId, $title, 'Created', $result['section'], $result['status']);
            }
        }

        if ($genAttachmentPath === null) {
            echo dms_upload_error_script('Order of Business attachment: ' . $genUploadError . ' Nothing was saved.');
        } elseif ($saved > 0 && $itemUploadErrors) {
            echo dms_scan_clear_script('ob:add');
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Saved, but some files were not uploaded',
                            text: " . json_encode(implode("\n", $itemUploadErrors) . "\nOpen those items and attach the file using Edit.") . ",
                            confirmButtonText: 'OK'
                        }).then(() => { dmsAddDone('files-meetingminutes.php'); });
                    });
                  </script>";
        } elseif ($saved > 0) {
            echo dms_scan_clear_script('ob:add');
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Order of Business Created',
                            text: 'The agenda items have been saved.',
                            confirmButtonText: 'OK'
                        }).then(() => { dmsAddDone('files-meetingminutes.php'); });
                    });
                  </script>";
        } else {
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'There was an error creating the Order of Business.',
                            confirmButtonText: 'OK'
                        });
                    });
                  </script>";
        }

        $conn->close();
    }

?>

<!DOCTYPE html>
<html lang="en">

<?php
    include "header.php";
    include "connect.php";
    require_once __DIR__ . "/includes/scanner.php";
    require_once __DIR__ . "/includes/minutes_workflow.php";
    dms_ob_ensure_schema($conn);
?>

<head>
    <style>
        .card {
            margin-bottom: 15px;
        }
    </style>


</head>


<body<?php echo $dmsEmbed ? ' class="dms-embed"' : ''; ?>>

    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php if (!$dmsEmbed) include "sidebar.php"; ?>

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
                                <div><h4 class="card-title mb-0">Add Order of Business</h4><p class="dms-card-sub">Fill in the details below, then save. New items start in First Reading with no status.</p></div>
                            </div>
                            <div class="card-body">
                                <div class="basic-form">
                                    <form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post" enctype="multipart/form-data">
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">No. of Regular Session</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" placeholder="Please type here..." id="no_regSession" name="no_regSession" required>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Date:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" placeholder="Please type here..." id="date" name="date" required>
                                            </div>
                                        </div>
                                        <label>Upload Attachment for Order of Business:</label>
                                        <div class="input-group mb-3">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"> <i class="fa fa-paperclip"></i></span>
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" class="custom-file-input" id="genAttachment" name="genAttachment" onchange="updateFileName(this)">
                                                <label class="custom-file-label text-truncate" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block;" for="genAttachment">Choose file</label>
                                            </div>
                                            <div class="input-group-append">
                                                <?php echo dms_scan_button(['mode' => 'form', 'module' => 'ob', 'field' => 'genAttachment', 'input' => 'genAttachment', 'extra_field' => '', 'label' => 'Order of Business', 'label_from' => 'no_regSession']); ?>
                                                <button class="btn btn-danger" type="button" onclick="removeFile()"><i class="fa fa-close"></i></button>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-5 mb-3 dms-agenda-head">
                                            <h5>AGENDA ITEM</h5>
                                            <div class="d-flex align-items-center dms-agenda-add">
                                                <input type="number" id="formCountInput" class="form-control mr-2" min="1" max="50" style="width: 5rem;" placeholder="0">
                                                <button type="button" class="btn btn-primary" id="add-card-btn" value="Save Data"><i class="fa fa-plus"></i> Form</button>
                                            </div>
                                        </div>
                                        <div id="dynamic-form-container">
                                            <!-- Dynamic cards will be appended here -->
                                        </div>
                                        <div class="form-group row d-flex justify-content-center mt-5 dms-form-actions">
                                            <button type="submit" class="btn btn-primary" id="save_btn" name="save" value="Save Data">Save</button>
                                            <a href="#" class="btn btn-light" id="cancel_btn" name="cancel" value="Cancel" data-href="files-meetingminutes.php">Cancel</a>
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

    <!-- One agenda item card; __IDX__ is replaced with a unique index per card. -->
    <template id="ob-item-template">
        <div class="card p-3">
            <div class="card-body mt-3">
                <div class="basic-form">
                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label" style="color: #000000">Item No.:</label>
                        <div class="col-sm-9">
                            <input type="text" placeholder="Please type here..." class="form-control" name="resNo[__IDX__]" required>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label" style="color:#000000">Title:</label>
                        <div class="col-sm-9">
                            <textarea class="form-control dynamic-textarea" style="resize: none; overflow: hidden;" rows="1" placeholder="Please type here..." name="title[__IDX__]" required></textarea>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label" style="color:#000000" for="ob__IDX___section">Section:</label>
                        <div class="col-sm-9">
                            <?php dms_ob_render_section_field('ob[__IDX__][section]', 'ob__IDX___section', 'First Reading'); ?>
                            <small class="dms-ob-muted">The status starts blank. Set it later from the Order of Business list (right-click the item).</small>
                        </div>
                    </div>

                    <label style="color: #000000">Upload Attachment as Supporting Documents:</label>
                    <div class="input-group mb-3">
                        <div class="input-group-prepend">
                            <span class="input-group-text" style="background-color: #098209;"> <i class="fa fa-paperclip"></i></span>
                        </div>
                        <div class="custom-file">
                            <input type="file" class="custom-file-input ob-item-file" id="attachment___IDX__" name="attachment[__IDX__]" onchange="updateFileName(this)">
                            <label class="custom-file-label text-truncate" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block;" for="attachment___IDX__">Choose file</label>
                        </div>
                        <div class="input-group-append">
                            <?php echo dms_scan_button(['mode' => 'form', 'module' => 'ob', 'field' => 'attachment', 'input' => 'attachment___IDX__', 'extra_field' => 'scan_extra_item[__IDX__][]', 'label' => 'OB Item', 'session' => 'ob:add:item:__IDX__']); ?>
                            <button class="btn btn-danger ob-item-file-clear" type="button"><i class="fa fa-close"></i></button>
                        </div>
                    </div>
                    <div class="form-group row d-flex justify-content-center">
                        <button type="button" class="btn btn-danger delete-btn flex"><i class='fa fa-trash' aria-hidden='true'> Delete</i></button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!--**********************************
        Scripts
    ***********************************-->
    <!-- Required vendors -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>

    <script>

        document.addEventListener("DOMContentLoaded", function() {
            addDynamicCard(); // Add a default card when the page loads
        });

        document.getElementById("add-card-btn").addEventListener("click", function() {
            let countInput = document.getElementById("formCountInput");
            let count = parseInt(countInput.value);

            // Check if count is a valid number and greater than 0
            if (!isNaN(count) && count > 0) {
                for (let i = 0; i < count; i++) {
                    addDynamicCard();
                }

                // Reset the input field after adding forms
                countInput.value = "";
            } else {
                // Alert the user if no valid number was entered
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please enter the number of forms to add.',
                    confirmButtonText: 'OK'
                });
            }
        });

        // Unique per card, so every card's fields post under their own index.
        let nextCardIndex = 0;

        function addDynamicCard() {
            const container = document.getElementById("dynamic-form-container");
            const template = document.getElementById("ob-item-template");
            const index = nextCardIndex++;

            const holder = document.createElement("div");
            holder.innerHTML = template.innerHTML.replace(/__IDX__/g, index);
            const card = holder.firstElementChild;
            container.appendChild(card);

            // Auto-expand the title textarea
            card.querySelector(".dynamic-textarea").addEventListener("input", autoExpand);

            // Delete this card
            card.querySelector(".delete-btn").addEventListener("click", function () {
                container.removeChild(card);
            });

            // Clear this card's attachment
            const fileInput = card.querySelector(".ob-item-file");
            card.querySelector(".ob-item-file-clear").addEventListener("click", function () {
                fileInput.value = "";
                fileInput.nextElementSibling.textContent = "Choose file";
            });
        }

        // Function to auto-expand the textarea
        function autoExpand() {
            this.style.height = "auto"; // Reset height
            this.style.height = (this.scrollHeight) + "px"; // Adjust height based on content
        }

        function updateFileName(input) {
            if (input.files.length > 0) {
                let fileName = input.files[0].name;
                let label = input.nextElementSibling;
                label.innerText = fileName;
            }
        }

        function removeFile() {
            const fileInput = document.getElementById("genAttachment");
            const fileLabel = fileInput.nextElementSibling;

            fileInput.value = ""; // Clear file inputs
            fileLabel.textContent = "Choose file"; // Reset labels
        }

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
                    dmsAddLeave(redirectUrl);
                }
            });
        });
    </script>


    <?php dms_scanner_assets(); // Scan Document (includes/scanner.php) ?>
</body>

</html>
