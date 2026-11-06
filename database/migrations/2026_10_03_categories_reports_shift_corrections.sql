-- =====================================================================
--  G-SMaRT Accounting - migration: Category List, new reports, closing-entry corrections
--
--  What it changes
--    1. A new page, Inventory > Lists > Category List (inv.categories), to add, rename and
--       delete item categories. It is given to the roles that may open the Item List, with the
--       same rights they have there (delete included for the roles that may edit items).
--    2. Five new reports, given to the roles that may open a report of the same family:
--         Income by Customer Summary and Income by Customer Detail (like Sales by Customer Summary)
--         Expenses by Vendor Summary and Expenses by Vendor Detail (like Vendor Balance Summary)
--         No. of Days Inventory Has No Movement (like Inventory Stock Status by Item)
--    3. Two empty columns on pos_shifts, corrected_by and corrected_at: who last corrected the
--       closing entries of a closed shift (Point of Sale > Shifts, Cash Drops & Readings), and when.
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
--    or:         mysql -u root -p accounting_system < 2026_10_03_categories_reports_shift_corrections.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The Category List page, next to the Item List
-- ---------------------------------------------------------------------

SET @inv_module = (SELECT id FROM modules WHERE code = 'inv' LIMIT 1);
SET @items_sort = (SELECT COALESCE(MAX(sort_order), 600) FROM pages WHERE page_key = 'inv.items');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('inv.categories', @inv_module, 'Category List', 'list', 'Lists', @items_sort + 3, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, src.can_create, src.can_edit, GREATEST(src.can_delete, src.can_edit), src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'inv.items'
  JOIN pages dst ON dst.page_key = 'inv.categories'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  2. The new reports, each given to the roles that may open a report of its family
-- ---------------------------------------------------------------------

SET @cust_module = (SELECT id FROM modules WHERE code = 'cust' LIMIT 1);
SET @vend_module = (SELECT id FROM modules WHERE code = 'vend' LIMIT 1);
SET @sales_sort = (SELECT COALESCE(MAX(sort_order), 300) FROM pages WHERE page_key = 'rpt.sales_customer_detail');
SET @vend_sort  = (SELECT COALESCE(MAX(sort_order), 400) FROM pages WHERE page_key = 'rpt.vendor_contacts');
SET @inv_sort   = (SELECT COALESCE(MAX(sort_order), 600) FROM pages WHERE page_key = 'rpt.negative_inventory');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('rpt.income_customer_summary', @cust_module, 'Income by Customer Summary', 'report', 'Sales Reports', @sales_sort + 2, 1),
  ('rpt.income_customer_detail',  @cust_module, 'Income by Customer Detail',  'report', 'Sales Reports', @sales_sort + 3, 1),
  ('rpt.expenses_vendor_summary', @vend_module, 'Expenses by Vendor Summary', 'report', 'Vendors and Payables Reports', @vend_sort + 2, 1),
  ('rpt.expenses_vendor_detail',  @vend_module, 'Expenses by Vendor Detail',  'report', 'Vendors and Payables Reports', @vend_sort + 3, 1),
  ('rpt.inv_no_movement',         @inv_module,  'No. of Days Inventory Has No Movement', 'report', 'Inventory Reports', @inv_sort + 2, 1);

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.sales_customer_summary'
  JOIN pages dst ON dst.page_key IN ('rpt.income_customer_summary', 'rpt.income_customer_detail')
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.vendor_balance_summary'
  JOIN pages dst ON dst.page_key IN ('rpt.expenses_vendor_summary', 'rpt.expenses_vendor_detail')
 WHERE src.allowed = 1;

INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, 1, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'rpt.stock_status_item'
  JOIN pages dst ON dst.page_key = 'rpt.inv_no_movement'
 WHERE src.allowed = 1;

-- ---------------------------------------------------------------------
--  3. Who last corrected the closing entries of a shift, and when
--     Written so the file can be run a second time without an error.
-- ---------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE pos_shifts ADD COLUMN corrected_by INT UNSIGNED NULL AFTER closed_by',
  'SELECT ''pos_shifts: corrected_by already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'pos_shifts' AND column_name = 'corrected_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE pos_shifts ADD COLUMN corrected_at DATETIME NULL AFTER corrected_by',
  'SELECT ''pos_shifts: corrected_at already exists''')
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'pos_shifts' AND column_name = 'corrected_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  4. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Category List, five reports, shift corrections', 'Migration 2026_10_03: Category List page, income and expense reports by customer and vendor, inventory without movement, shift corrections');

-- Done. Next steps in the application:
--   Inventory > Category List: add, rename and delete the item categories.
--   Point of Sale > Shifts, Cash Drops & Readings > Correct (on a closed shift): fix a wrong float, drop or count.
