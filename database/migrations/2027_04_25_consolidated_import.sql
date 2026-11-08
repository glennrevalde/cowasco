-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: version 56
--
--  Members & Others > Consolidated Import: one file for the members with their savings accounts, their loans,
--  their water-service account and the balances of their water bills (Import Members, Import Savings, Import Loans
--  and Upload Bill Balances in one), read into the database first, reviewed and corrected there, then saved.
--
--  What it adds
--    coop_import_batches  one row per file: its number (CI-00001), the file, the columns of the file and what each
--                         one holds, the values for the blank cells, the counts, who read it and when, the status
--                         (reading, review, saving, saved, cancelled)
--    coop_import_raw      the rows of the file as read (thousands of rows are kept here, not in the browser)
--    coop_import_lines    the lines of the review: a member, a savings account, a loan or a bill balance, its
--                         values, its problems and notes, removed or saved (with what it became)
--    pages                Members & Others > Consolidated Import, after Opening Balances Import, given to the roles
--                         that have Member Center, with the same rights (saving takes Create on the page and the
--                         rights of each part: Approve on Member Information, the Savings Account Center and the
--                         Loan Accounts Center, Create on Upload Bill Balances)
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database of version 55 (with the earlier migrations run).
--  New installs do not need it (tools/install.php runs it).
--  Safe to run more than once. Works on MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p your_database < 2027_04_25_consolidated_import.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The files, their rows and the lines of the review
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coop_import_batches` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `file_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `file_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reading',
  `headers_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `mapping_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `options_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `file_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `check_cursor` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `checked_at` datetime NULL DEFAULT NULL,
  `result_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  `saved_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cib_status` (`status`, `id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_import_raw` (
  `batch_id` int(10) UNSIGNED NOT NULL,
  `file_row` int(10) UNSIGNED NOT NULL,
  `cells` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`batch_id`, `file_row`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_import_lines` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` int(10) UNSIGNED NOT NULL,
  `kind` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `line_no` int(10) UNSIGNED NOT NULL,
  `member_line_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `member_line_no` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `file_row` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `vals` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `key_person` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `key_water` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `amount` decimal(15, 2) NULL DEFAULT NULL,
  `errors` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `info` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `cells` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `bad` tinyint(1) NOT NULL DEFAULT 0,
  `checked` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `saved_ref` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_cil_line` (`batch_id`, `line_no`),
  KEY `ix_cil_kind` (`batch_id`, `kind`, `status`, `line_no`),
  KEY `ix_cil_member` (`batch_id`, `member_line_id`),
  KEY `ix_cil_no` (`batch_id`, `kind`, `key_no`),
  KEY `ix_cil_person` (`batch_id`, `key_person`),
  KEY `ix_cil_water` (`batch_id`, `key_water`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Members & Others > Consolidated Import (the rights of Member Center)
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pages') = 0,
  'SELECT ''pages: table not installed''',
  'INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
   SELECT ''coop.consolidated_import'', p.module_id, ''Consolidated Import'', ''transaction'', p.menu_group, p.sort_order + 2, 1,
          ''One file for the members with their savings, loans, water-service account and bill balances, reviewed and corrected before it is saved.''
     FROM pages p WHERE p.page_key = ''coop.import'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, r.allowed, r.can_create, r.can_edit, r.can_delete, r.can_print, r.can_approve
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''coop.members''
     JOIN pages n ON n.page_key = ''coop.consolidated_import''
    WHERE r.allowed = 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Consolidated Import',
        'Migration 2027_04_25: the consolidated import of members, savings, loans and bill balances, given to the roles that have Member Center');
