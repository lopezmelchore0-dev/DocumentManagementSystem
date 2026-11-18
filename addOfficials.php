<!DOCTYPE html>
<html lang="en">

<?php include "header.php"; ?>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Only what the org chart needs; every other detail can be filled in later.
        const requiredFields = ["position", "surname", "firstname"];

        function validateField(field) {
            let inputElement = document.getElementById(field);
            let errorElement = document.getElementById(field + "-error");

            if (!inputElement.value.trim() || (field === "position" && inputElement.value === "Choose...")) {
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
                if (field === "position") {
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

        // "Other (type a position)" reveals a text box for a custom position.
        const positionSelect = document.getElementById("position");
        const positionOther = document.getElementById("position_other");
        if (positionSelect && positionOther) {
            positionSelect.addEventListener("change", function () {
                const other = positionSelect.value === "__other";
                positionOther.hidden = !other;
                positionOther.required = other;
                if (other) { positionOther.focus(); }
            });
        }

        // Form submit validation
        document.querySelector("form").addEventListener("submit", function (event) {
            let isValid = true;
            if (positionSelect && positionSelect.value === "__other" && !positionOther.value.trim()) {
                isValid = false;
                positionOther.focus();
            }
            requiredFields.forEach(function (field) {
                validateField(field);
                if (!document.getElementById(field).value.trim() || 
                    (field === "position" && document.getElementById(field).value === "Choose...")) {
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
                <div class="row d-flex justify-content-center">
                    <div class="col-xl-8 col-xxl-12 items-center dms-form-wrap">                        
                        <div class="card" style="align-self: center;">
                            <div class="card-header">
                                <div><h4 class="card-title mb-0">Add Officials</h4><p class="dms-card-sub">Fill in the details below, then save.</p></div>
                            </div>
                            <div class="card-body">
                                <div class="basic-form">
                                    <form action="addOfficials.php" method="post" enctype="multipart/form-data">
                                        <?php
                                            if (isset($_POST['save'])) {
                                                include("connect.php");
                                                error_reporting(0);

                                                require_once __DIR__ . '/includes/org_chart.php';
                                                dms_org_ensure_schema($conn);

                                                // Collect POST data
                                                $position = $_POST['position'];
                                                if ($position === '__other') { // "Other (type a position)"
                                                    $position = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['position_other'] ?? ''))), 0, 100);
                                                }
                                                // Optional fields: blank becomes NULL (DATE/ENUM columns reject '').
                                                $nul = function ($v) {
                                                    $v = trim((string) $v);
                                                    return $v === '' ? null : $v;
                                                };
                                                $term_start = $nul($_POST['term_start'] ?? '');
                                                $term_end = $nul($_POST['term_end'] ?? '');
                                                $surname = $_POST['surname'];
                                                $firstname = $_POST['firstname'];
                                                $middlename = $_POST['middlename'];
                                                $birthday = $nul($_POST['birthday'] ?? '');
                                                $birthplace = $_POST['birthplace'];
                                                $address = $_POST['address'];
                                                $mobile_number = $_POST['mobile_number'];
                                                $email = $_POST['email'];
                                                $gender = $nul($_POST['gender'] ?? '');

                                                $education_attainment = $_POST['education_attainment'];
                                                $education_school = $_POST['education_school'];
                                                $education_date = $_POST['education_date'];

                                                $civil_status = $nul($_POST['civil_status'] ?? '');
                                                $spouse_name = $_POST['spouse_name'];
                                                $spouse_birthday = $nul($_POST['spouse_birthday'] ?? '');
                                                $spouse_birthplace = $_POST['spouse_birthplace'];

                                                $dependents = $_POST['dependents'];
                                                $gsis_number = $_POST['gsis_number'];
                                                $pagibig_number = $_POST['pagibig_number'];
                                                $philhealth_number = $_POST['philhealth_number'];

                                                // Handle image upload
                                                $photo_path = "";
                                                if (!empty($_FILES['photo']['name'])) {
                                                    $targetDir = "uploads/";
                                                    $imageFileType = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                                                    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];

                                                    if (in_array($imageFileType, $allowedTypes)) {
                                                        $newFileName = uniqid() . '.' . $imageFileType; // avoid file name collisions
                                                        $photo_path = $targetDir . $newFileName;
                                                        move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path);
                                                    } else {
                                                        echo "<script>
                                                                Swal.fire({
                                                                    icon: 'error',
                                                                    title: 'Invalid File Type',
                                                                    text: 'Please upload a valid image (JPG, PNG, or GIF).'
                                                                });
                                                            </script>";
                                                        exit;
                                                    }
                                                }

                                                // Prepare SQL insert
                                                $sql = "INSERT INTO officials 
                                                    (position, term_start, term_end, surname, firstname, middlename, birthday, birthplace, address, mobile_number, email, gender,
                                                    education_attainment, education_school, education_date,
                                                    civil_status, spouse_name, spouse_birthday, spouse_birthplace,
                                                    dependents, gsis_number, pagibig_number, philhealth_number, photo_path) 
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                                                $stmt = $conn->prepare($sql);
                                                $stmt->bind_param("ssssssssssssssssssssssss", 
                                                    $position, $term_start, $term_end, $surname, $firstname, $middlename, $birthday, $birthplace, $address, $mobile_number, $email, $gender,
                                                    $education_attainment, $education_school, $education_date,
                                                    $civil_status, $spouse_name, $spouse_birthday, $spouse_birthplace,
                                                    $dependents, $gsis_number, $pagibig_number, $philhealth_number, $photo_path);

                                                if ($stmt->execute()) {
                                                    // Chart group: the one picked, otherwise where this position already sits.
                                                    $newId = (int) $conn->insert_id;
                                                    $group_id = (int) ($_POST['group_id'] ?? 0);
                                                    if (!$group_id || !dms_org_group_exists($conn, $group_id)) {
                                                        $group_id = dms_org_default_group($conn, $position);
                                                    }
                                                    if ($group_id) {
                                                        $g = $conn->prepare("UPDATE officials SET group_id = ?, sort_order = 1000 WHERE id = ?");
                                                        $g->bind_param("ii", $group_id, $newId);
                                                        $g->execute();
                                                    }
                                                    echo "<script>
                                                            Swal.fire({
                                                                icon: 'success',
                                                                title: 'Official Added',
                                                                text: 'The official has been successfully added.'
                                                            }).then(() => { DmsNav.returnTo('files-officials.php'); });
                                                        </script>";
                                                } else {
                                                    echo "<script>
                                                            Swal.fire({
                                                                icon: 'error',
                                                                title: 'Error',
                                                                text: 'There was an error creating the official.',
                                                                confirmButtonText: 'OK'
                                                            });
                                                        </script>";
                                                }

                                                $stmt->close();
                                                // $conn stays open: the position limits below still query it.
                                            }
                                        ?>

                                        <!-- Form Inputs -->
                                        
                                        <!-- BASIC INFORMATION-->
                                        <div class="form-group row">
                                            <div class="col-sm-12">
                                                <h5 style="font-weight: bold;">Basic Information</h5>
                                            </div>
                                        </div>
                                        <?php
                                        // Get current counts from DB
                                        $positions = ['Vice-Mayor', 'Councilor', 'LNB', 'PPSK'];
                                        $limits = ['Vice-Mayor' => 1, 'Councilor' => 10, 'LNB' => 5, 'PPSK' => 4];
                                        $current = [];

                                        foreach ($positions as $pos) {
                                            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM officials WHERE position = ?");
                                            $stmt->bind_param("s", $pos);
                                            $stmt->execute();
                                            $res = $stmt->get_result()->fetch_assoc();
                                            $current[$pos] = $res['count'];
                                        }

                                        // Chart groups and custom positions already in use (includes/org_chart.php)
                                        require_once __DIR__ . '/includes/org_chart.php';
                                        dms_org_ensure_schema($conn);
                                        $orgGroups = dms_org_groups($conn);
                                        $orgCustomPositions = array_values(array_diff(dms_org_known_positions($conn), $positions));
                                        ?>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Position:</label>
                                            <div class="col-sm-9">
                                                <select class="form-control" id="position" name="position" required>
                                                    <option value="" selected>Choose...</option>
                                                    <option value="Vice-Mayor" <?= $current['Vice-Mayor'] >= $limits['Vice-Mayor'] ? 'disabled' : '' ?>>Vice-Mayor</option>
                                                    <option value="Councilor" <?= $current['Councilor'] >= $limits['Councilor'] ? 'disabled' : '' ?>>Councilor</option>
                                                    <option value="LNB" <?= $current['LNB'] >= $limits['LNB'] ? 'disabled' : '' ?>>LNB</option>
                                                    <option value="PPSK" <?= $current['PPSK'] >= $limits['PPSK'] ? 'disabled' : '' ?>>PPSK</option>
                                                    <?php foreach ($orgCustomPositions as $p) { ?>
                                                        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                                                    <?php } ?>
                                                    <option value="__other">Other (type a position)…</option>
                                                </select>
                                                <input type="text" class="form-control mt-2" id="position_other" name="position_other" maxlength="100" placeholder="e.g. SB Secretary" hidden>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label" for="group_id">Chart group:</label>
                                            <div class="col-sm-9">
                                                <select class="form-control" id="group_id" name="group_id">
                                                    <option value="">Automatic (by position)</option>
                                                    <?php foreach ($orgGroups as $g) { ?>
                                                        <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['title']) ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Term Start:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" id="term_start" name="term_start" min="1900" max="2100">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Term End:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" id="term_end" name="term_end" min="1900" max="2100">
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Surname:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="surname" name="surname" required placeholder="Enter surname">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">First Name:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="firstname" name="firstname" required placeholder="Enter first name">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Middle Name:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="middlename" name="middlename" placeholder="Enter middle name (optional)">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Birthday:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" id="birthday" name="birthday">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Birthplace:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="birthplace" name="birthplace" placeholder="Enter birthplace">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Address:</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" id="address" name="address" rows="2" placeholder="Enter address"></textarea>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Mobile Number:</label>
                                            <div class="col-sm-9">
                                                <input type="tel" class="form-control" id="mobile_number" name="mobile_number" placeholder="Enter mobile number">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Email:</label>
                                            <div class="col-sm-9">
                                                <input type="email" class="form-control" id="email" name="email" placeholder="Enter email address">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Gender:</label>
                                            <div class="col-sm-9">
                                                <select class="form-control" id="gender" name="gender">
                                                    <option value="" selected>Choose...</option>
                                                    <option value="Male">Male</option>
                                                    <option value="Female">Female</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                        </div>

                                        <hr>
                                        
                                         <!-- EDUCATIONAL BACKGROUND-->
                                        <div class="form-group row">
                                            <div class="col-sm-12">
                                                <h5 style="font-weight: bold;">Educational Background</h5>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Highest Education Attainment:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="education_attainment" name="education_attainment" placeholder="e.g. Bachelor's Degree">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">School:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="education_school" name="education_school" placeholder="Enter school name">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Inclusive Dates:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="education_date" name="education_date" placeholder="e.g. June 2020">
                                            </div>
                                        </div>

                                        <hr>


                                         <!-- CIVIL STATUS INFORMATION -->
                                        <div class="form-group row">
                                            <div class="col-sm-12">
                                                <h5 style="font-weight: bold;">Civil Status</h5>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Civil Status:</label>
                                            <div class="col-sm-9">
                                                <select class="form-control" id="civil_status" name="civil_status">
                                                    <option value="" selected>Choose...</option>
                                                    <option value="Single">Single</option>
                                                    <option value="Married">Married</option>
                                                    <option value="Widowed">Widowed</option>
                                                    <option value="Divorced">Divorced</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Spouse Name:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="spouse_name" name="spouse_name" placeholder="Enter spouse name if applicable">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Spouse Birthday:</label>
                                            <div class="col-sm-9">
                                                <input type="date" class="form-control" id="spouse_birthday" name="spouse_birthday">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Spouse Birthplace:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="spouse_birthplace" name="spouse_birthplace" placeholder="Enter spouse birthplace">
                                            </div>
                                        </div>

                                        <hr>
                                        
                                        <!-- PERSONAL INFORMATION -->
                                        <div class="form-group row">
                                            <div class="col-sm-12">
                                                <h5 style="font-weight: bold;">Personal Information</h5>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Dependents:</label>
                                            <div class="col-sm-9">
                                                <textarea class="form-control" id="dependents" name="dependents" rows="2" placeholder="List dependents"></textarea>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">GSIS Number:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="gsis_number" name="gsis_number" placeholder="Enter GSIS number">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Pag-IBIG Number:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="pagibig_number" name="pagibig_number" placeholder="Enter Pag-IBIG number">
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">PhilHealth Number:</label>
                                            <div class="col-sm-9">
                                                <input type="text" class="form-control" id="philhealth_number" name="philhealth_number" placeholder="Enter PhilHealth number">
                                            </div>
                                        </div>

                                        <hr>

                                        <div class="input-group mb-3">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">
                                                    <i class="fa fa-paperclip"></i>
                                                </span>
                                            </div>
                                            <div class="custom-file">
                                                <input type="file" class="custom-file-input" id="photo" name="photo" accept="image/*" onchange="updateFileName(this)">
                                                <label class="custom-file-label text-truncate" for="photo" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block;">Choose image</label>
                                            </div>
                                            <div class="input-group-append">
                                                <button class="btn btn-danger" type="button" onclick="removeFile()"><i class="fa fa-close"></i></button>
                                            </div>
                                        </div>

                                        <div class="form-group row d-flex justify-content-center dms-form-actions">
                                            <button type="submit" class="btn btn-primary" id="save_btn" name="save" value="Save Data">Save</button>
                                            <a href="#" class="btn btn-light" id="cancel_btn" name="cancel" value="Cancel" data-href="files-officials.php">Cancel</a>
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
    <script src="./js/inputmask.min.js"></script>

    <script>

        function updateFileName(input) {
        const fileInput = input;
        if (fileInput.files.length > 0) {
            const fileName = fileInput.files[0].name;
            const label = fileInput.nextElementSibling; // label associated with input
            label.textContent = fileName;
        } else {
            const label = fileInput.nextElementSibling;
            label.textContent = "Choose image";
        }
    }

    // Auto-expand textarea (if you still want for age textarea)
    function autoExpand(event) {
        const textarea = event.target;
        textarea.style.height = "auto"; 
        textarea.style.height = textarea.scrollHeight + "px"; 
    }

    document.addEventListener("DOMContentLoaded", function () {
        // Auto-expand for age textarea
        const ageTextarea = document.getElementById("age");
        if (ageTextarea) {
            ageTextarea.addEventListener("input", autoExpand);
        }
    });

    function removeFile() {
        const fileInput = document.getElementById("photo");
        const fileLabel = fileInput.nextElementSibling;

        fileInput.value = ""; // Clear file input
        fileLabel.textContent = "Choose image"; // Reset label text
    }

    document.addEventListener("DOMContentLoaded", function () {
        const form = document.querySelector("form");
        const requiredFields = ["position", "surname", "firstname"];

        function validateField(field) {
            const inputElement = document.getElementById(field);
            if (!inputElement) return true;

            const errorElement = document.getElementById(field + "-error");
            const isEmpty = !inputElement.value.trim() || 
                            (field === "position" && inputElement.value === "Choose...");

            if (isEmpty) {
                if (!errorElement) {
                    const errorMsg = document.createElement("div");
                    errorMsg.id = field + "-error";
                    errorMsg.className = "text-danger mt-1";
                    errorMsg.textContent = "Required field.";
                    inputElement.parentNode.appendChild(errorMsg);
                }
                inputElement.classList.add("is-invalid");
                return false;
            } else {
                if (errorElement) errorElement.remove();
                inputElement.classList.remove("is-invalid");
                return true;
            }
        }

        function validateForm(event) {
            let isValid = true;
            let firstInvalid = null;

            requiredFields.forEach(function (field) {
                if (!validateField(field)) {
                    isValid = false;
                    if (!firstInvalid) firstInvalid = field;
                }
            });

            if (!isValid) {
                event.preventDefault(); // stop form submission

                Swal.fire({
                    icon: 'error',
                    title: 'Incomplete Form',
                    text: 'All required fields must be filled out before submitting!',
                    confirmButtonText: 'OK'
                });

                if (firstInvalid) {
                    document.getElementById(firstInvalid).scrollIntoView({ behavior: "smooth", block: "center" });
                    document.getElementById(firstInvalid).focus();
                }

                return false;
            }

            return true; // allow submission
        }

        // Attach validation on submit
        form.addEventListener("submit", validateForm);

        // Optional: live feedback on blur/input
        requiredFields.forEach(function (field) {
            const inputElement = document.getElementById(field);
            if (inputElement) {
                inputElement.addEventListener("input", () => validateField(field));
                inputElement.addEventListener("focusout", () => validateField(field));
            }
        });
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

    <script>
        Inputmask("99999999999").mask("#gsis_number");
        Inputmask("9999-9999-999").mask("#pagibig_number");
        Inputmask("99-999999999-9").mask("#philhealth_number");
    </script>
</body>

</html>