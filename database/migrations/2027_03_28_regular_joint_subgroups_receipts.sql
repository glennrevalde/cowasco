-- =====================================================================
--  G-SMaRT Integrated Cooperative + Water Billing System
--  migration: version 46 (ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v16.xlsx)
--
--  What it changes
--    member_types   a member type "Regular - Joint" (code RGJ) for joint accounts: a regular member (votes, may be
--                   elected), with the minimum share capital of the Regular type, listed beside Associate - Joint.
--                   Nothing is added when a type of that code or name is already on file.
--    pages          Water Billing > Service & Materials > Address Sub-groups (add, edit, delete the address
--                   sub-groups), given to the roles that have Water Service Accounts, with the same rights;
--                   Water Billing > Setup > Receipt Layout Setup (the pre-printed water bill / collection receipt and
--                   official receipt: paper, margins, where each entry prints), given to the roles that have Water
--                   Settings, with the same rights.
--
--  Nothing is deleted and no amount changes. The choice of Printed or Pre-Printed receipts and the layouts are kept
--  with the company settings (Company, Security & Closing Date > Water Billing); until they are saved, receipts print
--  as before (Printed).
--
--  For an EXISTING database that already has the earlier migrations. New installs get it from tools/install.php.
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the database > Import > choose this file > Go
--    Navicat:    right-click the database > Execute SQL File
--    or:         mysql -u root -p cooperative_system < 2027_03_28_regular_joint_subgroups_receipts.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Regular - Joint
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'member_types') = 0,
  'SELECT ''member_types: table not installed (cooperative module not in use)''',
  'INSERT INTO member_types (code, name, member_kind, is_regular, min_share_capital, is_active, sort_order)
   SELECT ''RGJ'', ''Regular - Joint'', ''joint'', 1,
          COALESCE(MAX(CASE WHEN code = ''REG'' THEN min_share_capital END), 0),
          1,
          COALESCE(MAX(CASE WHEN code = ''ASJ'' THEN sort_order END), 3)
     FROM member_types
   HAVING COALESCE(SUM(CASE WHEN code = ''RGJ'' OR name = ''Regular - Joint'' THEN 1 ELSE 0 END), 0) = 0'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  2. Water Billing > Service & Materials > Address Sub-groups (the rights of Water Service Accounts)
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pages') = 0,
  'SELECT ''pages: table not installed''',
  'INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
   SELECT ''water.address_subgroups'', p.module_id, ''Address Sub-groups'', ''transaction'', ''Service & Materials'', p.sort_order + 5, 1,
          ''Add, rename, sort, switch off or delete the address sub-groups of the members and the water-service accounts.''
     FROM pages p WHERE p.page_key = ''water.member_list'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, r.allowed, r.can_create, r.can_edit, r.can_delete, r.can_print, r.can_approve
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.member_list''
     JOIN pages n ON n.page_key = ''water.address_subgroups'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
--  3. Water Billing > Setup > Receipt Layout Setup (the rights of Water Settings)
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pages') = 0,
  'SELECT ''pages: table not installed''',
  'INSERT IGNORE INTO pages (page_key, module_id, name, page_type, menu_group, sort_order, show_in_menu, description)
   SELECT ''water.receipt_layouts'', p.module_id, ''Receipt Layout Setup'', ''transaction'', ''Setup'', p.sort_order + 5, 1,
          ''The pre-printed water bill / collection receipt and official receipt: paper, margins and where each entry prints.''
     FROM pages p WHERE p.page_key = ''water.settings'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_page_access') = 0,
  'SELECT ''role_page_access: table not installed''',
  'INSERT IGNORE INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
   SELECT r.role_id, n.id, r.allowed, r.can_create, r.can_edit, r.can_delete, r.can_print, r.can_approve
     FROM role_page_access r
     JOIN pages o ON o.id = r.page_id AND o.page_key = ''water.settings''
     JOIN pages n ON n.page_key = ''water.receipt_layouts'''));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
