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
  ADD COLUMN `group_id` int(11) DEFAULT NULL,
  ADD COLUMN `sort_order` int(11) NOT NULL DEFAULT 0;

INSERT INTO `org_chart_groups` (`id`, `title`, `color`, `sort_order`) VALUES
  (1, 'Presiding Officer', '#098209', 1),
  (2, 'Sangguniang Bayan Members', '#0F8C83', 2),
  (3, 'Ex-Officio Members', '#E07B00', 3);

UPDATE `officials` SET `group_id` = 1 WHERE `position` = 'Vice-Mayor';
UPDATE `officials` SET `group_id` = 2 WHERE `position` = 'Councilor';
UPDATE `officials` SET `group_id` = 3 WHERE `group_id` IS NULL;
