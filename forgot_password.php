<?php
    include("session.php");
    include("connect.php");
    require_once __DIR__ . '/includes/password_reset.php';

    dms_pw_reset_ensure_schema($conn);

    // Note: not "$username"/"$password" — connect.php (run again via header.php) uses those
    // names for the database login, which used to put "root" into the Username box.
    $fpUsername = '';
    $alert = null; // ['icon' => ..., 'title' => ..., 'text' => ..., 'redirect' => ..., 'field' => ...]

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $fpUsername = trim($_POST['username'] ?? '');
        $rawPassword = trim($_POST['password'] ?? '');
        $rawConfirm = trim($_POST['confirm_password'] ?? '');

        // Same transformation login.php applies before password_verify(), so the stored hash matches at sign-in
        $newPassword = htmlspecialchars($rawPassword);
        $confirmPassword = htmlspecialchars($rawConfirm);

        $error = '';
        $errorField = null; // field to focus after the message
        $lastRequest = isset($_SESSION['pw_reset_last']) ? (int) $_SESSION['pw_reset_last'] : 0;

        if ($fpUsername === '' || $rawPassword === '' || $rawConfirm === '') {
            $error = 'Please fill in all fields.';
            $errorField = $fpUsername === '' ? 'username' : ($rawPassword === '' ? 'password' : 'confirm_password');
        } elseif (strlen($rawPassword) < 8) {
            $error = 'New password must be at least 8 characters long.';
            $errorField = 'password';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New Password and Confirm Password do not match.';
            $errorField = 'confirm_password';
        } elseif ($lastRequest > 0 && time() - $lastRequest < 60) {
            $error = 'A request was just sent. Please wait a minute before sending another one.';
        }

        if ($error === '') {
            try {
                // Only active, non-master accounts. Unknown / pending / master usernames are silently
                // ignored so this page never reveals which usernames exist.
                $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE accounts SET reset_password = ?, reset_requested_at = NOW()
                                        WHERE email = ? AND account_status = 'active' AND role <> 'master'");
                $stmt->bind_param("ss", $hashedPassword, $fpUsername);
                $stmt->execute();
                $stmt->close();

                $_SESSION['pw_reset_last'] = time();
                $alert = [
                    'icon' => 'success',
                    'title' => 'Request Sent',
                    'text' => 'If that username belongs to an active account, your password reset request has been sent to the Sangguniang Bayan Secretary. You can sign in with your new password once it has been approved. Until then, your current password still works.',
                    'redirect' => 'login.php'
                ];
                $fpUsername = '';
            } catch (Throwable $e) {
                $error = 'There was an error sending your request. Please try again or contact the administrator.';
            }
        }

        if ($error !== '') {
            $alert = [
                'icon' => 'error',
                'title' => 'Request Not Sent',
                'text' => $error,
                'redirect' => null,
                'field' => $errorField
            ];
        }
    }

    $dmsAuthPage = true;
    $dmsPageTitle = 'Forgot Password';
?>


<!DOCTYPE html>
<html lang="en" class="h-100">

<?php include "header.php"; ?>

