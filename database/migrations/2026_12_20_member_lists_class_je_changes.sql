-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: member lists, class on cooperative and water transactions, journal entry changes with approval
--
--  What it changes
--    1. Address sub-group of a water-service account
--         water_accounts.address_subgroup   the member's Address sub-group (Coop > Member Lists), shown and set on
--                                           the Water Billing member list too
--    2. The class (Chart of Accounts > Class List, the same classes as the accounting books) kept with the
--       cooperative and water transactions that are saved first and posted later, or reported by class:
--         sav_batches.class_id              Batch Deduction
--         loan_waivers.class_id             Interest / Penalty Waiving
--         loan_applications.class_id        Loan Restructure / Renew
--         bill_readings.class_id            posted water bills
--         one_time_bills.class_id           One-Time Billing
--         installment_bills.class_id        Installment Billing
--         recurring_bills.class_id          Recurring Billing
--         payment_transactions.class_id     Collections (Cashier) and imported collections
--         water_postings.class_id           every accounting document a water event produced
--       The journal entries themselves already carry the class (transactions.class_id, journal_lines.class_id).
--       Rows saved before this upgrade keep an empty class; nothing is guessed for them.
--    3. journal_edit_requests: a change to a saved journal entry, with the explanation of the change, the entry as
--       it was and as proposed, waiting for (or decided by) an authorized approver. Its page "Journal Entry
--       Changes" is added to the Chart of Accounts menu, with the access of "Make Journal Entries".
--    4. Water Billing > Organization Units leaves the menu: the water forms ask for the Class instead of the
--       Branch, Segment and Division (the page still opens for the records already on file).
--
--  Nothing is deleted and no posted amount changes.
--
--  For an EXISTING database that already has the earlier migrations (the cooperative and the water-billing ones).
--  New installs do not need it (schema.sql, the water-billing migration and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2026_12_20_member_lists_class_je_changes.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1 and 2. The address sub-group and the class columns, each with a key.
--     A table of a module that is not installed is skipped.
--     No foreign key is added: a class that is later deleted or deactivated is
--     shown as "(not on the list)" by the program.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'water_accounts') = 0,
  'SELECT ''water_accounts: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'address_subgroup') = 0,
     'ALTER TABLE `water_accounts` ADD COLUMN `address_subgroup` VARCHAR(60) NULL',
     'SELECT ''water_accounts: address_subgroup already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND column_name = 'address_subgroup') = 0,
  'SELECT ''water_accounts: no address_subgroup column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'water_accounts' AND index_name = 'ix_water_accounts_subgroup') = 0,
     'ALTER TABLE `water_accounts` ADD KEY `ix_water_accounts_subgroup` (`address_subgroup`)',
     'SELECT ''water_accounts: ix_water_accounts_subgroup already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sav_batches') = 0,
  'SELECT ''sav_batches: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_batches' AND column_name = 'class_id') = 0,
     'ALTER TABLE `sav_batches` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''sav_batches: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sav_batches' AND column_name = 'class_id') = 0,
  'SELECT ''sav_batches: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sav_batches' AND index_name = 'ix_sav_batches_class') = 0,
     'ALTER TABLE `sav_batches` ADD KEY `ix_sav_batches_class` (`class_id`)',
     'SELECT ''sav_batches: ix_sav_batches_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_waivers') = 0,
  'SELECT ''loan_waivers: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_waivers' AND column_name = 'class_id') = 0,
     'ALTER TABLE `loan_waivers` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''loan_waivers: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_waivers' AND column_name = 'class_id') = 0,
  'SELECT ''loan_waivers: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_waivers' AND index_name = 'ix_loan_waivers_class') = 0,
     'ALTER TABLE `loan_waivers` ADD KEY `ix_loan_waivers_class` (`class_id`)',
     'SELECT ''loan_waivers: ix_loan_waivers_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_applications') = 0,
  'SELECT ''loan_applications: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND column_name = 'class_id') = 0,
     'ALTER TABLE `loan_applications` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''loan_applications: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND column_name = 'class_id') = 0,
  'SELECT ''loan_applications: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_applications' AND index_name = 'ix_loan_applications_class') = 0,
     'ALTER TABLE `loan_applications` ADD KEY `ix_loan_applications_class` (`class_id`)',
     'SELECT ''loan_applications: ix_loan_applications_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bill_readings') = 0,
  'SELECT ''bill_readings: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bill_readings' AND column_name = 'class_id') = 0,
     'ALTER TABLE `bill_readings` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''bill_readings: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bill_readings' AND column_name = 'class_id') = 0,
  'SELECT ''bill_readings: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'bill_readings' AND index_name = 'ix_bill_readings_class') = 0,
     'ALTER TABLE `bill_readings` ADD KEY `ix_bill_readings_class` (`class_id`)',
     'SELECT ''bill_readings: ix_bill_readings_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'one_time_bills') = 0,
  'SELECT ''one_time_bills: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'one_time_bills' AND column_name = 'class_id') = 0,
     'ALTER TABLE `one_time_bills` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''one_time_bills: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'one_time_bills' AND column_name = 'class_id') = 0,
  'SELECT ''one_time_bills: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'one_time_bills' AND index_name = 'ix_one_time_bills_class') = 0,
     'ALTER TABLE `one_time_bills` ADD KEY `ix_one_time_bills_class` (`class_id`)',
     'SELECT ''one_time_bills: ix_one_time_bills_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'installment_bills') = 0,
  'SELECT ''installment_bills: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'installment_bills' AND column_name = 'class_id') = 0,
     'ALTER TABLE `installment_bills` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''installment_bills: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'installment_bills' AND column_name = 'class_id') = 0,
  'SELECT ''installment_bills: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'installment_bills' AND index_name = 'ix_installment_bills_class') = 0,
     'ALTER TABLE `installment_bills` ADD KEY `ix_installment_bills_class` (`class_id`)',
     'SELECT ''installment_bills: ix_installment_bills_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'recurring_bills') = 0,
  'SELECT ''recurring_bills: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recurring_bills' AND column_name = 'class_id') = 0,
     'ALTER TABLE `recurring_bills` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''recurring_bills: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recurring_bills' AND column_name = 'class_id') = 0,
  'SELECT ''recurring_bills: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'recurring_bills' AND index_name = 'ix_recurring_bills_class') = 0,
     'ALTER TABLE `recurring_bills` ADD KEY `ix_recurring_bills_class` (`class_id`)',
     'SELECT ''recurring_bills: ix_recurring_bills_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'payment_transactions') = 0,
  'SELECT ''payment_transactions: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND column_name = 'class_id') = 0,
     'ALTER TABLE `payment_transactions` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''payment_transactions: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND column_name = 'class_id') = 0,
  'SELECT ''payment_transactions: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND index_name = 'ix_payment_transactions_class') = 0,
     'ALTER TABLE `payment_transactions` ADD KEY `ix_payment_transactions_class` (`class_id`)',
     'SELECT ''payment_transactions: ix_payment_transactions_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'water_postings') = 0,
  'SELECT ''water_postings: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_postings' AND column_name = 'class_id') = 0,
     'ALTER TABLE `water_postings` ADD COLUMN `class_id` INT UNSIGNED NULL',
     'SELECT ''water_postings: class_id already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'water_postings' AND column_name = 'class_id') = 0,
  'SELECT ''water_postings: no class_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'water_postings' AND index_name = 'ix_water_postings_class') = 0,
     'ALTER TABLE `water_postings` ADD KEY `ix_water_postings_class` (`class_id`)',
     'SELECT ''water_postings: ix_water_postings_class already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. Changes to saved journal entries, approved by a second user
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS journal_edit_requests (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id           BIGINT UNSIGNED NOT NULL,            -- the journal entry (transactions.id)
  status           VARCHAR(12)  NOT NULL DEFAULT 'pending',  -- pending, approved, rejected, withdrawn, superseded
  explanation      VARCHAR(1000) NOT NULL,              -- why the entry is changed (required)
  before_json      MEDIUMTEXT   NOT NULL,               -- the entry as it was when the change was asked
  proposed_json    MEDIUMTEXT   NOT NULL,               -- the entry as proposed
  before_hash      CHAR(32)     NOT NULL,               -- refuses the approval when the entry changed meanwhile
  requested_by     INT UNSIGNED NOT NULL,
  requested_at     DATETIME     NOT NULL,
  decided_by       INT UNSIGNED NULL,
  decided_at       DATETIME     NULL,
  decision_remarks VARCHAR(255) NULL,
  KEY ix_jer_txn (txn_id, status),
  KEY ix_jer_status (status, id),
  KEY ix_jer_requester (requested_by, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @je_module = (SELECT module_id FROM pages WHERE page_key = 'coa.journal' LIMIT 1);
SET @je_sort   = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'coa.journal');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'coa.je_edits', @je_module, 'Journal Entry Changes', 'list', 'Transactions', @je_sort + 1, 1,
       'Changes to saved journal entries, with their explanation, waiting for approval.'
  FROM DUAL WHERE @je_module IS NOT NULL;

-- the roles that open Make Journal Entries open the changes page too (the Approve right decides who approves)
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'coa.journal'
  JOIN pages dst ON dst.page_key = 'coa.je_edits';

-- ---------------------------------------------------------------------
--  4. Organization Units leaves the Water Billing menu
-- ---------------------------------------------------------------------

UPDATE pages SET show_in_menu = 0, description = 'Branches, divisions and segments of the utility (former program).'
 WHERE page_key = 'water.organization_units';

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the new columns.
-- ---------------------------------------------------------------------
