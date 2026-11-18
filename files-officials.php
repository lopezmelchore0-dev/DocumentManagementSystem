<?php 
    error_reporting(E_ALL); // Enable error reporting for development
    ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="en">

<?php include "header.php"; ?>

<body>
    
    <!--*******************
        Preloader start
    ********************-->
    <?php include"loadingscreen.php" ?>
    <!--*******************
        Preloader end
    ********************-->
    
    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php 
            
            include "sidebar.php";
            
            // Fetch role from session
            $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
            $canEdit = in_array($role, ['master', 'admin'], true);

            require_once __DIR__ . '/includes/org_chart.php';
            dms_org_ensure_schema($conn);
            $org = dms_org_load($conn);
            $orgGroups = dms_org_groups($conn);
            $orgPositions = dms_org_known_positions($conn);
            $orgTerm = dms_org_latest_term($conn);
            $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        ?>

<!-- MAIN WRAPPER -->
<div class="content-body">
    <div class="container-fluid">
        <div class="dms-page-head">
            <div>
                <h1>Organizational Chart</h1>
                <p>Current Sangguniang Bayan officials. Select an official to see their details<?php echo $canEdit ? '; use Edit chart to change titles, photos, groups and order' : ''; ?>.</p>
            </div>
            <!-- Buttons -->
            <div class="dms-page-actions">
                <?php if ($canEdit) { ?>
                    <button type="button" class="btn btn-light" data-org-toggle aria-pressed="false">
                        <i class="ti-pencil-alt"></i>&nbsp; Edit chart
                    </button>
                <?php } ?>
                <button type="button" class="btn btn-light" data-org-download title="Save the chart as a PNG image">
                    <i class="ti-download"></i>&nbsp; Download PNG
                </button>
                <button type="button" class="btn btn-light" data-org-copy title="Copy the chart image, then paste it into Word or a report">
                    <i class="ti-clipboard"></i>&nbsp; Copy image
                </button>
                <?php if ($canEdit) { ?>
                    <a href="addOfficials.php" class="btn btn-primary">
                        <i class="fa fa-plus"></i>&nbsp;Add Officials
                    </a>
                    <a href="previousTerms.php" class="btn btn-dark">
                        <i class="fa fa-calendar"></i>&nbsp;Publish the Chart
                    </a>
                <?php } ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <?php if ($org['count'] === 0 && !$canEdit) { ?>
                    <div class="dms-empty"><i class="ti-direction-alt"></i>No officials have been added yet.</div>
                <?php } else { ?>
                    <?php echo dms_org_render($org, ['editable' => $canEdit]); ?>
                <?php } ?>
            </div>
        </div>


         <!-- ********VIEW MODAL******** -->
        <div class="modal fade" id="officialModal" tabindex="-1" aria-labelledby="officialModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl"> <!-- wider modal -->
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success" id="officialModalLabel">Official Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

            <!-- View Mode -->
            <div id="viewMode">
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div style="background-color: #098209; color: white; padding: 1.5rem; border-radius: 0.5rem; height: 100%; display: flex; flex-direction: column; align-items: center;">

                            <!-- Image with top margin -->
                            <img id="modalImage" src="" class="img-fluid rounded shadow mb-3" style="max-height: 250px; margin-top: 2rem;">

                            <!-- Full Name and Position -->
                            <h4 id="modalName" class="mb-1 text-white text-center"></h4>
                            <p id="modalPosition" class="mb-3 text-white text-center"></p>
                            

                            <!-- Contact Information -->
                            <div class="d-flex flex-column align-items-center px-3 mt-4">
                                <p class="mb-1 text-white d-flex align-items-start" style="font-size: 0.9rem; text-align: justify;">
                                    <i class="fa fa-envelope me-2 mt-1"></i>
                                    <span id="modalEmailleft"></span>
                                </p>
                                <p class="mb-1 text-white d-flex align-items-start" style="font-size: 0.9rem; text-align: justify;">
                                    <i class="fa fa-phone me-2 mt-1"></i>
                                    <span id="modalMobileleft"></span>
                                </p>
                                <p class="mb-3 text-white d-flex align-items-start" style="font-size: 0.9rem; text-align: justify;">
                                    <i class="fa fa-map-marker me-2 mt-1"></i>
                                    <span id="modalAddressleft"></span>
                                </p>
                            </div>

                                                                <!-- Buttons -->
                                                                <div class="mt-auto d-flex justify-content-center gap-2"<?php echo $canEdit ? '' : ' hidden'; ?>>
                                                                    <button type="button" class="btn btn-light btn-sm me-2" onclick="editOfficial(currentOfficialId)">
                                                                        <i class="fa fa-pencil"></i> Edit
                                                                    </button>
                                                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(currentOfficialId)">    
                                                                        <i class="fa fa-trash text-white"></i> Delete
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-8">

                                                            <!-- Personal Info -->
                                                            <h5 class="border-bottom pb-1 mb-2" style="color: #28a745;">Personal Information</h5>
                                                            <div class="row text-dark">
                                                                <div class="col-md-6 mb-2"><strong>Year Term Start:</strong> <span id="modalYearTermStart"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Year Term End:</strong> <span id="modalYearTermEnd"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Surname:</strong> <span id="modalSurname"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Firstname:</strong> <span id="modalFirstname"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Middlename:</strong> <span id="modalMiddlename"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Birthday:</strong> <span id="modalBirthday"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Birthplace:</strong> <span id="modalBirthplace"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Address:</strong> <span id="modalAddress"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Mobile Number:</strong> <span id="modalMobile"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Email:</strong> <span id="modalEmail"></span></div>
                                                                <div class="col-md-6 mb-2"><strong>Gender:</strong> <span id="modalGender"></span></div>
                                                            </div>

                    <!-- Education -->
                    <h5 class="border-bottom pb-1 mt-4 mb-2" style="color: #28a745;">Highest Educational Attaintment</h5>
                    <div class="row text-dark">
                        <div class="col-md-6 mb-2"><strong>Degree/Level:</strong> <span id="modalEduAttain"></span></div>
                        <div class="col-md-6 mb-2"><strong>School:</strong> <span id="modalEduSchool"></span></div>
                        <div class="col-md-6 mb-2"><strong>Year Graduated:</strong> <span id="modalEduDate"></span></div>
                    </div>

                    <!-- Family -->
                    <h5 class="border-bottom pb-1 mt-4 mb-2" style="color: #28a745;">Family Information</h5>
                    <div class="row text-dark">
                        <div class="col-md-6 mb-2"><strong>Civil Status:</strong> <span id="modalCivilStatus"></span></div>
                        <div class="col-md-6 mb-2"><strong>Spouse Name:</strong> <span id="modalSpouseName"></span></div>
                        <div class="col-md-6 mb-2"><strong>Spouse Birthday:</strong> <span id="modalSpouseBday"></span></div>
                        <div class="col-md-6 mb-2"><strong>Spouse Birthplace:</strong> <span id="modalSpouseBirthplace"></span></div>
                        <div class="col-md-6 mb-2"><strong>Dependents:</strong> <span id="modalDependents"></span></div>
                    </div>

                    <!-- Government Info -->
                    <h5 class="border-bottom pb-1 mt-4 mb-2" style="color: #28a745;">Government Details</h5>
                    <div class="row text-dark">
                        <div class="col-md-4 mb-2"><strong>GSIS No:</strong> <span id="modalGSIS"></span></div>
                        <div class="col-md-4 mb-2"><strong>PAG-IBIG No:</strong> <span id="modalPAGIBIG"></span></div>
                        <div class="col-md-4 mb-2"><strong>PhilHealth No:</strong> <span id="modalPhilHealth"></span></div>
                    </div>
                    </div>
                </div>
                <div class="modal-footer">
                </div> <!-- end #viewMode -->
            </div>
            </div>
        </div>
    </div>


        <!-- ********EDIT MODAL******** -->
        <div class="modal fade" id="editModeOfficial" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl"> <!-- wider modal -->
                <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-success" id="editModalLabel">Official Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">

                <!-- Edit Mode -->
                <div id="editMode">
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div style="background-color: #098209; color: white; padding: 1.5rem; border-radius: 0.5rem; height: 100%; display: flex; flex-direction: column; align-items: center;">

                                <!-- Image with top margin -->
                                <img id="modalImageEdit" src="" class="img-fluid rounded shadow mb-3" style="max-height: 250px; margin-top: 2rem;">
                                <div class="mb-2">
                                    <label for="edit_image" class="form-label small" style="font-weight: normal;">Update Image:</label>
                                    <input type="file" class="form-control form-control-sm border" id="edit_image" name="edit_image" accept="image/*" style="border: 1px solid #ced4da; border-radius: 4px;">
                                </div>

                                <input type="hidden" id="edit_official_id" name="official_id" />
                                
                                <!-- Full Name and Position -->
                                <h4 id="modalNameEdit" class="mb-1 text-white text-center"></h4>
                                <p id="modalPositionEdit" class="mb-3 text-white text-center"></p>

                                <!-- Contact Information -->
                                <div class="d-flex flex-column align-items-center px-3 mt-4">
                                    <p class="mb-1 text-white d-flex align-items-start" style="font-size: 0.9rem; text-align: justify;">
                                        <i class="fa fa-envelope me-2 mt-1"></i>
                                        <span id="modalEmailleftEdit"></span>
                                    </p>
                                    <p class="mb-1 text-white d-flex align-items-start" style="font-size: 0.9rem; text-align: justify;">
                                        <i class="fa fa-phone me-2 mt-1"></i>
                                        <span id="modalMobileleftEdit"></span>
                                    </p>
                                    <p class="mb-3 text-white d-flex align-items-start" style="font-size: 0.9rem; text-align: justify;">
                                        <i class="fa fa-map-marker me-2 mt-1"></i>
                                        <span id="modalAddressleftEdit"></span>
                                    </p>
                                </div>

                                  <!-- <button id="saveBtn" type="submit" class="btn btn-primary">Save</button>
                                  <button id="cancelBtn" type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button> -->

                                <div class="d-flex gap-2 mt-3">
                                    <button id="saveBtn" type="submit" class="btn btn-primary btn-lg">
                                        <i class="fa fa-save me-1"></i> Save
                                    </button>
                                    <button id="cancelBtn" type="button" class="btn btn-secondary btn-lg">
                                        <i class="fa fa-close me-1"></i> Cancel
                                    </button>
                                </div>
                            </div>
                        </div>

                        
                        <div class="col-md-8">
                        <!-- Personal Info -->
                        <h5 class="border-bottom pb-1 mb-2" style="color: #28a745;">Personal Information</h5>
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" style="color: #000000">Position:</label>
                           <div class="col-sm-9">
                                <select class="form-control" id="edit_position" name="position" required>
                                    <option value="" disabled selected>Select...</option>
                                    <?php foreach ($orgPositions as $p) { ?>
                                        <option value="<?php echo $e($p); ?>"><?php echo $e($p); ?></option>
                                    <?php } ?>
                                    <option value="__other">Other (type a position)…</option>
                                </select>
                                <input type="text" class="form-control mt-2" id="edit_position_other" maxlength="100" placeholder="e.g. SB Secretary" hidden>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label" style="color: #000000" for="edit_group_id">Chart group:</label>
                            <div class="col-sm-9">
                                <select class="form-control" id="edit_group_id">
                                    <?php foreach ($orgGroups as $g) { ?>
                                        <option value="<?php echo (int) $g['id']; ?>"><?php echo $e($g['title']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="row text-dark">
                                <div class="col-md-6 mb-2">
                                    <label for="edit_yeartermstart" class="form-label">Year Term Start:</label>
                                    <input type="date" class="form-control" id="edit_yeartermstart" name="yeartermstart" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_yeartermend" class="form-label">Year Term End:</label>
                                    <input type="date" class="form-control" id="edit_yeartermend" name="yeartermend" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_surname" class="form-label">Surname:</label>
                                    <input type="text" class="form-control" id="edit_surname" name="surname">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_firstname" class="form-label">First Name:</label>
                                    <input type="text" class="form-control" id="edit_firstname" name="firstname">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_middlename" class="form-label">Middle Name:</label>
                                    <input type="text" class="form-control" id="edit_middlename" name="middlename">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_birthday" class="form-label">Birthday:</label>
                                    <input type="date" class="form-control" id="edit_birthday" name="birthday">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_birthplace" class="form-label">Birthplace:</label>
                                    <input type="text" class="form-control" id="edit_birthplace" name="birthplace">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_address" class="form-label">Address:</label>
                                    <input type="text" class="form-control" id="edit_address" name="address">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_contact" class="form-label">Mobile Number:</label>
                                    <input type="text" class="form-control" id="edit_contact" name="contact">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_email" class="form-label">Email:</label>
                                    <input type="email" class="form-control" id="edit_email" name="email">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_gender" class="form-label">Gender:</label>
                                    <input type="text" class="form-control" id="edit_gender" name="gender">
                                </div>          
                        </div>

                        <!-- Education -->
                        <h5 class="border-bottom pb-1 mt-4 mb-2" style="color: #28a745;">Highest Educational Attaintment</h5>
                        <div class="text-dark">
                            <div class="row text-dark">
                                <div class="col-md-6 mb-2">
                                    <label for="edit_education_attainment" class="form-label"><strong>Degree/Level:</strong></label>
                                    <input type="text" class="form-control" id="edit_education_attainment" name="education_attainment">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_education_school" class="form-label"><strong>School:</strong></label>
                                    <input type="text" class="form-control" id="edit_education_school" name="education_school">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edit_education_date" class="form-label"><strong>Year Graduated:</strong></label>
                                    <input type="date" class="form-control" id="edit_education_date" name="education_date">
                                </div>
                            </div>

                        </div>

                        <!-- Family -->
                        <h5 class="border-bottom pb-1 mt-4 mb-2" style="color: #28a745;">Family Information</h5>
                        <div class="row text-dark">
                            <div class="col-md-6 mb-2">
                                <label for="edit_civil_status" class="form-label"><strong>Civil Status:</strong></label>
                                <select class="form-control" id="edit_civil_status" name="civil_status">
                                    <option value="">Choose...</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Divorced">Divorced</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-2">
                                <label for="edit_spouse_name" class="form-label"><strong>Spouse Name:</strong></label>
                                <input type="text" class="form-control" id="edit_spouse_name" name="spouse_name">
                            </div>

                            <div class="col-md-6 mb-2">
                                <label for="edit_spouse_birthday" class="form-label"><strong>Spouse Birthday:</strong></label>
                                <input type="date" class="form-control" id="edit_spouse_birthday" name="spouse_birthday">
                            </div>

                            <div class="col-md-6 mb-2">
                                <label for="edit_spouse_birthplace" class="form-label"><strong>Spouse Birthplace:</strong></label>
                                <input type="text" class="form-control" id="edit_spouse_birthplace" name="spouse_birthplace">
                            </div>

                            <div class="col-md-6 mb-2">
                                <label for="edit_dependents" class="form-label"><strong>Dependents:</strong></label>
                                <input type="text" class="form-control" id="edit_dependents" name="dependents">
                            </div>
                        </div>

                        <!-- Government Info -->
                        <h5 class="border-bottom pb-1 mt-4 mb-2" style="color: #28a745;">Government Details</h5>
                        <div class="row text-dark">
                            <div class="col-md-4 mb-2">
                                <label for="edit_gsis" class="form-label"><strong>GSIS No:</strong></label>
                                <input type="text" class="form-control" id="edit_gsis" name="gsis_number" placeholder="Enter GSIS Number">
                            </div>
                            <div class="col-md-4 mb-2">
                                <label for="edit_pagibig" class="form-label"><strong>PAG-IBIG No:</strong></label>
                                <input type="text" class="form-control" id="edit_pagibig" name="pagibig_number" placeholder="Enter PAG-IBIG Number">
                            </div>
                            <div class="col-md-4 mb-2">
                                <label for="edit_philhealth" class="form-label"><strong>PhilHealth No:</strong></label>
                                <input type="text" class="form-control" id="edit_philhealth" name="philhealth_number" placeholder="Enter PhilHealth Number">
                            </div>
                        </div>
                        </div>
                    </div>
                    <!-- <div class="modal-footer">
                        <button class="btn btn-warning" onclick="toggleEdit()">Toggle Edit</button>
                    </div> -->
                    <div class="modal-footer">
                    </div> <!-- end #editMode -->
                </div>
                </div>
            </div>
        </div>


    <script>
        let currentOfficialId = null;

        // ********VIEW FUNCTION********
        function openModal(data, isEdit = false) {
            currentOfficialId = data.id;
             
        // Compose full name with optional middlename
        const fullName = `${data.firstname} ${data.middlename ? data.middlename + ' ' : ''}${data.surname}`.trim();

        // Set the full name into one element
        document.getElementById('modalName').textContent = fullName;

        
        // Dynamically handle image path
        let imagePath = 'uploads/default.png';  // fallback image
        if (data.image && data.image.trim() !== '') {
            imagePath = 'uploads/' + data.image;  // assuming 'image' is filename only
        } else if (data.photo_path && data.photo_path.trim() !== '') {
            imagePath = data.photo_path;  // if this is a full or relative path already
        }

        document.getElementById('modalImage').src = imagePath;

        // Set other fields as before
        document.getElementById('modalImage').src = data.photo_path;
        document.getElementById('modalPosition').textContent = data.position;
        // If you want to keep separate name fields, you can set them too:
        document.getElementById('modalYearTermStart').innerText = data.term_start;
        document.getElementById('modalYearTermEnd').innerText = data.term_end;
        document.getElementById('modalSurname').textContent = data.surname;
        document.getElementById('modalFirstname').textContent = data.firstname;
        document.getElementById('modalMiddlename').textContent = data.middlename;

        document.getElementById('modalAddressleft').textContent = data.address;
        document.getElementById('modalMobileleft').textContent = data.mobile_number;
        document.getElementById('modalEmailleft').textContent = data.email;

        document.getElementById('modalBirthday').textContent = data.birthday;
        document.getElementById('modalBirthplace').textContent = data.birthplace;
        document.getElementById('modalAddress').textContent = data.address;
        document.getElementById('modalMobile').textContent = data.mobile_number;
        document.getElementById('modalEmail').textContent = data.email;
        document.getElementById('modalGender').textContent = data.gender;
        document.getElementById('modalEduAttain').textContent = data.education_attainment;
        document.getElementById('modalEduSchool').textContent = data.education_school;
        document.getElementById('modalEduDate').textContent = data.education_date;
        document.getElementById('modalCivilStatus').textContent = data.civil_status;
        document.getElementById('modalSpouseName').textContent = data.spouse_name;
        document.getElementById('modalSpouseBday').textContent = data.spouse_birthday;
        document.getElementById('modalSpouseBirthplace').textContent = data.spouse_birthplace;
        document.getElementById('modalDependents').textContent = data.dependents;
        document.getElementById('modalGSIS').textContent = data.gsis_number;
        document.getElementById('modalPAGIBIG').textContent = data.pagibig_number;
        document.getElementById('modalPhilHealth').textContent = data.philhealth_number;

        document.getElementById('viewMode').style.display = 'block';
        document.getElementById('editMode').style.display = 'none';

        new bootstrap.Modal(document.getElementById('officialModal')).show();

        const editBtn = document.getElementById('editBtn');
            if (editBtn) {
                editBtn.setAttribute('data-official-id', data.id);
            }

        if (isEdit) {
        setTimeout(function () {
            editOfficial(data.id);
        }, 300); // Wait a bit for modal to fully render before switching modes
    }
    }


    // ********EDIT FUNCTION********
    // Position: one of the known positions, or "Other" with a typed one.
    function setEditPosition(value) {
        const sel = document.getElementById('edit_position');
        const other = document.getElementById('edit_position_other');
        const known = Array.from(sel.options).some(o => o.value === value && o.value !== '__other');
        sel.value = !value ? '' : (known ? value : '__other');
        other.value = known ? '' : (value || '');
        other.hidden = sel.value !== '__other';
    }
    function getEditPosition() {
        const sel = document.getElementById('edit_position');
        return sel.value === '__other' ? document.getElementById('edit_position_other').value.trim() : sel.value;
    }
    document.addEventListener('DOMContentLoaded', function () {
        const sel = document.getElementById('edit_position');
        const other = document.getElementById('edit_position_other');
        sel.addEventListener('change', function () {
            other.hidden = sel.value !== '__other';
            if (!other.hidden) { other.focus(); }
        });
    });

    function setInputValue(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.value = value || '';
        }
    }
        function editOfficial(id) {
        fetch('getOfficialsData.php?id=' + id)
            .then(response => response.json())
            .then(official => {
            if (!official || Object.keys(official).length === 0) {
                alert('Official not found.');
                return;
            }

            // setInputValue('edit_official_id', official.id);
            setEditPosition(official.position);
            if (official.group_id) { setInputValue('edit_group_id', official.group_id); }
            setInputValue('edit_yeartermstart', official.term_start);
            setInputValue('edit_yeartermend', official.term_end);
            setInputValue('edit_surname', official.surname);
            setInputValue('edit_firstname', official.firstname);
            setInputValue('edit_middlename', official.middlename);
            setInputValue('edit_birthday', official.birthday);
            setInputValue('edit_birthplace', official.birthplace);
            setInputValue('edit_address', official.address);
            setInputValue('edit_contact', official.mobile_number);
            setInputValue('edit_email', official.email);
            setInputValue('edit_gender', official.gender);

            setInputValue('edit_education_attainment', official.education_attainment);
            setInputValue('edit_education_school', official.education_school);
            setInputValue('edit_education_date', official.education_date);

            setInputValue('edit_civil_status', official.civil_status);
            setInputValue('edit_spouse_name', official.spouse_name);
            setInputValue('edit_spouse_birthday', official.spouse_birthday);
            setInputValue('edit_spouse_birthplace', official.spouse_birthplace);
            setInputValue('edit_dependents', official.dependents);

            setInputValue('edit_gsis', official.gsis_number);
            setInputValue('edit_pagibig', official.pagibig_number);
            setInputValue('edit_philhealth', official.philhealth_number);

           // Dynamic Image Handling using photo_path
            const imagePath = official.photo_path && official.photo_path.trim() !== ''
                ? official.photo_path
                : 'uploads/default.png';

            document.getElementById('edit_official_id').value = official.id;
            document.getElementById('modalImageEdit').src = imagePath;
            document.getElementById('modalNameEdit').textContent = `${official.firstname || ''} ${official.middlename || ''} ${official.surname || ''}`.trim();
            document.getElementById('modalPositionEdit').textContent = official.position || 'N/A';
            document.getElementById('modalEmailleftEdit').textContent = official.email || 'N/A';
            document.getElementById('modalMobileleftEdit').textContent = official.mobile_number || 'N/A';
            document.getElementById('modalAddressleftEdit').textContent = official.address || 'N/A';


            // Show edit mode, hide view mode
            const viewModeEl = document.getElementById('viewMode');
            const editModeEl = document.getElementById('editMode');

            if (viewModeEl) viewModeEl.style.display = 'none';
            if (editModeEl) editModeEl.style.display = 'block';

            // Show the modal (assuming modal container id is 'officialModal')
            const modalEl = document.getElementById('editModeOfficial');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
            })
            .catch(error => {
            console.error('Error fetching official data:', error);
            alert('Error fetching official data.');
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const editBtn = document.getElementById("editBtn");
            if (editBtn) {
                editBtn.addEventListener("click", function () {
                    const id = this.getAttribute('data-official-id');
                    if (!id) {
                        alert('No official ID found!');
                        return;
                    }
                    editofficial(id);
                });
            }

            const imageInput = document.getElementById('edit_image');
            if (imageInput) {
                imageInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            document.getElementById('modalImageEdit').src = e.target.result;
                        }
                        reader.readAsDataURL(file);
                    }
                });
            }

            const saveBtn = document.getElementById('saveBtn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function (e) {
                    e.preventDefault();

                    const formData = new FormData();
                    formData.append('id', document.getElementById('edit_official_id').value);
                    const position = getEditPosition();
                    if (!position) {
                        Swal.fire({ icon: 'warning', title: 'Position required', text: 'Choose or type a position.' });
                        return;
                    }
                    formData.append('position', position);
                    formData.append('group_id', document.getElementById('edit_group_id').value);
                    formData.append('term_start', document.getElementById('edit_yeartermstart').value);
                    formData.append('term_end', document.getElementById('edit_yeartermend').value);
                    formData.append('surname', document.getElementById('edit_surname').value);
                    formData.append('firstname', document.getElementById('edit_firstname').value);
                    formData.append('middlename', document.getElementById('edit_middlename').value);
                    formData.append('birthday', document.getElementById('edit_birthday').value);
                    formData.append('birthplace', document.getElementById('edit_birthplace').value);
                    formData.append('address', document.getElementById('edit_address').value);
                    formData.append('contact', document.getElementById('edit_contact').value);
                    formData.append('email', document.getElementById('edit_email').value);
                    formData.append('gender', document.getElementById('edit_gender').value);
                    formData.append('education_attainment', document.getElementById('edit_education_attainment').value);
                    formData.append('education_school', document.getElementById('edit_education_school').value);
                    formData.append('education_date', document.getElementById('edit_education_date').value);
                    formData.append('civil_status', document.getElementById('edit_civil_status').value);
                    formData.append('spouse_name', document.getElementById('edit_spouse_name').value);
                    formData.append('spouse_birthday', document.getElementById('edit_spouse_birthday').value);
                    formData.append('spouse_birthplace', document.getElementById('edit_spouse_birthplace').value);
                    formData.append('dependents', document.getElementById('edit_dependents').value);
                    formData.append('gsis_number', document.getElementById('edit_gsis').value);
                    formData.append('pagibig_number', document.getElementById('edit_pagibig').value);
                    formData.append('philhealth_number', document.getElementById('edit_philhealth').value);

                    const imageFile = document.getElementById('edit_image').files[0];
                    if (imageFile) {
                        formData.append('image', imageFile);
                    }

                    fetch('editofficial.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Official Updated',
                                text: 'The official has been successfully updated.',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Update Failed',
                                text: response.message || 'An unexpected error occurred.',
                                confirmButtonText: 'OK'
                            });
                        }
                    })
                });
            }
        });


        // CANCEL BUTTON TO EDIT
        document.getElementById('cancelBtn').addEventListener('click', function () {
            const modal = bootstrap.Modal.getInstance(document.getElementById('editModeOfficial'));
            modal.hide();
        });
    
    // function toggleEdit() {
    //     const view = document.getElementById('viewMode');
    //     const edit = document.getElementById('editMode');
    //     if (edit.style.display === 'none') {
    //         view.style.display = 'none';
    //         edit.style.display = 'block';
    //     } else {
    //         edit.style.display = 'none';
    //         view.style.display = 'block';
    //     }
    // }

        function confirmDelete(id) {
            // Close the Bootstrap modal first
            const modalElement = document.getElementById('officialModal');
            const bsModal = bootstrap.Modal.getInstance(modalElement);
            if (bsModal) {
                bsModal.hide();
            }

            // Then show SweetAlert password input
            Swal.fire({
                title: "Enter Password",
                input: "password",
                inputAttributes: {
                    autocapitalize: "off",
                    required: true
                },
                showCancelButton: true,
                confirmButtonText: "Submit",
                showLoaderOnConfirm: true,
                preConfirm: (password) => {
                    return fetch("validate_password.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded"
                        },
                        body: "password=" + encodeURIComponent(password)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || "Incorrect password.");
                        }
                    })
                    .catch(error => {
                        Swal.showValidationMessage(error.message);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: "Are you sure?",
                        text: "You won't be able to revert this!",
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#3085d6",
                        cancelButtonColor: "#d33",
                        confirmButtonText: "Confirm!"
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Delete via AJAX
                            fetch("deleteOfficial.php?id=" + id)
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        Swal.fire({
                                            title: "Deleted!",
                                            text: data.message,
                                            icon: "success",
                                            showConfirmButton: false,
                                            timer: 2000
                                        }).then(() => {
                                            location.reload(); // reload to reflect changes
                                        });
                                    } else {
                                        Swal.fire("Error", data.message, "error");
                                    }
                                });
                        }
                    });
                }
            });
        }

        // function confirmEdit(id) {
        //     Swal.fire({
        //         title: "Enter Password",
        //         input: "password",
        //         inputAttributes: {
        //             autocapitalize: "off",
        //             required: true
        //         },
        //         showCancelButton: true,
        //         confirmButtonText: "Submit",
        //         showLoaderOnConfirm: true,
        //         preConfirm: (password) => {
        //             return fetch("validate_password.php", {
        //                 method: "POST",
        //                 headers: {
        //                     "Content-Type": "application/x-www-form-urlencoded"
        //                 },
        //                 body: "password=" + encodeURIComponent(password)
        //             })
        //             .then(response => response.json())
        //             .then(data => {
        //                 if (!data.success) {
        //                     throw new Error(data.message || "Incorrect password.");
        //                 }
        //             })
        //             .catch(error => {
        //                 Swal.showValidationMessage(error.message);
        //             });
        //         },
        //         allowOutsideClick: () => !Swal.isLoading()
        //     }).then((result) => {
        //         if (result.isConfirmed) {
        //             window.location.href = 'editofficial.php?id=' + id;
        //         }
        //     });
        //}
