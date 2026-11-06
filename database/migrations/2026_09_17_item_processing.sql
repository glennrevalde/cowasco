-- =====================================================================
--  Accounting System - migration: Disassemble / Process Items
--
--  Adds inventory processing with cost allocation:
--    * Disassembly / unbuild orders (1 input -> its components)
--    * De-kitting / break-bulk (kit, box or bulk pack -> single units)
--    * Co-product / by-product processing (1 or more inputs -> several outputs)
--
--  What it changes
--    1. new tables   process_formulas, process_formula_lines (multi-output bills of materials)
--    2. new columns  transactions:      process_type, alloc_method, process_formula_id, process_batches
--                    transaction_lines: output_role, alloc_basis, alloc_pct, planned_qty
--    3. number sequence PRC-00001, and two module default accounts
--       (Processing / Disassembly Variance -> 5100, Processing Labor & Overhead Absorbed -> 5200)
--    4. five new pages and their access for the standard roles
--
--  For an EXISTING database created from accounting_system.sql / accounting_system_clean.sql.
--  (New installs do not need it: the updated schema.sql and .sql files already include everything.)
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    or:         mysql -u root -p accounting_system < 2026_09_17_item_processing.sql
--  Needs the CREATE, ALTER, INSERT and REFERENCES privileges (root has them).
--  Then copy the updated PHP files over the old ones.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Tables
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS process_formulas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  process_type VARCHAR(20)  NOT NULL DEFAULT 'co_product',  -- disassembly, dekit, co_product
  alloc_method VARCHAR(20)  NOT NULL DEFAULT 'sales_value', -- sales_value, standard_cost, quantity, weight, percent, average_cost, manual
  site_id      INT UNSIGNED NULL,                           -- usual processing site
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,                           -- set by the application (DB::update)
  UNIQUE KEY uq_pf_code (code),
  CONSTRAINT fk_pf_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS process_formula_lines (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  formula_id  INT UNSIGNED NOT NULL,
  line_no     INT          NOT NULL DEFAULT 0,
  line_type   VARCHAR(10)  NOT NULL,                  -- input, output, cost
  item_id     INT UNSIGNED NULL,
  account_id  INT UNSIGNED NULL,                      -- cost lines: account credited
  description VARCHAR(255) NULL,
  qty         DECIMAL(15,4) NOT NULL DEFAULT 0,
  output_role VARCHAR(12)  NULL,                      -- co_product, by_product, scrap
  alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0,       -- price / weight / percent per unit, by-product value per unit, manual amount per batch
  amount      DECIMAL(15,2) NOT NULL DEFAULT 0,       -- cost lines: amount per batch
  KEY ix_pfl_formula (formula_id),
  KEY ix_pfl_item (item_id),
  CONSTRAINT fk_pfl_formula FOREIGN KEY (formula_id) REFERENCES process_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_pfl_item    FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_pfl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Columns - added only when missing. MySQL 5.5 has no ADD COLUMN IF NOT EXISTS,
--     so each ALTER is built as text and run as a prepared statement
--     (no DELIMITER or stored procedure needed).
-- ---------------------------------------------------------------------

SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions
     ADD COLUMN process_type       VARCHAR(20)   NULL AFTER batch_ref,
     ADD COLUMN alloc_method       VARCHAR(20)   NULL AFTER process_type,
     ADD COLUMN process_formula_id INT UNSIGNED  NULL AFTER alloc_method,
     ADD COLUMN process_batches    DECIMAL(15,4) NULL AFTER process_formula_id,
     ADD KEY ix_txn_formula (process_formula_id)',
  'SELECT ''transactions: processing columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'process_type');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transactions
     ADD CONSTRAINT fk_txn_formula FOREIGN KEY (process_formula_id) REFERENCES process_formulas(id) ON DELETE SET NULL',
  'SELECT ''transactions: fk_txn_formula already exists''')
  FROM information_schema.table_constraints
 WHERE table_schema = DATABASE() AND table_name = 'transactions' AND constraint_name = 'fk_txn_formula');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

