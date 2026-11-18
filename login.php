<?php
    include("session.php");
    include("connect.php");

    $loginSuccess = false;
    $loginFlash = null;     // result of a failed sign-in, shown after the redirect below
    $loginUsername = '';    // typed username, kept when the sign-in fails

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $rawUsername = trim((string) ($_POST['email'] ?? ''));
        $email = htmlspecialchars($rawUsername);
        $password = htmlspecialchars(trim((string) ($_POST['password'] ?? '')));
        $fail = null; // [icon, title, text, field to focus]

        $query = $conn->prepare("SELECT * FROM accounts WHERE email = ?");
        $query->bind_param("s", $email);
        $query->execute();
        $result = $query->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                if ($user['account_status'] === 'active') {
                    session_regenerate_id(true);
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];

                    // A token for this device only: other devices signed in to the same account stay signed in
                    $_SESSION['token'] = dms_auth_create($conn, $user['id']);

                    // No popup: the page plays a short exit transition, then opens the dashboard
                    $loginSuccess = true;
                } elseif ($user['account_status'] === 'pending') {
                    $fail = ['info', 'Account Pending Approval', 'Your registration is awaiting approval by the Sangguniang Bayan Secretary. Please try again once it has been approved.', null];
                } else {
                    $fail = ['warning', 'Account Inactive', 'Your account is not active. Please contact the administrator.', null];
                }
            } else {
                $fail = ['error', 'Invalid Login', 'Invalid credentials. Please try again.', 'password'];
            }
        } else {
            $fail = ['error', 'Invalid Login', 'User not found. Please try again.', 'email'];
        }

        // Post/Redirect/Get: refreshing the page never re-sends the password, and the
        // typed username is put back in the form.
        if ($fail !== null) {
            $_SESSION['login_flash'] = [
                'icon' => $fail[0], 'title' => $fail[1], 'text' => $fail[2], 'field' => $fail[3],
                'username' => $rawUsername,
            ];
            header("Location: login.php");
            exit();
        }
    } elseif (isset($_SESSION['login_flash'])) {
        $loginFlash = $_SESSION['login_flash'];
        unset($_SESSION['login_flash']);
        $loginUsername = (string) ($loginFlash['username'] ?? '');
        unset($loginFlash['username']);
    }

    $dmsAuthPage = true;
    $dmsPageTitle = 'Log In';
?>


<!DOCTYPE html>
<html lang="en" class="h-100">

<?php include "header.php"; ?>

<body class="dms-auth-page<?php echo $loginSuccess ? ' dms-auth-leaving' : ''; ?>">
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $loginFlash === null) include __DIR__ . '/includes/splash.php'; ?>
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
            <!--TEXT RIGHT-->
            <div class="dms-auth-form" role="main">
                <h2>Log In</h2>
                <p class="lead-sub">Sign in to your account</p>
                <form id="loginForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate
                      data-auth-form data-auth-autofocus>
                    <!--Email-->
                    <div class="form-group">
                        <label for="email">Username</label>
                        <div class="dms-input-icon">
                            <i class="fa fa-user dms-input-lead" aria-hidden="true"></i>
                            <input type="text" class="form-control" placeholder="Username" name="email" id="email"
                                   value="<?php echo htmlspecialchars($loginUsername); ?>" required maxlength="255"
                                   autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next">
                        </div>
                    </div>
                    <!-- Password -->
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="dms-pw-wrap dms-input-icon">
                            <i class="fa fa-lock dms-input-lead" aria-hidden="true"></i>
                            <input type="password" class="form-control" placeholder="Password" name="password" id="password"
                                   required autocomplete="current-password" enterkeyhint="go">
                            <i class="fa fa-eye-slash dms-pw-toggle" id="toggleEye" data-pw-toggle="password"></i>
                        </div>
                    </div>
                    <p class="dms-auth-forgot"><a href="forgot_password.php">Forgot password?</a></p>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-block dms-auth-btn">Sign me in</button>
                    </div>
                    <p class="dms-auth-switch">Don't Have an Account? <a href="register.php">Sign Up</a></p>
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
        // Client-side check (the server checks the credentials)
        DmsAuth.form('loginForm', function () {
            var username = document.getElementById('email');
            var password = document.getElementById('password');
            if (!username.value.trim()) return ['email', 'Invalid Login', 'Please enter your username and password.'];
            if (!password.value.trim()) return ['password', 'Invalid Login', 'Please enter your username and password.'];
            return null;
        });

        <?php if ($loginFlash !== null) { ?>
        DmsAuth.alert(<?php echo json_encode($loginFlash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        <?php } ?>
    </script>

    <?php if ($loginSuccess): ?>
    <noscript><meta http-equiv="refresh" content="0;url=dashboard.php"></noscript>
    <script>
        (function () {
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            setTimeout(function () { window.location.replace('dashboard.php'); }, reduce ? 0 : 450);
        })();
    </script>
    <?php endif; ?>

</body>

</html>