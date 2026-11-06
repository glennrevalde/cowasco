-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: the menus of version 39 and the savings transaction code of the imports
--
--  - The menus: "Chart of Accounts" is now "Financial" and "Cooperative" is now "Members & Others", and the menus
--    are listed in the cooperative's order (Home, Financial, Members & Others, Teller, Savings & Deposits, Loans,
--    Water Billing, Banking, Company, Customers, Point of Sale, Vendors, Inventory, Fixed Assets, Parties,
--    BIR Reports, Year-end Distribution, CDA Compliance, System Administration). The program's own menu follows
--    app/registry.php; this script gives the same names and order to the lists of the access pages (User Role
--    Configuration, Page Access Configuration, the Audit Trail filters).
--  - Member Disbursement (Cash Voucher) and Cooperative Vouchers leave the menu. The pages stay for the vouchers
--    already made (they open from their links to be viewed, printed or voided); the access rights do not change.
--  - The savings transaction code INI "Initial Savings Import" (deposit, against Opening Balance Equity), offered
--    by Import Savings and Import Time Deposit, when the code INI is not taken yet.
--
--  Nothing is deleted and no amount changes. No table or column is added.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_02_21_menus_imports_codes.sql
-- =====================================================================

SET NAMES utf8mb4;

-- the menus: their names and their order
UPDATE modules SET name = 'Financial' WHERE code = 'coa';
UPDATE modules SET name = 'Members & Others' WHERE code = 'coop';
UPDATE modules SET sort_order = CASE code
    WHEN 'home' THEN 1 WHEN 'coa' THEN 2 WHEN 'coop' THEN 3 WHEN 'teller' THEN 4 WHEN 'sav' THEN 5 WHEN 'loan' THEN 6
    WHEN 'water' THEN 7 WHEN 'bank' THEN 8 WHEN 'company' THEN 9 WHEN 'cust' THEN 10 WHEN 'pos' THEN 11 WHEN 'vend' THEN 12
    WHEN 'inv' THEN 13 WHEN 'fa' THEN 14 WHEN 'party' THEN 15 WHEN 'bir' THEN 16 WHEN 'dist' THEN 17 WHEN 'cda' THEN 18
    WHEN 'admin' THEN 19 ELSE sort_order END;

-- Member Disbursement (Cash Voucher) and Cooperative Vouchers leave the menu
UPDATE pages SET show_in_menu = 0 WHERE page_key IN ('teller.disburse', 'coop.vouchers');

-- the code of the imported balances: INI Initial Savings Import, a deposit against Opening Balance Equity
INSERT INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_check_deposit, clearing_days, is_error_correct, related_code, is_system, is_active, is_memo)
SELECT 'INI', 'Initial Savings Import', 'gl', d.account_id, 'credit', 0, 0, 0, NULL, 0, 1, 0
  FROM default_accounts d
 WHERE d.setting_key = 'opening_equity' AND d.account_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM (SELECT code FROM sav_txn_codes) x WHERE x.code = 'INI');

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the change.
-- ---------------------------------------------------------------------
