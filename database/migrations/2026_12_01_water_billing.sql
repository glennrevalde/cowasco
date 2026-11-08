-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the water-billing module (tables, posting bridge, menu, settings)
--
--  What it does
--    1. Creates the water-billing tables in the company database, empty. They keep their former names and
--       columns, with these exceptions, so nothing collides with the cooperative tables:
--         members                       -> water_accounts   (a water-service account holder; new columns party_id,
--                                                             customer_id link it to the Party Master and its A/R customer)
--         audit_logs                    -> water_audit_logs
--         chartofaccntstbl, journalentries_copy1, journalvoucher_copy1, journal_voucher_voids, subsidiaryledger,
--         postingmethod, book_types, accounts_payable(_payments), inventory_purchases(_items), inventory_stock_movements,
--         inventory_adjustments, inventory_reorder_levels, suppliers, operator, operator_profile, profile,
--         operator_profile_page_permissions, access_control_pages, access_control_presets(_pages)
--                                       -> legacy_*  (read-only history and audit evidence; their jobs are now
--                                                     done by the cooperative general ledger, inventory, vendors,
--                                                     users, roles and page access)
--       Tables of the former program that nothing uses are not created (users, user_profiles, user_sessions,
--       session, password_resets, operator_password_reset_requests, the old savings tables, the backup table).
--    2. The posting bridge: water_postings (every accounting document a water event produced, with its event
--       key, source, status and reversal link), water_coa_map (legacy account id -> account), water_user_map
--       (legacy operator -> user), water_migration_log (checkpoints of tools/migrate_water.php).
--    3. Read-only compatibility views chartofaccntstbl and water_accounts_v so the remaining legacy SELECTs of the
--       water pages read the one chart of accounts.
--    4. The Water Billing menu (module "water"), its pages and reports, the Water Utility line of business,
--       the water settings and the water default accounts (set them on Chart of Accounts > Module Default Accounts
--       or by running tools/migrate_water.php, which maps them from the former bill codes and settings).
--
--  Nothing is deleted, no posted amount changes. Data is copied from the former water database by
--  tools/migrate_water.php (dry run first), never by this file.
--
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--  How to run (back up the database first): phpMyAdmin > Import, Navicat > Execute SQL File, or
--    mysql -u root -p <database> < 2026_12_01_water_billing.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
--  1. Water-billing tables (definitions of the former program, see above for the renames)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `legacy_access_control_pages` (
  `page_key` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `file_name` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `label` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `module_group` varchar(80) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`page_key`) USING BTREE,
  UNIQUE INDEX `uq_access_control_pages_file`(`file_name`) USING BTREE,
  INDEX `idx_access_control_pages_group`(`module_group`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_access_control_preset_pages` (
  `preset_key` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `page_key` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  PRIMARY KEY (`preset_key`, `page_key`) USING BTREE,
  INDEX `idx_access_control_preset_pages_page`(`page_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_access_control_presets` (
  `preset_key` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`preset_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_accounts_payable` (
  `ap_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `purchase_no` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `supplier_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `ap_account_id` int(11) NOT NULL,
  `original_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `purchase_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `status` enum('open','partial','paid','voided') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'open',
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`ap_id`) USING BTREE,
  UNIQUE INDEX `uq_ap_purchase`(`purchase_id`) USING BTREE,
  INDEX `idx_ap_supplier`(`supplier_id`) USING BTREE,
  INDEX `idx_ap_status`(`status`, `due_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_accounts_payable_payments` (
  `ap_payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ap_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12, 2) NOT NULL,
  `payment_account` int(11) NOT NULL,
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `request_key` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`ap_payment_id`) USING BTREE,
  UNIQUE INDEX `uq_appay_request_key`(`request_key`) USING BTREE,
  INDEX `idx_appay_ap`(`ap_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `addresses` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `city_id` int(11) NULL DEFAULT NULL,
  `housebldg` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `street` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `other_address_detail` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `zippostal_code` smallint(6) NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `idx_addresses_city_status`(`city_id`, `status`) USING BTREE,
  INDEX `idx_addresses_street`(`street`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this table will contain the detailed address';

CREATE TABLE IF NOT EXISTS `addresstype` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(15) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `name_UNIQUE`(`name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'types of address';

CREATE TABLE IF NOT EXISTS `water_audit_logs` (
  `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) USING BTREE,
  INDEX `idx_audit_user`(`user_id`) USING BTREE,
  INDEX `idx_audit_action`(`action`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bill_adjustments` (
  `adjustment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_type` enum('water','one_time','installment','recurring') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `adjustment_type` enum('debit','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(14, 2) NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `journal_voucher_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('draft','posted','reversed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'posted',
  PRIMARY KEY (`adjustment_id`) USING BTREE,
  INDEX `idx_bill_adjustments_bill`(`bill_type`, `bill_id`) USING BTREE,
  INDEX `idx_bill_adjustments_date`(`business_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bill_codes` (
  `code_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `type` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `ar_account` int(11) NULL DEFAULT NULL,
  `income_account` int(11) NULL DEFAULT NULL,
  `cogs_account` int(11) NULL DEFAULT NULL,
  `asset_account` int(11) NULL DEFAULT NULL,
  `cost_price` decimal(10, 2) NULL DEFAULT 0.00,
  `sales_price` decimal(10, 2) NULL DEFAULT 0.00,
  `allow_member_discount` tinyint(1) NOT NULL DEFAULT 0,
  `modified_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`code_id`) USING BTREE,
  UNIQUE INDEX `code`(`code`) USING BTREE,
  INDEX `fk_bill_codes_user`(`modified_by`) USING BTREE,
  INDEX `fk_bc_ar`(`ar_account`) USING BTREE,
  INDEX `fk_bc_inc`(`income_account`) USING BTREE,
  INDEX `fk_bc_cogs`(`cogs_account`) USING BTREE,
  INDEX `fk_bc_ast`(`asset_account`) USING BTREE,
  INDEX `idx_bill_codes_allow_discount`(`allow_member_discount`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `bill_periods` (
  `period_id` int(10) NOT NULL AUTO_INCREMENT,
  `bp_code` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('open','closed') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'open',
  `opened_by` int(11) NOT NULL,
  `closed_by` int(11) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `closed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`period_id`) USING BTREE,
  UNIQUE INDEX `uniq_bp_code`(`bp_code`) USING BTREE,
  INDEX `idx_opened_by`(`opened_by`) USING BTREE,
  INDEX `idx_closed_by`(`closed_by`) USING BTREE,
  INDEX `idx_bill_periods_start_print`(`start_date`, `period_id`) USING BTREE,
  INDEX `idx_bill_periods_date_range`(`start_date`, `end_date`, `status`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `bill_readings` (
  `reading_id` int(11) NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `period_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `prev_reading` int(11) NOT NULL DEFAULT 0,
  `pres_reading` int(11) NOT NULL DEFAULT 0,
  `consumption` int(11) NOT NULL DEFAULT 0,
  `is_billed` tinyint(1) NOT NULL DEFAULT 0,
  `billing_date` date NULL DEFAULT NULL,
  `due_date` date NULL DEFAULT NULL,
  `term_days` int(11) NOT NULL DEFAULT 15,
  `amount_due` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `snapshot_version` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `rate_code_id_snapshot` int(11) NULL DEFAULT NULL,
  `pipe_size_id_snapshot` int(10) UNSIGNED NULL DEFAULT NULL,
  `pipe_size_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `rate_code_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `tariff_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `discount_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `calculation_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `base_charge_snapshot` decimal(14, 2) NULL DEFAULT NULL,
  `discount_amount_snapshot` decimal(14, 2) NULL DEFAULT NULL,
  `snapshot_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `snapshot_created_at` datetime NULL DEFAULT NULL,
  `billing_journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `branch_id` int(11) NULL DEFAULT 0,
  `segment_id` int(11) NULL DEFAULT 0,
  `division_id` int(11) NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `revision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `is_edited_override` tinyint(1) NOT NULL DEFAULT 0,
  `entry_mode` enum('INDIVIDUAL','BATCH') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'INDIVIDUAL',
  `batch_group_id` int(11) NULL DEFAULT NULL,
  `encoded_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`reading_id`) USING BTREE,
  INDEX `ix_bill_readings_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `uq_reading_period_member`(`period_id`, `member_id`) USING BTREE,
  UNIQUE INDEX `idx_global_ref_reading`(`global_ref_number`) USING BTREE,
  INDEX `fk_readings_period`(`period_id`) USING BTREE,
  INDEX `fk_readings_member`(`member_id`) USING BTREE,
  INDEX `idx_bill_readings_member_period`(`member_id`, `period_id`, `is_billed`, `is_paid`) USING BTREE,
  INDEX `idx_bill_readings_member_period_print`(`member_id`, `period_id`) USING BTREE,
  INDEX `idx_bill_readings_lifecycle`(`bill_lifecycle_status`, `member_id`, `period_id`) USING BTREE,
  INDEX `idx_bill_readings_snapshot`(`snapshot_version`, `snapshot_hash`) USING BTREE,
  INDEX `idx_water_collectible_balance`(`member_id`, `is_billed`, `is_paid`, `bill_lifecycle_status`, `due_date`) USING BTREE,
  CONSTRAINT `fk_readings_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_readings_period` FOREIGN KEY (`period_id`) REFERENCES `bill_periods` (`period_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_book_types` (
  `book_type_id` int(11) NOT NULL,
  `book_type_key` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `book_type_name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`book_type_id`) USING BTREE,
  UNIQUE INDEX `book_type_key`(`book_type_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `branches` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `Deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_created` datetime NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_modified` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `name_UNIQUE`(`name`) USING BTREE,
  INDEX `name_idx`(`name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `bussinessprofile` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `address1` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `address2` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `logoName` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `logoImage` mediumblob NULL,
  `telephoneNo` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `faxNo` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `cellularNo` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `sssNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `pagibigNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `philhealthNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `tinNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `website` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `IsBranch` tinyint(4) NOT NULL DEFAULT 0,
  `parentBusProfID` int(11) NOT NULL DEFAULT 0,
  `lockProfile` tinyint(4) NULL DEFAULT NULL,
  `lockDate` datetime NULL DEFAULT NULL,
  `lockPassword` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `dateCreated` datetime NOT NULL,
  `createdBy` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `dateLastModified` datetime NULL DEFAULT NULL,
  `modifiedBy` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table describes the company information';

CREATE TABLE IF NOT EXISTS `legacy_chartofaccntstbl` (
  `AccntID` int(11) NOT NULL,
  `AccntName` varchar(45) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `BaseAccnt` int(11) NULL DEFAULT NULL,
  `ParentAccnt` int(11) NULL DEFAULT NULL,
  `SummaryAccnt` varchar(45) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `Type` int(11) NOT NULL,
  `Description` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `WithSL` tinyint(1) NULL DEFAULT 0,
  `CashFlowType` int(11) NOT NULL DEFAULT 0,
  `CreateTime` datetime NOT NULL,
  `Status` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`AccntID`) USING BTREE,
  INDEX `ParentAccntIdx`(`ParentAccnt`, `CashFlowType`) USING BTREE,
  INDEX `BaseAccntIdx`(`BaseAccnt`, `ParentAccnt`, `CashFlowType`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `cities` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `city` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `province` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `country` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `idx_cities_status_city`(`status`, `city`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this table will hold the address information';

CREATE TABLE IF NOT EXISTS `companyofficers` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `busProfID` int(11) NOT NULL,
  `name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `position` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `contactNo` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `dateCreated` datetime NOT NULL,
  `createdBy` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `dateLastModified` datetime NULL DEFAULT NULL,
  `modifiedBy` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `contactchannels` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `description` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `datecreated` datetime NOT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `idx_contactchannels_status_name`(`status`, `name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'table that stores mode of contact';

CREATE TABLE IF NOT EXISTS `data_import_batches` (
  `batch_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `stored_file_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staged',
  `options_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `row_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `ready_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `error_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `imported_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_ready_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_error_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `payment_imported_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_ready_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_error_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `recurring_imported_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `started_at` datetime NULL DEFAULT NULL,
  `completed_at` datetime NULL DEFAULT NULL,
  `last_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `stage_next_row` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `mapping_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `mapping_version` int(11) NOT NULL DEFAULT 0,
  `reconciliation_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  PRIMARY KEY (`batch_id`) USING BTREE,
  INDEX `idx_data_import_batch_status`(`status`, `created_at`) USING BTREE,
  INDEX `idx_data_import_batch_hash`(`source_sha256`, `import_type`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `data_import_mapping_profiles` (
  `profile_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_name` varchar(80) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `import_type` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'any',
  `version` int(11) NOT NULL DEFAULT 1,
  `options_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int(11) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`profile_id`) USING BTREE,
  UNIQUE INDEX `uq_import_profile_version`(`profile_name`, `import_type`, `version`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `data_import_mapping_rules` (
  `rule_key` varchar(60) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `rule_value` varchar(60) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unresolved',
  `notes` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `confirmed_by` int(11) NULL DEFAULT NULL,
  `confirmed_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`rule_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `data_import_rows` (
  `row_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `row_number` int(10) UNSIGNED NOT NULL,
  `external_account_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `source_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `raw_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issues_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `import_status` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'ready',
  `payment_status` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'not_applicable',
  `recurring_status` varchar(20) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'not_applicable',
  `target_member_id` int(11) NULL DEFAULT NULL,
  `target_bill_id` int(11) NULL DEFAULT NULL,
  `target_payment_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `target_recurring_ids_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `imported_at` datetime NULL DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`row_id`) USING BTREE,
  UNIQUE INDEX `uq_data_import_batch_row`(`batch_id`, `row_number`) USING BTREE,
  INDEX `idx_data_import_primary_queue`(`batch_id`, `import_status`, `row_id`) USING BTREE,
  INDEX `idx_data_import_payment_queue`(`batch_id`, `payment_status`, `row_id`) USING BTREE,
  INDEX `idx_data_import_recurring_queue`(`batch_id`, `recurring_status`, `row_id`) USING BTREE,
  INDEX `idx_data_import_account`(`external_account_no`) USING BTREE,
  INDEX `idx_data_import_source_key`(`source_key`) USING BTREE,
  CONSTRAINT `fk_data_import_rows_batch` FOREIGN KEY (`batch_id`) REFERENCES `data_import_batches` (`batch_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discount_privilege_rules` (
  `discount_rule_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `discount_id` int(10) UNSIGNED NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `module_context` enum('all','water','one_time','installment','recurring') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'all',
  `discount_method` enum('percent','fixed','free_cubic','none') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'percent',
  `discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `max_discount_amount` decimal(10, 2) NULL DEFAULT NULL,
  `max_discount_cubic` int(10) UNSIGNED NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`discount_rule_id`) USING BTREE,
  UNIQUE INDEX `uq_discount_privilege_rule`(`discount_id`, `bill_code_id`, `module_context`) USING BTREE,
  INDEX `idx_dpr_discount`(`discount_id`) USING BTREE,
  INDEX `idx_dpr_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_dpr_active_module`(`is_active`, `module_context`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `discounted_members` (
  `dm_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(10) NOT NULL,
  `discount_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`dm_id`) USING BTREE,
  UNIQUE INDEX `uq_discounted_member_discount`(`member_id`, `discount_id`) USING BTREE,
  INDEX `fk_discounted_members_discount`(`discount_id`) USING BTREE,
  INDEX `idx_discounted_members_member_discount`(`member_id`, `discount_id`) USING BTREE,
  CONSTRAINT `fk_discounted_members_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`discount_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_discounted_members_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discounts` (
  `discount_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `discount_type` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `water_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `water_discount_method` enum('none','percent','fixed','free_cubic') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `water_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `water_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `water_max_discount_cubic` int(10) UNSIGNED NULL DEFAULT NULL,
  `one_time_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `one_time_discount_method` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `one_time_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `one_time_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `installment_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `installment_discount_method` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `installment_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `installment_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `recurring_discount_active` tinyint(1) NOT NULL DEFAULT 0,
  `recurring_discount_method` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `recurring_discount_value` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `recurring_max_discount_amount` decimal(14, 2) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`discount_id`) USING BTREE,
  UNIQUE INDEX `uq_discount_type`(`discount_type`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `global_billing_sequence` (
  `ref_number` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ref_number`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ic_divisions` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `datecreated` datetime NOT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `ic_segments` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `datecreated` datetime NOT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `installment_bills` (
  `installment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12, 2) NOT NULL DEFAULT 1.00,
  `inventory_unit_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `inventory_total_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `payment_mode` enum('monthly','quarterly','semi_annually','annually') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(14, 2) NOT NULL,
  `gross_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `discount_rule_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `term` int(11) NOT NULL,
  `amortization_type` enum('fixed','flexible') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `status` enum('active','completed','cancelled','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `revision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `branch_id` int(11) NULL DEFAULT NULL,
  `segment_id` int(11) NULL DEFAULT NULL,
  `division_id` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`installment_id`) USING BTREE,
  INDEX `ix_installment_bills_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `idx_global_ref_installment`(`global_ref_number`) USING BTREE,
  INDEX `fk_installment_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_inst_discount`(`discount_id`, `discount_rule_id`) USING BTREE,
  INDEX `idx_installment_member_status`(`member_id`, `status`) USING BTREE,
  INDEX `idx_installment_lifecycle`(`bill_lifecycle_status`, `status`, `member_id`) USING BTREE,
  CONSTRAINT `fk_installment_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_installment_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `installment_schedules` (
  `schedule_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `installment_id` int(10) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(14, 2) NOT NULL,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','partial','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'pending',
  `paid_at` datetime NULL DEFAULT NULL,
  `payment_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `marked_by` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  PRIMARY KEY (`schedule_id`) USING BTREE,
  UNIQUE INDEX `uq_installment_due_date`(`installment_id`, `due_date`) USING BTREE,
  INDEX `idx_installment_schedules_installment_status_due`(`installment_id`, `status`, `due_date`) USING BTREE,
  INDEX `fk_installment_marked_operator`(`marked_by`) USING BTREE,
  CONSTRAINT `fk_installment_schedule` FOREIGN KEY (`installment_id`) REFERENCES `installment_bills` (`installment_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `legacy_inventory_adjustments` (
  `adjustment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id` int(10) UNSIGNED NOT NULL,
  `adjustment_date` date NOT NULL,
  `adjustment_type` enum('IN','OUT') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `quantity` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`adjustment_id`) USING BTREE,
  INDEX `idx_inv_adj_code`(`code_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `inventory_issuance_items` (
  `issuance_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_id` int(10) UNSIGNED NOT NULL,
  `code_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12, 2) NOT NULL,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `asset_account` int(11) NOT NULL,
  PRIMARY KEY (`issuance_item_id`) USING BTREE,
  UNIQUE INDEX `uq_issuance_item`(`issuance_id`, `code_id`) USING BTREE,
  INDEX `idx_issuance_item_code`(`code_id`) USING BTREE,
  INDEX `idx_issuance_item_asset`(`asset_account`) USING BTREE,
  CONSTRAINT `fk_issuance_item_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_issuance_item_header` FOREIGN KEY (`issuance_id`) REFERENCES `inventory_issuances` (`issuance_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `inventory_issuances` (
  `issuance_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `issuance_date` date NOT NULL,
  `recipient` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `department` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `purpose` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `journal_head_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reversal_journal_head_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reversal_journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('POSTED','REVERSED') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'POSTED',
  `reversal_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reversed_at` datetime NULL DEFAULT NULL,
  `reversed_by` int(11) NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`issuance_id`) USING BTREE,
  UNIQUE INDEX `uq_inventory_issuance_no`(`issuance_no`) USING BTREE,
  INDEX `idx_inventory_issuance_date`(`issuance_date`) USING BTREE,
  INDEX `idx_inventory_issuance_status`(`status`) USING BTREE,
  INDEX `idx_inventory_issuance_expense`(`expense_account_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_inventory_purchase_items` (
  `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` int(10) UNSIGNED NOT NULL,
  `code_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `asset_account` int(11) NOT NULL,
  PRIMARY KEY (`item_id`) USING BTREE,
  INDEX `idx_purchase_item_purchase`(`purchase_id`) USING BTREE,
  INDEX `idx_purchase_item_code`(`code_id`) USING BTREE,
  INDEX `idx_purchase_item_asset`(`asset_account`) USING BTREE,
  CONSTRAINT `fk_purchase_item_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_inventory_purchases` (
  `purchase_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_no` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `supplier_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `purchase_date` date NOT NULL,
  `payment_type` enum('Cash','Credit') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Cash',
  `payment_account` int(11) NOT NULL,
  `remarks` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `total_amount` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `void_journal_head_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `void_journal_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` enum('Posted','Voided') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Posted',
  `branch_id` int(11) NULL DEFAULT 0,
  `segment_id` int(11) NULL DEFAULT 0,
  `division_id` int(11) NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`purchase_id`) USING BTREE,
  UNIQUE INDEX `uq_purchase_no`(`purchase_no`) USING BTREE,
  INDEX `idx_purchase_supplier`(`supplier_id`) USING BTREE,
  INDEX `idx_purchase_payment_account`(`payment_account`) USING BTREE,
  INDEX `idx_purchase_journal`(`journal_head_id`, `journal_entry_id`) USING BTREE,
  INDEX `idx_purchase_void_journal`(`void_journal_head_id`, `void_journal_entry_id`) USING BTREE,
  INDEX `idx_purchase_created_by`(`created_by`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_inventory_reorder_levels` (
  `code_id` int(10) UNSIGNED NOT NULL,
  `reorder_level` decimal(12, 2) NOT NULL DEFAULT 5.00,
  `updated_by` int(11) NULL DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`code_id`) USING BTREE,
  CONSTRAINT `fk_reorder_bill_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_inventory_stock_movements` (
  `movement_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id` int(10) UNSIGNED NOT NULL,
  `ref_type` enum('PURCHASE','VOID_PURCHASE','SALE','ADJUSTMENT','ISSUANCE','VOID_ISSUANCE') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `ref_id` int(10) UNSIGNED NOT NULL,
  `movement_date` date NOT NULL,
  `qty_in` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `qty_out` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12, 2) NOT NULL DEFAULT 0.00,
  `movement_value` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`movement_id`) USING BTREE,
  INDEX `idx_stock_code`(`code_id`) USING BTREE,
  INDEX `idx_stock_ref`(`ref_type`, `ref_id`) USING BTREE,
  INDEX `idx_stock_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_stock_code` FOREIGN KEY (`code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `jointmember` (
  `pkey` int(11) NOT NULL,
  `Name` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `accountno` int(11) NULL DEFAULT NULL,
  `Status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_journal_voucher_voids` (
  `void_id` int(11) NOT NULL AUTO_INCREMENT,
  `original_jvid` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `original_jv_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `replacement_jvid` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `replacement_jv_entry_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `trans_ref_id` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `source_type` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'bill_edit',
  `source_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `voided_amount` decimal(20, 6) NOT NULL DEFAULT 0.000000,
  `voided_line_count` int(11) NOT NULL DEFAULT 0,
  `voided_by` int(11) NULL DEFAULT NULL,
  `voided_at` datetime NOT NULL,
  PRIMARY KEY (`void_id`) USING BTREE,
  UNIQUE INDEX `uq_jv_void_original`(`original_jvid`) USING BTREE,
  INDEX `idx_jv_void_replacement`(`replacement_jvid`) USING BTREE,
  INDEX `idx_jv_void_source`(`source_type`, `source_id`) USING BTREE,
  INDEX `idx_jv_void_ref`(`trans_ref_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_journalentries` (
  `JournalID` int(11) NOT NULL AUTO_INCREMENT,
  `JournalHeadID` varchar(37) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `TransRefID` varchar(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `AccountID` int(11) NOT NULL,
  `Debit` decimal(20, 6) NULL DEFAULT 0.000000,
  `Credit` decimal(20, 6) NULL DEFAULT 0.000000,
  `Description` varchar(200) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `SLType` int(11) NULL DEFAULT NULL,
  `SLCode` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `BookType` int(11) NULL DEFAULT NULL,
  `IsJournalHead` tinyint(1) NOT NULL DEFAULT 0,
  `TransactionDate` datetime NULL DEFAULT NULL,
  `IsPosted` tinyint(1) NULL DEFAULT 0,
  `Status` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'A',
  `entryType` enum('NORMAL','CLOSING') CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'NORMAL',
  `BranchID` int(11) NULL DEFAULT NULL,
  `SegmentID` int(11) NULL DEFAULT NULL,
  `DivisionID` int(11) NULL DEFAULT NULL,
  PRIMARY KEY (`JournalID`, `JournalHeadID`) USING BTREE,
  INDEX `DateIdx`(`TransactionDate`) USING BTREE,
  INDEX `AccountsIdx`(`AccountID`, `TransactionDate`, `JournalHeadID`) USING BTREE,
  INDEX `SubsidiaryIdx`(`SLType`, `SLCode`, `TransactionDate`, `AccountID`) USING BTREE,
  INDEX `TransRefIdx`(`TransRefID`, `Status`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `legacy_journalvoucher` (
  `JVID` varchar(37) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `JVEntryID` varchar(32) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `JVTransDate` datetime NOT NULL COMMENT 'Journal Date',
  `JVEntryDate` datetime NOT NULL COMMENT 'Date the journal was entered or created',
  `JVDateLastUpdated` datetime NOT NULL,
  `Status` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT 'A',
  `BranchID` tinyint(4) NULL DEFAULT NULL,
  `SegmentID` tinyint(4) NULL DEFAULT NULL,
  `DivisionID` tinyint(4) NULL DEFAULT NULL,
  PRIMARY KEY (`JVID`) USING BTREE,
  UNIQUE INDEX `JournalEntryID_UNIQUE`(`JVEntryID`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `manual_disconnection_logs` (
  `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bill_source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `bill_source_id` int(11) NULL DEFAULT NULL,
  `amount_due` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `due_date` date NULL DEFAULT NULL,
  `disconnection_date` date NULL DEFAULT NULL,
  `previous_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `new_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `action_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `action_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`) USING BTREE,
  INDEX `idx_mdl_member`(`member_id`) USING BTREE,
  INDEX `idx_mdl_action`(`action`) USING BTREE,
  INDEX `idx_mdl_source`(`bill_source_type`, `bill_source_id`) USING BTREE,
  INDEX `idx_mdl_action_at`(`action_at`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `memberaddress` (
  `member_key` int(11) NOT NULL,
  `address_key` int(11) NOT NULL,
  `dtaddressFrom` date NOT NULL,
  `address_type_key` int(11) NULL DEFAULT NULL,
  `dtaddressto` date NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`member_key`, `address_key`, `dtaddressFrom`) USING BTREE,
  INDEX `FK_Member_ID_idx`(`member_key`) USING BTREE,
  INDEX `FK_Address_ID_idx`(`address_key`) USING BTREE,
  INDEX `FK_Address_Type_idx`(`address_type_key`) USING BTREE,
  INDEX `idx_memberaddress_status_type_to`(`member_key`, `status`, `address_type_key`, `dtaddressto`) USING BTREE,
  INDEX `idx_memberaddress_member_status_type`(`member_key`, `status`, `address_type_key`) USING BTREE,
  CONSTRAINT `FK_Address_ID` FOREIGN KEY (`address_key`) REFERENCES `addresses` (`pkey`) ON DELETE RESTRICT ON UPDATE NO ACTION,
  CONSTRAINT `FK_Address_Type` FOREIGN KEY (`address_type_key`) REFERENCES `addresstype` (`pkey`) ON DELETE RESTRICT ON UPDATE NO ACTION
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this holds the member address information';

CREATE TABLE IF NOT EXISTS `membercontactchannels` (
  `conchannelkey` int(11) NOT NULL,
  `memberkey` int(11) NOT NULL,
  `activefromdate` date NOT NULL,
  `activetodate` date NULL DEFAULT NULL,
  `contactdata` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  PRIMARY KEY (`activefromdate`, `memberkey`, `conchannelkey`) USING BTREE,
  INDEX `idx_mcc_member_status_to`(`memberkey`, `status`, `activetodate`) USING BTREE,
  INDEX `idx_mcc_contactdata`(`contactdata`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'this table store member contact information';

CREATE TABLE IF NOT EXISTS `water_accounts` (
  `pkey` int(11) NOT NULL,
  `salutation` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `lastname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `firstname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `middlename` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `dateofbirth` date NULL DEFAULT NULL,
  `placeofbirth` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `tinno` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `gender` enum('Male','Female') CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `civilstatus` enum('Single','Married','Widow','Widower','Separated') CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `education` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `courselevel` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `yeargraduated` year(4) NULL DEFAULT NULL,
  `occupation` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `affiliatedOrg` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `datecreated` datetime NULL DEFAULT NULL,
  `datelastmodified` datetime NULL DEFAULT NULL,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'A',
  `account_number` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `zone` int(11) NULL DEFAULT NULL,
  `meter_sequence` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `rc_id` int(11) NULL DEFAULT NULL,
  `pipe_size_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `wmdiscount_id` int(11) NULL DEFAULT NULL,
  `connection_status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `disconnected_at` datetime NULL DEFAULT NULL,
  `disconnected_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `disconnection_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `reconnected_at` datetime NULL DEFAULT NULL,
  `reconnected_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `reconnection_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `address_subgroup` varchar(60) NULL DEFAULT NULL,
  `connection_date` date NULL DEFAULT NULL COMMENT 'the date the connection started',
  PRIMARY KEY (`pkey`) USING BTREE,
  INDEX `ix_water_accounts_subgroup`(`address_subgroup`) USING BTREE,
  UNIQUE INDEX `account_number`(`account_number`) USING BTREE,
  INDEX `idx_members_rc_status`(`rc_id`, `status`) USING BTREE,
  INDEX `idx_members_status_name`(`status`, `lastname`, `firstname`) USING BTREE,
  INDEX `idx_members_zone`(`zone`) USING BTREE,
  INDEX `idx_members_gender`(`gender`) USING BTREE,
  INDEX `idx_members_civilstatus`(`civilstatus`) USING BTREE,
  INDEX `idx_members_status_zone_name`(`status`, `zone`, `lastname`, `firstname`) USING BTREE,
  INDEX `idx_members_meter_sequence`(`meter_sequence`) USING BTREE,
  INDEX `idx_members_pipe_size`(`pipe_size_id`) USING BTREE,
  CONSTRAINT `fk_members_pipe_size` FOREIGN KEY (`pipe_size_id`) REFERENCES `water_pipe_sizes` (`pipe_size_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_members_rate_code` FOREIGN KEY (`rc_id`) REFERENCES `rate_code` (`rc_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table hold information of members.';

CREATE TABLE IF NOT EXISTS `membershipinfo` (
  `memberID` int(11) NOT NULL,
  `membershipDate` date NOT NULL,
  `membershipStatus` tinyint(4) NOT NULL,
  `membershipType` tinyint(4) NOT NULL,
  `clusterKey` int(11) NULL DEFAULT NULL,
  `segmentationKey` int(11) NULL DEFAULT NULL,
  `pmesDate` date NULL DEFAULT NULL,
  `passbook` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `dateCreated` datetime NULL DEFAULT NULL,
  `dateLastModified` datetime NULL DEFAULT NULL,
  `createdBy` varchar(36) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `modifiedBy` varchar(36) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` varchar(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A',
  `memberName` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`memberID`) USING BTREE,
  UNIQUE INDEX `passbook_UNIQUE`(`passbook`) USING BTREE,
  INDEX `cluster_idx`(`clusterKey`, `status`) USING BTREE,
  INDEX `segment_idx`(`segmentationKey`, `status`) USING BTREE,
  INDEX `status_idx`(`status`) USING BTREE,
  INDEX `membership_idx`(`membershipDate`, `membershipStatus`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `one_time_bills` (
  `bill_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12, 2) NOT NULL DEFAULT 1.00,
  `inventory_unit_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `inventory_total_cost` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `bill_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `term_days` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(14, 2) NOT NULL,
  `gross_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `discount_rule_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','paid','cancelled','deleted') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unpaid',
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `revision_note` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `source_type` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `source_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `source_billing_date` date NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `branch_id` int(11) NULL DEFAULT NULL,
  `segment_id` int(11) NULL DEFAULT NULL,
  `division_id` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  INDEX `ix_one_time_bills_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `idx_global_ref_onetime`(`global_ref_number`) USING BTREE,
  UNIQUE INDEX `uq_one_time_recurring_source`(`source_type`, `source_id`, `source_billing_date`) USING BTREE,
  INDEX `member_id`(`member_id`) USING BTREE,
  INDEX `bill_code_id`(`bill_code_id`) USING BTREE,
  INDEX `created_by`(`created_by`) USING BTREE,
  INDEX `idx_otb_discount`(`discount_id`, `discount_rule_id`) USING BTREE,
  INDEX `idx_one_time_member_status_due`(`member_id`, `status`, `due_date`) USING BTREE,
  INDEX `idx_one_time_lifecycle`(`bill_lifecycle_status`, `status`, `member_id`) USING BTREE,
  CONSTRAINT `fk_one_time_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_one_time_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_operator` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `accesslevel` tinyint(4) NOT NULL,
  `description` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `relatedkey` int(11) NULL DEFAULT NULL,
  `isSystem` tinyint(4) NOT NULL DEFAULT 0,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_created` datetime NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_modified` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `operator_notification_reads` (
  `operator_id` int(11) NOT NULL,
  `notification_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`operator_id`, `notification_key`) USING BTREE,
  INDEX `idx_notification_read_at`(`read_at`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_operator_profile` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `operatorID` int(11) NOT NULL,
  `profID` int(11) NOT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `uq_operator_profile_assignment`(`operatorID`, `profID`) USING BTREE,
  INDEX `operator_idx`(`operatorID`) USING BTREE,
  INDEX `profile_idx`(`profID`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_operator_profile_page_permissions` (
  `operator_profile_id` int(11) NOT NULL,
  `page_key` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `can_access` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by_operator_id` int(11) NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`operator_profile_id`, `page_key`) USING BTREE,
  INDEX `idx_opp_page`(`page_key`) USING BTREE,
  INDEX `idx_opp_granted_by`(`granted_by_operator_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `payment_adjustments` (
  `adjustment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `adjustment_type` enum('reversal','refund') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(14, 2) NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` enum('pending','completed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  PRIMARY KEY (`adjustment_id`) USING BTREE,
  INDEX `idx_payment_adjustments_transaction`(`transaction_id`) USING BTREE,
  CONSTRAINT `fk_payment_adjustment_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`transaction_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_transaction_items` (
  `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `source_type` enum('water','installment','onetime','recurring','sharecap') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `due_date` date NULL DEFAULT NULL,
  `amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `principal_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `balance_before` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `allocation_status` enum('active','reversed','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`item_id`) USING BTREE,
  UNIQUE INDEX `uq_payment_transaction_source`(`transaction_id`, `source_type`, `source_id`) USING BTREE,
  INDEX `idx_pti_transaction`(`transaction_id`) USING BTREE,
  INDEX `idx_pti_source`(`source_type`, `source_id`) USING BTREE,
  INDEX `idx_pti_active_source`(`source_type`, `source_id`, `allocation_status`) USING BTREE,
  CONSTRAINT `fk_pti_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`transaction_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `transaction_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` int(11) NOT NULL,
  `payment_type` enum('water','installment','onetime','recurring','mixed','sharecap') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `compromise_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `share_capital_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `tendered_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `receipt_print_count` int(11) NOT NULL DEFAULT 0,
  `last_printed_at` datetime NULL DEFAULT NULL,
  `cashier_id` int(10) UNSIGNED NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `business_date` date NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `transaction_status` enum('active','reversed','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `idempotency_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `branch_id` int(11) NULL DEFAULT 0,
  `segment_id` int(11) NULL DEFAULT 0,
  `division_id` int(11) NULL DEFAULT 0,
  `journal_head_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `journal_entry_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `journal_status` enum('draft','posted','reversed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `reversed_at` datetime NULL DEFAULT NULL,
  `reversed_by` int(11) NULL DEFAULT NULL,
  `reversal_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `reversal_journal_voucher_id` varchar(37) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `cash_tendered` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`transaction_id`) USING BTREE,
  INDEX `ix_payment_transactions_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `idx_receipt_no`(`receipt_no`) USING BTREE,
  UNIQUE INDEX `uq_payment_idempotency`(`idempotency_key`) USING BTREE,
  INDEX `idx_member`(`member_id`) USING BTREE,
  INDEX `idx_cashier`(`cashier_id`) USING BTREE,
  INDEX `idx_payment_transactions_receipt`(`receipt_no`) USING BTREE,
  INDEX `idx_payment_transactions_date`(`payment_date`) USING BTREE,
  INDEX `idx_payment_transactions_member_date`(`member_id`, `payment_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `legacy_postingmethod` (
  `pkey` int(11) NOT NULL,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `status` char(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table holds the records of all postings types';

CREATE TABLE IF NOT EXISTS `legacy_profile` (
  `profID` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `typeid` int(11) NOT NULL,
  `groupid` int(11) NOT NULL,
  `cashGL` int(11) NOT NULL DEFAULT 0,
  `issystem` tinyint(4) NOT NULL DEFAULT 0,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_created` datetime NULL DEFAULT NULL,
  `modifiedby` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `date_modified` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`profID`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `rate_code` (
  `rc_id` int(11) NOT NULL AUTO_INCREMENT,
  `rc_code` char(1) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `rc_name` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `billing_method` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'straight',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rc_id`) USING BTREE,
  INDEX `idx_rate_code_code`(`rc_code`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `recurring_bill_payments` (
  `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `remarks` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`payment_id`) USING BTREE,
  INDEX `idx_recurring_payment_bill`(`bill_id`) USING BTREE,
  INDEX `idx_recurring_payment_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_recurring_payment_bill` FOREIGN KEY (`bill_id`) REFERENCES `recurring_bills` (`bill_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `recurring_bills` (
  `bill_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `global_ref_number` int(10) UNSIGNED NULL DEFAULT NULL,
  `journal_voucher_id` varchar(37) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `subscription_id` int(10) UNSIGNED NOT NULL,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(11) NOT NULL,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `billing_period_start` date NOT NULL,
  `billing_period_end` date NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date NOT NULL,
  `term_days` int(11) NOT NULL DEFAULT 15,
  `total_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `discount_rule_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `amount_paid` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `penalty_amount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `balance` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','paid','overdue','cancelled','deleted') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'unpaid',
  `bill_lifecycle_status` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `bill_edit_count` int(11) NOT NULL DEFAULT 0,
  `last_bill_edited_at` datetime NULL DEFAULT NULL,
  `last_bill_edited_by` int(11) NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(11) NULL DEFAULT NULL,
  `delete_reason` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `revision_note` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `last_adjustment_journal_voucher_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_adjustment_jv_entry_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `last_accounting_adjusted_at` datetime NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `branch_id` int(11) NULL DEFAULT NULL,
  `segment_id` int(11) NULL DEFAULT NULL,
  `division_id` int(11) NULL DEFAULT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  `disconnection_date` date NULL DEFAULT NULL,
  `class_id` int(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  INDEX `ix_recurring_bills_class`(`class_id`) USING BTREE,
  UNIQUE INDEX `uniq_recurring_subscription_bill_date`(`subscription_id`, `bill_date`) USING BTREE,
  UNIQUE INDEX `uniq_recurring_global_ref`(`global_ref_number`) USING BTREE,
  INDEX `idx_recurring_bill_subscription`(`subscription_id`) USING BTREE,
  INDEX `idx_recurring_bill_plan`(`plan_id`) USING BTREE,
  INDEX `idx_recurring_bill_member`(`member_id`) USING BTREE,
  INDEX `idx_recurring_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_recurring_bill_due_status`(`status`, `due_date`) USING BTREE,
  INDEX `idx_recurring_bill_created_by`(`created_by`) USING BTREE,
  INDEX `idx_recur_discount`(`discount_id`, `discount_rule_id`) USING BTREE,
  INDEX `idx_recurring_bills_member_status_due`(`member_id`, `status`, `due_date`) USING BTREE,
  INDEX `idx_recurring_lifecycle`(`bill_lifecycle_status`, `status`, `member_id`) USING BTREE,
  CONSTRAINT `fk_recurring_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_bill_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_bill_plan` FOREIGN KEY (`plan_id`) REFERENCES `recurring_plans` (`plan_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_bill_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `recurring_subscriptions` (`subscription_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `recurring_plans` (
  `plan_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_code_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `frequency` enum('Monthly','Quarterly','Semi-Annually','Yearly') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Monthly',
  `first_billing_date` date NOT NULL,
  `term_days` int(11) NOT NULL DEFAULT 15,
  `status` enum('active','inactive') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `disconnection_days` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`plan_id`) USING BTREE,
  INDEX `idx_recurring_plan_bill_code`(`bill_code_id`) USING BTREE,
  INDEX `idx_recurring_plan_status`(`status`) USING BTREE,
  INDEX `idx_recurring_plan_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_recurring_plan_bill_code` FOREIGN KEY (`bill_code_id`) REFERENCES `bill_codes` (`code_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `recurring_subscriptions` (
  `subscription_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(11) NOT NULL,
  `subscription_start_date` date NOT NULL,
  `next_billing_date` date NOT NULL,
  `last_billed_date` date NULL DEFAULT NULL,
  `status` enum('active','paused','ended') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`subscription_id`) USING BTREE,
  INDEX `idx_recurring_sub_plan`(`plan_id`) USING BTREE,
  INDEX `idx_recurring_sub_member`(`member_id`) USING BTREE,
  INDEX `idx_recurring_sub_due`(`status`, `next_billing_date`) USING BTREE,
  INDEX `idx_recurring_sub_created_by`(`created_by`) USING BTREE,
  CONSTRAINT `fk_recurring_sub_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_recurring_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `recurring_plans` (`plan_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `setup_tutorial_progress` (
  `operator_id` int(11) NOT NULL,
  `operator_profile_id` int(11) NOT NULL DEFAULT 0,
  `requirement_key` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `state` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `first_seen_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`operator_id`, `requirement_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_subsidiaryledger` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `slcode` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `sltype` tinyint(4) NOT NULL,
  `slname` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `pkey_UNIQUE`(`pkey`) USING BTREE,
  INDEX `slcode_type_idx`(`slcode`, `sltype`, `isdeleted`) USING BTREE,
  INDEX `slName_idx`(`slname`, `isdeleted`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_suppliers` (
  `supplier_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `contact_person` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `contact_number` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `address` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL,
  `status` enum('Active','Inactive') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`supplier_id`) USING BTREE,
  UNIQUE INDEX `uq_supplier_name`(`supplier_name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;

-- Fixed Share Capital Billing (migrations 2027_03_14 and 2027_03_21): the plans, the bills (no journal entry) and their collections
CREATE TABLE IF NOT EXISTS `share_capital_plans` (
  `plan_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(10) UNSIGNED NOT NULL,
  `sav_account_id` int(10) UNSIGNED NOT NULL,
  `frequency` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `posting_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `term_days` smallint(5) UNSIGNED NULL DEFAULT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `batch_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `next_bill_date` date NULL DEFAULT NULL,
  `last_bill_date` date NULL DEFAULT NULL,
  `hold_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `held_at` datetime NULL DEFAULT NULL,
  `held_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `deleted_at` datetime NULL DEFAULT NULL,
  `deleted_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `delete_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `updated_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`plan_id`) USING BTREE,
  INDEX `ix_scp_member`(`member_id`) USING BTREE,
  INDEX `ix_scp_account`(`sav_account_id`) USING BTREE,
  INDEX `ix_scp_due`(`status`, `next_bill_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `share_capital_bills` (
  `bill_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `sav_account_id` int(10) UNSIGNED NOT NULL,
  `bill_date` date NOT NULL,
  `due_date` date NULL DEFAULT NULL,
  `term_days` smallint(5) UNSIGNED NULL DEFAULT NULL,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime NULL DEFAULT NULL,
  `cancelled_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  UNIQUE INDEX `uq_scb_plan_date`(`plan_id`, `bill_date`) USING BTREE,
  INDEX `ix_scb_member_status`(`member_id`, `status`) USING BTREE,
  INDEX `ix_scb_date`(`bill_date`) USING BTREE,
  INDEX `ix_scb_due`(`status`, `due_date`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `share_capital_collections` (
  `collection_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bill_id` int(10) UNSIGNED NOT NULL,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `receipt_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `sav_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `ledger_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `trans_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `reversal_sav_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `reversal_ledger_txn_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  `reversed_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`collection_id`) USING BTREE,
  INDEX `ix_scc_bill`(`bill_id`) USING BTREE,
  INDEX `ix_scc_transaction`(`transaction_id`) USING BTREE,
  INDEX `ix_scc_receipt`(`receipt_no`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Upload Bill Balances (migration 2027_04_18): the uploads of the open balances of the bills and their lines
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

CREATE TABLE IF NOT EXISTS `syssequence` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT COMMENT 'primary key for the sequence',
  `SeqName` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'Name of the sequence',
  `SeqDesc` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'detailed description for which this sequence applies',
  `SeqType` int(11) NOT NULL COMMENT 'numerice or alpha numeric sequence',
  `SeqPrefixCode` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL COMMENT 'Prefix code for the sequence number',
  `SeqCurValue` varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT 'current value for the sequence',
  `SeqStep` int(11) NOT NULL COMMENT 'number of step per iteration',
  `SeqStatus` varchar(2) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'A' COMMENT 'A-Active or I-Inactive',
  PRIMARY KEY (`pkey`) USING BTREE,
  UNIQUE INDEX `SeqName_UNIQUE`(`SeqName`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = 'This table holds the sequence numbering for transaction and records';

CREATE TABLE IF NOT EXISTS `water_billing_settings` (
  `setting_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `setting_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`setting_key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `water_meter_discounted_members` (
  `wmdm_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `wmdiscount_id` int(11) NOT NULL,
  `assigned_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  PRIMARY KEY (`wmdm_id`) USING BTREE,
  UNIQUE INDEX `unique_member_discount`(`member_id`) USING BTREE,
  UNIQUE INDEX `uq_water_discount_member`(`member_id`) USING BTREE,
  INDEX `fk_wmdm_discount`(`wmdiscount_id`) USING BTREE,
  CONSTRAINT `fk_wmdm_discount` FOREIGN KEY (`wmdiscount_id`) REFERENCES `water_meter_discounts` (`wmdiscount_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_wmdm_member` FOREIGN KEY (`member_id`) REFERENCES `water_accounts` (`pkey`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `water_meter_discounts` (
  `wmdiscount_id` int(11) NOT NULL AUTO_INCREMENT,
  `wmd_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'e.g., Senior Citizen, Board of Directors, Secretary',
  `free_water_m3` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `percent_discount` decimal(7, 4) NOT NULL DEFAULT 0.0000,
  `fixed_discount` decimal(14, 2) NOT NULL DEFAULT 0.00,
  `active_discount` enum('none','percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'none',
  `max_m3_for_discount` int(10) UNSIGNED NULL DEFAULT NULL,
  `created_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`wmdiscount_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `water_meter_members` (
  `wmm_id` int(11) NOT NULL AUTO_INCREMENT,
  `rc_id` int(11) NOT NULL COMMENT 'Links to rate_code (Member Type)',
  `pipe_size_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `bill_code_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `from_cb` int(11) NOT NULL,
  `to_cb` int(11) NULL DEFAULT NULL COMMENT 'NULL means Infinity',
  `amount` decimal(14, 4) NOT NULL,
  `bill_type` enum('FIXED','VARIABLE') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'VARIABLE',
  `created_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`wmm_id`) USING BTREE,
  UNIQUE INDEX `uq_water_rate_pipe_start`(`rc_id`, `pipe_size_id`, `from_cb`) USING BTREE,
  INDEX `idx_wmm_bill_code_id`(`bill_code_id`) USING BTREE,
  INDEX `idx_wmm_rc_pipe_range`(`rc_id`, `pipe_size_id`, `from_cb`, `to_cb`) USING BTREE,
  INDEX `fk_wmm_pipe_size`(`pipe_size_id`) USING BTREE,
  CONSTRAINT `fk_wmm_pipe_size` FOREIGN KEY (`pipe_size_id`) REFERENCES `water_pipe_sizes` (`pipe_size_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `water_pipe_sizes` (
  `pipe_size_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `size_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `size_label` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `diameter_inches` decimal(8, 4) NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`pipe_size_id`) USING BTREE,
  UNIQUE INDEX `uq_water_pipe_size_code`(`size_code`) USING BTREE,
  UNIQUE INDEX `uq_water_pipe_size_label`(`size_label`) USING BTREE,
  INDEX `idx_water_pipe_size_active_diameter`(`is_active`, `diameter_inches`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `water_rates` (
  `rate_id` int(11) NOT NULL AUTO_INCREMENT,
  `rc_id` int(11) NOT NULL DEFAULT 1,
  `discount_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `from_cb` int(11) NOT NULL,
  `to_cb` int(11) NULL DEFAULT NULL COMMENT 'NULL signifies Unlimited / Infinity',
  `amount` decimal(10, 2) NOT NULL,
  `bill_type` enum('FIXED','VARIABLE') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'VARIABLE',
  `free_m3_water_rate` int(11) NOT NULL DEFAULT 0,
  `percent_discount_water_rate` decimal(5, 2) NOT NULL DEFAULT 0.00,
  `fixed_discount_water_rate` decimal(10, 2) NOT NULL DEFAULT 0.00,
  `max_m3_water_rate` int(11) NULL DEFAULT NULL,
  `active_discount_type` enum('percent','fixed','none') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'none',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`rate_id`) USING BTREE,
  INDEX `fk_wr_discount`(`discount_id`) USING BTREE,
  CONSTRAINT `fk_wr_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`discount_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB CHARACTER SET = utf8 COLLATE = utf8_general_ci;


-- the former program's legacy cooperative savings register (savings accounts and their transactions, carried in the
-- water database from the earlier cooperative system): read-only history. The balances are brought into the
-- cooperative savings module only by tools/migrate_water.php --phase=membership --import-membership (see
-- docs/WATER_MIGRATION.md, "Legacy cooperative membership and savings").
CREATE TABLE IF NOT EXISTS `legacy_savingsaccounts` (
  `accountno` bigint(20) NOT NULL AUTO_INCREMENT,
  `memberid` int(11) NOT NULL,
  `savingsid` int(11) NOT NULL,
  `branchid` int(11) NOT NULL,
  `unitsplan` int(11) DEFAULT NULL,
  `ammountperunit` decimal(15,10) DEFAULT NULL,
  `maturitydate` date DEFAULT NULL,
  `applicationdate` date NOT NULL,
  `checkedby` int(11) DEFAULT NULL COMMENT 'user ID of the teller who included the savings account',
  `approvedby` int(11) DEFAULT NULL COMMENT 'user id of the manager who approved the savings.',
  `dateapproved` date DEFAULT NULL,
  `lasttransdate` date DEFAULT NULL COMMENT 'the last date of activity for this particular savings account, this will be usefull in determining if the savings account is dormant',
  `withdrawabledate` date DEFAULT NULL,
  `curpassbookprintline` tinyint(4) NOT NULL DEFAULT 0,
  `passbooktype` int(11) NOT NULL,
  `status` enum('CLOSED','PENDING','ACTIVE','DORMANT','WITHDRAWN') NOT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `createdby` varchar(45) DEFAULT NULL,
  `modifiedby` varchar(45) DEFAULT NULL,
  `datecreated` datetime DEFAULT NULL,
  `datelastmodified` datetime DEFAULT NULL,
  `closeApprovedBy` int(11) DEFAULT NULL,
  `dateClosed` datetime DEFAULT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `hasRestriction` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`accountno`) USING BTREE,
  KEY `memberID_idx` (`memberid`) USING BTREE,
  KEY `savingsID_idx` (`savingsid`) USING BTREE,
  KEY `branchId_idx` (`branchid`) USING BTREE,
  KEY `fkeybranchID_idx` (`branchid`) USING BTREE,
  KEY `savAccount_idx` (`branchid`,`memberid`,`savingsid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_savaccnttransaction` (
  `savtransHeadID` varchar(37) NOT NULL,
  `savtransEntryID` varchar(32) NOT NULL,
  `savaccountno` bigint(20) NOT NULL,
  `savtransEntryDate` datetime NOT NULL,
  `savtransTransDate` datetime NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `savTransCode` int(11) NOT NULL,
  `memo` varchar(200) DEFAULT NULL,
  `sessionid` varchar(37) NOT NULL,
  `isprinted` tinyint(4) NOT NULL DEFAULT 0,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  `operatorkey` int(11) DEFAULT NULL,
  `TDCertNo` varchar(20) DEFAULT NULL,
  `errTransRefNo` varchar(37) DEFAULT NULL,
  PRIMARY KEY (`savtransHeadID`) USING BTREE,
  KEY `fk_savaccount_idx` (`savaccountno`) USING BTREE,
  KEY `fk_savTransCode_idx` (`savTransCode`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_savingstranscode` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `transcode` varchar(10) DEFAULT NULL,
  `description` varchar(100) DEFAULT NULL,
  `journalside` enum('DEBIT','CREDIT') DEFAULT NULL,
  `oppAccountType` enum('GLTYPE','USERTYPE','SAVINGSTYPE') DEFAULT NULL,
  `oppAccountID` int(11) DEFAULT NULL,
  `relatedcode` varchar(10) DEFAULT NULL,
  `iserrorcorrect` tinyint(4) DEFAULT NULL,
  `IsCheckDeposit` tinyint(4) DEFAULT NULL,
  `checkClearingDays` int(11) DEFAULT NULL,
  `createdby` varchar(45) DEFAULT NULL,
  `modifiedby` varchar(45) DEFAULT NULL,
  `datecreated` datetime DEFAULT NULL,
  `datelastmodified` datetime DEFAULT NULL,
  `status` varchar(1) DEFAULT 'A',
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_savingsinterest` (
  `IntKey` int(11) NOT NULL AUTO_INCREMENT,
  `savingskey` int(11) NOT NULL,
  `Inttype` int(11) NOT NULL,
  `datafrom` int(15) NOT NULL,
  `datato` int(15) NOT NULL,
  `interestrate` decimal(5,2) NOT NULL,
  `postingMethod` int(11) NOT NULL,
  `effectivedate` date NOT NULL,
  `status` char(1) NOT NULL DEFAULT 'A',
  PRIMARY KEY (`IntKey`,`savingskey`) USING BTREE,
  KEY `fk_savingsId_idx` (`savingskey`) USING BTREE,
  KEY `fk_posting_idx` (`postingMethod`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='this table holds records of interest rates configure per savings, each savings product can hold 1 or many interest rates';

CREATE TABLE IF NOT EXISTS `legacy_savingsaccount_param` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `savsaccountno` bigint(20) NOT NULL,
  `paramname` varchar(45) NOT NULL,
  `paramvalue` varchar(500) DEFAULT NULL,
  `relatedparamkey` int(11) DEFAULT NULL,
  PRIMARY KEY (`pkey`) USING BTREE,
  KEY `frelatedparamkey_idx` (`relatedparamkey`) USING BTREE,
  KEY `fkeysavAccountno_idx` (`savsaccountno`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `legacy_savingsrestrictions` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(11) NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `savaccountNo` bigint(20) NOT NULL,
  `sessionID` varchar(45) NOT NULL,
  `approvedby` varchar(45) NOT NULL,
  `operatorkey` varchar(45) NOT NULL,
  `createdby` varchar(45) DEFAULT NULL,
  `modifiedby` varchar(45) DEFAULT NULL,
  `datecreated` datetime DEFAULT NULL,
  `datemodified` datetime DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`pkey`) USING BTREE,
  KEY `fk_savaccountNo_idx` (`savaccountNo`) USING BTREE,
  KEY `fk_restrictionType_idx` (`type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Savings Restrictions';

CREATE TABLE IF NOT EXISTS `legacy_savrestrictiontype` (
  `pkey` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(45) NOT NULL,
  `description` varchar(200) NOT NULL,
  `createdBy` varchar(45) DEFAULT NULL,
  `modifiedBy` varchar(45) DEFAULT NULL,
  `dateCreated` datetime DEFAULT NULL,
  `dateModified` datetime DEFAULT NULL,
  `isdeleted` tinyint(4) NOT NULL,
  PRIMARY KEY (`pkey`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='Table for savings restrictions type.';


-- ---------------------------------------------------------------------
--  2. Links to the Party Master and the posting bridge
-- ---------------------------------------------------------------------

-- a water-service account belongs to one party (the Party Master) and posts its A/R to that party's customer record
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE water_accounts ADD COLUMN party_id INT UNSIGNED NULL AFTER pkey, ADD KEY ix_water_accounts_party (party_id)', 'SELECT ''water_accounts.party_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'party_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE water_accounts ADD COLUMN customer_id INT UNSIGNED NULL AFTER party_id, ADD KEY ix_water_accounts_customer (customer_id)', 'SELECT ''water_accounts.customer_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'customer_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE water_accounts ADD COLUMN legacy_member_id INT NULL AFTER customer_id', 'SELECT ''water_accounts.legacy_member_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'legacy_member_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- a bill code that is an inventory material maps to an item of the cooperative item master
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE bill_codes ADD COLUMN item_id INT UNSIGNED NULL AFTER asset_account, ADD KEY ix_bill_codes_item (item_id)', 'SELECT ''bill_codes.item_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bill_codes' AND column_name = 'item_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- a material issuance is a cooperative inventory adjustment: the document it posted
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE inventory_issuances ADD COLUMN txn_id BIGINT UNSIGNED NULL AFTER status, ADD COLUMN site_id INT UNSIGNED NULL AFTER txn_id, ADD KEY ix_inventory_issuances_txn (txn_id)', 'SELECT ''inventory_issuances.txn_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_issuances' AND column_name = 'txn_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE inventory_issuance_items ADD COLUMN item_id INT UNSIGNED NULL AFTER code_id', 'SELECT ''inventory_issuance_items.item_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_issuance_items' AND column_name = 'item_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- every accounting document a water event produced (the audited link between the water record and the ledger)
CREATE TABLE IF NOT EXISTS water_postings (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key       VARCHAR(64)  NOT NULL,                 -- unique key of the event: WBILL-123, OTB-45, COL-<receipt>, ... (idempotency)
  source_type     VARCHAR(30)  NOT NULL,                 -- water_bill, one_time_bill, installment_bill, recurring_bill, collection, bill_adjustment, issuance, ...
  source_id       VARCHAR(64)  NOT NULL,                 -- the water record (reading_id, bill_id, receipt_no ...)
  doc_no          VARCHAR(60)  NULL,                     -- the printed number of the water document (invoice no., OR no.)
  water_account_id INT         NULL,                     -- water_accounts.pkey (the service account)
  party_id        INT UNSIGNED NULL,
  txn_id          BIGINT UNSIGNED NULL,                  -- transactions.id (the ledger document)
  txn_type        VARCHAR(30)  NULL,
  amount          DECIMAL(15,2) NOT NULL DEFAULT 0,
  status          VARCHAR(12)  NOT NULL DEFAULT 'posted',-- posted / voided / replaced
  reversal_of_id  BIGINT UNSIGNED NULL,                  -- the posting this one reverses or replaces
  reason          VARCHAR(255) NULL,
  branch_id       INT NULL,
  segment_id      INT NULL,
  division_id     INT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_by       INT UNSIGNED NULL,
  voided_at       DATETIME     NULL,
  class_id        INT UNSIGNED NULL,                     -- the class of the document (Chart of Accounts > Class List)
  UNIQUE KEY uq_water_postings_event (event_key),
  KEY ix_water_postings_class (class_id),
  KEY ix_water_postings_source (source_type, source_id),
  KEY ix_water_postings_txn (txn_id),
  KEY ix_water_postings_account (water_account_id),
  KEY ix_water_postings_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the former chart of accounts and the account each entry became
CREATE TABLE IF NOT EXISTS water_coa_map (
  legacy_accnt_id INT          NOT NULL PRIMARY KEY,
  legacy_name     VARCHAR(45)  NULL,
  legacy_type     INT          NULL,
  account_id      INT UNSIGNED NULL,
  how             VARCHAR(12)  NOT NULL DEFAULT 'imported',   -- imported / matched / skipped
  note            VARCHAR(255) NULL,
  KEY ix_water_coa_map_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the former operators and the user account each became
CREATE TABLE IF NOT EXISTS water_user_map (
  legacy_operator_id INT        NOT NULL PRIMARY KEY,
  legacy_name        VARCHAR(45) NULL,
  user_id            INT UNSIGNED NULL,
  note               VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- checkpoints and row counts of the data migration (tools/migrate_water.php), one row per phase and run
CREATE TABLE IF NOT EXISTS water_migration_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id      VARCHAR(32)  NOT NULL,
  phase       VARCHAR(40)  NOT NULL,
  status      VARCHAR(12)  NOT NULL,                      -- started / done / failed / dry-run
  rows_read   INT          NOT NULL DEFAULT 0,
  rows_written INT         NOT NULL DEFAULT 0,
  rows_skipped INT         NOT NULL DEFAULT 0,
  amount_in   DECIMAL(15,2) NULL,
  amount_out  DECIMAL(15,2) NULL,
  notes       TEXT         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_water_migration_log_run (run_id, phase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. Compatibility views (read-only): the legacy column names over the one chart of accounts
-- ---------------------------------------------------------------------

DROP VIEW IF EXISTS chartofaccntstbl;
-- (text the view computes carries an explicit collation: a literal would otherwise take the collation of whatever
--  connection created the view and clash with the program's utf8mb4_unicode_ci in "WHERE Status = 'A'")
CREATE VIEW chartofaccntstbl AS
SELECT a.id AS AccntID, a.name AS AccntName, 0 AS BaseAccnt, COALESCE(a.parent_id, 0) AS ParentAccnt,
       CAST('' AS CHAR(1) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS SummaryAccnt,
       CASE a.category WHEN 'Asset' THEN 1 WHEN 'Liability' THEN 2 WHEN 'Equity' THEN 3 WHEN 'Income' THEN 4 ELSE 5 END AS `Type`,
       COALESCE(a.description, a.name) AS Description, a.with_sl AS WithSL, 0 AS CashFlowType, a.created_at AS CreateTime,
       CAST(IF(a.is_active = 1, 'A', 'I') AS CHAR(1) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS Status, a.code AS AccntCode, a.account_type AS AccntType
  FROM accounts a;

-- the former operator table over the user accounts (water pages join it for the cashier's / encoder's name)
DROP VIEW IF EXISTS operator;
CREATE VIEW operator AS
SELECT u.id AS pkey, u.username AS name, CAST('' AS CHAR(1) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS password, 0 AS accesslevel, COALESCE(u.full_name, u.username) AS description,
       NULL AS relatedkey, u.is_platform AS isSystem, IF(u.status = 'active', 0, 1) AS deleted,
       NULL AS createdby, u.created_at AS date_created, NULL AS modifiedby, u.updated_at AS date_modified
  FROM users u;

-- the water branches post under a class (branch) of the ledger
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE branches ADD COLUMN class_id INT UNSIGNED NULL AFTER description', 'SELECT ''branches.class_id exists''')
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'branches' AND column_name = 'class_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  4. Menu, pages, line of business, settings and default accounts
-- ---------------------------------------------------------------------

INSERT IGNORE INTO modules (code, name, icon, sort_order) VALUES ('water', 'Water Billing', 'droplet-half', 19);
SET @m_water = (SELECT id FROM modules WHERE code = 'water' LIMIT 1);
SET @psort = (SELECT COALESCE(MAX(sort_order), 4000) FROM pages);

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description) VALUES
  ('water.dashboard', @m_water, 'Water Dashboard', 'transaction', 'Overview', @psort + 1, 1, 'Operational overview of the water utility.'),
  ('water.billing_management', @m_water, 'Billing Management', 'transaction', 'Billing', @psort + 2, 1, 'Billing dashboard.'),
  ('water.bill_period', @m_water, 'Bill Period Management', 'transaction', 'Billing', @psort + 3, 1, 'Create and manage billing periods.'),
  ('water.bill_codes', @m_water, 'Bill Codes', 'transaction', 'Billing', @psort + 4, 1, 'Bill and charge codes and the accounts they post to.'),
  ('water.reading_entry', @m_water, 'Reading Entry', 'transaction', 'Billing', @psort + 5, 1, 'Enter individual and batch meter readings.'),
  ('water.meter_reading_list_print', @m_water, 'Meter Reading List Print', 'transaction', 'Billing', @psort + 6, 0, 'Print meter reading lists.'),
  ('water.reading_history', @m_water, 'Reading History', 'transaction', 'Billing', @psort + 7, 1, 'View meter reading history.'),
  ('water.billing_history', @m_water, 'Billing History', 'transaction', 'Billing', @psort + 8, 1, 'View posted and historical bill records.'),
  ('water.generate_soa', @m_water, 'Generate SOA / Post Billing', 'transaction', 'Billing', @psort + 9, 1, 'Generate statements and post water bills.'),
  ('water.billing_list', @m_water, 'Billing List', 'transaction', 'Billing', @psort + 10, 1, 'Unified register of receivables.'),
  ('water.update_billing', @m_water, 'Update Billing', 'transaction', 'Billing', @psort + 11, 1, 'Review supported billing updates.'),
  ('water.billing_adjustments', @m_water, 'Billing Adjustments', 'transaction', 'Billing', @psort + 12, 1, 'Controlled debit, credit, reversal and refund workflows.'),
  ('water.recurring_bill', @m_water, 'Recurring Billing', 'transaction', 'Billing', @psort + 13, 1, 'Manage recurring bills and subscriptions.'),
  ('water.installment_bill', @m_water, 'Installment Billing', 'transaction', 'Billing', @psort + 14, 1, 'Manage installment bills and schedules.'),
  ('water.one_time_billing', @m_water, 'One-Time Billing', 'transaction', 'Billing', @psort + 15, 1, 'Manage one-time bills.'),
  ('water.member_list', @m_water, 'Water Service Accounts', 'transaction', 'Service & Materials', @psort + 16, 1, 'Water-service account holders (consumers), each linked to a party.'),
  ('water.collection_management', @m_water, 'Collection Management', 'transaction', 'Collection', @psort + 17, 1, 'Collection dashboard.'),
  ('water.water_bills_collection', @m_water, 'Collections (Cashier)', 'transaction', 'Collection', @psort + 18, 1, 'Collect water bills and other receivables.'),
  ('water.other_payments_collection', @m_water, 'Other Payments Collection', 'transaction', 'Collection', @psort + 19, 0, 'Collect non-water receivables.'),
  ('water.other_payments', @m_water, 'Other Payments', 'transaction', 'Collection', @psort + 20, 0, 'View and manage supported other payments.'),
  ('water.payment_history', @m_water, 'Payment History', 'transaction', 'Collection', @psort + 21, 1, 'View receipts and payment history.'),
  ('water.disconnection_management', @m_water, 'Disconnection Management', 'transaction', 'Collection', @psort + 22, 1, 'Manage disconnection and reconnection actions.'),
  ('water.collection_receipt', @m_water, 'Collection Receipt Print', 'transaction', 'Collection', @psort + 23, 0, 'Print collection receipts.'),
  ('water.collection_corrections', @m_water, 'Collection Corrections', 'transaction', 'Collection', @psort + 24, 0, 'Reverse or refund a collection.'),
  ('water.customer_ledger', @m_water, 'Customer Ledger', 'transaction', 'Collection', @psort + 25, 0, 'Water-service account ledger.'),
  ('water.rates_management', @m_water, 'Rates Management', 'transaction', 'Rates & Discounts', @psort + 26, 1, 'Rates dashboard.'),
  ('water.water_rates', @m_water, 'Water Rate Schedule', 'transaction', 'Rates & Discounts', @psort + 27, 1, 'View the validated tariff schedule.'),
  ('water.water_meter', @m_water, 'Water Meter Rates', 'transaction', 'Rates & Discounts', @psort + 28, 1, 'Manage water meter rate tiers.'),
  ('water.rate_codes', @m_water, 'Rate Codes', 'transaction', 'Rates & Discounts', @psort + 29, 1, 'Manage rate codes.'),
  ('water.discount_management', @m_water, 'Discount Management', 'transaction', 'Rates & Discounts', @psort + 30, 1, 'Discount dashboard.'),
  ('water.discounts', @m_water, 'General Discounts', 'transaction', 'Rates & Discounts', @psort + 31, 1, 'Discount classifications and billing privilege values.'),
  ('water.discounted_members', @m_water, 'Discounted Accounts', 'transaction', 'Rates & Discounts', @psort + 32, 1, 'Discount assignments of water-service accounts.'),
  ('water.reports_management', @m_water, 'Water Reports', 'center', 'Reports', @psort + 33, 1, 'Operational water reports and printed documents.'),
  ('water.generate_official_receipt', @m_water, 'Generate Official Receipt', 'center', 'Reports', @psort + 34, 0, 'Print an official receipt.'),
  ('water.generate_disconnection_notice', @m_water, 'Generate Disconnection Notice', 'center', 'Reports', @psort + 35, 0, 'Print disconnection notices.'),
  ('water.settings', @m_water, 'Water Settings', 'transaction', 'Setup', @psort + 36, 1, 'Water utility settings hub.'),
  ('water.institution_configuration', @m_water, 'Institution Configuration', 'transaction', 'Setup', @psort + 37, 0, 'Institution profile shown on water documents.'),
  ('water.collection_policy_configuration', @m_water, 'Collection Policy', 'transaction', 'Setup', @psort + 38, 0, 'Penalty, compromise and collection accounts.'),
  ('water.organization_units', @m_water, 'Organization Units', 'transaction', 'Setup', @psort + 39, 0, 'Branches, divisions and segments of the utility (former program).'),
  ('water.branches', @m_water, 'Branches', 'transaction', 'Setup', @psort + 40, 0, 'Manage branch records.'),
  ('water.divisions', @m_water, 'Divisions', 'transaction', 'Setup', @psort + 41, 0, 'Manage division records.'),
  ('water.segments', @m_water, 'Segments', 'transaction', 'Setup', @psort + 42, 0, 'Manage segment records.'),
  ('water.import_management', @m_water, 'Data Import Management', 'transaction', 'Setup', @psort + 43, 1, 'Stage, validate, review and import company CSV records.'),
  ('water.item_issuance', @m_water, 'Material Issuance', 'transaction', 'Service & Materials', @psort + 44, 1, 'Issue meters, pipes and supplies from cooperative stock to field work.'),
  ('water.search', @m_water, 'Water Search', 'transaction', 'Setup', @psort + 45, 0, 'Search water-service accounts and documents.'),
  ('water.notifications', @m_water, 'Water Notifications', 'transaction', 'Setup', @psort + 46, 0, 'Operational alerts and reminders.'),
  ('water.help_center', @m_water, 'Water Help Center', 'transaction', 'Setup', @psort + 47, 0, 'Searchable in-system guidance.'),
  ('water.user_manual', @m_water, 'Water User Manual', 'transaction', 'Setup', @psort + 48, 0, 'Full water-billing user manual.'),
  ('water.coming_soon', @m_water, 'Coming Soon', 'transaction', 'Setup', @psort + 49, 0, 'Placeholder page.'),
  ('rpt.water_collection_summary', @m_water, 'Collection Summary', 'report', 'Reports', @psort + 50, 1, NULL),
  ('rpt.water_revenue_period', @m_water, 'Revenue by Billing Period', 'report', 'Reports', @psort + 51, 1, NULL),
  ('rpt.water_receivables_source', @m_water, 'Receivables by Source', 'report', 'Reports', @psort + 52, 1, NULL),
  ('rpt.water_payment_history', @m_water, 'Payment History (Ledger)', 'report', 'Reports', @psort + 53, 1, NULL),
  ('rpt.water_account_statement', @m_water, 'Water Service Account Statement', 'report', 'Reports', @psort + 54, 1, NULL),
  ('rpt.water_posting_log', @m_water, 'Water Posting Log & Exceptions', 'report', 'Reports', @psort + 55, 1, NULL),
  ('rpt.water_mapping_check', @m_water, 'Account Mapping Validation', 'report', 'Reports', @psort + 56, 1, NULL);
-- a database made before the menu was regrouped: Water Service Accounts and Material Issuance share one group
UPDATE pages SET menu_group = 'Service & Materials' WHERE page_key IN ('water.member_list', 'water.item_issuance') AND menu_group IN ('Service Accounts', 'Setup');

-- the Water Utility line of business: every water document and ledger line carries it
INSERT IGNORE INTO business_units (code, name, kind, nonmember_policy, patronage_eligible, description, sort_order)
VALUES ('WU', 'Water Utility', 'services', 'allow', 0, 'Water service: metered water sales, connection fees, materials and collections of water-service accounts', 3);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('water_business_unit_id', (SELECT id FROM business_units WHERE code = 'WU')),
  ('water_cutover_date', ''),
  ('water_source_db', ''),
  ('water_legacy_posting_locked', '1'),
  ('water_penalty_as_finance_charge', '1');

-- the accounts water events post to when a bill code or the collection policy does not name one
INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id) VALUES
  ('water_ar',         'Water Accounts Receivable',             'water', NULL),
  ('water_income',     'Water Sales (metered water)',           'water', NULL),
  ('water_penalty',    'Water Penalty / Surcharge Income',      'water', NULL),
  ('water_compromise', 'Water Compromise Income',               'water', NULL),
  ('water_discount',   'Water Discounts Granted',               'water', NULL),
  ('water_cash',       'Water Collections Cash (default)',      'water', NULL),
  ('water_legacy_clearing', 'Legacy Water Balances Clearing',   'water', NULL);

-- Administrator and General Manager open every water page; the Cashier collects; the Bookkeeper and Accountant read
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 1, 1, 1 FROM roles r, pages p WHERE r.name IN ('General Manager') AND p.module_id = @m_water;
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 1, 1, 0, 1, 0 FROM roles r, pages p WHERE r.name IN ('Cashier', 'Teller')
   AND p.page_key IN ('water.dashboard', 'water.collection_management', 'water.water_bills_collection', 'water.other_payments_collection',
                      'water.other_payments', 'water.payment_history', 'water.collection_receipt', 'water.customer_ledger', 'water.member_list',
                      'water.generate_official_receipt', 'rpt.water_collection_summary', 'rpt.water_payment_history');
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 0 FROM roles r, pages p WHERE r.name IN ('Bookkeeper', 'Accountant', 'Auditor') AND p.module_id = @m_water;

SET FOREIGN_KEY_CHECKS = 1;
