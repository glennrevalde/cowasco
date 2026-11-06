-- =====================================================================
--  Accounting System - migration: searching for items, one choice per transaction
--
--  What it changes
--    1. new setting  item_search_by_page  (the transactions whose item boxes are listed
--                                          differently from the overall choice, one
--                                          "page key=format" per line; empty = they all
--                                          follow item_search_format)
--
--  Nothing else changes. Your existing choice in
--  System Administration > Company, Security & Closing Date > Searching for items
--  stays exactly as it is and becomes the choice every transaction starts from.
--
--  For an EXISTING database that already has the earlier migrations.
--  New installs do not need it (schema.sql and tools/install.php include everything).
--  Safe to run more than once. Works on MySQL 5.5.3+ and MariaDB 5.5+.
--
--  How to run (back up the database first):
--    phpMyAdmin: click the accounting_system database > Import > choose this file > Go
--    Navicat:    right-click the accounting_system database > Execute SQL File
--    or:         mysql -u root -p accounting_system < 2026_09_23_item_search_per_transaction.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Settings
-- ---------------------------------------------------------------------

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('item_search_format', 'code_name');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('item_search_by_page', '');

-- ---------------------------------------------------------------------
--  2. Record the change in the audit trail
-- ---------------------------------------------------------------------

INSERT INTO audit_trail (username, user_roles, ip_address, workstation, module, page_key, action, record_type, record_ref, reason)
VALUES ('system', 'Database migration', '127.0.0.1', 'SQL migration', 'admin', 'admin.settings', 'settings_change', 'settings',
        'Searching for items', 'Migration 2026_09_23_item_search_per_transaction: the item name/code and display name order can now be set for each transaction on its own');

-- Done. Next step in the application:
--   System Administration > Company, Security & Closing Date > Searching for items:
--   the table there now lists every transaction, so each one can be set on its own.
--   Anything left alone keeps following the overall choice, exactly as before.
