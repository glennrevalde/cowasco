-- =====================================================================
--  Accounting System - migration: Classes, Cash Voucher, POS options
--
--  What it changes
--    1. new tables   classes      (Class List, names are your own: branches, departments, projects ...)
--                    other_names  (employees and other names used as payees / subsidiary ledgers)
--    2. new columns  transactions:      class_id, other_name_id
--                    transaction_lines: class_id, other_name_id
--                    journal_lines:     class_id, other_name_id
--                    pos_terminals:     class_id       (default class of the terminal)
--                    payment_methods:   show_in_pos    (which methods appear as POS payment options)
--    3. number sequence CAV-00001 (cash vouchers)
--    4. payment methods "P.O. Card" and "Bank" for the POS, with account 1070 P.O. Card Clearing
--    5. two more price levels (Price Level 4 and Price Level 5; rename them under
--       Chart of Accounts > Terms, Tax Codes & Other Lists > Price levels)
--    6. POS receipt wording settings (edit under System Administration > Company, Security & Closing Date)
--    7. account 1006 Revolving Fund (only when no revolving fund account exists yet)
--    8. five new pages and their access for the standard roles:
--       Class List, Employees & Other Names, Cash Voucher, Income Statement by Class, Balance Sheet by Class
--
--  For an EXISTING database. New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_19_classes_cash_voucher.sql
--  Needs the CREATE, ALTER, INSERT, UPDATE and REFERENCES privileges (root has them).
--  Run it BEFORE copying the updated PHP files over the old ones, or right after.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Tables
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS classes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  UNIQUE KEY uq_classes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS other_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name_type   VARCHAR(10)  NOT NULL DEFAULT 'other',  -- employee, other
  name        VARCHAR(120) NOT NULL,
  employee_no VARCHAR(30)  NULL,
  position    VARCHAR(80)  NULL,
  phone       VARCHAR(40)  NULL,
  address     VARCHAR(255) NULL,
  tin         VARCHAR(30)  NULL,
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL,                  -- set by the application (DB::update)
  KEY ix_other_names_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Columns - added only when missing. MySQL 5.5 has no ADD COLUMN IF NOT EXISTS,
--     so each ALTER is built as text and run as a prepared statement
--     (no DELIMITER or stored procedure needed).
-- ---------------------------------------------------------------------

-- transactions.class_id / other_name_id
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions
     ADD COLUMN class_id      INT UNSIGNED NULL,
     ADD COLUMN other_name_id INT UNSIGNED NULL,
     ADD KEY ix_txn_class (class_id),
     ADD KEY ix_txn_other_name (other_name_id)',
  'SELECT ''transactions: class columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'class_id');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions
     ADD CONSTRAINT fk_txn_class FOREIGN KEY (class_id) REFERENCES classes(id),
     ADD CONSTRAINT fk_txn_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id)',
  'SELECT ''transactions: fk_txn_class already exists''')
  FROM information_schema.table_constraints
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND constraint_name = 'fk_txn_class');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- transaction_lines.class_id / other_name_id
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines
     ADD COLUMN class_id      INT UNSIGNED NULL,
     ADD COLUMN other_name_id INT UNSIGNED NULL,
     ADD KEY ix_tl_class (class_id),
     ADD KEY ix_tl_other_name (other_name_id)',
  'SELECT ''transaction_lines: class columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND column_name = 'class_id');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines
     ADD CONSTRAINT fk_tl_class FOREIGN KEY (class_id) REFERENCES classes(id),
     ADD CONSTRAINT fk_tl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id)',
  'SELECT ''transaction_lines: fk_tl_class already exists''')
  FROM information_schema.table_constraints
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND constraint_name = 'fk_tl_class');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- journal_lines.class_id / other_name_id (the general ledger carries the class, so every financial report can filter by it)
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE journal_lines
     ADD COLUMN class_id      INT UNSIGNED NULL,
     ADD COLUMN other_name_id INT UNSIGNED NULL,
     ADD KEY ix_jl_class (class_id, txn_date),
     ADD KEY ix_jl_other_name (other_name_id)',
  'SELECT ''journal_lines: class columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'journal_lines' AND column_name = 'class_id');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE journal_lines
     ADD CONSTRAINT fk_jl_class FOREIGN KEY (class_id) REFERENCES classes(id),
     ADD CONSTRAINT fk_jl_other_name FOREIGN KEY (other_name_id) REFERENCES other_names(id)',
  'SELECT ''journal_lines: fk_jl_class already exists''')
  FROM information_schema.table_constraints
 WHERE table_schema = DATABASE() AND table_name = 'journal_lines' AND constraint_name = 'fk_jl_class');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- pos_terminals.class_id (default class for sales made at the terminal)
SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE pos_terminals
     ADD COLUMN class_id INT UNSIGNED NULL,
     ADD CONSTRAINT fk_term_class FOREIGN KEY (class_id) REFERENCES classes(id)',
  'SELECT ''pos_terminals: class_id already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'pos_terminals' AND column_name = 'class_id');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- payment_methods.show_in_pos (checks and bank transfers were never POS options, so they start hidden)
SET @acct_new_pos_flag = (SELECT IF(COUNT(*) = 0, 1, 0)
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'payment_methods' AND column_name = 'show_in_pos');

SET @acct_sql = IF(@acct_new_pos_flag = 1,
  'ALTER TABLE payment_methods ADD COLUMN show_in_pos TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT ''payment_methods: show_in_pos already exists''');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

SET @acct_sql = IF(@acct_new_pos_flag = 1,
  'UPDATE payment_methods SET show_in_pos = 0 WHERE method_type IN (''check'', ''bank_transfer'')',
  'SELECT ''payment_methods: POS options left as they are''');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- ---------------------------------------------------------------------
--  3. Number sequence for cash vouchers
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('cash_voucher', 'CAV-', 1, 5);

-- ---------------------------------------------------------------------
--  4. POS payment options: P.O. Card and Bank
--     P.O. Card sales wait in 1070 P.O. Card Clearing until they are collected
--     (Banking > Make Deposits > card and e-wallet settlements).
--     Bank payments go straight to the bank account. Change either account under
--     Chart of Accounts > Terms, Tax Codes & Other Lists > Payment methods.
-- ---------------------------------------------------------------------

INSERT INTO accounts (code, name, account_type, category, normal_balance, description, is_active, is_system)
SELECT '1070', 'P.O. Card Clearing', 'Other Current Asset', 'Asset', 'Debit', 'POS sales paid by P.O. card, waiting to be collected', 1, 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM accounts a WHERE a.code = '1070')
   AND NOT EXISTS (SELECT 1 FROM payment_methods pm WHERE pm.name = 'P.O. Card');

INSERT INTO payment_methods (name, method_type, clearing_account_id, is_active, show_in_pos)
SELECT 'P.O. Card', 'card',
       COALESCE((SELECT a.id FROM accounts a WHERE a.code = '1070' AND a.name = 'P.O. Card Clearing' LIMIT 1),
                (SELECT a.id FROM accounts a WHERE a.code = '1060' LIMIT 1)),
       1, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM payment_methods pm WHERE pm.name = 'P.O. Card');

INSERT INTO payment_methods (name, method_type, clearing_account_id, is_active, show_in_pos)
SELECT 'Bank', 'bank_transfer',
       (SELECT a.id FROM accounts a WHERE a.account_type = 'Bank' AND a.is_active = 1 AND a.name LIKE '%Bank%' ORDER BY a.code LIMIT 1),
       1, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM payment_methods pm WHERE pm.name = 'Bank');

-- ---------------------------------------------------------------------
--  5. Two more price levels (five in all). They start at 0 %, so they only change a price
--     when you type a custom price for the level on the item.
-- ---------------------------------------------------------------------

INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 4', 0, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM price_levels p WHERE p.name = 'Price Level 4')
   AND (SELECT COUNT(*) FROM price_levels p2) < 5;

INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 5', 0, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM price_levels p WHERE p.name = 'Price Level 5')
   AND (SELECT COUNT(*) FROM price_levels p2) < 5;