SET @acct_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE transaction_lines
     ADD COLUMN output_role VARCHAR(12)   NULL AFTER value_change,
     ADD COLUMN alloc_basis DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER output_role,
     ADD COLUMN alloc_pct   DECIMAL(9,4)  NOT NULL DEFAULT 0 AFTER alloc_basis,
     ADD COLUMN planned_qty DECIMAL(15,4) NULL AFTER alloc_pct',
  'SELECT ''transaction_lines: processing columns already exist''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'transaction_lines' AND column_name = 'output_role');
PREPARE acct_stmt FROM @acct_sql;
EXECUTE acct_stmt;
DEALLOCATE PREPARE acct_stmt;

-- ---------------------------------------------------------------------
--  3. Number sequence and default accounts
-- ---------------------------------------------------------------------

INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('processing', 'PRC-', 1, 5);

INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id)
VALUES ('processing_variance', 'Processing / Disassembly Variance', 'inv', (SELECT a.id FROM accounts a WHERE a.code = '5100' LIMIT 1));

INSERT IGNORE INTO default_accounts (setting_key, label, module, account_id)
VALUES ('processing_absorbed', 'Processing Labor & Overhead Absorbed', 'inv', (SELECT a.id FROM accounts a WHERE a.code = '5200' LIMIT 1));

-- If your chart of accounts has no 5100 / 5200, set these two under
-- Chart of Accounts > Module Default Accounts (Inventory) after the migration.

-- ---------------------------------------------------------------------
--  4. Pages (menu and Page Access Configuration)
-- ---------------------------------------------------------------------

SET @acct_inv_module = (SELECT id FROM modules WHERE code = 'inv' LIMIT 1);
SET @acct_sort_build = (SELECT COALESCE(MAX(sort_order), 900) FROM pages WHERE page_key = 'inv.build');
SET @acct_sort_list  = (SELECT COALESCE(MAX(sort_order), 900) FROM pages WHERE page_key = 'inv.sites');
SET @acct_sort_rpt   = (SELECT COALESCE(MAX(sort_order), 900) FROM pages WHERE page_key = 'rpt.pending_builds_site');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('inv.process',             @acct_inv_module, 'Disassemble / Process Items',             'transaction', 'Transactions',      @acct_sort_build + 1, 1),
  ('inv.formulas',            @acct_inv_module, 'Processing Formulas (Multi-Output BOM)',  'list',        'Lists',             @acct_sort_list + 1,  1),
  ('rpt.processing_register', @acct_inv_module, 'Processing & Disassembly Register',       'report',      'Inventory Reports', @acct_sort_rpt + 1,   1),
  ('rpt.coproduct_costs',     @acct_inv_module, 'Co-product & By-product Cost Allocation', 'report',      'Inventory Reports', @acct_sort_rpt + 2,   1),
  ('rpt.process_yield',       @acct_inv_module, 'Processing Yield Analysis',               'report',      'Inventory Reports', @acct_sort_rpt + 3,   1);

-- ---------------------------------------------------------------------
--  5. Page access
--     Administrators can open every page already.
--     Roles that can build assemblies get the same rights on processing orders and formulas
--     (Inventory Clerk: create/edit/print; Auditor: view/print; your own roles likewise).
--     Roles that see Pending Builds see the register and yield reports; roles that see the
--     Inventory Valuation Detail (a cost report) see the cost allocation report.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'inv.build'
  JOIN pages dst ON dst.page_key IN ('inv.process', 'inv.formulas');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.pending_builds'
  JOIN pages dst ON dst.page_key IN ('rpt.processing_register', 'rpt.process_yield');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.inv_valuation_detail'
  JOIN pages dst ON dst.page_key = 'rpt.coproduct_costs';

-- Accountant: view and print processing orders and formulas
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT r.id, p.id, 1, 0, 0, 0, 1, 0
  FROM roles r
  JOIN pages p ON p.page_key IN ('inv.process', 'inv.formulas')
 WHERE r.name = 'Accountant';

-- Record the change in the audit trail
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'page_access_change', 'pages',
        'Disassemble / Process Items', 'Migration 2026_09_17_item_processing: processing orders, formulas and 3 reports added');

-- Done. Check the new pages under Inventory, and adjust access in
-- System Administration > Page Access Configuration if needed.
