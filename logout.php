<?php
    session_start();
    require 'connect.php';
    require_once __DIR__ . '/includes/auth_sessions.php';

    // Sign out this device only; the account stays signed in on other devices.
    if (isset($_SESSION['token'])) {
        dms_auth_revoke($conn, $_SESSION['token']);
    }

    session_unset();
    session_destroy();

    // Drop the session cookie too. Changing it also tells the browser that cached copies of
    // signed-in pages are stale, so Back after signing out can't show them.
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    header("Location: login.php");
    exit();
?>