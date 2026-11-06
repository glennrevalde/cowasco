-- =====================================================================
--  Accounting System - migration: memorized transactions, budgets and forecasts,
--  report layouts, CSV imports, backups
--
--  What it changes
--    1. new tables   memorized_txns        (Memorized Transaction List)
--                    budgets, budget_lines (budgets for the income statement and the balance sheet,
--                                           and sales forecasts per customer)
--                    report_layouts        (saved column layouts of the reports)
--                    import_batches        (what every CSV import loaded, and what it created)
--                    backup_log            (database backups made and restored)
--    2. new setting  item_search_format    (how items are listed in the transaction forms)
--    3. new pages and their access for the standard roles:
--       Memorized Transactions, Set Up Budgets, Set Up Forecast, Import from CSV,
--       Backup & Restore, Set Item Prices, Saved Report Layouts, and six new reports
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_22_memorized_budgets_imports.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Memorized transactions
--     A saved copy of a transaction that can be entered again at any time, and on a schedule:
--     automatically, or with a reminder on the Home page.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS memorized_txns (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  group_name     VARCHAR(60)  NULL,
  txn_type       VARCHAR(30)  NOT NULL,
  entity_label   VARCHAR(160) NULL,               -- customer / supplier / payee, for the list
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  payload        LONGTEXT     NOT NULL,           -- the header and the lines of the saved transaction
  how            VARCHAR(20)  NOT NULL DEFAULT 'remind',   -- remind, auto, none (list only)
  frequency      VARCHAR(10)  NOT NULL DEFAULT 'monthly',  -- daily, weekly, months, quarterly, yearly
  every_n        INT          NOT NULL DEFAULT 1,
  next_date      DATE         NULL,
  days_in_advance INT         NOT NULL DEFAULT 5,
  remaining      INT          NULL,               -- how many are left to enter (NULL = no limit)
  end_date       DATE         NULL,
  last_entered   DATE         NULL,
  entered_count  INT          NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  notes          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL,
  KEY ix_mt_due (is_active, next_date),
  KEY ix_mt_type (txn_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Budgets and forecasts
--     One row per budget or forecast, twelve monthly amounts per account (budget)
--     or per customer and account (forecast).
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS budgets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  kind          VARCHAR(10)  NOT NULL DEFAULT 'pl',   -- pl = income statement, bs = balance sheet, forecast = sales forecast
  fiscal_year   SMALLINT     NOT NULL,
  start_month   TINYINT      NOT NULL DEFAULT 1,      -- the month the fiscal year starts in
  class_id      INT UNSIGNED NULL,
  customer_id   INT UNSIGNED NULL,                    -- forecast for one customer (NULL = all customers)
  notes         VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_budget (kind, fiscal_year, name),
  KEY ix_budget_class (class_id),
  KEY ix_budget_customer (customer_id),
  CONSTRAINT fk_budget_class    FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_budget_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id    INT UNSIGNED NOT NULL,
  account_id   INT UNSIGNED NOT NULL,
  customer_id  INT UNSIGNED NULL,                 -- forecasts: the customer the amounts belong to
  period_month TINYINT      NOT NULL,             -- 1..12, counted from the start of the fiscal year
  amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget_line (budget_id, account_id, customer_id, period_month),
  KEY ix_bl_account (account_id),
  CONSTRAINT fk_bl_budget   FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_account  FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_bl_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. Report layouts: the columns of a report, their order and their headings
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS report_layouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_key   VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  title        VARCHAR(160) NULL,                 -- a heading of your own for the print-out
  columns      TEXT         NULL,                 -- key:Heading, one per line, in the order they print; a leading - means hidden
  options      TEXT         NULL,                 -- name=value, one per line: negatives, zeros, decimals, density and the rest
  is_shared    TINYINT(1)   NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL,
  KEY ix_rl_report (report_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. CSV imports and database backups
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_type   VARCHAR(40)  NOT NULL,
  file_name     VARCHAR(190) NULL,
  rows_total    INT          NOT NULL DEFAULT 0,
  rows_ok       INT          NOT NULL DEFAULT 0,
  rows_failed   INT          NOT NULL DEFAULT 0,
  created_ids   LONGTEXT     NULL,                -- what the import created, so it can be traced
  messages      LONGTEXT     NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ib_type (import_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(10)  NOT NULL,             -- backup, restore
  file_name    VARCHAR(190) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT          NOT NULL DEFAULT 0,
  rows_count   INT          NOT NULL DEFAULT 0,
  note         VARCHAR(255) NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bl_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  5. Settings
-- ---------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('item_search_format', 'code_name');

-- ---------------------------------------------------------------------
--  6. Pages (menu and Page Access Configuration)
-- ---------------------------------------------------------------------

SET @acct_coa_module   = (SELECT id FROM modules WHERE code = 'coa' LIMIT 1);
SET @acct_cust_module  = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @acct_inv_module   = (SELECT id FROM modules WHERE code = 'inv' LIMIT 1);
SET @acct_admin_module = (SELECT id FROM modules WHERE code = 'admin' LIMIT 1);
SET @acct_home_module  = (SELECT id FROM modules WHERE code = 'home' LIMIT 1);
SET @acct_sort_mem     = (SELECT COALESCE(MAX(sort_order), 20) FROM pages WHERE page_key = 'home.memorized');
SET @acct_sort_lists   = (SELECT COALESCE(MAX(sort_order), 50) FROM pages WHERE page_key = 'coa.lists');
SET @acct_sort_tb      = (SELECT COALESCE(MAX(sort_order), 70) FROM pages WHERE page_key = 'rpt.trial_balance');
SET @acct_sort_bs      = (SELECT COALESCE(MAX(sort_order), 80) FROM pages WHERE page_key = 'rpt.balance_sheet');
SET @acct_sort_prices  = (SELECT COALESCE(MAX(sort_order), 600) FROM pages WHERE page_key = 'inv.change_prices');
SET @acct_sort_util    = (SELECT COALESCE(MAX(sort_order), 1500) FROM pages WHERE page_key = 'admin.utilities');
SET @acct_sort_custrpt = (SELECT COALESCE(MAX(sort_order), 400) FROM pages WHERE page_key = 'rpt.sales_by_customer');
SET @acct_sort_center  = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key = 'cust.center');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('home.report_layouts',    @acct_home_module,  'Saved Report Layouts',          'list',        'Home',         @acct_sort_mem + 1,     1),
  ('coa.memorized_txns',     @acct_coa_module,   'Memorized Transactions',        'list',        'Lists',        @acct_sort_lists + 3,   1),
  ('coa.budgets',            @acct_coa_module,   'Set Up Budgets',                'setup',       'Setup',        @acct_sort_lists + 4,   1),
  ('cust.forecast',          @acct_cust_module,  'Set Up Forecast',               'setup',       'Transactions', @acct_sort_center + 90, 1),
  ('inv.set_prices',         @acct_inv_module,   'Set Item Prices and Levels',    'transaction', 'Transactions', @acct_sort_prices + 1,  1),
  ('admin.import',           @acct_admin_module, 'Import from CSV',               'admin',       'Utilities',    @acct_sort_util + 1,    1),
  ('admin.backup',           @acct_admin_module, 'Backup & Restore',              'admin',       'Utilities',    @acct_sort_util + 2,    1),
  ('rpt.trial_balance_range',@acct_coa_module,   'Trial Balance by Date Range',   'report',      'Reports',      @acct_sort_tb + 1,      1),
  ('rpt.trial_balance_totals',@acct_coa_module,  'Trial Balance of Totals',       'report',      'Reports',      @acct_sort_tb + 2,      1),
  ('rpt.balance_sheet_range',@acct_coa_module,   'Balance Sheet by Date Range',   'report',      'Reports',      @acct_sort_bs + 2,      1),
  ('rpt.budget_income',      @acct_coa_module,   'Income Statement vs Budget',    'report',      'Reports',      @acct_sort_bs + 3,      1),
  ('rpt.budget_balance',     @acct_coa_module,   'Balance Sheet vs Budget',       'report',      'Reports',      @acct_sort_bs + 4,      1),
  ('rpt.forecast_vs_actual', @acct_cust_module,  'Forecast vs Actual by Customer','report',      'Sales Reports', @acct_sort_custrpt + 1, 1);

-- ---------------------------------------------------------------------
--  7. Page access
--     Administrators can open every page already. The new pages follow the page each one belongs with,
--     so your own roles keep the access they already have.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'coa.lists'
  JOIN pages dst ON dst.page_key IN ('coa.memorized_txns', 'coa.budgets');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'home.memorized'
  JOIN pages dst ON dst.page_key = 'home.report_layouts';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'cust.center'
  JOIN pages dst ON dst.page_key = 'cust.forecast';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'inv.change_prices'
  JOIN pages dst ON dst.page_key = 'inv.set_prices';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, src.can_delete, src.can_print, src.can_approve
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'admin.utilities'
  JOIN pages dst ON dst.page_key IN ('admin.import', 'admin.backup');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.trial_balance'
  JOIN pages dst ON dst.page_key IN ('rpt.trial_balance_range', 'rpt.trial_balance_totals');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.balance_sheet'
  JOIN pages dst ON dst.page_key IN ('rpt.balance_sheet_range', 'rpt.budget_balance');

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.income_statement'
  JOIN pages dst ON dst.page_key = 'rpt.budget_income';

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.sales_by_customer'
  JOIN pages dst ON dst.page_key = 'rpt.forecast_vs_actual';

-- Record the change in the audit trail
INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'page_access_change', 'pages',
        'Memorized transactions, budgets and imports', 'Migration 2026_09_22_memorized_budgets_imports: memorized transactions, budgets and forecasts, new financial reports, CSV imports, backup and restore, batch item prices');

-- Done. Next steps in the application:
--   1. System Administration > Company, Security & Closing Date: choose how items are listed in the transaction forms.
--   2. Chart of Accounts > Set Up Budgets, Customers > Set Up Forecast: enter the figures you want to compare against.
--   3. System Administration > Backup & Restore: make the first backup.
