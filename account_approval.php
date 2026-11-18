<?php
    // Activate or reject a pending (self-registered) or inactive account, or approve / reject a
    // forgot-password request (forgot_password.php). Master admin only.
    // Used by the Approve/Reject buttons and the right-click menu on manageAccounts.php.
    include "session.php";
    require_once __DIR__ . '/includes/password_reset.php';

    header('Content-Type: application/json');

    function respond($success, $message, $status = 200) {
        http_response_code($status);
        echo json_encode(['success' => $success, 'message' => $message]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Invalid request method.', 405);
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'master') {
        respond(false, 'Only the Sangguniang Bayan Secretary can approve or reject registrations and password resets.', 403);
    }

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($id <= 0 || !in_array($action, ['approve', 'reject', 'approve_reset', 'reject_reset'], true)) {
        respond(false, 'Invalid account or action.', 400);
    }

    // Forgot-password requests: the requested hash replaces the password (approve) or is discarded (reject).
    if ($action === 'approve_reset' || $action === 'reject_reset') {
        dms_pw_reset_ensure_schema($conn);
        $resetGuard = "reset_password IS NOT NULL AND role <> 'master'";
        if ($action === 'approve_reset') {
            // The account's sign-ins on every device are removed below (dms_auth_revoke_all)
            $stmt = $conn->prepare("UPDATE accounts SET password = reset_password, reset_password = NULL,
                                    reset_requested_at = NULL, token = '' WHERE id = ? AND $resetGuard");
        } else {
            $stmt = $conn->prepare("UPDATE accounts SET reset_password = NULL, reset_requested_at = NULL
                                    WHERE id = ? AND $resetGuard");
        }

        if (!$stmt) {
            respond(false, 'Database error. Please try again.', 500);
        }

        $stmt->bind_param("i", $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            respond(false, 'Account not found, or it no longer has a password reset request.', 404);
        }

        if ($action === 'approve_reset') {
            dms_auth_revoke_all($conn, $id);
        }

        respond(true, $action === 'approve_reset'
            ? 'The new password is now active. The user can sign in with it.'
            : 'The password reset request has been rejected. The current password is unchanged.');
    }

    // Only pending (self-registered) or inactive accounts can be activated/rejected here;
    // active accounts and the master account are never touched.
    $guard = "account_status IN ('pending', 'inactive') AND role <> 'master'";
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE accounts SET account_status = 'active' WHERE id = ? AND $guard");
    } else {
        $stmt = $conn->prepare("DELETE FROM accounts WHERE id = ? AND $guard");
    }

    if (!$stmt) {
        respond(false, 'Database error. Please try again.', 500);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        respond(false, 'Account not found, or it is no longer pending or inactive.', 404);
    }

    respond(true, $action === 'approve'
        ? 'The account has been activated and can now sign in.'
        : 'The account has been rejected and removed.');
?>
