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
  ADD COLUMN `ob_action` varchar(50) NOT NULL DEFAULT '',
  ADD COLUMN `reading` varchar(20) NOT NULL DEFAULT 'First Reading',
  ADD COLUMN `referral_office` varchar(255) NOT NULL DEFAULT '',
  ADD COLUMN `referral_notes` text DEFAULT NULL,
  ADD COLUMN `action_updated_at` datetime DEFAULT NULL;

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
