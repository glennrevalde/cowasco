-- =====================================================================
--  G-SMaRT Accounting - migration: Find, and report design for authorized users
--
--  What it changes
--    1. two new pages:
--         home.find   Find (Home menu): every transaction of every module, by any
--                     combination of filters, with Go To, Report and Export
--         rpt.find    Find Results: what Find found, as a report that can be printed,
--                     exported, customized and memorized
--       Every role that can open Search can open both. A role that can print any
--       report can also print and export the Find results.
--    2. a new special permission, "Can design report layouts" (design_reports).
--       Designing a report (fonts, headings and footers, columns and rows, saved
--       layouts) is now for the roles that hold it; everyone else still opens every
--       report, and any layout that was shared with them. The Administrator role holds
--       it without being given it. So that nobody loses what they already use, every
--       role of a user who has saved a report layout before is given it here.
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
--    or:         mysql -u root -p accounting_system < 2026_09_27_find_report_design.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. The two pages
-- ---------------------------------------------------------------------

SET @find_home_module = (SELECT id FROM modules WHERE code = 'home' LIMIT 1);
SET @find_sort_search = (SELECT COALESCE(MAX(sort_order), 50) FROM pages WHERE page_key = 'home.search');

INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu) VALUES
  ('home.find', @find_home_module, 'Find',         'center', 'Home',    @find_sort_search + 5, 1),
  ('rpt.find',  @find_home_module, 'Find Results', 'report', 'Reports', @find_sort_search + 6, 0);

-- Find follows the access Search already has, so your roles keep exactly what they can open now.
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0, src.can_print, 0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'home.search'
  JOIN pages dst ON dst.page_key = 'home.find';

-- The Find Results report: open to the same roles; printing and exporting it for the roles that
-- can already print a report of their own.
INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
SELECT src.role_id, dst.id, src.allowed, 0, 0, 0,
       CASE WHEN EXISTS (SELECT 1 FROM role_page_access rp JOIN pages pp ON pp.id = rp.page_id
                          WHERE rp.role_id = src.role_id AND pp.page_type = 'report' AND rp.allowed = 1 AND rp.can_print = 1)
            THEN 1 ELSE 0 END,
       0
  FROM role_page_access src
  JOIN pages sp  ON sp.id = src.page_id AND sp.page_key = 'home.search'
  JOIN pages dst ON dst.page_key = 'rpt.find';

-- ---------------------------------------------------------------------
--  2. "Can design report layouts", for the roles already using it
-- ---------------------------------------------------------------------

INSERT IGNORE INTO role_special_permissions (role_id, perm_key, perm_value)
SELECT DISTINCT ur.role_id, 'design_reports', '1'
  FROM report_layouts l
  JOIN user_roles ur ON ur.user_id = l.created_by;

-- ---------------------------------------------------------------------
--  3. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.page_access', 'access_change', 'page',
        'Find; report design', 'Migration 2026_09_27_find_report_design: Find and Find Results pages added with the access Search has; "Can design report layouts" added for the roles already saving layouts');

-- Done. Next steps in the application:
--   1. Home > Find. Pick a transaction type on the Simple tab, or combine any filters on the
--      Advanced tab, then Find. Click a line to open the transaction itself.
--   2. System Administration > User Role Configuration > (a role) > Special permissions:
--      tick "Can design report layouts" for the roles that should design reports.
--   3. Open any report and press Design: fonts, headings and footers, columns, rows,
--      figures and the printed page, then save it as a layout.
