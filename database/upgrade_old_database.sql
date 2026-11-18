-- =====================================================================
-- Upgrade an OLD lgu_dms database to the new DMS (run ONCE).
--
-- Use this on a computer that still has the old system's database and is
-- getting the new DMS code: phpMyAdmin > click lgu_dms > Import > choose
-- this file > Import. Back up (Export) the database first.
--
-- It only ADDS tables/columns and fills defaults; no records are deleted.
-- Safe to run more than once (needs MariaDB 10.0.2+ / XAMPP 7.x or newer).
--
-- Built from database/add_*.sql (2026-11-17), plus the attachment fix for
-- 9 resolutions whose scans were overwritten by the old upload code.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- add_committee_reports.sql
-- ---------------------------------------------------------------------
-- committee_reports is used by the Committee Reports module but is missing from lgu_dms.sql.
-- Structure as found in the running database (only created if it does not exist).
CREATE TABLE IF NOT EXISTS `committee_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `committee_category` varchar(255) DEFAULT NULL,
  `committee_section` varchar(255) DEFAULT NULL,
  `councilor` varchar(255) NOT NULL,
  `date_report` date NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- add_full_name_to_accounts.sql
-- ---------------------------------------------------------------------
-- Adds the full_name column used by the self-registration page (register.php).
-- Run once on the existing lgu_dms database (e.g. in phpMyAdmin > SQL).
-- Nullable so existing accounts and admin-created accounts keep working unchanged.

ALTER TABLE `accounts`
  ADD COLUMN IF NOT EXISTS `full_name` varchar(255) DEFAULT NULL AFTER `id`;

-- ---------------------------------------------------------------------
-- add_password_reset.sql
-- ---------------------------------------------------------------------
-- Adds the columns used by the Forgot Password page (forgot_password.php).
-- A user's reset request stores the hash of the requested new password until the
-- Sangguniang Bayan Secretary approves or rejects it in Manage Accounts.
-- Run once on the existing lgu_dms database (e.g. in phpMyAdmin > SQL).
-- The app also adds these automatically (includes/password_reset.php).

ALTER TABLE `accounts`
  ADD COLUMN IF NOT EXISTS `reset_password` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reset_requested_at` datetime DEFAULT NULL;

-- ---------------------------------------------------------------------
-- add_minutes_workflow.sql
-- ---------------------------------------------------------------------
-- Order of Business workflow: action buttons + First / Second / Third Reading.
--
-- SUPERSEDED by add_ob_item_status.sql: sections are now First Reading,
-- Second Reading and Other Matters, and each item has its own status
-- (minutes.ob_status). The columns below are still created because older
-- records keep their earlier action, referral and schedule details.
--
-- includes/minutes_workflow.php (dms_ob_ensure_schema) applies this
-- automatically the first time an Order of Business page is opened, so
-- importing this file by hand is optional. Run it only once.
--
--  ob_action          selected action ('' = none). One of: Deferred,
--                     File It Away, For Committee Meeting, For Public Hearing,
--                     For Further Study, Referred to Concerned Offices, Approved
--  reading            First Reading | Second Reading | Third Reading
--                     (File It Away keeps it, so un-filing restores the reading)
--  referral_office    Referred to Concerned Offices: assigned office
--  referral_notes     Referred to Concerned Offices: instructions / status / remarks
--  action_updated_at  when the action last changed
--
-- The old `status`, `returnNo` and `returnDate` columns are kept untouched as
-- legacy data; the pages show them read-only.

ALTER TABLE `minutes`
  ADD COLUMN IF NOT EXISTS `ob_action` varchar(50) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `reading` varchar(20) NOT NULL DEFAULT 'First Reading',
  ADD COLUMN IF NOT EXISTS `referral_office` varchar(255) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `referral_notes` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `action_updated_at` datetime DEFAULT NULL;

