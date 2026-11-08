-- =====================================================================
--  G-SMaRT Integrated Cooperative System - migration: version 57
--
--  Water Billing > Billing > Bill Accounts Center: every bill of the members and customers (water bills,
--  one-time, installment, recurring and fixed share capital bills), the open ones by default or the closed
--  (fully paid) ones too, with their filters, exported to Excel or CSV, printed or saved as PDF.
--
--  What it adds
--    pages             Water Billing > Billing > Bill Accounts Center, after Billing Management, given to the
--                      roles that have Billing List, Generate SOA / Post Billing or Collections (Cashier): they
--                      may open it, and print and export it when they may print on that page
--
--  No table is added or changed, nothing is deleted and no amount changes. The other requests of this version
--  (the import of the chart of accounts, the address sub-group edited on the member form, the water bill
--  receipts on Letter paper) need nothing in the database.
--
--  For an EXISTING database of version 56 (with the earlier migrations run).
--  New installs do not need it (tools/install.php runs it).
--  Safe to run more than once. Works on MySQL 5.5.3+ / 5.6 / 5.7 / 8.x and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p your_database < 2027_05_02_bill_accounts_center.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Water Billing > Billing > Bill Accounts Center
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pages') = 0,
  'SELECT ''pages: table not installed''',
  'INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
   SELECT ''bill.accounts'', p.module_id, ''Bill Accounts Center'', ''center'', p.menu_group, p.sort_order + 1, 1,
          ''Every bill of the members and customers, open or fully paid, with its amount, what was paid and its balance; filtered, exported, printed.''
     FROM pages p WHERE p.page_key = ''water.billing_management'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the roles of Billing List first, then of Generate SOA / Post Billing, then of Collections (Cashier): a role keeps
-- the first one it has (INSERT IGNORE), with the right to open the center and, if it prints there, to print and export
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, 1, 0, 0, 0, r.can_print, 0
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.billing_list''
     JOIN pages n ON n.page_key = ''bill.accounts''
    WHERE r.allowed = 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, 1, 0, 0, 0, r.can_print, 0
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.generate_soa''
     JOIN pages n ON n.page_key = ''bill.accounts''
    WHERE r.allowed = 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, 1, 0, 0, 0, r.can_print, 0
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.water_bills_collection''
     JOIN pages n ON n.page_key = ''bill.accounts''
    WHERE r.allowed = 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Bill Accounts Center',
        'Migration 2027_05_02: the Bill Accounts Center of Water Billing, given to the roles that have Billing List, Generate SOA / Post Billing or Collections (Cashier)');
