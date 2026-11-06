-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: Fixed Share Capital Billing (Water Billing > Billing)
--
--  What it adds
--    share_capital_plans        the members on fixed billing: their open share capital account, the frequency
--                               (monthly, quarterly, semi-annually, annually), the start date, the day of the
--                               month the bill is posted (1 to 31) and the fixed amount; active, on hold or deleted
--    share_capital_bills        the bills posted on those days: what each member is expected to contribute. No
--                               journal entry is made for a bill: it only tracks the contribution still unpaid.
--    share_capital_collections  what the water cashier collected on a bill: the receipt, and the deposit to the
--                               share capital account posted by the savings transaction code (its journal entry)
--    payment_transactions       share_capital_amount: the share capital collected on a receipt (its total
--                               includes it; base_amount stays the bills paid); payment type 'sharecap'
--    payment_transaction_items  source type 'sharecap' (a share capital bill paid on a receipt)
--    pages                      Water Billing > Billing > Fixed Share Capital Billing, given to the roles that
--                               have One-Time Billing, with the same rights
--    (the savings transaction code a collection posts with, and its class, are chosen on the page and kept in
--     water_billing_settings: share_capital_txn_code, share_capital_class_id. Until then CSD Cash Savings Deposit.
--     Nothing is written there by this script, so a fresh install stays ready for tools/migrate_water.php.)
--
--  Nothing is deleted and no amount changes.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_03_14_share_capital_billing.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `share_capital_plans` (
  `plan_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` int(10) UNSIGNED NOT NULL,
  `sav_account_id` int(10) UNSIGNED NOT NULL,
  `frequency` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `posting_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
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
  `amount` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15, 2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime NULL DEFAULT NULL,
  `cancelled_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`bill_id`) USING BTREE,
  UNIQUE INDEX `uq_scb_plan_date`(`plan_id`, `bill_date`) USING BTREE,
  INDEX `ix_scb_member_status`(`member_id`, `status`) USING BTREE,
  INDEX `ix_scb_date`(`bill_date`) USING BTREE
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

-- the share capital collected on a receipt
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payment_transactions') = 0,
  'SELECT ''payment_transactions: table not installed (water billing not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND column_name = 'share_capital_amount') = 0,
     'ALTER TABLE `payment_transactions` ADD COLUMN `share_capital_amount` decimal(14, 2) NOT NULL DEFAULT 0.00 AFTER `compromise_amount`',
     'SELECT ''payment_transactions.share_capital_amount already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- a receipt of share capital only, and a share capital bill paid on a receipt
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND column_name = 'payment_type' AND column_type NOT LIKE '%sharecap%') = 0,
  'SELECT ''payment_transactions.payment_type: nothing to change''',
  'ALTER TABLE `payment_transactions` MODIFY COLUMN `payment_type` enum(''water'',''installment'',''onetime'',''recurring'',''mixed'',''sharecap'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transaction_items' AND column_name = 'source_type' AND column_type NOT LIKE '%sharecap%') = 0,
  'SELECT ''payment_transaction_items.source_type: nothing to change''',
  'ALTER TABLE `payment_transaction_items` MODIFY COLUMN `source_type` enum(''water'',''installment'',''onetime'',''recurring'',''sharecap'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the page, after One-Time Billing, for the roles that have One-Time Billing (the same rights)
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pages') = 0,
  'SELECT ''pages: table not installed''',
  'INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
   SELECT ''water.share_capital_billing'', p.module_id, ''Fixed Share Capital Billing'', ''transaction'', ''Billing'', p.sort_order + 5, 1,
          ''Fixed share capital contributions of members, posted on their day and collected by the water cashier.''
     FROM pages p WHERE p.page_key = ''water.one_time_billing'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, r.allowed, r.can_create, r.can_edit, r.can_delete, r.can_print, r.can_approve
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.one_time_billing''
     JOIN pages n ON n.page_key = ''water.share_capital_billing'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
