-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: version 55
--
--  Water Billing > Billing > Upload Bill Balances: the open balances of the members' and customers' bills of
--  Recurring Billing, Installment Billing, One-Time Billing and Fixed Share Capital Billing, uploaded from a
--  CSV or Excel file and reviewed line by line before they are recorded.
--
--  What it adds
--    water_balance_uploads       one row per upload: its number (BAL-00001), the file, the number of balances,
--                                their total, the class of the journal entries, who uploaded it and when
--    water_balance_upload_lines  the balances of an upload: the line, the row of the file, the billing type,
--                                the water-service account, the charge category (or the share capital account),
--                                the bill made, the amount and the dates. The same balance is not uploaded twice.
--    pages                       Water Billing > Billing > Upload Bill Balances, after Fixed Share Capital
--                                Billing, given to the roles that have One-Time Billing, with the same rights
--                                (recording an upload takes the Create right)
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database of version 54 (with the earlier migrations run).
--  New installs do not need it (tools/install.php runs it).
--  Safe to run more than once. Works on MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p your_database < 2027_04_18_bill_balance_upload.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The uploads and their lines
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `water_balance_uploads` (
  `upload_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `file_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `line_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`upload_id`),
  KEY `ix_wbu_created` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `water_balance_upload_lines` (
  `line_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_id` int(10) UNSIGNED NOT NULL,
  `line_no` int(10) UNSIGNED NOT NULL,
  `file_row` int(10) UNSIGNED NULL DEFAULT NULL,
  `billing_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `water_account_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `sav_account_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `bill_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `installments` smallint(5) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`line_id`),
  KEY `ix_wbul_upload` (`upload_id`, `line_no`),
  KEY `ix_wbul_account` (`water_account_id`, `billing_type`, `bill_date`),
  KEY `ix_wbul_bill` (`billing_type`, `bill_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Water Billing > Billing > Upload Bill Balances (the rights of One-Time Billing)
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pages') = 0,
  'SELECT ''pages: table not installed''',
  'INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
   SELECT ''water.bill_balance_upload'', p.module_id, ''Upload Bill Balances'', ''transaction'', ''Billing'', p.sort_order + 2, 1,
          ''Upload the open balances of the recurring, installment, one-time and fixed share capital bills from a file, reviewed line by line before they are recorded.''
     FROM pages p WHERE p.page_key = ''water.share_capital_billing'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, r.allowed, r.can_create, r.can_edit, r.can_delete, r.can_print, r.can_approve
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.one_time_billing''
     JOIN pages n ON n.page_key = ''water.bill_balance_upload''
    WHERE r.allowed = 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Upload Bill Balances',
        'Migration 2027_04_18: the uploads of bill balances and the page, given to the roles that have One-Time Billing');