-- Scheduling details for For Committee Meeting / For Public Hearing.
-- One row per record and type, so both sets of details are kept.
CREATE TABLE IF NOT EXISTS `minutes_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `minutes_id` int(255) NOT NULL,
  `schedule_type` varchar(30) NOT NULL,
  `sched_date` date DEFAULT NULL,
  `sched_time` time DEFAULT NULL,
  `venue` varchar(255) NOT NULL DEFAULT '',
  `invitees` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_minutes_type` (`minutes_id`, `schedule_type`),
  CONSTRAINT `fk_minutes_schedule_minutes` FOREIGN KEY (`minutes_id`) REFERENCES `minutes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Existing records: a resolution number meant "approved" under the old logic.
UPDATE `minutes`
   SET `ob_action` = 'Approved', `reading` = 'Third Reading'
 WHERE TRIM(COALESCE(`resolutionNo`, '')) <> '' AND `ob_action` = '';

-- ---------------------------------------------------------------------
-- add_ob_item_status.sql
-- ---------------------------------------------------------------------
-- Order of Business: agenda item status, separate from its section.
--
-- includes/minutes_workflow.php (dms_ob_ensure_schema) applies this
-- automatically the first time an Order of Business page is opened, so
-- importing this file by hand is optional. Run it only once, after
-- add_minutes_workflow.sql.
--
--  reading            the section: First Reading | Second Reading | Third Reading | Other Matters
--  ob_status          the item's status, '' until the user sets one:
--                       First Reading:  Referred, For Information, Approved
--                       Second Reading: Deferred, Returned, File It Away, For Approval, Approved
--                       Third Reading:  Approved
--                       Other Matters:  Adopted, Referred, To Be Calendared
--                     Approved (First or Second Reading) moves the item to Third Reading.
--  status_updated_at  when the status last changed
--
-- Existing rows keep a blank status; their earlier action / referral /
-- schedule details stay untouched and are shown read-only.

ALTER TABLE `minutes`
  ADD COLUMN IF NOT EXISTS `ob_status` varchar(30) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS `status_updated_at` datetime DEFAULT NULL;

-- ---------------------------------------------------------------------
-- add_org_chart.sql
-- ---------------------------------------------------------------------
-- Organizational Chart: editable groups, chart title, and per-official group/order.
--
-- includes/org_chart.php (dms_org_ensure_schema) applies this automatically the first time the
-- Organizational Chart or the Dashboard is opened, so importing this file by hand is optional.
-- Run it only once.
--
--  org_chart_groups   one row per tier of the chart ("Presiding Officer", ...): title, colour, order
--  org_chart_meta     chart title / subtitle (k = 'title' | 'subtitle')
--  officials.group_id the group an official is shown in
--  officials.sort_order  their order inside that group

CREATE TABLE IF NOT EXISTS `org_chart_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL DEFAULT '',
  `color` varchar(7) NOT NULL DEFAULT '#098209',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `org_chart_meta` (
  `k` varchar(50) NOT NULL,
  `v` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `officials`
  ADD COLUMN IF NOT EXISTS `group_id` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sort_order` int(11) NOT NULL DEFAULT 0;

INSERT IGNORE INTO `org_chart_groups` (`id`, `title`, `color`, `sort_order`) VALUES
  (1, 'Presiding Officer', '#098209', 1),
  (2, 'Sangguniang Bayan Members', '#0F8C83', 2),
  (3, 'Ex-Officio Members', '#E07B00', 3);

UPDATE `officials` SET `group_id` = 1 WHERE `position` = 'Vice-Mayor';
UPDATE `officials` SET `group_id` = 2 WHERE `position` = 'Councilor';
UPDATE `officials` SET `group_id` = 3 WHERE `group_id` IS NULL;

-- ---------------------------------------------------------------------
-- add_record_attachments.sql
-- ---------------------------------------------------------------------
-- Additional attachments (built-in document scanner, js/doc-scanner.js).
--
-- Each record keeps its single attachment column. When more than one PDF is
-- attached to a record, the extra files are listed here. Files live in
-- uploads/ like every other attachment.
--
-- includes/record_attachments.php (dms_ra_ensure_schema) creates this table
-- automatically the first time it is needed, so importing this file by hand
-- is optional.
--
--  module        resolution | ordinance | committee_report | ob (minutes row)
--  record_id     id in that module's table
--  client_token  random id sent by the scanner per PDF: a repeated click or a
--                retry after a lost response never attaches the file twice

CREATE TABLE IF NOT EXISTS `record_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module` varchar(30) NOT NULL,
  `record_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `file_size` int(11) NOT NULL DEFAULT 0,
  `page_count` int(11) NOT NULL DEFAULT 0,
  `paper` varchar(20) NOT NULL DEFAULT '',
  `orientation` varchar(10) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'scan',
  `client_token` varchar(64) DEFAULT NULL,
  `uploaded_by` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_record_attachments_token` (`client_token`),
  KEY `idx_record_attachments_record` (`module`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- add_account_sessions.sql
-- ---------------------------------------------------------------------
-- Per-device sign-in tokens: the same account can stay signed in on several devices/browsers.
-- session.php creates this table automatically (includes/auth_sessions.php); run this only for manual setups.

CREATE TABLE IF NOT EXISTS `account_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `token` char(64) NOT NULL,
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Keep anyone already signed in with the old single token signed in.
INSERT IGNORE INTO `account_sessions` (account_id, token)
  SELECT id, token FROM `accounts` WHERE token IS NOT NULL AND CHAR_LENGTH(token) = 64;

-- ---------------------------------------------------------------------
-- Attachment fix: the old code saved new scans over older files with the
-- same name. The new scans are shipped as "0XX (2).pdf"; the original
-- "0XX.pdf" files (for the older resolutions) are restored in uploads/.
-- ---------------------------------------------------------------------
UPDATE `resolution` SET `attachment` = 'uploads/002 (2).pdf' WHERE `id` = 2613 AND `attachment` = 'uploads/002.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/006 (2).pdf' WHERE `id` = 2619 AND `attachment` = 'uploads/006.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/007 (2).pdf' WHERE `id` = 2620 AND `attachment` = 'uploads/007.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/008 (2).pdf' WHERE `id` = 2621 AND `attachment` = 'uploads/008.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/009 (2).pdf' WHERE `id` = 2622 AND `attachment` = 'uploads/009.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/010 (2).pdf' WHERE `id` = 2623 AND `attachment` = 'uploads/010.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/013 (2).pdf' WHERE `id` = 2627 AND `attachment` = 'uploads/013.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/014 (2).pdf' WHERE `id` = 2628 AND `attachment` = 'uploads/014.pdf';
UPDATE `resolution` SET `attachment` = 'uploads/015 (2).pdf' WHERE `id` = 2629 AND `attachment` = 'uploads/015.pdf';
