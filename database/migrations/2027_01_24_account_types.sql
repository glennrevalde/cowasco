-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: account types of the user's own, linked to the Statement of Cash Flows; the Balance Sheet Summary
--  and the Income Statement Summary
--
--  What it changes
--    account_types    new table: the account types (Chart of Accounts > Account Types). The fifteen built-in types
--                     (Bank, Accounts Receivable, ... Other Expense) are written as its first rows; the user adds
--                     types of his own (for example Cash, Loans Receivable), each behaving as one built-in type and
--                     linked to a section of the Statement of Cash Flows (cash and cash equivalents, operating,
--                     investing or financing activities)
--    accounts         type_id: the type chosen for the account (empty = the built-in type of account_type, which
--                     every account keeps as before)
--    pages            Chart of Accounts > Account Types (with the access of Chart of Accounts), and the reports
--                     Balance Sheet Summary and Income Statement Summary (with the access of the Balance Sheet and
--                     the Income Statement)
--
--  Nothing is deleted and no amount changes. Every account stays of the type it is.
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_01_24_account_types.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. the account types
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS account_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  base_type   ENUM('Bank','Accounts Receivable','Other Current Asset','Fixed Asset','Other Asset',
                   'Accounts Payable','Credit Card','Other Current Liability','Long Term Liability',
                   'Equity','Income','Other Income','Cost of Goods Sold','Expense','Other Expense') NOT NULL,
  cash_flow   VARCHAR(10)  NOT NULL DEFAULT '',     -- cash / operating / investing / financing ('' = income and expense)
  sort_order  INT          NOT NULL DEFAULT 0,      -- the order of the types of one base on the statements
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,      -- one of the fifteen built-in types
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_account_types_name (name),
  KEY ix_account_types_base (base_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the built-in types, each once (a name the user already took is left to him)
INSERT IGNORE INTO account_types (name, base_type, cash_flow, sort_order, is_system, description)
SELECT x.name, x.base_type, x.cash_flow, x.sort_order, 1, x.description FROM (
  SELECT 'Bank' AS name, 'Bank' AS base_type, 'cash' AS cash_flow, 10 AS sort_order, 'Cash on hand and in banks' AS description
  UNION ALL SELECT 'Accounts Receivable', 'Accounts Receivable', 'operating', 20, 'What customers owe on invoices'
  UNION ALL SELECT 'Other Current Asset', 'Other Current Asset', 'operating', 30, 'Loans and advances, prepaid expenses, inventory, clearing accounts'
  UNION ALL SELECT 'Fixed Asset', 'Fixed Asset', 'investing', 40, 'Property and equipment and their accumulated depreciation'
  UNION ALL SELECT 'Other Asset', 'Other Asset', 'investing', 50, 'Investments, deposits and other long-term assets'
  UNION ALL SELECT 'Accounts Payable', 'Accounts Payable', 'operating', 60, 'What is owed to suppliers on bills'
  UNION ALL SELECT 'Credit Card', 'Credit Card', 'operating', 70, 'Credit card balances'
  UNION ALL SELECT 'Other Current Liability', 'Other Current Liability', 'operating', 80, 'Deposits, taxes, accruals and other debts due within a year'
  UNION ALL SELECT 'Long Term Liability', 'Long Term Liability', 'financing', 90, 'Debts due after a year'
  UNION ALL SELECT 'Equity', 'Equity', 'financing', 100, 'Capital, share capital, reserves, retained earnings'
  UNION ALL SELECT 'Income', 'Income', '', 110, 'Income from the main business'
  UNION ALL SELECT 'Other Income', 'Other Income', '', 120, 'Income outside the main business'
  UNION ALL SELECT 'Cost of Goods Sold', 'Cost of Goods Sold', '', 130, 'The cost of what was sold'
  UNION ALL SELECT 'Expense', 'Expense', '', 140, 'The costs of running the business'
  UNION ALL SELECT 'Other Expense', 'Other Expense', '', 150, 'Expenses outside the operations'
) x
 WHERE NOT EXISTS (SELECT 1 FROM account_types s WHERE s.is_system = 1 AND s.base_type = x.base_type);

-- ---------------------------------------------------------------------
--  2. the type of an account
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'type_id') = 0,
  'ALTER TABLE `accounts` ADD COLUMN `type_id` INT UNSIGNED NULL AFTER `account_type`, ADD KEY `ix_accounts_type` (`type_id`)',
  'SELECT ''accounts: type_id already exists'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. Chart of Accounts > Account Types, after Chart of Accounts, with its access
-- ---------------------------------------------------------------------
SET @coa_module = (SELECT id FROM modules WHERE code = 'coa');
SET @coa_sort   = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'coa.accounts');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'coa.account_types', @coa_module, 'Account Types', 'list', 'Lists', @coa_sort + 1, 1,
       'The account types: the built-in ones and those of your own (Cash, Loans Receivable ...), each a classification of the financial statements, linked to the Statement of Cash Flows.'
  FROM DUAL WHERE @coa_module IS NOT NULL;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'coa.accounts'
  JOIN pages dst ON dst.page_key = 'coa.account_types';

-- ---------------------------------------------------------------------
--  4. the Balance Sheet Summary and the Income Statement Summary (the main accounts only)
-- ---------------------------------------------------------------------
SET @bs_sort = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'rpt.balance_sheet');
SET @is_sort = (SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE page_key = 'rpt.income_statement');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'rpt.balance_sheet_summary', @coa_module, 'Balance Sheet Summary', 'report', 'Reports', @bs_sort + 1, 1,
       'The balance sheet with the main accounts only: each main account with the total of its sub-accounts.'
  FROM DUAL WHERE @coa_module IS NOT NULL;
INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
SELECT 'rpt.income_statement_summary', @coa_module, 'Income Statement Summary', 'report', 'Reports', @is_sort + 1, 1,
       'The income statement with the main accounts only: each main account with the total of its sub-accounts.'
  FROM DUAL WHERE @coa_module IS NOT NULL;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.balance_sheet'
  JOIN pages dst ON dst.page_key = 'rpt.balance_sheet_summary';
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.income_statement'
  JOIN pages dst ON dst.page_key = 'rpt.income_statement_summary';

-- ---------------------------------------------------------------------
--  Done. Sign in again (or open any page) and the program sees the new table.
-- ---------------------------------------------------------------------
