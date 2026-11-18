<?php
    /**
     * Forgot-password requests (forgot_password.php → approved by the SB Secretary in manageAccounts.php).
     * A request stores the bcrypt hash of the requested new password on the account itself;
     * the current password keeps working until the Secretary approves it.
     */

    /**
     * Adds the reset columns the first time they are missing
     * (same SQL as database/add_password_reset.sql). Existing data is never changed.
     */
    function dms_pw_reset_ensure_schema($conn)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $res = $conn->query("SHOW COLUMNS FROM `accounts` LIKE 'reset_password'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE `accounts`
                    ADD COLUMN `reset_password` varchar(255) DEFAULT NULL,
                    ADD COLUMN `reset_requested_at` datetime DEFAULT NULL");
            }
        } catch (Throwable $e) {
            // Leave the page working; the SQL file can be run manually.
        }
    }
?>
