-- Adds the full_name column used by the self-registration page (register.php).
-- Run once on the existing lgu_dms database (e.g. in phpMyAdmin > SQL).
-- Nullable so existing accounts and admin-created accounts keep working unchanged.

ALTER TABLE `accounts`
  ADD COLUMN `full_name` varchar(255) DEFAULT NULL AFTER `id`;
