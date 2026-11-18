<?php
    if(isset($_POST['save_account'])){
        include "connect.php";
        error_reporting(0);

        $email = $_POST['email'];
        $password = $_POST['password'];
        $confirm_pasword = $_POST['confirm_password'];
        $role = $_POST['role'];
        $account_status = 'active';

        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Insert into database
        $sql = "INSERT INTO accounts (email, password, role, account_status) 
                VALUES (?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $email, $hashed_password, $role, $account_status);
        $stmt->execute();

        if ($stmt) {
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Account Created',
                            text: 'The account have been successfully created.',
                            confirmButtonText: 'OK'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                DmsNav.returnTo('manageAccounts.php');
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
                            text: 'There was an error creating the account.',
                            confirmButtonText: 'OK'
                        });
                    });
                  </script>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;    
        }

        $stmt->close();
        $conn->close();
    }
?>

<?php
    if (isset($_POST['update_account'])) {
        include "connect.php";

        // Sanitize and get inputs
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $account_status = isset($_POST['account_status']) && $_POST['account_status'] === 'active' ? 'active' : 'inactive';
        $role = isset($_POST['role']) ? mysqli_real_escape_string($conn, $_POST['role']) : '';

        
        // Update the record
        $sql = "UPDATE accounts SET account_status = ?, role = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $account_status, $role, $id);
        $stmt->execute();

        if ($stmt) {
            echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            icon: 'success',
                            title: 'Account Updated',
                            text: 'The account have been successfully updated.',
                            confirmButtonText: 'OK'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                DmsNav.returnTo('manageAccounts.php');
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
                            text: 'There was an error updating the account.',
                            confirmButtonText: 'OK'
                        });
                    });
                </script>";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
        }

        $stmt->close();
        $conn->close();
    }
?>


<!DOCTYPE html>
<html lang="en">
    
    <?php include('header.php'); ?>

