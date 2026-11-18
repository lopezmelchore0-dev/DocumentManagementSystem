-- Adds the columns used by the Forgot Password page (forgot_password.php).
-- A user's reset request stores the hash of the requested new password until the
-- Sangguniang Bayan Secretary approves or rejects it in Manage Accounts.
-- Run once on the existing lgu_dms database (e.g. in phpMyAdmin > SQL).
-- The app also adds these automatically (includes/password_reset.php).

ALTER TABLE `accounts`
  ADD COLUMN `reset_password` varchar(255) DEFAULT NULL,
  ADD COLUMN `reset_requested_at` datetime DEFAULT NULL;
