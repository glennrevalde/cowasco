-- =====================================================================
--  Accounting System - migration: subsidiary ledgers
--
--  What it changes
--    1. new column   accounts.with_sl   ("With SL": the account keeps a subsidiary ledger,
--                                        so every entry to it must name a customer, supplier,
--                                        employee or other name)
--    2. new pages    Subsidiary Ledger Report, Schedule of Accounts Report
--    3. Chart of Accounts added to Import from CSV (no table change; the page reads the new column)
--
--  Nothing you already have is changed: every account starts with "With SL" switched off, so
--  entries carry on exactly as they do today until you tick the accounts you want a listing of.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_24_subsidiary_ledger.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. accounts.with_sl
--     MySQL 5.5 has no "ADD COLUMN IF NOT EXISTS", so the column is added only when it is missing.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE accounts ADD COLUMN with_sl TINYINT(1) NOT NULL DEFAULT 0 AFTER bank_account_no',
  'SELECT ''accounts: with_sl already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'with_sl');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE accounts ADD KEY ix_acc_with_sl (with_sl)',
  'SELECT ''accounts: ix_acc_with_sl already exists''')
  FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'accounts' AND index_name = 'ix_acc_with_sl');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. Pages (menu and Page Access Configuration)
-- ---------------------------------------------------------------------

SET @acct_coa_module = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @acct_sort_gl    = (SELECT COALESCE(MAX(sort_order), 60) FROM pages WHERE page_key = 'rpt.general_ledger');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.subsidiary_ledger',   @acct_coa_module, 'Subsidiary Ledger',    'report', 'Reports', @acct_sort_gl + 1, 1),
  ('rpt.schedule_of_accounts',@acct_coa_module, 'Schedule of Accounts', 'report', 'Reports', @acct_sort_gl + 2, 1);

-- The two new reports follow the access the General Ledger report already has, so your roles
-- keep exactly the access they have now.
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.general_ledger'
  JOIN pages dst ON dst.page_key IN ('rpt.subsidiary_ledger', 'rpt.schedule_of_accounts');

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'coa', 'coa.accounts', 'settings_change', 'accounts',
        'With SL', 'Migration 2026_09_24_subsidiary_ledger: accounts can be marked "With SL", and the Subsidiary Ledger and Schedule of Accounts reports were added');

-- Done. Next steps in the application:
--   1. Chart of Accounts: open each account that should keep a listing of names and tick "With SL".
--      From then on a journal entry, cash voucher or check posting to it must name a customer,
--      supplier, employee or other name.
--   2. Chart of Accounts > Reports: Subsidiary Ledger and Schedule of Accounts.
--   3. System Administration > Import from CSV now offers "Chart of accounts".