<body>

    <!--**********************************
        Main wrapper start
    ***********************************-->
    <div id="main-wrapper">

        <?php include('sidebar.php'); ?>

        <!--**********************************
            Content body start
        ***********************************-->
        <div class="content-body">
            <!-- row -->
            <div class="container-fluid">
                <div class="dms-page-head">
                    <div>
                        <h1>Manage Accounts</h1>
                        <?php
                            require_once __DIR__ . '/includes/password_reset.php';
                            dms_pw_reset_ensure_schema($conn);

                            $pendingCount = 0;
                            $resetCount = 0;
                            if ($role === 'master') {
                                $pendingRes = $conn->query("SELECT COUNT(*) AS c FROM accounts WHERE account_status = 'pending'");
                                if ($pendingRes) {
                                    $pendingCount = (int) $pendingRes->fetch_assoc()['c'];
                                }
                                $resetRes = $conn->query("SELECT COUNT(*) AS c FROM accounts WHERE reset_password IS NOT NULL AND role <> 'master'");
                                if ($resetRes) {
                                    $resetCount = (int) $resetRes->fetch_assoc()['c'];
                                }
                            }
                        ?>
                        <p>User accounts, their roles and whether they can sign in.<?php if ($pendingCount > 0) { ?>
                            &middot; <strong class="text-warning"><?php echo $pendingCount; ?> registration<?php echo $pendingCount === 1 ? '' : 's'; ?> awaiting approval</strong>
                        <?php } ?><?php if ($resetCount > 0) { ?>
                            &middot; <strong class="text-info"><?php echo $resetCount; ?> password reset request<?php echo $resetCount === 1 ? '' : 's'; ?></strong>
                        <?php } ?></p>
                    </div>
                    <!-- Button trigger modal -->
                    <div class="button-container dms-page-actions">
                        <a href="#">
                            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addAccountModal"><i class="fa fa-plus"></i>&nbsp;Add New Account</button>
                        </a>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <?php if ($role === 'master') { ?>
                                    <p class="dms-ob-help"><i class="fa fa-mouse-pointer" aria-hidden="true"></i>Right-click a pending or inactive account to Activate or Reject it, or an account with a password reset request to approve or reject the new password.</p>
                                <?php } ?>
                                <div class="table-responsive">
                                    <table id="example" class="display dms-stack" style="width: 100%;">
                                        <colgroup>
                                            <col style="width: 35%;">
                                            <col style="width: 25%;">
                                            <col style="width: 15%;">
                                            <col style="width: 15%;">
                                        </colgroup>
                                        <thead class="text-center">
                                            <tr>
                                                <th>Username</th>
                                                <th>Role</th>
                                                <th>Account Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-left" >
                                            <?php
                                                $sql = "SELECT id, full_name, email, role, account_status, reset_requested_at,
                                                               (reset_password IS NOT NULL) AS has_reset FROM accounts";
                                                $stmt = $conn->prepare($sql);
                                                $stmt->execute();
                                                $result = $stmt->get_result();

                                                if (!$result) {
                                                    die("SQL Error: " . $conn->error);
                                                }

                                                while ($row = mysqli_fetch_assoc($result)) {
                                                    // Right-click menu (Activate / Reject): master only, for pending or inactive non-master accounts
                                                    // ...and for accounts with a forgot-password request (Approve / Reject reset)
                                                    $statusMenu = $role === 'master' && $row['role'] !== 'master'
                                                        && in_array($row['account_status'], ['pending', 'inactive'], true);
                                                    $hasReset = $role === 'master' && $row['role'] !== 'master' && (int) $row['has_reset'] === 1;
                                                    $menuable = $statusMenu || $hasReset;
                                                    $rowName = !empty($row['full_name']) ? $row['full_name'] : $row['email'];
                                                    ?>
                                                    <tr<?php if ($menuable) { ?> class="dms-acct-row" tabindex="0"
                                                        data-account-id="<?php echo (int) $row['id']; ?>"
                                                        data-account-name="<?php echo htmlspecialchars($rowName, ENT_QUOTES); ?>"
                                                        data-account-status="<?php echo htmlspecialchars($row['account_status'], ENT_QUOTES); ?>"
                                                        data-status-menu="<?php echo $statusMenu ? '1' : '0'; ?>"
                                                        data-reset="<?php echo $hasReset ? '1' : '0'; ?>"
                                                        title="Right-click for account actions"<?php } ?>>
                                                        <td style="pointer-events: none;">
                                                            <span style="text-transform: uppercase;"><?php echo htmlspecialchars($row["email"]); ?></span>
                                                            <?php if (!empty($row["full_name"])) { ?>
                                                                <br><small class="text-muted"><?php echo htmlspecialchars($row["full_name"]); ?></small>
                                                            <?php } ?>
                                                        </td>
                                                        <td style="pointer-events: none; text-align: center; text-transform: uppercase;">
                                                            <?php 
                                                                switch ($row["role"]) {
                                                                    case "master":
                                                                        echo "Sangguniang Bayan Secretary";
                                                                        break;
                                                                    case "admin":
                                                                        echo "Sangguniang Bayan Staff";
                                                                        break;
                                                                    case "user":
                                                                        echo "Sangguniang Bayan Council";
                                                                        break;
                                                                    default:
                                                                        echo strtoupper($row["role"]); // fallback display
                                                                }
                                                            ?>
                                                        </td>
                                                        <td style="pointer-events: none;" class="text-center fs-4">
                                                            <?php 
                                                                switch ($row['account_status']) {
                                                                    case 'active':
                                                                        echo '<span class="badge badge-success">Active</span>';
                                                                        break;
                                                                    case 'inactive':
                                                                        echo '<span class="badge badge-danger">Inactive</span>';
                                                                        break;
                                                                    case 'pending':
                                                                        echo '<span class="badge badge-warning">Pending Approval</span>';
                                                                        break;
                                                                    default:
                                                                        echo $row['account_status']; // fallback if action is not recognized
                                                                }
                                                                if ($hasReset) {
                                                                    $resetWhen = !empty($row['reset_requested_at']) ? date('M j, Y g:i A', strtotime($row['reset_requested_at'])) : '';
                                                                    echo '<br><span class="badge badge-info mt-1">Password Reset Requested</span>';
                                                                    if ($resetWhen !== '') {
                                                                        echo '<br><small class="text-muted">' . htmlspecialchars($resetWhen) . '</small>';
                                                                    }
                                                                }
                                                            ?>
                                                        </td>
                                                        <td style="text-align: center; vertical-align: middle;">
                                                            <div class="dms-row-actions">
                                                                <!--Button trigger modal-->
                                                                <?php if ($role === 'admin' || $role === 'master') { ?>
                                                                    <?php if ($row['role'] === 'master') { ?>
                                                                        <!-- Disabled actions with SweetAlert -->
                                                                        <a onclick="showProtectedAlert()" class="btn btn-success btn-sm d-flex align-items-center justify-content-center p-2 ml-1 mr-1">
                                                                            <i class="fa fa-edit" aria-hidden="true"></i>
                                                                        </a>
                                                                        <a onclick="showProtectedAlert()" class="btn btn-danger btn-sm d-flex align-items-center justify-content-center p-2">
                                                                            <i class="fa fa-trash" aria-hidden="true"></i>
                                                                        </a>
                                                                    <?php } else { ?>
                                                                        <?php if ($role === 'master' && $row['account_status'] === 'pending') {
                                                                            $approvalName = !empty($row['full_name']) ? $row['full_name'] : $row['email'];
                                                                            $approvalArgs = htmlspecialchars((int) $row['id'] . ', ' . json_encode($approvalName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                                                        ?>
                                                                            <a onclick="approveAccount(<?php echo $approvalArgs; ?>)" title="Approve" class="btn btn-primary btn-sm d-flex align-items-center justify-content-center p-2 ml-1">
                                                                                <i class="fa fa-check" aria-hidden="true"></i>
                                                                            </a>
                                                                            <a onclick="rejectAccount(<?php echo $approvalArgs; ?>)" title="Reject" class="btn btn-warning btn-sm d-flex align-items-center justify-content-center p-2 ml-1">
                                                                                <i class="fa fa-times" aria-hidden="true"></i>
                                                                            </a>
                                                                        <?php } ?>
                                                                        <?php if ($hasReset) {
                                                                            $resetArgs = htmlspecialchars((int) $row['id'] . ', ' . json_encode($rowName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                                                                        ?>
                                                                            <a onclick="approveReset(<?php echo $resetArgs; ?>)" title="Approve password reset" class="btn btn-info btn-sm d-flex align-items-center justify-content-center p-2 ml-1">
                                                                                <i class="fa fa-key" aria-hidden="true"></i>
                                                                            </a>
                                                                            <a onclick="rejectReset(<?php echo $resetArgs; ?>)" title="Reject password reset" class="btn btn-warning btn-sm d-flex align-items-center justify-content-center p-2 ml-1">
                                                                                <i class="fa fa-ban" aria-hidden="true"></i>
                                                                            </a>
                                                                        <?php } ?>
                                                                        <a onclick="confirmEdit(<?php echo $row['id']; ?>)" title="Edit" class="btn btn-success btn-sm d-flex align-items-center justify-content-center p-2 ml-1 mr-1">
                                                                            <i class="fa fa-edit" aria-hidden="true"></i>
                                                                        </a>
                                                                        <a onclick="confirmDelete(<?php echo $row['id']; ?>)" class="btn btn-danger btn-sm d-flex align-items-center justify-content-center p-2">
                                                                            <i class="fa fa-trash" aria-hidden="true"></i>
                                                                        </a>
                                                                    <?php } ?>
                                                                <?php } ?> 
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php
                                                }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!--**********************************
                    MODALS
                ***********************************-->
                <!-- Add Modal -->  
                <div class="modal fade" id="addAccountModal">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Add Account</h5>
                                <button type="button" class="close" data-dismiss="modal"><span>&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="basic-form">
                                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" onsubmit="return validatePasswords();"  method="POST">
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label">Username</label>
                                            <div class="col-sm-8">
                                                <input type="text" class="form-control" name="email" id="email" placeholder="Please type here..." required>
                                                <small id="emailWarning" style="display: none;">Username already exists!</small>
                                            </div>
                                        </div>
                                        <!-- Password Field -->
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label">Password</label>
                                            <div class="col-sm-8">
                                                <div style="position: relative;">
                                                    <input type="password" class="form-control" name="password" id="password" placeholder="Please type here..." required style="padding-right: 35px;">
                                                    <i class="fa fa-eye-slash" id="togglePassword" onclick="toggleVisibility('password', 'togglePassword')" style="position: absolute; top: 50%; right: 10px; transform: translateY(-50%); font-size: 18px; cursor: pointer;"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Confirm Password Field -->
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label">Confirm Password</label>
                                            <div class="col-sm-8">
                                                <div style="position: relative;">
                                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" placeholder="Please type here..." required style="padding-right: 35px;">
                                                    <i class="fa fa-eye-slash" id="toggleConfirmPassword" onclick="toggleVisibility('confirm_password', 'toggleConfirmPassword')" style="position: absolute; top: 50%; right: 10px; transform: translateY(-50%); font-size: 18px; cursor: pointer;"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-group row mb-0">
                                            <label class="col-form-label col-sm-4 pt-0">Role</label>
                                            <div class="col-sm-8 d-flex align-items-center">
                                                <label class="mr-3 mb-0">
                                                    <input type="radio" name="role" value="admin" required> Admin
                                                </label>
                                                <label class="mb-0">
                                                    <input type="radio" name="role" value="user" class="ml-2"> User
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group row justify-content-center mt-4">
                                            <div class="col-sm-12 text-center">
                                                <button type="submit" class="btn btn-primary mr-2" name="save_account">Add Account</button>
                                                <button type="button" class="btn btn-danger" data-dismiss="modal">Cancel</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Edit Modal -->
                <div class="modal fade" id="editAccountModal">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Account</h5>
                                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <div class="basic-form">
                                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                                        <input type="hidden" name="id">
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label">Username</label>
                                            <div class="col-sm-8">
                                                <input type="text" name="email" class="form-control" readonly>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label class="col-sm-4 col-form-label">Account Status</label>
                                            <div class="col-sm-8">
                                                <input type="checkbox" id="account_status_toggle" value="active" data-toggle="toggle" data-on="Active" data-off="Inactive" data-onstyle="success" data-offstyle="danger" data-size="sm" data-width="30%" style="border-radius: 50px; text-align: center;">
                                                <input type="hidden" name="account_status" id="account_status_value">
                                            </div>
                                        </div>
                                        <div class="form-group row mb-0">
                                            <label class="col-form-label col-sm-4 pt-0">Role</label>
                                            <div class="col-sm-8 d-flex align-items-center">
                                                <label class="mr-3 mb-0">
                                                    <input type="radio" name="role" value="admin" required> Admin
                                                </label>
                                                <label class="mb-0">
                                                    <input type="radio" name="role" value="user" class="ml-2"> User
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group row justify-content-center mt-4">
                                            <div class="col-sm-12 text-center">
                                                <button type="submit" class="btn btn-primary mr-2" name="update_account">Update Account</button>
                                                <button type="button" class="btn btn-danger" data-dismiss="modal">Cancel</button>
                                            </div>
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

    <!-- Datatable -->
    <script src="./vendor/datatables/js/jquery.dataTables.min.js"></script>
    <script src="./js/plugins-init/datatables.init.js"></script>
    <!-- Note: jQuery is already provided by global.min.js (with Bootstrap). Loading it again here
         replaced $ and removed $.fn.modal, which made the Edit button do nothing. -->


    <!-- Toggle -->
    <script src="https://cdn.jsdelivr.net/gh/gitbrent/bootstrap4-toggle@3.6.1/js/bootstrap4-toggle.min.js"></script>


    <script>
        $(document).ready(function () {
            $('#email').on('input', function () {
                var email = $(this).val();

                if (email.length > 0) {
                    $.ajax({
                        url: 'check_username.php', // Create this PHP file
                        type: 'POST',
                        data: { email: email },
                        success: function (response) {
                            if (response === 'exists') {
                                $('#emailWarning').show();
                                $('button[name="save_account"]').prop('disabled', true);
                            } else {
                                $('#emailWarning').hide();
                                $('button[name="save_account"]').prop('disabled', false);
                            }
                        }
                    });
                } else {
                    $('#emailWarning').hide();
                    $('button[name="save_account"]').prop('disabled', false);
                }
            });
        });
    </script>


    <script>
        $(document).ready(function() {
            // Function to reset the add account form
            function resetAddAccountForm() {
                $('#addAccountModal form')[0].reset();
            }
            
            // Reset forms when modals are closed (X button or backdrop click)
            $('#addAccountModal').on('hidden.bs.modal', resetAddAccountForm);
            
            // Reset forms when Cancel buttons are clicked
            $('#addAccountModal .btn-danger').click(resetAddAccountForm);
            
        });
    </script>
    
    <script>
        function validatePasswords() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Password Mismatch',
                    text: 'Password and Confirm Password do not match.',
                    confirmButtonText: 'OK'
                });
                return false; // Prevent form submission
            }

            return true; // Allow form submission
        }
        </script>                                            
    
    
    <!--Eye Toggle Script-->
    <script>
        
        function toggleVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        }
    </script>

    <script>
        function confirmDelete(id) {
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
                            Swal.fire({
                                title: "Deleted!",
                                text: "Acount has been deleted.",
                                icon: "success",
                                showConfirmButton: false,
                                timer: 2000
                            });
                            DmsNav.go('deleteaccount.php?id=' + id, { replace: true }); // replace: the list comes back in place of the delete
                        }
                    });
                }
            });
        } 
        
        function confirmEdit(id) {
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
                    // AJAX fetch data here instead of redirecting
                    $.ajax({
                        url: "fetch_account.php",
                        type: "GET",
                        data: { id: id },
                        dataType: "json",
                        success: function(data) {
                            if (data.success) {
                                // Populate modal fields
                                var $edit = $('#editAccountModal');
                                $edit.find('input[name="id"]').val(data.id);
                                $edit.find('input[name="email"]').val(data.email);
                                // Set toggle and hidden field
                                let isActive = data.account_status === 'active';
                                $('#account_status_toggle')
                                    .prop('checked', isActive)
                                    .bootstrapToggle(isActive ? 'on' : 'off');

                                $('#account_status_value').val(isActive ? 'active' : 'inactive');
                                $edit.find('input[name="role"][value="' + data.role + '"]').prop('checked', true);

                                // Show modal
                                $('#editAccountModal').modal('show');
                            } else {
                                Swal.fire("Error", data.message, "error");
                            }
                        },
                        error: function() {
                            Swal.fire("Error", "Failed to fetch account data.", "error");
                        }
                    });
                }
            });
        }

        $('#account_status_toggle').change(function () {
            $('#account_status_value').val(this.checked ? 'active' : 'inactive');
        });

        // Approve / reject self-registered (pending) accounts
        function postApproval(id, action) {
            return fetch("account_approval.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "id=" + encodeURIComponent(id) + "&action=" + encodeURIComponent(action)
            })
            .then(response => response.json().catch(() => ({ success: false, message: "Unexpected server response. Please sign in again." })))
            .then(data => {
                if (data.success) {
                    var titles = {
                        approve: "Account Activated",
                        reject: "Account Rejected",
                        approve_reset: "Password Reset Approved",
                        reject_reset: "Password Reset Rejected"
                    };
                    Swal.fire({
                        icon: "success",
                        title: titles[action] || "Done",
                        text: data.message,
                        showConfirmButton: false,
                        timer: 1800
                    }).then(() => window.location.reload());
                } else {
                    Swal.fire("Error", data.message || "Something went wrong.", "error");
                }
            })
            .catch(() => Swal.fire("Error", "Could not reach the server. Please try again.", "error"));
        }

        function approveAccount(id, name, status) {
            var isInactive = status === "inactive";
            Swal.fire({
                title: isInactive ? "Activate Account?" : "Approve Account?",
                text: (isInactive ? "Activate the account of " : "Approve the registration of ") + name + "? They will be able to sign in.",
                icon: "question",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: isInactive ? "Activate" : "Approve"
            }).then((result) => {
                if (result.isConfirmed) {
                    postApproval(id, "approve");
                }
            });
        }

        function rejectAccount(id, name, status) {
            var isInactive = status === "inactive";
            Swal.fire({
                title: isInactive ? "Reject Account?" : "Reject Registration?",
                text: (isInactive ? "The account of " : "The registration of ") + name + " will be permanently deleted. This cannot be undone.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Reject"
            }).then((result) => {
                if (result.isConfirmed) {
                    postApproval(id, "reject");
                }
            });
        }

        // Forgot-password requests (forgot_password.php)
        function approveReset(id, name) {
            Swal.fire({
                title: "Approve Password Reset?",
                html: "Make sure you have verified that this request really came from <b></b>.<br>" +
                      "Their password will be replaced with the one they requested, and any open session will be signed out.",
                icon: "question",
                showCancelButton: true,
                confirmButtonColor: "#3085d6",
                cancelButtonColor: "#d33",
                confirmButtonText: "Approve",
                didOpen: function (popup) { popup.querySelector(".swal2-html-container b").textContent = name; }
            }).then((result) => {
                if (result.isConfirmed) {
                    postApproval(id, "approve_reset");
                }
            });
        }

        function rejectReset(id, name) {
            Swal.fire({
                title: "Reject Password Reset?",
                text: "The password reset request of " + name + " will be discarded. Their current password stays the same.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Reject"
            }).then((result) => {
                if (result.isConfirmed) {
                    postApproval(id, "reject_reset");
                }
            });
        }

        // Right-click menu on pending/inactive rows (Activate / Reject) and on rows with a
        // password reset request (Approve / Reject reset). Styled by .dms-ob-menu in dms-theme.css
        (function () {
            var table = document.getElementById("example");
            if (!table) { return; }

            var menu = document.createElement("div");
            menu.className = "dms-ob-menu";
            menu.setAttribute("role", "menu");
            menu.hidden = true;
            menu.innerHTML =
                '<div class="dms-ob-menu-head">ACCOUNT <span class="dms-ob-menu-sub" data-menu-name></span></div>' +
                '<button type="button" class="dms-ob-menu-item is-green" role="menuitem" data-op="approve">' +
                    '<i class="fa fa-check" aria-hidden="true"></i><span class="dms-ob-menu-label">Activate</span></button>' +
                '<button type="button" class="dms-ob-menu-item is-red" role="menuitem" data-op="reject">' +
                    '<i class="fa fa-times" aria-hidden="true"></i><span class="dms-ob-menu-label">Reject</span></button>' +
                '<button type="button" class="dms-ob-menu-item is-green" role="menuitem" data-op="approve_reset">' +
                    '<i class="fa fa-key" aria-hidden="true"></i><span class="dms-ob-menu-label">Approve password reset</span></button>' +
                '<button type="button" class="dms-ob-menu-item is-red" role="menuitem" data-op="reject_reset">' +
                    '<i class="fa fa-ban" aria-hidden="true"></i><span class="dms-ob-menu-label">Reject password reset</span></button>';
            document.body.appendChild(menu);
            var menuName = menu.querySelector("[data-menu-name]");
            var menuRow = null;

            function closeMenu() {
                if (menu.hidden) { return; }
                menu.hidden = true;
                if (menuRow) { menuRow.classList.remove("is-menu-open"); }
                menuRow = null;
            }

            function openMenu(tr, x, y) {
                closeMenu();
                menuRow = tr;
                tr.classList.add("is-menu-open");
                menuName.textContent = tr.getAttribute("data-account-name") || "";
                // Only show the actions that apply to this row
                var statusMenu = tr.getAttribute("data-status-menu") === "1";
                var resetMenu = tr.getAttribute("data-reset") === "1";
                menu.querySelectorAll(".dms-ob-menu-item").forEach(function (b) {
                    var isReset = b.getAttribute("data-op").indexOf("_reset") !== -1;
                    b.hidden = isReset ? !resetMenu : !statusMenu;
                });
                menu.hidden = false;
                // Keep the menu inside the visible screen
                var w = menu.offsetWidth, h = menu.offsetHeight;
                menu.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + "px";
                menu.style.top = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + "px";
                menu.querySelector(".dms-ob-menu-item:not([hidden])").focus();
            }

            // Delegated so it keeps working across DataTables paging/sorting
            table.addEventListener("contextmenu", function (e) {
                var tr = e.target.closest ? e.target.closest("tr.dms-acct-row") : null;
                if (!tr) { return; } // other rows keep the browser's own menu
                e.preventDefault();
                if (e.button !== 2) { // ContextMenu key / Shift+F10 on a focused row
                    var r = tr.getBoundingClientRect();
                    openMenu(tr, r.left + 24, r.top + Math.min(r.height, 40));
                } else {
                    openMenu(tr, e.clientX, e.clientY);
                }
            });

            menu.addEventListener("click", function (e) {
                var b = e.target.closest(".dms-ob-menu-item");
                if (!b || !menuRow) { return; }
                var id = menuRow.getAttribute("data-account-id");
                var name = menuRow.getAttribute("data-account-name");
                var status = menuRow.getAttribute("data-account-status");
                closeMenu();
                var op = b.getAttribute("data-op");
                if (op === "approve") {
                    approveAccount(id, name, status);
                } else if (op === "reject") {
                    rejectAccount(id, name, status);
                } else if (op === "approve_reset") {
                    approveReset(id, name);
                } else {
                    rejectReset(id, name);
                }
            });

            menu.addEventListener("keydown", function (e) {
                var items = Array.prototype.slice.call(menu.querySelectorAll(".dms-ob-menu-item:not([hidden])"));
                var i = items.indexOf(document.activeElement);
                if (e.key === "Escape") { e.preventDefault(); var tr = menuRow; closeMenu(); if (tr) { tr.focus(); } }
                else if (e.key === "ArrowDown") { e.preventDefault(); items[(i + 1) % items.length].focus(); }
                else if (e.key === "ArrowUp") { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
            });

            menu.addEventListener("contextmenu", function (e) { e.preventDefault(); });
            document.addEventListener("mousedown", function (e) { if (!menu.contains(e.target)) { closeMenu(); } });
            document.addEventListener("scroll", closeMenu, true);
            window.addEventListener("resize", closeMenu);
            window.addEventListener("blur", closeMenu);
        })();

        function showProtectedAlert() {
            Swal.fire({
                icon: 'warning',
                title: 'Action Not Allowed',
                text: 'This master account cannot be EDITED or DELETED.',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });
        }

    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    

</body>

</html>