-- ---------------------------------------------------------------------
--  6. POS receipt wording (System Administration > Company, Security & Closing Date)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('pos_receipt_title',         'OFFICIAL RECEIPT'),
  ('pos_receipt_title_return',  'RETURN / REFUND'),
  ('pos_receipt_title_charge',  'CHARGE INVOICE'),
  ('pos_receipt_title_payment', 'ACKNOWLEDGEMENT RECEIPT'),
  ('pos_receipt_footer',        'Thank you for shopping!\nThis serves as your official receipt.'),
  ('pos_receipt_show_customer', '1');

-- ---------------------------------------------------------------------
--  7. Revolving fund account for cash vouchers (skipped when you already have one)
-- ---------------------------------------------------------------------

INSERT INTO accounts (code, name, account_type, category, normal_balance, parent_id, description, is_active, is_system)
SELECT '1006', 'Revolving Fund', 'Bank', 'Asset', 'Debit',
       (SELECT p.id FROM (SELECT id FROM accounts WHERE code = '1000' AND account_type = 'Bank' LIMIT 1) p),
       'Cash fund replenished from time to time (cash vouchers)', 1, 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM accounts a WHERE a.code = '1006')
   AND NOT EXISTS (SELECT 1 FROM accounts a2 WHERE a2.name LIKE '%Revolving%');

-- ---------------------------------------------------------------------
--  8. Pages (menu and Page Access Configuration)
-- ---------------------------------------------------------------------

SET @acct_coa_module  = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @acct_bank_module = (SELECT id FROM modules WHERE code = 'bank' LIMIT 1);
SET @acct_sort_coa    = (SELECT COALESCE(MAX(sort_order), 50) FROM pages WHERE page_key = 'coa.accounts');
SET @acct_sort_is     = (SELECT COALESCE(MAX(sort_order), 90) FROM pages WHERE page_key = 'rpt.income_statement');
SET @acct_sort_bs     = (SELECT COALESCE(MAX(sort_order), 80) FROM pages WHERE page_key = 'rpt.balance_sheet');
SET @acct_sort_chk    = (SELECT COALESCE(MAX(sort_order), 1200) FROM pages WHERE page_key = 'bank.write_checks');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('coa.classes',                @acct_coa_module,  'Class List',                'list',        'Lists',        @acct_sort_coa + 1, 1),
  ('coa.names',                  @acct_coa_module,  'Employees & Other Names',   'list',        'Lists',        @acct_sort_coa + 2, 1),
  ('rpt.income_statement_class', @acct_coa_module,  'Income Statement by Class', 'report',      'Reports',      @acct_sort_is + 1,  1),
  ('rpt.balance_sheet_class',    @acct_coa_module,  'Balance Sheet by Class',    'report',      'Reports',      @acct_sort_bs + 1,  1),
  ('bank.cash_voucher',          @acct_bank_module, 'Cash Voucher',              'transaction', 'Transactions', @acct_sort_chk + 1, 1);

-- ---------------------------------------------------------------------
--  9. Page access
--     Administrators can open every page already.
--     Class List and Employees & Other Names follow "Terms, Tax Codes & Other Lists";
--     Cash Voucher follows Write Checks; the two new reports follow the Income Statement
--     and the Balance Sheet (your own roles are copied the same way).
-- ---------------------------------------------------------------------

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'coa.lists'
  JOIN pages dst ON dst.page_key IN ('coa.classes', 'coa.names');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'bank.write_checks'
  JOIN pages dst ON dst.page_key = 'bank.cash_voucher';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.income_statement'
  JOIN pages dst ON dst.page_key = 'rpt.income_statement_class';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.balance_sheet'
  JOIN pages dst ON dst.page_key = 'rpt.balance_sheet_class';

-- Record the change in the audit trail
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'page_access_change', 'pages',
        'Classes and Cash Voucher', 'Migration 2026_09_19_classes_cash_voucher: class tracking, cash vouchers, by-class reports, POS payment options and price levels added');

-- Done. Next steps in the application:
--   1. Chart of Accounts > Class List: add your classes (branches, departments, projects ...).
--   2. Point of Sale > POS Terminals: choose the default class of each terminal (optional).
--   3. System Administration > Company, Security & Closing Date: edit the POS receipt wording.
--   4. System Administration > Utilities > Rebuild data (optional): re-costs past sales with the
--      improved average costing, so items sold down to zero leave no value behind.
