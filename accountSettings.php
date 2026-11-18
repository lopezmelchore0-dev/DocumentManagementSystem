<?php
    // Signed-in check first (redirects to login.php), so the form below is only ever
    // handled for a valid session. Also starts the session and provides $conn.
    include('session.php');

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $result = 'error';
        if (isset($_POST['current_password']) && isset($_POST['new_password'])) {
            $email = $_SESSION['email'];
            $rawCurrent = trim((string) $_POST['current_password']);
            $rawNew = trim((string) $_POST['new_password']);
            $rawConfirm = trim((string) ($_POST['confirm_password'] ?? ''));

            if ($rawCurrent === '' || $rawNew === '' || $rawConfirm === '') {
                $result = 'empty';
            } elseif (strlen($rawNew) < 8) {
                $result = 'short'; // same minimum as Register / Forgot Password
            } elseif ($rawNew !== $rawConfirm) {
                $result = 'mismatch';
            } else {
                // Fetch current password from database
                $stmt = $conn->prepare("SELECT password FROM accounts WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->bind_result($db_password);
                $found = $stmt->fetch();
                $stmt->close();

                // login.php checks htmlspecialchars(trim(password)), so passwords are stored that way.
                // The raw check covers passwords saved by the old version of this page.
                $currentOk = $found && (password_verify(htmlspecialchars($rawCurrent), $db_password)
                                        || password_verify((string) $_POST['current_password'], $db_password));

                if (!$currentOk) {
                    $result = 'incorrect'; // Wrong current password
                } else {
                    $hashed_password = password_hash(htmlspecialchars($rawNew), PASSWORD_BCRYPT);

                    // Update password in the database (and drop any pending forgot-password request,
                    // so approving it later can't overwrite the password chosen here)
                    require_once __DIR__ . '/includes/password_reset.php';
                    dms_pw_reset_ensure_schema($conn);
                    $update_stmt = $conn->prepare("UPDATE accounts SET password = ?, reset_password = NULL, reset_requested_at = NULL WHERE email = ?");
                    $update_stmt->bind_param("ss", $hashed_password, $email);
                    $result = $update_stmt->execute() ? 'success' : 'error';
                    $update_stmt->close();
                }
            }
        }

        // Post/Redirect/Get: refreshing the page never re-sends the passwords
        $_SESSION['pw_flash'] = $result;
        header("Location: accountSettings.php");
        exit();
    }

    $message = '';
    if (isset($_SESSION['pw_flash'])) {
        $message = (string) $_SESSION['pw_flash'];
        unset($_SESSION['pw_flash']);
    }
    $dmsPageTitle = 'Account Settings';
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
                        <h1>Account Settings</h1>
                        <p>Your profile and sign-in password.</p>
                    </div>
                </div>
                <div class="row d-flex align-items-stretch">
                    <div class="col-lg-5 col-xl-4 mb-4 mb-lg-0">
                        <div class="card h-100">
                            <div class="card-body d-flex flex-column justify-content-center align-items-center text-center" style="height: 100%;">
                                <div class="profile-statistics">
                                    <div class="text-center mt-4 border-bottom-1 pb-3">
                                        <div class="row">
                                            <div class="col">
                                                <img src="./images/user.jpg" alt="User Image" class="rounded-circle dms-profile-img" width="50%">
                                            </div>
                                        </div>
                                        <div class="row mt-4">
                                            <div class="col">
                                                <h4 class="m-b-0" style="text-transform: uppercase;"><?php echo htmlspecialchars($_SESSION['email']); ?></h4>
                                                <span class="text-muted">Username</span>
                                            </div>
                                        </div>
                                        <div class="row mt-4">
                                            <div class="col">
                                                <h4 class="m-b-0" style="text-transform: uppercase;">
                                                    <?php 
                                                        switch ($_SESSION['role']) {
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
                                                                echo strtoupper(htmlspecialchars($_SESSION['role']));
                                                        }
                                                    ?>
                                                </h4>
                                                <span class="text-muted">Role</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7 col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="auth-form">
                                    <h4 class="dms-card-title">Set New Password</h4>
                                    <p class="dms-card-sub mb-4">Enter your current password, then choose a new one.</p>
                                    <form method="POST" id="passwordForm" novalidate data-auth-form data-auth-guard>
                                        <!-- Lets password managers save the new password under this account -->
                                        <input type="text" autocomplete="username" value="<?php echo htmlspecialchars($_SESSION['email']); ?>" hidden readonly tabindex="-1" aria-hidden="true">
                                        <div class="form-group" style="position: relative;">
                                            <label for="current_password">Enter Current Password</label>
                                            <div class="position-relative"><input type="password" class="form-control" name="current_password" id="current_password" 
                                                style="padding-right: 35px; width: 100%;" required autocomplete="current-password" enterkeyhint="next">
                                            <i class="fa fa-eye-slash" id="toggleEyeCurrent" data-pw-toggle="current_password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 18px; cursor: pointer;"></i></div>
                                        </div>

                                        <div class="form-group" style="position: relative;">
                                                <label for="new_password">Enter New Password</label>
                                                <div class="position-relative"><input type="password" class="form-control" name="new_password" id="new_password" 
                                                    style="padding-right: 35px; width: 100%;" required minlength="8" autocomplete="new-password" enterkeyhint="next" aria-describedby="newPasswordHint">
                                                <span id="newPasswordHint" class="sr-only">At least 8 characters.</span>
                                                <i class="fa fa-eye-slash" id="toggleEyeNew" data-pw-toggle="new_password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 18px; cursor: pointer;"></i></div>
                                            </div>

                                            <div class="form-group" style="position: relative;">
                                                <label for="confirm_password">Confirm New Password</label>
                                                <div class="position-relative"><input type="password" class="form-control" name="confirm_password" id="confirm_password" 
                                                    style="padding-right: 35px; width: 100%;" required minlength="8" autocomplete="new-password" enterkeyhint="done">
                                                <i class="fa fa-eye-slash" id="toggleEyeConfirm" data-pw-toggle="confirm_password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 18px; cursor: pointer;"></i></div>
                                            </div>

                                        <div class="text-center">
                                            <button type="submit" class="btn btn-primary btn-block">Save</button>
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
    <!-- SweetAlert2 Library -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Password eye, Enter = next field, busy button, keyboard handling (shared by the sign-in forms) -->
    <script src="./js/auth-forms.js?v=<?php echo filemtime(__DIR__ . '/js/auth-forms.js'); ?>"></script>

    <script>
        // Client-side checks (the server checks everything again)
        DmsAuth.form('passwordForm', function () {
            var red = { confirmButtonColor: '#d33' };
            var current = document.getElementById('current_password').value.trim();
            var newPassword = document.getElementById('new_password').value.trim();
            var confirmPassword = document.getElementById('confirm_password').value.trim();

            if (!current) return ['current_password', 'Error', 'Please fill in all fields.', red];
            if (!newPassword) return ['new_password', 'Error', 'Please fill in all fields.', red];
            if (!confirmPassword) return ['confirm_password', 'Error', 'Please fill in all fields.', red];
            if (newPassword.length < 8) return ['new_password', 'Error', 'New password must be at least 8 characters long.', red];
            if (newPassword !== confirmPassword) return ['confirm_password', 'Error', 'New passwords do not match!', red];
            return null;
        });

        <?php
            $pwResults = [
                'success'   => ['icon' => 'success', 'title' => 'Success!', 'text' => 'Password has been changed successfully.', 'confirmButtonColor' => '#28a745'],
                'incorrect' => ['icon' => 'error', 'title' => 'Error', 'text' => 'Current password is incorrect!', 'confirmButtonColor' => '#d33', 'field' => 'current_password'],
                'short'     => ['icon' => 'error', 'title' => 'Error', 'text' => 'New password must be at least 8 characters long.', 'confirmButtonColor' => '#d33', 'field' => 'new_password'],
                'mismatch'  => ['icon' => 'error', 'title' => 'Error', 'text' => 'New passwords do not match!', 'confirmButtonColor' => '#d33', 'field' => 'confirm_password'],
                'empty'     => ['icon' => 'error', 'title' => 'Error', 'text' => 'Please fill in all fields.', 'confirmButtonColor' => '#d33', 'field' => 'current_password'],
                'error'     => ['icon' => 'error', 'title' => 'Error', 'text' => 'Something went wrong. Please try again!', 'confirmButtonColor' => '#d33'],
            ];
            if (isset($pwResults[$message])) {
        ?>
        DmsAuth.alert(<?php echo json_encode($pwResults[$message], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        <?php } ?>
    </script>


</body>

</html>