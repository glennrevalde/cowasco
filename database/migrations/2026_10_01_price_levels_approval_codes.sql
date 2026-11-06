-- =====================================================================
--  G-SMaRT Accounting - migration: price levels 6 to 10, approval codes, saved report columns
--
--  What it changes
--    1. Five more price levels, "Price Level 6" to "Price Level 10", each at 0% (same as the
--       default price) and active, so every company has ten levels to name and use. A company
--       that already has ten or more levels gets none. Rename them on Inventory > Set Item Prices
--       and Levels > Rename price levels (or Chart of Accounts > Terms, Tax Codes & Other Lists).
--    2. A new table approval_codes: one-time codes a supervisor generates for a cashier or clerk who
--       needs an approval while the supervisor is away. A code is used once and then expires.
--    3. A new page, Point of Sale > Approval Codes (pos.approval_codes), open to the roles that may
--       approve on the POS Register; everyone with it can generate codes and see the list.
--    4. The two POS reports "POS Sales by Cashier" and "POS Sales by Payment Method" become one,
--       "POS Sales by Cashier & Payment Method" (rpt.pos_sales_cashier). The old payment-method
--       report page is removed from the menu; its access rows are left as they are.
--
--  Nothing is deleted and no existing row is changed.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  With several companies, run it on every company database (the Super Admin's
--  Companies > Database updates > Update all companies does that).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_10_01_price_levels_approval_codes.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Price levels 6 to 10 (only while the company has fewer than ten)
-- ---------------------------------------------------------------------

INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 6', 0, 1 FROM DUAL WHERE (SELECT COUNT(*) FROM price_levels) < 6 AND NOT EXISTS (SELECT 1 FROM price_levels WHERE name = 'Price Level 6');
INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 7', 0, 1 FROM DUAL WHERE (SELECT COUNT(*) FROM price_levels) < 7 AND NOT EXISTS (SELECT 1 FROM price_levels WHERE name = 'Price Level 7');
INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 8', 0, 1 FROM DUAL WHERE (SELECT COUNT(*) FROM price_levels) < 8 AND NOT EXISTS (SELECT 1 FROM price_levels WHERE name = 'Price Level 8');
INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 9', 0, 1 FROM DUAL WHERE (SELECT COUNT(*) FROM price_levels) < 9 AND NOT EXISTS (SELECT 1 FROM price_levels WHERE name = 'Price Level 9');
INSERT INTO price_levels (name, adjust_pct, is_active)
SELECT 'Price Level 10', 0, 1 FROM DUAL WHERE (SELECT COUNT(*) FROM price_levels) < 10 AND NOT EXISTS (SELECT 1 FROM price_levels WHERE name = 'Price Level 10');

-- ---------------------------------------------------------------------
--  2. Approval codes
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS approval_codes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(12)  NOT NULL,
  purpose       VARCHAR(160) NULL,
  for_user_id   INT UNSIGNED NULL,
  page_key      VARCHAR(60)  NULL,
  max_amount    DECIMAL(15,2) NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'open',
  issued_by     INT UNSIGNED NOT NULL,
  issued_at     DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_by       INT UNSIGNED NULL,
  used_at       DATETIME     NULL,
  used_on       VARCHAR(160) NULL,
  used_txn_id   BIGINT UNSIGNED NULL,
  cancelled_by  INT UNSIGNED NULL,
  cancelled_at  DATETIME     NULL,
  UNIQUE KEY uq_approval_codes_code (code),
  KEY ix_approval_codes_status (status, expires_at),
  KEY ix_approval_codes_issued (issued_by, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. The Approval Codes page, open to the roles that may approve at the register
-- ---------------------------------------------------------------------

SET @ac_pos_module = (SELECT id FROM modules WHERE code = 'pos' LIMIT 1);
SET @ac_sort = (SELECT COALESCE(MAX(sort_order), 700) FROM pages WHERE module_id = @ac_pos_module AND page_type <> 'report');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('pos.approval_codes', @ac_pos_module, 'Approval Codes', 'transaction', 'Transactions', @ac_sort + 5, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 1, 1, 1, src.can_print, 1
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'pos.register'
  JOIN pages dst ON dst.page_key = 'pos.approval_codes'
 WHERE src.allowed = 1 AND src.can_approve = 1;

-- ---------------------------------------------------------------------
--  4. The combined POS report
-- ---------------------------------------------------------------------

UPDATE pages SET name = 'POS Sales by Cashier & Payment Method' WHERE page_key = 'rpt.pos_sales_cashier';
UPDATE pages SET show_in_menu = 0 WHERE page_key = 'rpt.pos_sales_payment';

-- ---------------------------------------------------------------------
--  5. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Price levels 6-10, approval codes, combined POS report', 'Migration 2026_10_01_price_levels_approval_codes: price levels 6 to 10, the approval_codes table and the Approval Codes page (open to the roles that may approve at the register), POS Sales by Cashier & Payment Method');

-- Done. Next steps in the application:
--   Inventory > Set Item Prices and Levels > Rename price levels: give the new levels their names and percentages.
--   Point of Sale > Approval Codes: a supervisor generates a one-time code for a cashier who needs an approval.
