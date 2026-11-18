<?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Never keep pages in the browser cache: after signing out, Back must not show a
    // stored copy of a signed-in page (it reloads and lands on the login page instead).
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    require 'connect.php';
    require_once __DIR__ . '/includes/auth_sessions.php';

    $current_page = basename($_SERVER['PHP_SELF']); // Get current page

    if (!isset($_SESSION['email']) || !isset($_SESSION['token'])) {
        if (!in_array($current_page, ['login.php', 'register.php', 'forgot_password.php'])) {
            header("Location: login.php");
            exit();
        }
    } else {
        $email = $_SESSION['email'];
        $token = $_SESSION['token'];

        // Each device has its own token (includes/auth_sessions.php), so signing in elsewhere
        // does not sign this browser out. Only this browser's session is cleared on failure.
        if (!dms_auth_valid($conn, $email, $token)) {
            session_unset();
            session_destroy();

            header("Location: login.php");
            exit();
        }

        // Only redirect if the request is not POST (login form submission)
        if (in_array($current_page, ['login.php', 'register.php', 'forgot_password.php']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            header("Location: dashboard.php");
            exit();
        }
    }
?>
