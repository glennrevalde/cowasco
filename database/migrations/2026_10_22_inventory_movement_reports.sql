-- =====================================================================
--  G-SMaRT Accounting - migration: inventory movement reports
--
--  What it changes
--    1. four new report pages:
--         rpt.inv_movement_summary  Inventory Movement Summary Report (Inventory Reports)
--         rpt.inv_movement_detail   Inventory Movement Detailed Report (Inventory Reports)
--         rpt.sales_inv_movement    Sales Report Through Inventory Movement (Sales Reports)
--         rpt.inv_movement_txns     Inventory Movement Transactions: the source transactions
--                                   behind every figure of the three reports (not in the menu,
--                                   opened by clicking a figure)
--       The two inventory reports are given to the roles that may open the No. of Days
--       Inventory Has No Movement report, the sales report to the roles that may open
--       Sales by Item Summary, and the transactions page to both, each with its print right.
--
--  No table or column is added, changed or dropped, and nothing is deleted.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_10_22_inventory_movement_reports.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The pages
-- ---------------------------------------------------------------------

SET @imr_inv  = (SELECT id FROM modules WHERE code = 'inv' LIMIT 1);
SET @imr_cust = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @imr_isort = (SELECT COALESCE(MAX(sort_order), 500) FROM pages WHERE page_key IN ('rpt.inv_custom', 'rpt.inv_no_movement'));
SET @imr_ssort = (SELECT COALESCE(MAX(sort_order), 200) FROM pages WHERE page_key = 'rpt.inventory_sales_site');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.inv_movement_summary', @imr_inv,  'Inventory Movement Summary Report',       'report', 'Inventory Reports', @imr_isort + 1, 1),
  ('rpt.inv_movement_detail',  @imr_inv,  'Inventory Movement Detailed Report',      'report', 'Inventory Reports', @imr_isort + 2, 1),
  ('rpt.inv_movement_txns',    @imr_inv,  'Inventory Movement Transactions',         'report', 'Inventory Reports', @imr_isort + 3, 0),
  ('rpt.sales_inv_movement',   @imr_cust, 'Sales Report Through Inventory Movement', 'report', 'Sales Reports',     @imr_ssort + 1, 1);

-- ---------------------------------------------------------------------
--  2. Who may open them
-- ---------------------------------------------------------------------

-- the two inventory reports and the transactions page: the roles of the No. of Days Inventory Has No Movement report
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.inv_no_movement'
  JOIN pages dst ON dst.page_key IN ('rpt.inv_movement_summary', 'rpt.inv_movement_detail', 'rpt.inv_movement_txns')
 WHERE src.allowed = 1;

-- the sales report and the transactions page: the roles of Sales by Item Summary
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.sales_item_summary'
  JOIN pages dst ON dst.page_key IN ('rpt.sales_inv_movement', 'rpt.inv_movement_txns')
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Inventory movement reports',
        'Migration 2026_10_22: Inventory Movement Summary and Detailed Reports, Sales Report Through Inventory Movement and their drill-down page added');

-- Done. Next steps in the application:
--   Inventory > Reports: Inventory Movement Summary Report and Inventory Movement Detailed Report.
--   Customers > Reports (Sales Reports): Sales Report Through Inventory Movement.
