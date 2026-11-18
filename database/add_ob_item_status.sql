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
  ADD COLUMN `ob_status` varchar(30) NOT NULL DEFAULT '',
  ADD COLUMN `status_updated_at` datetime DEFAULT NULL;