<body class="dms-auth-page">
    <div class="dms-auth">
        <!-- Background decorations (purely visual) -->
        <span class="dms-auth-blob b1" aria-hidden="true"></span>
        <span class="dms-auth-blob b2" aria-hidden="true"></span>
        <svg class="dms-auth-wave" viewBox="0 0 1440 220" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0,140 C240,60 480,200 760,150 C1040,100 1220,40 1440,90 L1440,220 L0,220 Z" fill="rgba(9,130,9,0.07)"/>
            <path d="M0,180 C300,120 560,220 860,185 C1120,155 1280,120 1440,150 L1440,220 L0,220 Z" fill="rgba(9,130,9,0.06)"/>
        </svg>

        <div class="dms-auth-shell">
            <!--TEXT LEFT-->
            <div class="dms-auth-brand">
                <img class="dms-auth-building" src="./images/municipal-building.jpg" alt="" aria-hidden="true" width="628" height="318" decoding="async">
                <span class="dms-auth-building-tint" aria-hidden="true"></span>
                <div class="dms-auth-brand-top">
                    <img class="dms-auth-logo" src="./images/logo.png" alt="Sangguniang Bayan seal">
                    <div class="dms-auth-titles">
                        <h1>Welcome</h1>
                        <h3>Sangguniang Bayan Office</h3>
                        <h4>Document Management System</h4>
                    </div>
                </div>
                <small>Resolutions · Ordinances · Order of Business · Committee Reports</small>
            </div>
            <!--FORM RIGHT-->
            <div class="dms-auth-form" role="main">
                <h2>Forgot password</h2>
                <p class="lead-sub">Request a new password for your account</p>
                <p class="dms-auth-note">
                    Your request is sent to the Sangguniang Bayan Secretary for approval. Your current password keeps working until it is approved.
                    The Secretary's own account cannot be reset here; please contact the system administrator.
                </p>
                <form id="forgotForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate
                      data-auth-form data-auth-guard data-auth-autofocus<?php echo ($alert !== null && $alert['icon'] === 'error') ? ' data-auth-dirty' : ''; ?>>
                    <!-- Username -->
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="dms-input-icon">
                            <i class="fa fa-user dms-input-lead" aria-hidden="true"></i>
                            <input type="text" class="form-control" placeholder="Username" name="username" id="username"
                                   value="<?php echo htmlspecialchars($fpUsername); ?>" required maxlength="255" autocomplete="username"
                                   autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next">
                        </div>
                    </div>
                    <!-- New Password -->
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="dms-pw-wrap dms-input-icon">
                            <i class="fa fa-lock dms-input-lead" aria-hidden="true"></i>
                            <input type="password" class="form-control" placeholder="New password (min. 8 characters)" name="password" id="password"
                                   required minlength="8" autocomplete="new-password" enterkeyhint="next" aria-describedby="passwordHint">
                            <span id="passwordHint" class="sr-only">At least 8 characters.</span>
                            <i class="fa fa-eye-slash dms-pw-toggle" id="togglePasswordEye" data-pw-toggle="password"></i>
                        </div>
                    </div>
                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="dms-pw-wrap dms-input-icon">
                            <i class="fa fa-lock dms-input-lead" aria-hidden="true"></i>
                            <input type="password" class="form-control" placeholder="Confirm new password" name="confirm_password" id="confirm_password"
                                   required minlength="8" autocomplete="new-password" enterkeyhint="send">
                            <i class="fa fa-eye-slash dms-pw-toggle" id="toggleConfirmEye" data-pw-toggle="confirm_password"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-block dms-auth-btn">Send Request</button>
                    </div>
                    <p class="dms-auth-switch">Remembered it? <a href="login.php">Back to Sign In</a></p>
                </form>
            </div>
        </div>
    </div>


    <!--**********************************
        Scripts
    ***********************************-->
    <!-- Required vendors -->
    <script src="./vendor/global/global.min.js"></script>
    <script src="./js/quixnav-init.js"></script>
    <script src="./js/custom.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Password eye, Enter = next field, busy button, keyboard handling (shared by the sign-in forms) -->
    <script src="./js/auth-forms.js?v=<?php echo filemtime(__DIR__ . '/js/auth-forms.js'); ?>"></script>

    <script>
        // Client-side checks (the server validates everything again)
        DmsAuth.form('forgotForm', function () {
            var title = 'Request Not Sent';
            var username = document.getElementById('username').value.trim();
            var password = document.getElementById('password').value.trim();
            var confirm = document.getElementById('confirm_password').value.trim();

            if (!username) return ['username', title, 'Please fill in all fields.'];
            if (!password) return ['password', title, 'Please fill in all fields.'];
            if (!confirm) return ['confirm_password', title, 'Please fill in all fields.'];
            if (password.length < 8) return ['password', title, 'New password must be at least 8 characters long.'];
            if (password !== confirm) return ['confirm_password', title, 'New Password and Confirm Password do not match.'];
            return null;
        });

        <?php if ($alert !== null) { ?>
        DmsAuth.alert(<?php echo json_encode($alert, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        <?php } ?>
    </script>

</body>

</html>
