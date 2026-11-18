<?php
    include("session.php");
    include("connect.php");

    // Note: not "$username"/"$password" — connect.php (run again via header.php) uses those
    // names for the database login, which used to put "root" into the Username box.
    $fullName = '';
    $regUsername = '';
    $alert = null; // ['icon' => ..., 'title' => ..., 'text' => ..., 'redirect' => ..., 'field' => ...]

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $fullName = trim($_POST['full_name'] ?? '');
        $regUsername = trim($_POST['username'] ?? '');
        $rawPassword = trim($_POST['password'] ?? '');
        $rawConfirm = trim($_POST['confirm_password'] ?? '');

        // Same transformation login.php applies before password_verify(), so the stored hash matches at sign-in
        $regPassword = htmlspecialchars($rawPassword);
        $confirmPassword = htmlspecialchars($rawConfirm);

        $error = '';
        $errorField = null; // field to focus after the message

        if ($fullName === '' || $regUsername === '' || $rawPassword === '' || $rawConfirm === '') {
            $error = 'Please fill in all fields.';
            $errorField = $fullName === '' ? 'full_name' : ($regUsername === '' ? 'username' : ($rawPassword === '' ? 'password' : 'confirm_password'));
        } elseif (!preg_match("/^[\p{L} .'\-]{2,100}$/u", $fullName)) {
            $error = 'Full name must be 2-100 characters and contain only letters, spaces, periods, apostrophes or hyphens.';
            $errorField = 'full_name';
        } elseif (!preg_match('/^[A-Za-z0-9._@\-]{4,50}$/', $regUsername)) {
            $error = 'Username must be 4-50 characters and contain only letters, numbers, periods, underscores, hyphens or @.';
            $errorField = 'username';
        } elseif (strlen($rawPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
            $errorField = 'password';
        } elseif ($regPassword !== $confirmPassword) {
            $error = 'Password and Confirm Password do not match.';
            $errorField = 'confirm_password';
        }

        if ($error === '') {
            try {
                // Prevent duplicate usernames (column collation is case-insensitive)
                $check = $conn->prepare("SELECT id FROM accounts WHERE email = ?");
                $check->bind_param("s", $regUsername);
                $check->execute();
                $check->store_result();
                $exists = $check->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = 'That username is already taken. Please choose another one.';
                    $errorField = 'username';
                } else {
                    $hashedPassword = password_hash($regPassword, PASSWORD_BCRYPT);
                    $role = 'user';
                    $accountStatus = 'pending'; // must be approved by the master admin in Manage Accounts
                    $token = '';

                    $insert = $conn->prepare("INSERT INTO accounts (full_name, email, password, role, account_status, token) VALUES (?, ?, ?, ?, ?, ?)");
                    $insert->bind_param("ssssss", $fullName, $regUsername, $hashedPassword, $role, $accountStatus, $token);
                    $ok = $insert->execute();
                    $insert->close();

                    if ($ok) {
                        $alert = [
                            'icon' => 'success',
                            'title' => 'Registration Submitted',
                            'text' => 'Your account has been created and is pending administrator approval. You can sign in once it has been activated.',
                            'redirect' => 'login.php'
                        ];
                        $fullName = '';
                        $regUsername = '';
                    } else {
                        $error = 'There was an error creating your account. Please try again.';
                    }
                }
            } catch (Throwable $e) {
                $error = 'There was an error creating your account. Please try again or contact the administrator.';
            }
        }

        if ($error !== '') {
            $alert = [
                'icon' => 'error',
                'title' => 'Registration Failed',
                'text' => $error,
                'redirect' => null,
                'field' => $errorField
            ];
        }
    }

    $dmsAuthPage = true;
    $dmsPageTitle = 'Create Account';
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
                <h2>Create account</h2>
                <p class="lead-sub">Register for a new account</p>
                <form id="registerForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate
                      data-auth-form data-auth-guard data-auth-autofocus<?php echo ($alert !== null && $alert['icon'] === 'error') ? ' data-auth-dirty' : ''; ?>>
                    <!-- Full Name -->
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <div class="dms-input-icon">
                            <i class="fa fa-id-card dms-input-lead" aria-hidden="true"></i>
                            <input type="text" class="form-control" placeholder="Full Name" name="full_name" id="full_name"
                                   value="<?php echo htmlspecialchars($fullName); ?>" required minlength="2" maxlength="100" autocomplete="name" enterkeyhint="next">
                        </div>
                    </div>
                    <!-- Username -->
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="dms-input-icon">
                            <i class="fa fa-user dms-input-lead" aria-hidden="true"></i>
                            <input type="text" class="form-control" placeholder="Username" name="username" id="username"
                                   value="<?php echo htmlspecialchars($regUsername); ?>" required minlength="4" maxlength="50"
                                   pattern="[A-Za-z0-9._@\-]{4,50}" autocomplete="username" autocapitalize="none"
                                   autocorrect="off" spellcheck="false" enterkeyhint="next" aria-describedby="usernameHint">
                            <span id="usernameHint" class="sr-only">4 to 50 characters: letters, numbers, periods, underscores, hyphens or @.</span>
                        </div>
                    </div>
                    <!-- Password -->
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="dms-pw-wrap dms-input-icon">
                            <i class="fa fa-lock dms-input-lead" aria-hidden="true"></i>
                            <input type="password" class="form-control" placeholder="Password (min. 8 characters)" name="password" id="password"
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
                            <input type="password" class="form-control" placeholder="Confirm Password" name="confirm_password" id="confirm_password"
                                   required minlength="8" autocomplete="new-password" enterkeyhint="go">
                            <i class="fa fa-eye-slash dms-pw-toggle" id="toggleConfirmEye" data-pw-toggle="confirm_password"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-block dms-auth-btn">Register</button>
                    </div>
                    <p class="dms-auth-switch">Already have an account? <a href="login.php">Sign In</a></p>
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
        DmsAuth.form('registerForm', function () {
            var title = 'Registration Failed';
            var fullName = document.getElementById('full_name').value.trim();
            var username = document.getElementById('username').value.trim();
            var password = document.getElementById('password').value.trim();
            var confirm = document.getElementById('confirm_password').value.trim();
            var nameOk;
            // Same rule as the server (letters in any language, spaces, . ' -)
            try { nameOk = new RegExp("^[\\p{L} .'\\-]{2,100}$", 'u').test(fullName); }
            catch (e) { nameOk = fullName.length >= 2 && fullName.length <= 100; } // older browsers

            if (!fullName) return ['full_name', title, 'Please fill in all fields.'];
            if (!username) return ['username', title, 'Please fill in all fields.'];
            if (!password) return ['password', title, 'Please fill in all fields.'];
            if (!confirm) return ['confirm_password', title, 'Please fill in all fields.'];
            if (!nameOk) return ['full_name', title, 'Full name must be 2-100 characters and contain only letters, spaces, periods, apostrophes or hyphens.'];
            if (!/^[A-Za-z0-9._@\-]{4,50}$/.test(username)) return ['username', title, 'Username must be 4-50 characters and contain only letters, numbers, periods, underscores, hyphens or @.'];
            if (password.length < 8) return ['password', title, 'Password must be at least 8 characters long.'];
            if (password !== confirm) return ['confirm_password', title, 'Password and Confirm Password do not match.'];
            return null;
        });

        <?php if ($alert !== null) { ?>
        DmsAuth.alert(<?php echo json_encode($alert, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        <?php } ?>
    </script>

</body>

</html>