</script>

<?php if ($canEdit) { ?>
        <!-- ********QUICK ADD (the "+" slot on the chart)******** -->
        <div class="modal fade" id="orgQuickAdd" tabindex="-1" aria-labelledby="orgQuickAddLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title" id="orgQuickAddLabel">Add an official to the chart</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label class="d-block text-center mb-3" style="cursor: pointer;">
                            <span class="dms-org-qa-photo" data-qa-preview><i class="ti-camera"></i></span>
                            <span class="small text-muted">Photo (JPG, PNG, GIF or WebP) &ndash; optional</span>
                            <input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp" class="d-none">
                        </label>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label" for="qa_firstname">First name</label>
                                <input type="text" class="form-control" id="qa_firstname" name="firstname" maxlength="100" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="qa_middlename">Middle name</label>
                                <input type="text" class="form-control" id="qa_middlename" name="middlename" maxlength="100">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="qa_surname">Surname</label>
                                <input type="text" class="form-control" id="qa_surname" name="surname" maxlength="100" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="qa_position">Position</label>
                                <input type="text" class="form-control" id="qa_position" name="position" maxlength="100" list="orgPositionList" placeholder="e.g. Councilor" required>
                                <datalist id="orgPositionList">
                                    <?php foreach ($orgPositions as $p) { ?><option value="<?php echo $e($p); ?>"></option><?php } ?>
                                </datalist>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="qa_group">Chart group</label>
                                <select class="form-control" id="qa_group" name="group_id" required>
                                    <?php foreach ($orgGroups as $g) { ?>
                                        <option value="<?php echo (int) $g['id']; ?>"><?php echo $e($g['title']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="qa_term_start">Term start</label>
                                <input type="date" class="form-control" id="qa_term_start" name="term_start" data-qa-default="<?php echo $e($orgTerm['start']); ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="qa_term_end">Term end</label>
                                <input type="date" class="form-control" id="qa_term_end" name="term_end" data-qa-default="<?php echo $e($orgTerm['end']); ?>">
                            </div>
                        </div>
                        <p class="small text-muted mt-3 mb-0">Only the basics are needed here. Click the official on the chart later to fill in the full personal details.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="ti-plus"></i>&nbsp; Add to chart</button>
                    </div>
                </form>
            </div>
        </div>
<?php } ?>


    <!-- Required vendors -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="./css/dms-theme.css?v=<?php echo filemtime(__DIR__ . '/css/dms-theme.css'); ?>" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="./js/org-chart.js?v=<?php echo filemtime(__DIR__ . '/js/org-chart.js'); ?>"></script>


    
