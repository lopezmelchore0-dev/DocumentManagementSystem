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
