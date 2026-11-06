-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the teller's menu, the Loan Release Form and its Banking vouchers, the Loan Accounts Center
--
--  What it changes
--    pages            Loan Collection and the Savings Transaction Center move to the Teller menu (their page
--                     keys and the access of every role stay as they are)
--    pages            the new page Loans > Loan Release Vouchers (the list of every voucher of a loan release),
--                     with the access the roles have on Loans > Loan Release
--    loan_accounts    voucher_no: the voucher ID of the loan release (the LR- number series); an index on
--                     release_txn_id (the voucher of the release)
--    loan_products    addl_interest: how the Internal Ledger of a loan computes additional interest when the loan
--                     is past due or an amortization is missed (auto, arrears, maturity, none)
--
--  From this version a loan released in cash is a Cash Voucher (Banking > Cash Voucher) and a loan released by
--  cheque is a Check Voucher of Banking > Write Checks, each with its class. The releases made before keep their loan
--  release voucher; their voucher ID is their LR- number.
--
--  Nothing is deleted and no posted amount changes.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_01_10_teller_menu_loan_release_vouchers.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. the voucher ID of a loan release, and the index of its voucher
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_accounts') = 0,
  'SELECT ''loan_accounts: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_accounts' AND column_name = 'voucher_no') = 0,
     'ALTER TABLE `loan_accounts` ADD COLUMN `voucher_no` VARCHAR(30) NULL AFTER `release_txn_id`',
     'SELECT ''loan_accounts: voucher_no already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_accounts' AND column_name = 'voucher_no') = 0,
  'SELECT ''loan_accounts: no voucher_no column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_accounts' AND index_name = 'ix_loan_accounts_voucher') = 0,
     'ALTER TABLE `loan_accounts` ADD KEY `ix_loan_accounts_voucher` (`voucher_no`)',
     'SELECT ''loan_accounts: ix_loan_accounts_voucher already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_accounts' AND column_name = 'release_txn_id') = 0,
  'SELECT ''loan_accounts: no release_txn_id column to index''',
  IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'loan_accounts' AND index_name = 'ix_loan_accounts_release_txn') = 0,
     'ALTER TABLE `loan_accounts` ADD KEY `ix_loan_accounts_release_txn` (`release_txn_id`)',
     'SELECT ''loan_accounts: ix_loan_accounts_release_txn already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the releases made before: their voucher ID is the number of their loan release voucher
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_accounts' AND column_name = 'voucher_no') = 0,
  'SELECT ''loan_accounts: no voucher_no column''',
  'UPDATE `loan_accounts` l JOIN `transactions` t ON t.id = l.release_txn_id SET l.voucher_no = t.txn_no WHERE l.voucher_no IS NULL AND t.txn_type = ''loan_release'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. additional interest of a past-due loan, by the loan product (Internal Ledger)
--     auto     : diminishing-balance loans on the principal of each missed amortization from its due date,
--                add-on loans on the principal balance after maturity
--     arrears  : on the principal of each missed amortization from its due date
--     maturity : on the principal balance after the maturity date
--     none     : no additional interest
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_products') = 0,
  'SELECT ''loan_products: table not installed (module not in use)''',
  IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_products' AND column_name = 'addl_interest') = 0,
     'ALTER TABLE `loan_products` ADD COLUMN `addl_interest` VARCHAR(10) NOT NULL DEFAULT ''auto'' AFTER `penalty_account_id`',
     'SELECT ''loan_products: addl_interest already exists''')));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the number series of the loan release vouchers (Voucher ID); kept when it already exists
INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('loan_release', 'LR-', 1, 6);

-- ---------------------------------------------------------------------
--  3. the Teller menu: Loan Collection and the Savings Transaction Center, after Teller Shift & Cash Count
-- ---------------------------------------------------------------------
SET @teller_module = (SELECT id FROM modules WHERE code = 'teller');
SET @teller_sort   = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'teller.shift');

UPDATE pages SET module_id = @teller_module, sort_order = @teller_sort + 2
 WHERE page_key = 'sav.center' AND @teller_module IS NOT NULL;
UPDATE pages SET module_id = @teller_module, sort_order = @teller_sort + 4
 WHERE page_key = 'loan.collection' AND @teller_module IS NOT NULL;

-- ---------------------------------------------------------------------
--  4. Loans > Loan Release Vouchers, with the access of Loans > Loan Release
-- ---------------------------------------------------------------------
SET @loan_module = (SELECT id FROM modules WHERE code = 'loan');
SET @loan_sort   = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'loan.release');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'loan.vouchers', @loan_module, 'Loan Release Vouchers', 'center', 'Transactions', @loan_sort + 5, 1,
       'Every voucher of a loan release: the cash vouchers and the checks (Banking), with their class.'
  FROM DUAL WHERE @loan_module IS NOT NULL;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'loan.release'
  JOIN pages dst ON dst.page_key = 'loan.vouchers';

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the new columns.
-- ---------------------------------------------------------------------
