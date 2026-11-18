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
